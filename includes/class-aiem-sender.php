<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Sender {

	private static int $current_send_id = 0;

	/** Message of the most recent wp_mail() failure in this request. */
	public static string $last_mail_error = '';

	public static function init(): void {
		add_action( 'wp_mail_failed', [ __CLASS__, 'handle_mail_failed' ] );
	}

	public static function handle_mail_failed( WP_Error $error ): void {
		self::$last_mail_error = (string) $error->get_error_message();
		if ( ! self::$current_send_id ) {
			return;
		}

		$send_id   = self::$current_send_id;
		$error_msg = $error->get_error_message();

		// wp_mail_failed fires for ANY failure (bad SMTP password, connection
		// timeout, rate limit...). Only a permanent rejection of the recipient
		// address is a bounce. Everything else is kept as a plain failure so a
		// mail-server outage can never suppress the subscriber list.
		if ( ! self::is_hard_bounce( $error_msg ) ) {
			AIEM_DB::update_send( $send_id, [ 'bounce_error' => $error_msg ] );
			return;
		}

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

	/**
	 * True only when the mail server permanently rejected the recipient address.
	 * Prefers the enhanced status code (5.1.x = bad address, 5.2.1 = mailbox
	 * disabled); falls back to a 550-553 reply with "no such user" style text.
	 */
	public static function is_hard_bounce( string $error_msg ): bool {
		if ( preg_match( '/\b5\.(\d)\.(\d{1,3})\b/', $error_msg, $m ) ) {
			if ( $m[1] === '1' ) {
				return in_array( $m[2], [ '1', '2', '3', '6', '10' ], true );
			}
			return $m[1] === '2' && $m[2] === '1';
		}
		if ( ! preg_match( '/\b55[0-3]\b/', $error_msg ) ) {
			return false;
		}
		return (bool) preg_match(
			'/user unknown|unknown user|no such (user|mailbox|recipient|address)|does not exist|mailbox (unavailable|not found)|invalid (recipient|mailbox|address)|recipient (rejected|not found)|address rejected/i',
			$error_msg
		);
	}

	const FAIL_STREAK_LIMIT = 3;
	const BACKOFF_FIRST     = 15 * MINUTE_IN_SECONDS;
	const BACKOFF_MAX       = 4 * HOUR_IN_SECONDS;

	/**
	 * True when the mail server refused for a reason that has nothing to do
	 * with the recipient and should clear up later: rate or daily limits,
	 * "try again later" (4xx) replies, connection or login trouble.
	 */
	public static function is_temporary_failure( string $error_msg ): bool {
		if ( $error_msg === '' || self::is_hard_bounce( $error_msg ) ) {
			return false;
		}
		if ( preg_match( '/\b4\.\d\.\d{1,3}\b/', $error_msg ) || preg_match( '/(^|[^0-9.])4[2-5]\d([^0-9.]|$)/', $error_msg ) ) {
			return true;
		}
		return (bool) preg_match(
			'/rate.?limit|too many|limit (exceeded|reached)|exceeded|quota|throttl|try (again )?later|temporar|could not connect|connect\(\) failed|connection (refused|timed out|reset)|timed? ?out|could not authenticate|authentication (failed|unsuccessful)|\b535\b/i',
			$error_msg
		);
	}

	/** Seconds a batch may spend sending, kept under PHP's time limit. */
	public static function batch_time_budget(): int {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return 50;
		}
		return max( 20, min( 50, $limit - 10 ) );
	}

	/** Rolling average of seconds per email, for the pace estimate. */
	private static function record_send_speed( float $seconds ): void {
		$prev = (float) get_option( 'aiem_avg_send_seconds', 0 );
		$avg  = $prev > 0 ? ( $prev * 0.7 + $seconds * 0.3 ) : $seconds;
		update_option( 'aiem_avg_send_seconds', round( $avg, 3 ), false );
	}

	/** Replace any queued batch for this campaign with one $delay seconds out. */
	public static function schedule_next_batch( int $campaign_id, int $delay ): void {
		wp_clear_scheduled_hook( 'aiem_process_batch', [ $campaign_id ] );
		wp_schedule_single_event( time() + max( 1, $delay ), 'aiem_process_batch', [ $campaign_id ] );
	}

	public static function get_backoff( int $campaign_id ): ?array {
		$b = get_option( 'aiem_backoff_' . $campaign_id );
		return is_array( $b ) ? $b : null;
	}

	/** Start or extend a back-off: 15 min, doubling each time, capped at 4 h. */
	private static function start_backoff( int $campaign_id, string $error, ?array $prev ): int {
		$step = $prev ? (int) $prev['step'] + 1 : 0;
		$wait = (int) min( self::BACKOFF_MAX, self::BACKOFF_FIRST * ( 2 ** $step ) );
		update_option( 'aiem_backoff_' . $campaign_id, [
			'until' => time() + $wait,
			'step'  => $step,
			'error' => mb_substr( $error, 0, 300 ),
		], false );
		return $wait;
	}

	public static function clear_backoff( int $campaign_id ): void {
		delete_option( 'aiem_backoff_' . $campaign_id );
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
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
				'campaign_id' => $campaign_id,
				'error'       => 'campaign_not_found',
			] );
			return false;
		}
		// A leftover scheduled batch for a campaign that is no longer sending.
		if ( $campaign->status !== 'sending' ) {
			return false;
		}

		// Backing off after the mail server refused mail: wait it out.
		$backoff = self::get_backoff( $campaign_id );
		if ( $backoff && $backoff['until'] > time() ) {
			self::schedule_next_batch( $campaign_id, $backoff['until'] - time() );
			return true;
		}

		$batch_size = max( 1, (int) get_option( 'aiem_batch_size', 50 ) );

		// Hourly cap across all campaign mail.
		$hourly_limit = max( 0, (int) get_option( 'aiem_hourly_limit', 0 ) );
		if ( $hourly_limit ) {
			$room = $hourly_limit - AIEM_DB::count_sent_last_hour();
			if ( $room <= 0 ) {
				$wait = AIEM_DB::seconds_until_hourly_room();
				self::schedule_next_batch( $campaign_id, $wait );
				AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
					'campaign_id'  => $campaign_id,
					'action'       => 'hourly_limit_reached',
					'hourly_limit' => $hourly_limit,
					'resume_in'    => $wait,
				] );
				return true;
			}
			$batch_size = min( $batch_size, $room );
		}

		$sends = AIEM_DB::get_pending_sends( $campaign_id, $batch_size );

		AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
			'campaign_id' => $campaign_id,
			'batch_size'  => $batch_size,
			'pending'     => count( $sends ),
		] );

		// Stop before PHP's time limit so the "schedule next batch" step
		// below always gets to run. Unsent rows stay pending for the next batch.
		$started     = microtime( true );
		$deadline    = $started + self::batch_time_budget();
		$sent_count  = 0;
		$fail_streak = [];     // consecutive non-bounce failures: send ids
		$refused     = '';     // set when the server refused mail; stops the batch

		foreach ( $sends as $send ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			// Atomic claim: if an overlapping batch already took this row, skip it.
			if ( ! AIEM_DB::claim_send( (int) $send->id ) ) {
				continue;
			}

			$subscriber  = (object) [ 'email' => $send->email, 'first_name' => $send->first_name, 'last_name' => $send->last_name ];
			$html        = self::build_email( $campaign->html_content, $send->tracking_key, $subscriber, $campaign->preheader ?? '' );
			$headers     = self::campaign_headers( $campaign, $send->tracking_key );

			self::$current_send_id = (int) $send->id;
			$sent = wp_mail( $send->email, $campaign->subject, $html, $headers );
			self::$current_send_id = 0;

			if ( $sent ) {
				$sent_count++;
				$fail_streak = [];
				AIEM_DB::update_send( (int) $send->id, [ 'status' => 'sent', 'sent_at' => current_time( 'mysql' ), 'bounce_error' => null ] );
				AIEM_DB::reset_bounce_count( (int) $send->subscriber_id );
				AIEM_Logs::log( AIEM_Logs::EVENT_SEND, [
					'subscriber_id' => $send->subscriber_id,
					'campaign_id'   => $campaign_id,
					'send_id'       => $send->id,
					'email'         => $send->email,
				] );
			} else {
				$fresh = AIEM_DB::get_send( (int) $send->id );
				$error = (string) ( $fresh->bounce_error ?? '' );
				if ( $fresh && $fresh->status === 'bounced' ) {
					// Hard bounce, already handled by handle_mail_failed. The
					// address was bad, not the server, so the streak resets.
					$fail_streak = [];
				} elseif ( self::is_temporary_failure( $error ) ) {
					// Rate limit, quota, connection or login problem: nothing
					// wrong with this subscriber. Put it back and stop.
					AIEM_DB::update_send( (int) $send->id, [ 'status' => 'pending', 'sent_at' => null ] );
					$refused = $error;
					break;
				} else {
					AIEM_DB::update_send( (int) $send->id, [ 'status' => 'failed', 'sent_at' => current_time( 'mysql' ) ] );
					AIEM_Logs::log( AIEM_Logs::EVENT_SEND_FAILED, [
						'subscriber_id' => $send->subscriber_id,
						'campaign_id'   => $campaign_id,
						'send_id'       => $send->id,
						'email'         => $send->email,
						'error'         => $error,
					] );
					// Several different addresses failing in a row for reasons
					// other than a bad address means the server is the problem.
					$fail_streak[] = (int) $send->id;
					if ( count( $fail_streak ) >= self::FAIL_STREAK_LIMIT ) {
						foreach ( $fail_streak as $sid ) {
							AIEM_DB::update_send( $sid, [ 'status' => 'pending', 'sent_at' => null ] );
						}
						$refused = $error ?: 'Several sends failed in a row.';
						break;
					}
				}
			}
		}

		if ( $sent_count ) {
			self::record_send_speed( ( microtime( true ) - $started ) / $sent_count );
			if ( $backoff ) {
				self::clear_backoff( $campaign_id );
				AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
					'campaign_id' => $campaign_id,
					'action'      => 'resumed_after_backoff',
				] );
			}
		}

		if ( $refused !== '' ) {
			$wait = self::start_backoff( $campaign_id, $refused, $backoff );
			self::schedule_next_batch( $campaign_id, $wait );
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
				'campaign_id' => $campaign_id,
				'action'      => 'server_refused_backing_off',
				'error'       => $refused,
				'retry_in'    => $wait,
			] );
			return true;
		}

		$remaining = count( AIEM_DB::get_pending_sends( $campaign_id, 1 ) );

		if ( $remaining > 0 ) {
			$delay = max( 1, (int) get_option( 'aiem_batch_delay', 5 ) );
			self::schedule_next_batch( $campaign_id, $delay );
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
				'campaign_id' => $campaign_id,
				'action'      => 'next_batch_scheduled',
				'delay'       => $delay,
			] );
		} elseif ( AIEM_DB::count_sends_in_progress( $campaign_id ) > 0 ) {
			// Another batch is still mid-send. It (or the cron recovery sweep)
			// will finish the campaign.
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
				'campaign_id' => $campaign_id,
				'action'      => 'waiting_on_other_batch',
			] );
		} elseif ( AIEM_DB::complete_campaign( $campaign_id ) ) {
			self::clear_backoff( $campaign_id );
			// complete_campaign() is atomic, so these run exactly once.
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_BATCH, [
				'campaign_id' => $campaign_id,
				'action'      => 'campaign_complete',
			] );
			AIEM_Workflows::handle_campaign_sent( $campaign_id );
			AIEM_DB::schedule_next_recurrence( $campaign_id );
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
		$headers      = self::campaign_headers( $campaign, $tracking_key );

		self::$current_send_id = (int) $send_id;
		$sent = wp_mail( $subscriber->email, $campaign->subject, $html, $headers );
		self::$current_send_id = 0;

		if ( $sent ) {
			AIEM_DB::update_send( (int) $send_id, [ 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ] );
			AIEM_DB::reset_bounce_count( $subscriber_id );
		} else {
			$fresh = AIEM_DB::get_send( (int) $send_id );
			if ( ! $fresh || $fresh->status !== 'bounced' ) {
				AIEM_DB::update_send( (int) $send_id, [ 'status' => 'failed', 'sent_at' => current_time( 'mysql' ) ] );
			}
		}

		return $sent;
	}

	/**
	 * Headers for a campaign email. Real sends (not test previews) get the
	 * List-Unsubscribe pair that Gmail/Yahoo require from bulk senders.
	 */
	private static function campaign_headers( object $campaign, string $tracking_key = '' ): array {
		$from_name  = $campaign->from_name ?: get_bloginfo( 'name' );
		$from_email = $campaign->from_email ?: get_option( 'admin_email' );
		$headers    = [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		];
		if ( $tracking_key && $tracking_key !== 'test_preview' ) {
			$headers = array_merge( $headers, self::list_unsubscribe_headers( self::unsub_url_for_key( $tracking_key ) ) );
		}
		return $headers;
	}

	/** RFC 8058 one-click unsubscribe headers. */
	public static function list_unsubscribe_headers( string $unsub_url ): array {
		return [
			'List-Unsubscribe: <' . esc_url_raw( $unsub_url ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		];
	}

	/** Unsubscribe URL tied to a campaign send (tracking key). */
	public static function unsub_url_for_key( string $tracking_key ): string {
		return add_query_arg( [
			'aiem_unsub' => '1',
			'k'          => $tracking_key,
		], home_url( '/' ) );
	}

	public static function subscriber_unsub_token( int $subscriber_id ): string {
		return substr( hash_hmac( 'sha256', 'aiem_unsub|' . $subscriber_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** Unsubscribe URL for emails with no send row (workflow emails). */
	public static function subscriber_unsub_url( int $subscriber_id ): string {
		return add_query_arg( [
			'aiem_unsub' => '1',
			'sid'        => $subscriber_id,
			'tok'        => self::subscriber_unsub_token( $subscriber_id ),
		], home_url( '/' ) );
	}

	/** Signature proving a tracked click URL was generated by this site. */
	public static function click_signature( string $tracking_key, string $url ): string {
		return substr( hash_hmac( 'sha256', 'aiem_click|' . $tracking_key . '|' . $url, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** Fallback footer used when the email HTML has no {{unsubscribe_url}}. */
	public static function unsubscribe_footer( string $unsub_url ): string {
		return '<div style="text-align:center;font-family:Arial,sans-serif;font-size:12px;line-height:18px;color:#888888;padding:20px 10px;">'
			. '<a href="' . esc_url( $unsub_url ) . '" style="color:#888888;text-decoration:underline;">Unsubscribe</a>'
			. '</div>';
	}

	/** Insert a snippet just before </body>, or append when there is none. */
	public static function inject_at_end( string $html, string $snippet ): string {
		$pos = strripos( $html, '</body>' );
		if ( $pos === false ) {
			return $html . $snippet;
		}
		return substr( $html, 0, $pos ) . $snippet . substr( $html, $pos );
	}

	public static function build_email( string $html_content, string $tracking_key, object $subscriber, string $preheader = '' ): string {
		$unsub_url     = self::unsub_url_for_key( $tracking_key );
		$has_unsub_tag = strpos( $html_content, '{{unsubscribe_url}}' ) !== false;

		$content = self::replace_merge_tags( $html_content, $subscriber );

		// Placeholders must be filled BEFORE links are rewritten. Otherwise
		// href="{{unsubscribe_url}}" gets wrapped in a click-tracking redirect
		// while still a raw placeholder, and the unsubscribe link is dead.
		$content = str_replace(
			[ '{{unsubscribe_url}}', '{{site_name}}', '{{site_url}}', '{{preheader}}' ],
			[ esc_url( $unsub_url ), get_bloginfo( 'name' ), home_url(), esc_html( $preheader ) ],
			$content
		);

		$content = self::rewrite_links( $content, $tracking_key );

		// Inject preheader hidden div if the HTML has a body tag.
		if ( $preheader && stripos( $content, '<body' ) !== false ) {
			$preheader_div = '<div style="display:none;font-size:1px;color:transparent;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . esc_html( $preheader ) . '</div>';
			$content = preg_replace( '/(<body[^>]*>)/i', '$1' . $preheader_div, $content, 1 );
		}

		// Every marketing email must carry an unsubscribe link.
		if ( ! $has_unsub_tag ) {
			$content = self::inject_at_end( $content, self::unsubscribe_footer( $unsub_url ) );
		}

		$pixel_url      = add_query_arg( [ 'aiem_track' => 'open', 'k' => $tracking_key ], home_url( '/' ) );
		$tracking_pixel = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" style="display:block;border:0;" alt="" />';

		return self::inject_at_end( $content, $tracking_pixel );
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
				// The attribute is HTML-encoded (&amp;); decode to the real URL.
				$href = html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				// Only track real web links: skip mailto:, tel:, #anchors, etc.
				if ( ! preg_match( '#^https?://#i', $href ) ) {
					return $matches[0];
				}
				if ( strpos( $href, 'aiem_unsub' ) !== false ) {
					return $matches[0];
				}

				// URL-safe base64 (no + / =) so the value survives the query string.
				$tracked = add_query_arg( [
					'aiem_track' => 'click',
					'k'          => $tracking_key,
					'url'        => rtrim( strtr( base64_encode( $href ), '+/', '-_' ), '=' ),
					'sig'        => self::click_signature( $tracking_key, $href ),
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

		self::$last_mail_error = '';
		$sent = wp_mail(
			$subscriber->email,
			'Confirm your subscription to ' . $site_name,
			$html,
			[
				'Content-Type: text/html; charset=UTF-8',
				"From: {$from_name} <{$from_email}>",
			]
		);
		AIEM_Logs::log( AIEM_Logs::EVENT_CONFIRM_EMAIL, array_filter( [
			'subscriber_id' => $subscriber_id,
			'email'         => $subscriber->email,
			'sent'          => $sent,
			'error'         => $sent ? '' : ( self::$last_mail_error ?: 'wp_mail() returned false' ),
		], fn( $v ) => $v !== '' ) );
		return $sent;
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
