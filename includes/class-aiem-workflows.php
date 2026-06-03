<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Workflows {

	const TRIGGER_SUBSCRIBER_ADDED         = 'subscriber_added';
	const TRIGGER_SUBSCRIBER_UNSUBSCRIBED  = 'subscriber_unsubscribed';
	const TRIGGER_CAMPAIGN_SENT            = 'campaign_sent';
	const TRIGGER_POST_PUBLISHED           = 'post_published';

	const ACTION_SEND_EMAIL = 'send_email';

	public static function fire_trigger( string $trigger_type, int $subscriber_id, array $context = [] ): void {
		$workflows = AIEM_DB::get_active_workflows_by_trigger( $trigger_type );

		foreach ( $workflows as $wf ) {
			$delay_seconds = self::delay_to_seconds( (int) $wf->delay_value, $wf->delay_unit );
			$scheduled_at  = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );

			$queued = AIEM_DB::insert_workflow_queue( (int) $wf->id, $subscriber_id, $scheduled_at );

			if ( $queued ) {
				AIEM_Logs::log( AIEM_Logs::EVENT_WORKFLOW_QUEUED, [
					'subscriber_id' => $subscriber_id,
					'workflow_id'   => $wf->id,
					'workflow_name' => $wf->name,
					'trigger'       => $trigger_type,
					'scheduled_at'  => $scheduled_at,
				] );
			}
		}

		// Process immediately so zero-delay workflows fire on this request.
		// Delayed workflows stay pending and are picked up by the 5-min cron.
		if ( ! empty( $workflows ) ) {
			self::process_queue();
		}
	}

	public static function handle_campaign_sent( int $campaign_id ): void {
		$workflows = AIEM_DB::get_active_workflows_by_trigger( self::TRIGGER_CAMPAIGN_SENT );
		if ( empty( $workflows ) ) {
			return;
		}

		// Get all subscribers who were successfully sent this campaign.
		$sends = AIEM_DB::get_sends_for_campaign( $campaign_id );
		$sent_subscriber_ids = [];
		foreach ( $sends as $s ) {
			if ( $s->status === 'sent' ) {
				$sent_subscriber_ids[] = (int) $s->subscriber_id;
			}
		}

		if ( empty( $sent_subscriber_ids ) ) {
			return;
		}

		$campaign = AIEM_DB::get_campaign( $campaign_id );
		$context  = [
			'campaign_subject' => $campaign->subject ?? '',
			'campaign_count'   => count( $sent_subscriber_ids ),
			'campaign_date'    => current_time( 'Y-m-d H:i' ),
		];

		foreach ( $workflows as $wf ) {
			$config            = json_decode( $wf->trigger_config ?? '{}', true );
			$filter_campaign   = (int) ( $config['filter_campaign_id'] ?? 0 );

			// 0 = any campaign; non-zero = only fire for that specific campaign.
			if ( $filter_campaign && $filter_campaign !== $campaign_id ) {
				continue;
			}

			$delay_seconds = self::delay_to_seconds( (int) $wf->delay_value, $wf->delay_unit );
			$scheduled_at  = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );

			foreach ( $sent_subscriber_ids as $subscriber_id ) {
				$queued = AIEM_DB::insert_workflow_queue( (int) $wf->id, $subscriber_id, $scheduled_at, 0, $context );
				if ( $queued ) {
					AIEM_Logs::log( AIEM_Logs::EVENT_WORKFLOW_QUEUED, [
						'subscriber_id' => $subscriber_id,
						'workflow_id'   => $wf->id,
						'workflow_name' => $wf->name,
						'trigger'       => self::TRIGGER_CAMPAIGN_SENT,
						'campaign_id'   => $campaign_id,
						'scheduled_at'  => $scheduled_at,
					] );
				}
			}
		}
	}

	public static function handle_post_published( string $new_status, string $old_status, WP_Post $post ): void {
		// Only fire on the transition to published (not on re-saves of already-published posts)
		if ( $new_status !== 'publish' || $old_status === 'publish' ) {
			return;
		}
		if ( $post->post_type !== 'post' ) {
			return;
		}

		$workflows = AIEM_DB::get_active_workflows_by_trigger( self::TRIGGER_POST_PUBLISHED );
		if ( empty( $workflows ) ) {
			return;
		}

		foreach ( $workflows as $wf ) {
			$config      = json_decode( $wf->trigger_config ?? '{}', true );
			$category_id = (int) ( $config['category_id'] ?? 0 );

			if ( $category_id && ! has_category( $category_id, $post ) ) {
				continue;
			}

			if ( ! $wf->action_list_id || ! $wf->action_subject || ! $wf->action_content ) {
				continue;
			}

			$subscribers = AIEM_DB::get_subscribed_for_campaign( (int) $wf->action_list_id );
			if ( empty( $subscribers ) ) {
				continue;
			}

			$delay_seconds = self::delay_to_seconds( (int) $wf->delay_value, $wf->delay_unit );
			$scheduled_at  = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );

			$count = 0;
			foreach ( $subscribers as $sub ) {
				$queued = AIEM_DB::insert_workflow_queue( (int) $wf->id, (int) $sub->id, $scheduled_at, (int) $post->ID );
				if ( $queued ) {
					$count++;
				}
			}

			if ( $count > 0 ) {
				AIEM_Logs::log( 'workflow_queued', [
					'workflow_id'     => $wf->id,
					'workflow_name'   => $wf->name,
					'trigger'         => self::TRIGGER_POST_PUBLISHED,
					'post_id'         => $post->ID,
					'post_title'      => $post->post_title,
					'recipient_count' => $count,
					'scheduled_at'    => $scheduled_at,
				] );
			}
		}
	}

	public static function process_queue(): void {
		$items = AIEM_DB::get_due_workflow_queue( 20 );

		foreach ( $items as $item ) {
			AIEM_DB::update_workflow_queue_item( (int) $item->id, [ 'status' => 'processing' ] );

			$wf = AIEM_DB::get_workflow( (int) $item->workflow_id );
			if ( ! $wf || $wf->status !== 'active' ) {
				AIEM_DB::update_workflow_queue_item( (int) $item->id, [
					'status'       => 'skipped',
					'processed_at' => current_time( 'mysql' ),
				] );
				continue;
			}

			if ( $wf->action_type === self::ACTION_SEND_EMAIL ) {
				$subscriber = AIEM_DB::get_subscriber( (int) $item->subscriber_id );
				$sent       = false;

				if ( $subscriber && $subscriber->status === 'subscribed' ) {
					$post    = ( (int) $item->post_id ) ? get_post( (int) $item->post_id ) : null;
					$context = json_decode( $item->context ?? '{}', true ) ?: [];
					$sent    = self::send_inline_email( $wf, $subscriber, $post instanceof WP_Post ? $post : null, $context );
				}

				AIEM_DB::update_workflow_queue_item( (int) $item->id, [
					'status'       => $sent ? 'sent' : 'failed',
					'processed_at' => current_time( 'mysql' ),
				] );

				AIEM_Logs::log(
					$sent ? AIEM_Logs::EVENT_WORKFLOW_SENT : AIEM_Logs::EVENT_WORKFLOW_FAILED,
					[
						'subscriber_id' => $item->subscriber_id,
						'workflow_id'   => $wf->id,
						'workflow_name' => $wf->name,
						'subject'       => $wf->action_subject,
					]
				);
			}
		}
	}

	private static function send_inline_email( object $wf, object $subscriber, ?WP_Post $post = null, array $context = [] ): bool {
		$send_to = self::replace_subscriber_tags( $wf->action_send_to ?: '{{EMAIL}}', $subscriber );
		$subject = self::replace_subscriber_tags( $wf->action_subject, $subscriber );
		$content = self::replace_subscriber_tags( $wf->action_content, $subscriber );

		if ( $post instanceof WP_Post ) {
			$subject = self::replace_post_tags( $subject, $post );
			$content = self::replace_post_tags( $content, $post );
		}

		$subject = self::replace_context_tags( $subject, $context );
		$content = self::replace_context_tags( $content, $context );

		if ( ! $send_to || ! $subject || ! $content ) {
			return false;
		}

		if ( ( $wf->action_email_styling ?? 'none' ) === 'default' ) {
			$template_file = AIEM_PLUGIN_DIR . 'templates/default-email.html';
			if ( file_exists( $template_file ) ) {
				$template = file_get_contents( $template_file );
				$content  = str_replace(
					[ '{{content}}', '{{unsubscribe_url}}', '{{site_name}}', '{{site_url}}' ],
					[ $content, home_url( '/' ), get_bloginfo( 'name' ), home_url() ],
					$template
				);
			}
		}

		$from_name  = get_option( 'aiem_from_name', get_bloginfo( 'name' ) ) ?: get_bloginfo( 'name' );
		$from_email = get_option( 'aiem_from_email', get_option( 'admin_email' ) ) ?: get_option( 'admin_email' );

		return (bool) wp_mail( $send_to, $subject, $content, [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		] );
	}

	private static function replace_subscriber_tags( string $text, object $subscriber ): string {
		$first = $subscriber->first_name ?? '';
		$last  = $subscriber->last_name ?? '';
		$email = $subscriber->email ?? '';
		return str_replace(
			[ '{{EMAIL}}', '{{email}}', '{{first_name}}', '{{last_name}}', '{{full_name}}', '{{site_name}}', '{{site_url}}' ],
			[ $email, $email, $first, $last, trim( "$first $last" ), get_bloginfo( 'name' ), home_url() ],
			$text
		);
	}

	private static function replace_post_tags( string $text, WP_Post $post ): string {
		$author  = get_the_author_meta( 'display_name', (int) $post->post_author );
		$excerpt = $post->post_excerpt
			?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );

		return str_replace(
			[ '{{post_title}}', '{{post_url}}', '{{post_excerpt}}', '{{post_author}}' ],
			[ esc_html( $post->post_title ), esc_url( get_permalink( $post->ID ) ), esc_html( $excerpt ), esc_html( $author ) ],
			$text
		);
	}

	private static function replace_context_tags( string $text, array $context ): string {
		return str_replace(
			[ '{{DATE}}',                          '{{SUBJECT}}',                              '{{COUNT}}' ],
			[
				$context['campaign_date']    ?? current_time( 'Y-m-d H:i' ),
				$context['campaign_subject'] ?? '',
				(string) ( $context['campaign_count'] ?? '' ),
			],
			$text
		);
	}

	private static function delay_to_seconds( int $value, string $unit ): int {
		if ( $value <= 0 ) {
			return 0;
		}
		switch ( $unit ) {
			case 'hours': return $value * 3600;
			case 'days':  return $value * 86400;
			default:      return $value * 60;
		}
	}
}
