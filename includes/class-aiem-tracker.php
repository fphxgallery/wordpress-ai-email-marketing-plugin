<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Tracker {

	public function __construct() {
		add_action( 'init', [ $this, 'handle_tracking' ], 1 );
	}

	public function handle_tracking(): void {
		if ( isset( $_GET['aiem_track'] ) ) {
			$type = sanitize_text_field( $_GET['aiem_track'] );
			$key  = sanitize_text_field( $_GET['k'] ?? '' );

			if ( $type === 'open' ) {
				$this->handle_open( $key );
			} elseif ( $type === 'click' ) {
				// Accepts URL-safe base64 (current links) and classic base64
				// (links in older emails, where "+" arrives as a space).
				$raw = isset( $_GET['url'] ) ? (string) wp_unslash( $_GET['url'] ) : '';
				$url = (string) base64_decode( strtr( $raw, '-_ ', '+/+' ) );
				$sig = sanitize_text_field( wp_unslash( $_GET['sig'] ?? '' ) );
				$this->handle_click( $key, $url, $sig );
			}
		}

		if ( isset( $_GET['aiem_unsub'] ) && $_GET['aiem_unsub'] === '1' ) {
			$this->handle_unsubscribe();
		}

		if ( isset( $_GET['aiem_confirm'] ) && $_GET['aiem_confirm'] === '1' ) {
			$key = sanitize_text_field( $_GET['k'] ?? '' );
			$this->handle_confirm( $key );
		}
	}

	private function handle_open( string $key ): void {
		if ( $key && $key !== 'test_preview' ) {
			AIEM_DB::record_open( $key );
			$send = AIEM_DB::get_send_by_tracking_key( $key );
			if ( $send ) {
				AIEM_Logs::log( AIEM_Logs::EVENT_OPEN, [
					'subscriber_id' => $send->subscriber_id,
					'campaign_id'   => $send->campaign_id,
					'send_id'       => $send->id,
				] );
			}
		}

		$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: ' . strlen( $gif ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo $gif;
		exit;
	}

	private function handle_click( string $key, string $url, string $sig = '' ): void {
		// Only redirect to URLs this site put in an email. Without this check
		// anyone could craft a link on this domain that redirects anywhere.
		$trusted = $sig !== '' && hash_equals( AIEM_Sender::click_signature( $key, $url ), $sig );

		if ( ! $trusted && $url !== '' && $key && $key !== 'test_preview' ) {
			// Unsigned link from an email sent before signing existed: accept it
			// only if that exact URL appears in the campaign it claims to be from.
			$send     = AIEM_DB::get_send_by_tracking_key( $key );
			$campaign = $send ? AIEM_DB::get_campaign( (int) $send->campaign_id ) : null;
			if ( $campaign && strpos( (string) $campaign->html_content, $url ) !== false ) {
				$trusted = true;
				$url     = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		if ( ! $trusted ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_die( 'Invalid URL.' );
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			wp_die( 'Invalid URL.' );
		}

		if ( $key && $key !== 'test_preview' ) {
			AIEM_DB::record_click( $key, $url );
			$send = AIEM_DB::get_send_by_tracking_key( $key );
			if ( $send ) {
				AIEM_Logs::log( AIEM_Logs::EVENT_CLICK, [
					'subscriber_id' => $send->subscriber_id,
					'campaign_id'   => $send->campaign_id,
					'send_id'       => $send->id,
					'url'           => $url,
				] );
			}
		}

		wp_redirect( $url );
		exit;
	}

	private function handle_confirm( string $key ): void {
		if ( ! $key ) {
			wp_die( 'Invalid confirmation link.' );
		}

		$subscriber = AIEM_DB::confirm_subscriber( $key );

		if ( ! $subscriber ) {
			wp_die(
				'<p style="font-family:sans-serif;text-align:center;padding:40px;">This confirmation link is invalid or has already been used.</p>',
				'Invalid Link',
				[ 'response' => 200 ]
			);
		}

		AIEM_Logs::log( AIEM_Logs::EVENT_CONFIRM, [
			'subscriber_id' => (int) $subscriber->id,
			'email'         => $subscriber->email,
			'list_id'       => $subscriber->list_id,
		] );
		AIEM_Workflows::fire_trigger( AIEM_Workflows::TRIGGER_SUBSCRIBER_ADDED, (int) $subscriber->id );

		wp_die(
			'<p style="font-family:sans-serif;text-align:center;padding:40px;">&#10003; You\'ve been successfully subscribed. Thank you!</p>',
			'Subscribed',
			[ 'response' => 200 ]
		);
	}

	private function handle_unsubscribe(): void {
		$key = sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) );
		$sid = (int) ( $_GET['sid'] ?? 0 );
		$tok = sanitize_text_field( wp_unslash( $_GET['tok'] ?? '' ) );

		$subscriber_id = 0;
		$campaign_id   = null;
		$send_id       = 0;

		if ( $key ) {
			// Link from a campaign email: the tracking key identifies the send.
			$send = AIEM_DB::get_send_by_tracking_key( $key );
			if ( $send ) {
				$subscriber_id = (int) $send->subscriber_id;
				$campaign_id   = (int) $send->campaign_id;
				$send_id       = (int) $send->id;
			}
			$link_args = [ 'aiem_unsub' => '1', 'k' => $key ];
		} elseif ( $sid && $tok && hash_equals( AIEM_Sender::subscriber_unsub_token( $sid ), $tok ) ) {
			// Link from a workflow email: signed subscriber ID.
			$subscriber_id = $sid;
			$link_args     = [ 'aiem_unsub' => '1', 'sid' => $sid, 'tok' => $tok ];
		} else {
			wp_die( 'Invalid unsubscribe link.' );
		}

		// A plain GET only shows a confirmation button. Mail security scanners
		// and link previewers fetch every URL in an email; if GET unsubscribed,
		// they would silently remove people. The actual unsubscribe needs a POST:
		// either this button, or the mail client's one-click List-Unsubscribe-Post.
		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$action = add_query_arg( $link_args, home_url( '/' ) );
			wp_die(
				'<div style="font-family:sans-serif;text-align:center;padding:40px;">'
				. '<p>Do you want to unsubscribe from these emails?</p>'
				. '<form method="post" action="' . esc_url( $action ) . '">'
				. '<button type="submit" style="background:#1a1a1a;color:#fff;border:0;padding:12px 30px;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;">Yes, unsubscribe me</button>'
				. '</form></div>',
				'Unsubscribe',
				[ 'response' => 200 ]
			);
		}

		// unsubscribe_subscriber() is true only when the status actually changed,
		// so repeat clicks don't log or fire workflows again.
		if ( $subscriber_id && AIEM_DB::unsubscribe_subscriber( $subscriber_id ) ) {
			if ( $send_id ) {
				// Credit the unsubscribe to the campaign whose link was used.
				AIEM_DB::mark_send_unsubscribed( $send_id );
			}
			AIEM_Logs::log( AIEM_Logs::EVENT_UNSUBSCRIBE, [
				'subscriber_id' => $subscriber_id,
				'campaign_id'   => $campaign_id,
			] );
			AIEM_Workflows::fire_trigger( AIEM_Workflows::TRIGGER_SUBSCRIBER_UNSUBSCRIBED, $subscriber_id );
		}

		wp_die(
			'<p style="font-family:sans-serif;text-align:center;padding:40px;">You have been unsubscribed successfully.</p>',
			'Unsubscribed',
			[ 'response' => 200 ]
		);
	}
}
