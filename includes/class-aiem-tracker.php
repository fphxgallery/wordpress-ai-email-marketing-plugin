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
				$url = base64_decode( $_GET['url'] ?? '' );
				$this->handle_click( $key, $url );
			}
		}

		if ( isset( $_GET['aiem_unsub'] ) && $_GET['aiem_unsub'] === '1' ) {
			$key = sanitize_text_field( $_GET['k'] ?? '' );
			$this->handle_unsubscribe( $key );
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

	private function handle_click( string $key, string $url ): void {
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

	private function handle_unsubscribe( string $key ): void {
		if ( ! $key ) {
			wp_die( 'Invalid unsubscribe link.' );
		}

		$send = AIEM_DB::get_send_by_tracking_key( $key );
		AIEM_DB::unsubscribe_by_tracking_key( $key );

		if ( $send ) {
			AIEM_Logs::log( AIEM_Logs::EVENT_UNSUBSCRIBE, [
				'subscriber_id' => $send->subscriber_id,
				'campaign_id'   => $send->campaign_id,
			] );
			AIEM_Workflows::fire_trigger( AIEM_Workflows::TRIGGER_SUBSCRIBER_UNSUBSCRIBED, (int) $send->subscriber_id );
		}

		wp_die(
			'<p style="font-family:sans-serif;text-align:center;padding:40px;">You have been unsubscribed successfully.</p>',
			'Unsubscribed',
			[ 'response' => 200 ]
		);
	}
}
