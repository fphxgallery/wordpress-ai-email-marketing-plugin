<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Ajax {

	public function __construct() {
		$admin_actions = [
			'aiem_generate_email',
			'aiem_generate_subject',
			'aiem_send_campaign',
			'aiem_schedule_campaign',
			'aiem_import_subscribers',
			'aiem_export_subscribers',
			'aiem_test_send',
			'aiem_save_campaign',
			'aiem_delete_subscriber',
			'aiem_save_workflow',
			'aiem_delete_workflow',
			'aiem_toggle_workflow',
			'aiem_save_segment',
			'aiem_delete_segment',
			'aiem_preview_segment',
			'aiem_save_form',
			'aiem_delete_form',
			'aiem_save_email_template',
			'aiem_delete_email_template',
			'aiem_duplicate_email_template',
			'aiem_test_send_template',
			'aiem_rewrite_text',
			'aiem_resend_non_openers',
			'aiem_resend_campaign',
			'aiem_process_workflow_queue',
		];

		foreach ( $admin_actions as $action ) {
			add_action( "wp_ajax_{$action}", [ $this, str_replace( 'aiem_', '', $action ) ] );
		}

		add_action( 'wp_ajax_aiem_subscribe',        [ $this, 'subscribe' ] );
		add_action( 'wp_ajax_nopriv_aiem_subscribe', [ $this, 'subscribe' ] );
	}

	private function verify_admin( string $nonce_action = 'aiem_admin_nonce' ): void {
		check_ajax_referer( $nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}
	}

	public function generate_email(): void {
		$this->verify_admin();

		$prompt      = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		$use_woo     = ! empty( $_POST['use_woo'] );
		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );

		if ( ! $prompt ) {
			wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
		}

		$products = [];
		if ( $use_woo ) {
			$raw_cats = wp_unslash( $_POST['woo_category_ids'] ?? '[]' );
			$raw_tags = wp_unslash( $_POST['woo_tag_ids'] ?? '[]' );
			$cat_ids  = array_filter( array_map( 'intval', json_decode( $raw_cats, true ) ?: [] ) );
			$tag_ids  = array_filter( array_map( 'intval', json_decode( $raw_tags, true ) ?: [] ) );

			if ( empty( $cat_ids ) ) {
				$cat_ids = array_filter( array_map( 'intval', json_decode( get_option( 'aiem_woo_categories', '[]' ), true ) ?: [] ) );
			}
			if ( empty( $tag_ids ) ) {
				$tag_ids = array_filter( array_map( 'intval', json_decode( get_option( 'aiem_woo_tags', '[]' ), true ) ?: [] ) );
			}

			$products = AIEM_WooCommerce::get_recent_products( array_values( $cat_ids ), array_values( $tag_ids ) );
		}

		$template_html = '';
		$template_id   = (int) ( $_POST['template_id'] ?? 0 );
		// The HTML currently in the campaign editor (the loaded template plus
		// any edits) takes priority over the saved template.
		$base_html = trim( (string) wp_unslash( $_POST['base_html'] ?? '' ) );
		if ( $template_id && $base_html !== '' ) {
			$template_html = $base_html;
		} elseif ( $template_id ) {
			$tpl = AIEM_DB::get_email_template( $template_id );
			if ( $tpl && ! empty( $tpl->html_content ) ) {
				$template_html = $tpl->html_content;
			}
		}

		$result = AIEM_OpenAI::generate( $prompt, $products, $template_html );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		if ( $campaign_id ) {
			$raw_cats = wp_unslash( $_POST['woo_category_ids'] ?? '[]' );
			$raw_tags = wp_unslash( $_POST['woo_tag_ids'] ?? '[]' );
			AIEM_DB::update_campaign( $campaign_id, [
				'ai_prompt'        => $prompt,
				'woo_category_ids' => wp_json_encode( array_filter( array_map( 'intval', json_decode( $raw_cats, true ) ?: [] ) ) ),
				'woo_tag_ids'      => wp_json_encode( array_filter( array_map( 'intval', json_decode( $raw_tags, true ) ?: [] ) ) ),
			] );
		}

		wp_send_json_success( [
			'subject'      => $result['subject'],
			'preview_text' => $result['preview_text'],
			'html'         => $result['html'],
		] );
	}

	public function generate_subject(): void {
		$this->verify_admin();

		$prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		if ( ! $prompt ) {
			wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
		}

		$system_prompt = "You are an expert email marketing copywriter. "
			. "Respond with a single JSON object (no markdown, no code fences) containing exactly two keys:\n"
			. "  \"subject\"      — a compelling email subject line (under 60 characters)\n"
			. "  \"preview_text\" — inbox preview text that complements the subject (under 100 characters)";

		$data = AIEM_OpenAI::chat(
			[
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user',   'content' => $prompt ],
			],
			200,
			0.8
		);
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( [ 'message' => $data->get_error_message() ] );
		}

		$content = trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
		$parsed  = json_decode( preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $content ), true );

		if ( ! is_array( $parsed ) ) {
			wp_send_json_error( [ 'message' => 'Could not parse response.' ] );
		}

		wp_send_json_success( [
			'subject'      => $parsed['subject']      ?? '',
			'preview_text' => $parsed['preview_text'] ?? '',
		] );
	}

	public function send_campaign(): void {
		$this->verify_admin();

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = AIEM_DB::get_campaign( $campaign_id );

		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}
		if ( ! $campaign->html_content ) {
			wp_send_json_error( [ 'message' => 'Campaign has no content. Generate or write content first.' ] );
		}
		if ( ! $campaign->list_id && ! $campaign->segment_id ) {
			wp_send_json_error( [ 'message' => 'No audience selected. Choose a list or segment.' ] );
		}

		// Atomic claim: a second click (or a cron run) while this campaign is
		// already sending gets rejected instead of corrupting its status.
		$prev_status = (string) $campaign->status;
		if ( ! AIEM_DB::claim_campaign_for_send( $campaign_id ) ) {
			wp_send_json_error( [ 'message' => 'This campaign is already sending.' ] );
		}

		// Regenerate AI content for recurring AI campaigns, same as the cron path.
		if ( $campaign->recur_schedule && $campaign->ai_prompt ) {
			$regen = AIEM_OpenAI::regenerate_campaign( $campaign );
			if ( is_wp_error( $regen ) ) {
				AIEM_DB::update_campaign( $campaign_id, [ 'status' => $prev_status ] );
				wp_send_json_error( [ 'message' => 'AI generation failed, nothing was sent: ' . $regen->get_error_message() ] );
			}
		}

		$count = AIEM_Sender::enqueue_sends( $campaign_id );

		if ( $count === 0 ) {
			AIEM_DB::update_campaign( $campaign_id, [ 'status' => $prev_status ] );
			$message = $prev_status === 'sent'
				? 'This campaign was already sent to everyone on this list. Use Resend to send it again.'
				: 'No subscribed recipients found on this list.';
			wp_send_json_error( [ 'message' => $message ] );
		}

		// Process synchronously so sends go out immediately (no WP-Cron dependency).
		// For very large lists the batch loop will re-schedule itself for the remainder.
		AIEM_Sender::process_batch( $campaign_id );

		wp_send_json_success( [ 'message' => "Sent to {$count} subscriber(s).", 'count' => $count ] );
	}

	public function schedule_campaign(): void {
		$this->verify_admin();

		$campaign_id  = (int) ( $_POST['campaign_id'] ?? 0 );
		$scheduled_at = sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ?? '' ) );

		if ( ! $campaign_id || ! $scheduled_at ) {
			wp_send_json_error( [ 'message' => 'Campaign ID and schedule time required.' ] );
		}

		$campaign = AIEM_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}
		if ( ! $campaign->html_content ) {
			wp_send_json_error( [ 'message' => 'Campaign has no content.' ] );
		}

		try {
			$dt = new DateTimeImmutable( $scheduled_at, wp_timezone() );
		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => 'Invalid date format.' ] );
		}
		$ts = $dt->getTimestamp();
		if ( ! $ts || $ts <= current_time( 'timestamp' ) ) {
			wp_send_json_error( [ 'message' => 'Scheduled time must be in the future.' ] );
		}

		AIEM_DB::update_campaign( $campaign_id, [
			'status'       => 'scheduled',
			'scheduled_at' => wp_date( 'Y-m-d H:i:s', $ts ),
		] );

		wp_send_json_success( [ 'message' => 'Campaign scheduled for ' . wp_date( 'M j, Y g:i a', $ts ) ] );
	}

	public function import_subscribers(): void {
		$this->verify_admin();

		$list_id = (int) ( $_POST['list_id'] ?? 0 );
		if ( ! $list_id ) {
			wp_send_json_error( [ 'message' => 'List ID required.' ] );
		}

		if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
		}

		$file = $_FILES['csv_file']['tmp_name'];
		$fh   = fopen( $file, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( [ 'message' => 'Could not read file.' ] );
		}

		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			wp_send_json_error( [ 'message' => 'CSV file is empty or unreadable.' ] );
		}
		$header = array_map( 'strtolower', array_map( 'trim', $header ) );

		$email_col = array_search( 'email', $header );
		if ( $email_col === false ) {
			fclose( $fh );
			wp_send_json_error( [ 'message' => 'CSV must have an "email" column.' ] );
		}

		$first_col = array_search( 'first_name', $header );
		$last_col  = array_search( 'last_name', $header );

		$added   = 0;
		$skipped = 0;
		$errors  = 0;

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$email = trim( $row[ $email_col ] ?? '' );
			if ( ! is_email( $email ) ) {
				$errors++;
				continue;
			}

			$result = AIEM_DB::insert_subscriber( [
				'list_id'    => $list_id,
				'email'      => $email,
				'first_name' => $first_col !== false ? ( $row[ $first_col ] ?? '' ) : '',
				'last_name'  => $last_col !== false ? ( $row[ $last_col ] ?? '' ) : '',
				'source'     => 'import',
			] );

			if ( $result ) {
				$added++;
			} else {
				$skipped++;
			}
		}

		fclose( $fh );

		wp_send_json_success( [
			'message' => "Import complete: {$added} added, {$skipped} skipped (duplicate), {$errors} invalid.",
			'added'   => $added,
			'skipped' => $skipped,
			'errors'  => $errors,
		] );
	}

	public function export_subscribers(): void {
		$this->verify_admin();

		$list_id = (int) ( $_POST['list_id'] ?? $_GET['list_id'] ?? 0 );
		if ( ! $list_id ) {
			wp_die( 'List ID required.' );
		}

		$list        = AIEM_DB::get_list( $list_id );
		$subscribers = AIEM_DB::get_subscribed_for_campaign( $list_id );

		$filename = sanitize_file_name( ( $list->name ?? 'list' ) . '-export-' . date( 'Y-m-d' ) . '.csv' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( "Content-Disposition: attachment; filename={$filename}" );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'email', 'first_name', 'last_name', 'status', 'subscribed_at' ] );

		foreach ( $subscribers as $sub ) {
			fputcsv( $out, array_map( [ $this, 'csv_escape' ], [
				$sub->email,
				$sub->first_name,
				$sub->last_name,
				$sub->status,
				$sub->subscribed_at,
			] ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Neutralize CSV formula injection: prefix cells beginning with =, +, -, @,
	 * tab or CR (which spreadsheet apps treat as formulas) with a single quote.
	 */
	private function csv_escape( $value ): string {
		$value = (string) $value;
		if ( $value !== '' && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	public function test_send(): void {
		$this->verify_admin();

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$to_email    = sanitize_email( wp_unslash( $_POST['to_email'] ?? get_option( 'admin_email' ) ) );

		if ( ! $campaign_id ) {
			wp_send_json_error( [ 'message' => 'Campaign ID required.' ] );
		}

		$sent = AIEM_Sender::send_test( $campaign_id, $to_email );

		if ( $sent ) {
			wp_send_json_success( [ 'message' => "Test email sent to {$to_email}." ] );
		} else {
			wp_send_json_error( [ 'message' => 'wp_mail() returned false. Check your mail configuration.' ] );
		}
	}

	public function save_campaign(): void {
		$this->verify_admin();

		$id          = (int) ( $_POST['campaign_id'] ?? 0 );
		$html        = wp_unslash( $_POST['html_content'] ?? '' );
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$subject     = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$preheader   = sanitize_text_field( wp_unslash( $_POST['preheader'] ?? '' ) );
		$list_id     = (int) ( $_POST['list_id'] ?? 0 );
		$segment_id  = (int) ( $_POST['segment_id'] ?? 0 );
		$from_name   = sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) );
		$from_email  = sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) );
		$ai_prompt   = sanitize_textarea_field( wp_unslash( $_POST['ai_prompt'] ?? '' ) );
		$blocks_raw  = wp_unslash( $_POST['blocks'] ?? '[]' );
		$blocks_arr  = json_decode( $blocks_raw, true );
		$blocks      = is_array( $blocks_arr ) ? wp_json_encode( $blocks_arr ) : '[]';

		$recur_schedule = sanitize_text_field( wp_unslash( $_POST['recur_schedule'] ?? '' ) );
		$valid_recur    = [ '', 'daily', 'weekly', 'monthly' ];
		if ( ! in_array( $recur_schedule, $valid_recur, true ) ) {
			$recur_schedule = '';
		}

		$raw_cats        = wp_unslash( $_POST['woo_category_ids'] ?? '[]' );
		$raw_tags        = wp_unslash( $_POST['woo_tag_ids'] ?? '[]' );
		$woo_cat_ids     = wp_json_encode( array_filter( array_map( 'intval', json_decode( $raw_cats, true ) ?: [] ) ) );
		$woo_tag_ids_enc = wp_json_encode( array_filter( array_map( 'intval', json_decode( $raw_tags, true ) ?: [] ) ) );

		$data = [
			'name'             => $name,
			'subject'          => $subject,
			'preheader'        => $preheader,
			'list_id'          => $list_id,
			'segment_id'       => $segment_id,
			'html_content'     => $html,
			'blocks'           => $blocks,
			'ai_prompt'        => $ai_prompt,
			'from_name'        => $from_name,
			'from_email'       => $from_email,
			'recur_schedule'   => $recur_schedule,
			'woo_category_ids' => $woo_cat_ids,
			'woo_tag_ids'      => $woo_tag_ids_enc,
			'template_id'      => (int) ( $_POST['template_id'] ?? 0 ),
		];

		if ( $id ) {
			AIEM_DB::update_campaign( $id, $data );
			wp_send_json_success( [ 'message' => 'Campaign saved.', 'campaign_id' => $id ] );
		} else {
			$new_id = AIEM_DB::create_campaign( $data );
			if ( $new_id ) {
				wp_send_json_success( [ 'message' => 'Campaign created.', 'campaign_id' => $new_id ] );
			} else {
				wp_send_json_error( [ 'message' => 'Failed to create campaign.' ] );
			}
		}
	}

	public function delete_subscriber(): void {
		$this->verify_admin();

		$id = (int) ( $_POST['subscriber_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Subscriber ID required.' ] );
		}

		AIEM_DB::delete_subscriber( $id );
		wp_send_json_success( [ 'message' => 'Subscriber deleted.' ] );
	}

	// ── Email Template CRUD ───────────────────────────────────────────────────

	public function save_email_template(): void {
		$this->verify_admin();

		$id    = (int) ( $_POST['template_id'] ?? 0 );
		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$raw   = wp_unslash( $_POST['blocks'] ?? '[]' );
		$arr   = json_decode( $raw, true );
		$blocks = is_array( $arr ) ? wp_json_encode( $arr ) : '[]';
		$html  = wp_unslash( $_POST['html_content'] ?? '' );

		if ( ! $name ) {
			wp_send_json_error( [ 'message' => 'Template name is required.' ] );
		}

		$data = [ 'name' => $name, 'blocks' => $blocks, 'html_content' => $html ];

		if ( $id ) {
			AIEM_DB::update_email_template( $id, $data );
			wp_send_json_success( [ 'message' => 'Template saved.', 'template_id' => $id ] );
		} else {
			$new_id = AIEM_DB::create_email_template( $data );
			if ( $new_id ) {
				wp_send_json_success( [ 'message' => 'Template created.', 'template_id' => $new_id ] );
			} else {
				wp_send_json_error( [ 'message' => 'Failed to create template.' ] );
			}
		}
	}

	public function delete_email_template(): void {
		$this->verify_admin();
		$id = (int) ( $_POST['template_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Template ID required.' ] );
		}
		AIEM_DB::delete_email_template( $id );
		wp_send_json_success( [ 'message' => 'Template deleted.' ] );
	}

	public function duplicate_email_template(): void {
		$this->verify_admin();
		$id = (int) ( $_POST['template_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Template ID required.' ] );
		}
		$new_id = AIEM_DB::duplicate_email_template( $id );
		if ( ! $new_id ) {
			wp_send_json_error( [ 'message' => 'Could not duplicate template.' ] );
		}
		wp_send_json_success( [
			'template_id' => $new_id,
			'redirect'    => admin_url( 'admin.php?page=aiem-email-template-edit&template_id=' . $new_id ),
		] );
	}

	/**
	 * Send the design currently open in the editor (saved or not) to one
	 * address, with merge tags filled the same way a real send would.
	 */
	public function test_send_template(): void {
		$this->verify_admin();

		$html = wp_unslash( $_POST['html_content'] ?? '' );
		$to   = sanitize_email( wp_unslash( $_POST['to_email'] ?? '' ) );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! is_email( $to ) ) {
			wp_send_json_error( [ 'message' => 'Enter a valid email address.' ] );
		}
		if ( trim( $html ) === '' ) {
			wp_send_json_error( [ 'message' => 'Nothing to send yet.' ] );
		}

		$subscriber = (object) [ 'email' => $to, 'first_name' => 'Test', 'last_name' => 'User' ];
		$body       = AIEM_Sender::build_email( $html, 'test_preview', $subscriber, '' );
		$from_name  = get_option( 'aiem_from_name', get_bloginfo( 'name' ) ) ?: get_bloginfo( 'name' );
		$from_email = get_option( 'aiem_from_email', get_option( 'admin_email' ) ) ?: get_option( 'admin_email' );

		$sent = wp_mail( $to, '[TEST] ' . ( $name ?: 'Email template' ), $body, [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		] );

		if ( $sent ) {
			wp_send_json_success( [ 'message' => "Test sent to {$to}" ] );
		}
		wp_send_json_error( [ 'message' => 'Could not send. Check your mail configuration.' ] );
	}

	public function rewrite_text(): void {
		$this->verify_admin();

		$is_html     = ! empty( $_POST['is_html'] );
		$text        = wp_unslash( $_POST['text'] ?? '' );
		$text        = $is_html ? wp_kses_post( $text ) : sanitize_textarea_field( $text );
		$instruction = sanitize_text_field( wp_unslash( $_POST['instruction'] ?? '' ) );

		if ( trim( wp_strip_all_tags( $text ) ) === '' ) {
			wp_send_json_error( [ 'message' => 'Add some text to rewrite first.' ] );
		}

		$result = AIEM_OpenAI::rewrite( $text, $instruction, $is_html );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [ 'text' => $is_html ? wp_kses_post( $result ) : $result ] );
	}

	// ── Form CRUD ─────────────────────────────────────────────────────────────

	public function save_form(): void {
		$this->verify_admin();

		$id       = (int) ( $_POST['form_id'] ?? 0 );
		$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$list_id  = (int) ( $_POST['list_id'] ?? 0 );
		$fields   = json_decode( wp_unslash( $_POST['fields'] ?? '[]' ), true );
		$settings = json_decode( wp_unslash( $_POST['settings'] ?? '{}' ), true );

		if ( ! $name || ! $list_id ) {
			wp_send_json_error( [ 'message' => 'Name and list are required.' ] );
		}
		if ( ! is_array( $fields ) )   $fields   = [];
		if ( ! is_array( $settings ) ) $settings = [];

		$data = compact( 'name', 'list_id', 'fields', 'settings' );

		if ( $id ) {
			AIEM_DB::update_form( $id, $data );
			wp_send_json_success( [ 'message' => 'Form updated.', 'form_id' => $id ] );
		} else {
			$new_id = AIEM_DB::create_form( $data );
			if ( $new_id ) {
				wp_send_json_success( [ 'message' => 'Form created.', 'form_id' => $new_id ] );
			} else {
				wp_send_json_error( [ 'message' => 'Failed to create form.' ] );
			}
		}
	}

	public function delete_form(): void {
		$this->verify_admin();
		$id = (int) ( $_POST['form_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Form ID required.' ] );
		}
		AIEM_DB::delete_form( $id );
		wp_send_json_success( [ 'message' => 'Form deleted.' ] );
	}

	public function subscribe(): void {
		// No nonce check here on purpose. The form sits on pages that page
		// caches (Cloudflare etc.) keep for days, so the nonce baked into the
		// HTML expires and every signup would fail. A nonce adds nothing for
		// an anonymous signup anyway; abuse is handled by the honeypot, the
		// per-IP rate limit and (optionally) double opt-in.

		// Honeypot: real users never fill this hidden field. Pretend success to bots.
		if ( ! empty( $_POST['aiem_hp'] ) ) {
			wp_send_json_success( [ 'message' => 'Thanks! You\'ve been subscribed.' ] );
		}

		// Per-IP rate limit: max 5 attempts per 10 minutes.
		$ip_raw = aiem_client_ip();
		if ( $ip_raw ) {
			$rl_key   = 'aiem_sub_rl_' . md5( $ip_raw );
			$attempts = (int) get_transient( $rl_key );
			if ( $attempts >= 5 ) {
				wp_send_json_error( [ 'message' => 'Too many attempts. Please try again later.' ] );
			}
			set_transient( $rl_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
		}

		$email        = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$list_id      = (int) ( $_POST['list_id'] ?? 0 );
		$first_name   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$double_optin = get_option( 'aiem_double_optin', '0' ) === '1';
		$success_msg  = null;

		// If form_id provided, use the form's list and validate GDPR if required.
		$form_id = (int) ( $_POST['form_id'] ?? 0 );
		if ( $form_id ) {
			$form = AIEM_DB::get_form( $form_id );
			if ( $form ) {
				$list_id = (int) $form->list_id;
				$f_settings = json_decode( $form->settings, true ) ?: [];
				if ( ! empty( $f_settings['success_message'] ) ) {
					$success_msg = $f_settings['success_message'];
				}
				// GDPR check
				$f_fields = json_decode( $form->fields, true ) ?: [];
				foreach ( $f_fields as $f ) {
					if ( ( $f['type'] ?? '' ) === 'gdpr' && empty( $_POST['gdpr_consent'] ) ) {
						wp_send_json_error( [ 'message' => 'Please accept the terms to subscribe.' ] );
					}
				}
			}
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
		}

		if ( ! $list_id ) {
			wp_send_json_error( [ 'message' => 'Invalid subscription form.' ] );
		}

		$existing = AIEM_DB::get_subscriber_by_email( $list_id, $email );
		if ( $existing ) {
			if ( $existing->status === 'subscribed' ) {
				wp_send_json_success( [ 'message' => "You're already subscribed!" ] );
			}
			if ( in_array( $existing->status, [ 'unsubscribed', 'bounced' ], true ) ) {
				// Coming back after unsubscribing/bouncing. Always require an
				// emailed confirmation (even with double opt-in off), so a third
				// party cannot re-add someone who opted out.
				$reactivated = AIEM_DB::reactivate_subscriber( (int) $existing->id, bin2hex( random_bytes( 32 ) ), [
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'ip_address' => $ip_raw,
				] );
				if ( ! $reactivated ) {
					wp_send_json_error( [ 'message' => 'Could not subscribe. Please try again.' ] );
				}
				AIEM_Logs::log( AIEM_Logs::EVENT_SUBSCRIBE, [
					'subscriber_id'   => (int) $existing->id,
					'email'           => $email,
					'list_id'         => $list_id,
					'source'          => 'resubscribe',
					'pending_confirm' => true,
				] );
				AIEM_Sender::send_confirmation_email( (int) $existing->id );
				wp_send_json_success( [ 'message' => 'Please check your email to confirm your subscription.' ] );
			}
			if ( $existing->status === 'unconfirmed' ) {
				// Returning subscribers (source "resubscribe") must confirm by
				// email even when double opt-in is off.
				if ( $double_optin || $existing->source === 'resubscribe' ) {
					AIEM_Sender::send_confirmation_email( (int) $existing->id );
					wp_send_json_success( [ 'message' => 'Please check your email to confirm your subscription.' ] );
				} else {
					// Setting was toggled off — just confirm them directly.
					AIEM_DB::confirm_subscriber_by_id( (int) $existing->id );
					AIEM_Logs::log( AIEM_Logs::EVENT_CONFIRM, [
						'subscriber_id' => (int) $existing->id,
						'email'         => $email,
						'list_id'       => $list_id,
					] );
					AIEM_Workflows::fire_trigger( AIEM_Workflows::TRIGGER_SUBSCRIBER_ADDED, (int) $existing->id );
					wp_send_json_success( [ 'message' => "Thanks! You've been subscribed." ] );
				}
			}
		}

		$ip = $ip_raw;

		if ( $double_optin ) {
			$confirm_key = bin2hex( random_bytes( 32 ) );
			$result      = AIEM_DB::insert_subscriber( [
				'list_id'     => $list_id,
				'email'       => $email,
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'source'      => 'form',
				'ip_address'  => $ip,
				'status'      => 'unconfirmed',
				'confirm_key' => $confirm_key,
			] );
			if ( $result ) {
				AIEM_Logs::log( AIEM_Logs::EVENT_SUBSCRIBE, [
					'subscriber_id'   => $result,
					'email'           => $email,
					'list_id'         => $list_id,
					'source'          => 'form',
					'pending_confirm' => true,
				] );
				AIEM_Sender::send_confirmation_email( $result );
				wp_send_json_success( [ 'message' => 'Thanks! Please check your email to confirm your subscription.' ] );
			} else {
				wp_send_json_error( [ 'message' => 'Could not subscribe. Please try again.' ] );
			}
		}

		$result = AIEM_DB::insert_subscriber( [
			'list_id'    => $list_id,
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'source'     => 'form',
			'ip_address' => $ip,
		] );

		if ( $result ) {
			AIEM_Logs::log( AIEM_Logs::EVENT_SUBSCRIBE, [
				'subscriber_id' => $result,
				'email'         => $email,
				'list_id'       => $list_id,
				'source'        => 'form',
			] );
			AIEM_Workflows::fire_trigger( AIEM_Workflows::TRIGGER_SUBSCRIBER_ADDED, $result );
			wp_send_json_success( [ 'message' => $success_msg ?? "Thanks! You've been subscribed." ] );
		} else {
			wp_send_json_error( [ 'message' => 'Could not subscribe. Please try again.' ] );
		}
	}

	// ── Workflow CRUD ──────────────────────────────────────────────────────

	public function save_workflow(): void {
		$this->verify_admin();

		$id      = (int) ( $_POST['workflow_id'] ?? 0 );
		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$trigger = sanitize_text_field( wp_unslash( $_POST['trigger_type'] ?? '' ) );

		$send_to  = sanitize_text_field( wp_unslash( $_POST['action_send_to'] ?? '{{EMAIL}}' ) );
		$subject  = sanitize_text_field( wp_unslash( $_POST['action_subject'] ?? '' ) );
		$content  = wp_unslash( $_POST['action_content'] ?? '' );
		$list_id  = (int) ( $_POST['action_list_id'] ?? 0 );
		$styling  = sanitize_text_field( wp_unslash( $_POST['action_email_styling'] ?? 'none' ) );

		$delay_value = max( 0, (int) ( $_POST['delay_value'] ?? 0 ) );
		$delay_unit  = sanitize_text_field( wp_unslash( $_POST['delay_unit'] ?? 'minutes' ) );

		$valid_triggers = [ 'subscriber_added', 'subscriber_unsubscribed', 'campaign_sent', 'post_published' ];
		if ( ! $name || ! in_array( $trigger, $valid_triggers ) ) {
			wp_send_json_error( [ 'message' => 'Name and trigger are required.' ] );
		}
		if ( ! $subject || ! $content ) {
			wp_send_json_error( [ 'message' => 'Email subject and content are required.' ] );
		}
		if ( $trigger === 'post_published' && ! $list_id ) {
			wp_send_json_error( [ 'message' => 'Post Published trigger requires a subscriber list.' ] );
		}

		$trigger_config = [];
		if ( $trigger === 'post_published' ) {
			$category_id = (int) ( $_POST['category_id'] ?? 0 );
			if ( $category_id ) {
				$trigger_config['category_id'] = $category_id;
			}
		} elseif ( $trigger === 'campaign_sent' ) {
			$filter_campaign_id = (int) ( $_POST['filter_campaign_id'] ?? 0 );
			$trigger_config['filter_campaign_id'] = $filter_campaign_id; // 0 = any
		}

		$data = [
			'name'                 => $name,
			'trigger_type'         => $trigger,
			'trigger_config'       => $trigger_config,
			'action_send_to'       => $send_to,
			'action_subject'       => $subject,
			'action_content'       => $content,
			'action_list_id'       => $list_id,
			'action_email_styling' => $styling,
			'delay_value'          => $delay_value,
			'delay_unit'           => $delay_unit,
		];

		if ( $id ) {
			AIEM_DB::update_workflow( $id, $data );
			wp_send_json_success( [ 'message' => 'Workflow updated.' ] );
		} else {
			$new_id = AIEM_DB::create_workflow( $data );
			if ( $new_id ) {
				wp_send_json_success( [ 'message' => 'Workflow created.', 'workflow_id' => $new_id ] );
			} else {
				wp_send_json_error( [ 'message' => 'Failed to create workflow.' ] );
			}
		}
	}

	public function delete_workflow(): void {
		$this->verify_admin();
		$id = (int) ( $_POST['workflow_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Workflow ID required.' ] );
		}
		AIEM_DB::delete_workflow( $id );
		wp_send_json_success( [ 'message' => 'Workflow deleted.' ] );
	}

	public function toggle_workflow(): void {
		$this->verify_admin();
		$id = (int) ( $_POST['workflow_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Workflow ID required.' ] );
		}
		$wf = AIEM_DB::get_workflow( $id );
		if ( ! $wf ) {
			wp_send_json_error( [ 'message' => 'Workflow not found.' ] );
		}
		$new_status = $wf->status === 'active' ? 'inactive' : 'active';
		AIEM_DB::update_workflow( $id, [ 'status' => $new_status ] );
		wp_send_json_success( [ 'message' => "Workflow {$new_status}.", 'status' => $new_status ] );
	}

	// ── Segments ─────────────────────────────────────────────────────────────

	public function save_segment(): void {
		$this->verify_admin();

		$id      = (int) ( $_POST['segment_id'] ?? 0 );
		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$list_id = (int) ( $_POST['list_id'] ?? 0 );
		$filters = json_decode( wp_unslash( $_POST['filters'] ?? '[]' ), true );

		if ( ! $name || ! $list_id ) {
			wp_send_json_error( [ 'message' => 'Name and list are required.' ] );
		}
		if ( ! is_array( $filters ) ) {
			$filters = [];
		}

		$data = compact( 'name', 'list_id', 'filters' );

		if ( $id ) {
			AIEM_DB::update_segment( $id, $data );
			$count = AIEM_DB::count_subscribers_for_segment( $id );
			wp_send_json_success( [ 'message' => 'Segment updated.', 'segment_id' => $id, 'count' => $count ] );
		} else {
			$new_id = AIEM_DB::create_segment( $data );
			if ( $new_id ) {
				$count = AIEM_DB::count_subscribers_for_segment( $new_id );
				wp_send_json_success( [ 'message' => 'Segment created.', 'segment_id' => $new_id, 'count' => $count ] );
			} else {
				wp_send_json_error( [ 'message' => 'Failed to create segment.' ] );
			}
		}
	}

	public function delete_segment(): void {
		$this->verify_admin();
		$id = (int) ( $_POST['segment_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Segment ID required.' ] );
		}
		AIEM_DB::delete_segment( $id );
		wp_send_json_success( [ 'message' => 'Segment deleted.' ] );
	}

	public function preview_segment(): void {
		$this->verify_admin();

		$list_id = (int) ( $_POST['list_id'] ?? 0 );
		$filters = json_decode( wp_unslash( $_POST['filters'] ?? '[]' ), true );

		if ( ! $list_id ) {
			wp_send_json_error( [ 'message' => 'List required.' ] );
		}
		if ( ! is_array( $filters ) ) {
			$filters = [];
		}

		// Build a temporary segment object for the query
		$temp_id = AIEM_DB::create_segment( [ 'name' => '__preview__', 'list_id' => $list_id, 'filters' => $filters ] );
		if ( ! $temp_id ) {
			wp_send_json_error( [ 'message' => 'Preview failed.' ] );
		}
		$count = AIEM_DB::count_subscribers_for_segment( $temp_id );
		AIEM_DB::delete_segment( $temp_id );

		wp_send_json_success( [ 'count' => $count ] );
	}

	// ── Resend to non-openers ─────────────────────────────────────────────────

	public function resend_non_openers(): void {
		$this->verify_admin();

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = $campaign_id ? AIEM_DB::get_campaign( $campaign_id ) : null;

		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}

		$count = AIEM_DB::count_non_openers( $campaign_id );
		if ( $count === 0 ) {
			wp_send_json_error( [ 'message' => 'No non-openers found for this campaign.' ] );
		}

		$segment_id = AIEM_DB::create_segment( [
			'name'    => 'Non-openers of: ' . $campaign->name,
			'list_id' => (int) $campaign->list_id,
			'filters' => [ [ 'type' => 'non_openers_of', 'campaign_id' => $campaign_id ] ],
		] );

		if ( ! $segment_id ) {
			wp_send_json_error( [ 'message' => 'Failed to create segment.' ] );
		}

		$new_id = AIEM_DB::create_campaign( [
			'name'         => 'Re: ' . $campaign->name,
			'subject'      => 'Re: ' . $campaign->subject,
			'preheader'    => $campaign->preheader ?? '',
			'list_id'      => 0,
			'segment_id'   => $segment_id,
			'html_content' => $campaign->html_content,
			'blocks'       => $campaign->blocks ?? '[]',
			'ai_prompt'    => $campaign->ai_prompt ?? '',
			'from_name'    => $campaign->from_name ?? '',
			'from_email'   => $campaign->from_email ?? '',
		] );

		if ( ! $new_id ) {
			AIEM_DB::delete_segment( $segment_id );
			wp_send_json_error( [ 'message' => 'Failed to create campaign.' ] );
		}

		wp_send_json_success( [
			'redirect' => admin_url( 'admin.php?page=aiem-campaign-edit&campaign_id=' . $new_id ),
			'count'    => $count,
		] );
	}

	public function resend_campaign(): void {
		$this->verify_admin();

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = $campaign_id ? AIEM_DB::get_campaign( $campaign_id ) : null;

		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}
		if ( ! $campaign->html_content ) {
			wp_send_json_error( [ 'message' => 'Campaign has no content.' ] );
		}
		if ( ! $campaign->list_id && ! $campaign->segment_id ) {
			wp_send_json_error( [ 'message' => 'No audience selected.' ] );
		}

		// Claim before clearing: wiping send rows while a send is in progress
		// would re-send to people who already received it.
		if ( ! AIEM_DB::claim_campaign_for_send( $campaign_id ) ) {
			wp_send_json_error( [ 'message' => 'This campaign is already sending.' ] );
		}

		AIEM_DB::archive_campaign_run( $campaign_id );
		AIEM_DB::clear_sends( $campaign_id );
		AIEM_DB::update_campaign( $campaign_id, [ 'sent_at' => null ] );
		$count = AIEM_Sender::enqueue_sends( $campaign_id );

		if ( $count === 0 ) {
			AIEM_DB::update_campaign( $campaign_id, [ 'status' => 'sent' ] );
			wp_send_json_error( [ 'message' => 'No subscribed recipients found.' ] );
		}

		AIEM_Sender::process_batch( $campaign_id );
		wp_send_json_success( [ 'message' => "Re-sent to {$count} subscriber(s).", 'count' => $count ] );
	}

	public function process_workflow_queue(): void {
		$this->verify_admin();
		$items_before = count( AIEM_DB::get_due_workflow_queue( 100 ) );
		AIEM_Workflows::process_queue();
		$items_after = count( AIEM_DB::get_due_workflow_queue( 100 ) );
		$processed   = $items_before - $items_after;
		wp_send_json_success( [ 'message' => "Processed {$processed} queued item(s). Check Logs for results." ] );
	}
}
