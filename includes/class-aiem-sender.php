<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Sender {

	private static int $current_send_id = 0;

	public static function init(): void {
		add_action( 'wp_mail_failed', [ __CLASS__, 'handle_mail_failed' ] );
	}

	public static function handle_mail_failed( WP_Error $error ): void {
		if ( ! self::$current_send_id ) {
			return;
		}

		$send_id   = self::$current_send_id;
		$error_msg = $error->get_error_message();

		AIEM_DB::mark_send_bounced( $send_id, $error_msg );

		$send = AIEM_DB::get_send( $send_id );
		if ( ! $send ) {
			return;
		}

		$new_count = AIEM_DB::increment_bounce_count( (int) $send->subscriber_id );
		$threshold = max( 1, (int) get_option( 'aiem_bounce_threshold', 3 ) );
		if ( $new_count >= $threshold ) {
			AIEM_DB::bounce_subscriber( (int) $send->subscriber_id );
		}

		AIEM_Logs::log( AIEM_Logs::EVENT_BOUNCE, [
			'subscriber_id' => (int) $send->subscriber_id,
			'campaign_id'   => (int) $send->campaign_id,
			'send_id'       => $send_id,
			'error'         => $error_msg,
			'bounce_count'  => $new_count,
		] );
	}

	public static function enqueue_sends( int $campaign_id ): int {
		$campaign = AIEM_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return 0;
		}

		if ( (int) $campaign->segment_id ) {
			$subscribers = AIEM_DB::get_subscribers_for_segment( (int) $campaign->segment_id );
		} else {
			$subscribers = AIEM_DB::get_subscribed_for_campaign( (int) $campaign->list_id );
		}
		$count       = 0;

		foreach ( $subscribers as $sub ) {
			$send_id = AIEM_DB::insert_send( $campaign_id, (int) $sub->id );
			if ( $send_id ) {
				$count++;
			}
		}

		return $count;
	}

	public static function process_batch( int $campaign_id ): bool {
		$campaign = AIEM_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		$batch_size = max( 1, (int) get_option( 'aiem_batch_size', 50 ) );
		$sends = AIEM_DB::get_pending_sends( $campaign_id, $batch_size );

		foreach ( $sends as $send ) {
			$subscriber  = (object) [ 'email' => $send->email, 'first_name' => $send->first_name, 'last_name' => $send->last_name ];
			$html        = self::build_email( $campaign->html_content, $send->tracking_key, $subscriber, $campaign->preheader ?? '' );
			$from_name   = $campaign->from_name ?: get_bloginfo( 'name' );
			$from_email  = $campaign->from_email ?: get_option( 'admin_email' );
			$headers     = [
				'Content-Type: text/html; charset=UTF-8',
				"From: {$from_name} <{$from_email}>",
			];

			self::$current_send_id = (int) $send->id;
			$sent = wp_mail( $send->email, $campaign->subject, $html, $headers );
			self::$current_send_id = 0;

			if ( $sent ) {
				AIEM_DB::update_send( (int) $send->id, [ 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ] );
				AIEM_Logs::log( AIEM_Logs::EVENT_SEND, [
					'subscriber_id' => $send->subscriber_id,
					'campaign_id'   => $campaign_id,
					'send_id'       => $send->id,
					'email'         => $send->email,
				] );
			} else {
				$fresh = AIEM_DB::get_send( (int) $send->id );
				if ( $fresh && $fresh->status === 'bounced' ) {
					// already handled by handle_mail_failed
				} else {
					AIEM_DB::update_send( (int) $send->id, [ 'status' => 'failed', 'sent_at' => current_time( 'mysql' ) ] );
					AIEM_Logs::log( AIEM_Logs::EVENT_SEND_FAILED, [
						'subscriber_id' => $send->subscriber_id,
						'campaign_id'   => $campaign_id,
						'send_id'       => $send->id,
						'email'         => $send->email,
					] );
				}
			}
		}

		$remaining = count( AIEM_DB::get_pending_sends( $campaign_id, 1 ) );

		if ( $remaining > 0 ) {
			$delay = max( 1, (int) get_option( 'aiem_batch_delay', 5 ) );
			wp_schedule_single_event( time() + $delay, 'aiem_process_batch', [ $campaign_id ] );
		} else {
			AIEM_DB::update_campaign( $campaign_id, [
				'status'  => 'sent',
				'sent_at' => current_time( 'mysql' ),
			] );
			AIEM_Workflows::handle_campaign_sent( $campaign_id );
		}

		return true;
	}

	public static function send_to_subscriber( int $campaign_id, int $subscriber_id ): bool {
		$campaign   = AIEM_DB::get_campaign( $campaign_id );
		$subscriber = AIEM_DB::get_subscriber( $subscriber_id );

		if ( ! $campaign || ! $subscriber ) {
			return false;
		}
		if ( $subscriber->status !== 'subscribed' ) {
			return false;
		}

		// Create (or reuse) a tracked send row so open/click/unsubscribe links work.
		$send_id = AIEM_DB::insert_send( $campaign_id, $subscriber_id );
		if ( $send_id ) {
			$send = AIEM_DB::get_send( (int) $send_id );
		} else {
			$send    = AIEM_DB::get_send_by_campaign_subscriber( $campaign_id, $subscriber_id );
			$send_id = $send ? (int) $send->id : 0;
		}
		if ( ! $send ) {
			return false;
		}

		$tracking_key = $send->tracking_key;
		$html         = self::build_email( $campaign->html_content, $tracking_key, $subscriber, $campaign->preheader ?? '' );
		$from_name    = $campaign->from_name ?: get_bloginfo( 'name' );
		$from_email   = $campaign->from_email ?: get_option( 'admin_email' );
		$headers      = [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		];

		self::$current_send_id = (int) $send_id;
		$sent = wp_mail( $subscriber->email, $campaign->subject, $html, $headers );
		self::$current_send_id = 0;

		if ( $sent ) {
			AIEM_DB::update_send( (int) $send_id, [ 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ] );
		} else {
			$fresh = AIEM_DB::get_send( (int) $send_id );
			if ( ! $fresh || $fresh->status !== 'bounced' ) {
				AIEM_DB::update_send( (int) $send_id, [ 'status' => 'failed', 'sent_at' => current_time( 'mysql' ) ] );
			}
		}

		return $sent;
	}

	public static function build_email( string $html_content, string $tracking_key, object $subscriber, string $preheader = '' ): string {
		$template_file = AIEM_PLUGIN_DIR . 'templates/default-email.html';
		$template      = file_get_contents( $template_file );

		$content = self::replace_merge_tags( $html_content, $subscriber );
		$content = self::rewrite_links( $content, $tracking_key );

		$unsub_url = add_query_arg( [
			'aiem_unsub' => '1',
			'k'          => $tracking_key,
		], home_url( '/' ) );

		$pixel_url      = add_query_arg( [ 'aiem_track' => 'open', 'k' => $tracking_key ], home_url( '/' ) );
		$tracking_pixel = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" style="display:block;border:0;" alt="" />';

		$output = str_replace(
			[ '{{content}}', '{{unsubscribe_url}}', '{{site_name}}', '{{site_url}}', '{{preheader}}' ],
			[ $content . $tracking_pixel, esc_url( $unsub_url ), get_bloginfo( 'name' ), home_url(), esc_html( $preheader ) ],
			$template
		);

		return $output;
	}

	private static function replace_merge_tags( string $html, object $subscriber ): string {
		$first = $subscriber->first_name ?? '';
		$last  = $subscriber->last_name ?? '';
		$email = $subscriber->email ?? '';

		return str_replace(
			[ '{{first_name}}', '{{last_name}}', '{{full_name}}', '{{email}}', '{{site_name}}', '{{site_url}}' ],
			[ $first, $last, trim( "$first $last" ), $email, get_bloginfo( 'name' ), home_url() ],
			$html
		);
	}

	private static function rewrite_links( string $html, string $tracking_key ): string {
		return preg_replace_callback(
			'/<a\s([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
			function ( $matches ) use ( $tracking_key ) {
				$href = $matches[2];

				if ( strpos( $href, 'mailto:' ) === 0 ) {
					return $matches[0];
				}
				if ( strpos( $href, 'aiem_unsub' ) !== false ) {
					return $matches[0];
				}

				$tracked = add_query_arg( [
					'aiem_track' => 'click',
					'k'          => $tracking_key,
					'url'        => base64_encode( $href ),
				], home_url( '/' ) );

				return '<a ' . $matches[1] . 'href="' . esc_url( $tracked ) . '"' . $matches[3] . '>';
			},
			$html
		);
	}

	public static function send_confirmation_email( int $subscriber_id ): bool {
		$subscriber = AIEM_DB::get_subscriber( $subscriber_id );
		if ( ! $subscriber || $subscriber->status !== 'unconfirmed' || empty( $subscriber->confirm_key ) ) {
			return false;
		}

		$confirm_url = add_query_arg( [
			'aiem_confirm' => '1',
			'k'            => $subscriber->confirm_key,
		], home_url( '/' ) );

		$from_name  = get_option( 'aiem_from_name', get_bloginfo( 'name' ) ) ?: get_bloginfo( 'name' );
		$from_email = get_option( 'aiem_from_email', get_option( 'admin_email' ) ) ?: get_option( 'admin_email' );
		$site_name  = get_bloginfo( 'name' );
		$first      = $subscriber->first_name ? esc_html( $subscriber->first_name ) : 'there';

		$html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
			. '<h2 style="color:#1a1a1a;">Confirm your subscription</h2>'
			. "<p>Hi {$first},</p>"
			. '<p>Click the button below to confirm your subscription to <strong>' . esc_html( $site_name ) . '</strong>.</p>'
			. '<p style="text-align:center;margin:30px 0;">'
			. '<a href="' . esc_url( $confirm_url ) . '" style="background:#7c3aed;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Confirm Subscription</a>'
			. '</p>'
			. '<p style="color:#999;font-size:13px;">If you didn\'t sign up for this, you can safely ignore this email.</p>'
			. '</div>';

		return wp_mail(
			$subscriber->email,
			'Confirm your subscription to ' . $site_name,
			$html,
			[
				'Content-Type: text/html; charset=UTF-8',
				"From: {$from_name} <{$from_email}>",
			]
		);
	}

	public static function send_test( int $campaign_id, string $to_email ): bool {
		$campaign = AIEM_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		$subscriber = (object) [ 'email' => $to_email, 'first_name' => 'Test', 'last_name' => 'User' ];
		$html       = self::build_email( $campaign->html_content, 'test_preview', $subscriber, $campaign->preheader ?? '' );
		$from_name  = $campaign->from_name ?: get_bloginfo( 'name' );
		$from_email = $campaign->from_email ?: get_option( 'admin_email' );
		$headers    = [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		];

		return wp_mail( $to_email, '[TEST] ' . $campaign->subject, $html, $headers );
	}
}
