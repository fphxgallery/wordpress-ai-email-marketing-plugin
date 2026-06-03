<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Cron {

	public function __construct() {
		add_action( 'aiem_process_batch',      [ $this, 'process_batch' ] );
		add_action( 'aiem_check_scheduled',    [ $this, 'check_scheduled' ] );
		add_action( 'aiem_process_workflows',  [ $this, 'process_workflows' ] );
		add_action( 'init',                    [ $this, 'register_schedules' ] );
	}

	public function register_schedules(): void {
		add_filter( 'cron_schedules', function ( array $schedules ): array {
			if ( ! isset( $schedules['aiem_15min'] ) ) {
				$schedules['aiem_15min'] = [ 'interval' => 900,  'display' => '15 Minutes' ];
			}
			if ( ! isset( $schedules['aiem_5min'] ) ) {
				$schedules['aiem_5min']  = [ 'interval' => 300,  'display' => '5 Minutes' ];
			}
			return $schedules;
		} );

		if ( ! wp_next_scheduled( 'aiem_check_scheduled' ) ) {
			wp_schedule_event( time(), 'aiem_15min', 'aiem_check_scheduled' );
		}
		if ( ! wp_next_scheduled( 'aiem_process_workflows' ) ) {
			wp_schedule_event( time(), 'aiem_5min', 'aiem_process_workflows' );
		}
	}

	public function process_batch( int $campaign_id ): void {
		AIEM_Sender::process_batch( $campaign_id );
	}

	public function check_scheduled(): void {
		$campaigns = AIEM_DB::get_scheduled_due();
		foreach ( $campaigns as $campaign ) {
			$id = (int) $campaign->id;

			if ( $campaign->recur_schedule && $campaign->ai_prompt ) {
				$products = [];
				if ( function_exists( 'wc_get_products' ) ) {
					$cat_ids  = array_filter( array_map( 'intval', json_decode( $campaign->woo_category_ids ?? '[]', true ) ?: [] ) );
					$tag_ids  = array_filter( array_map( 'intval', json_decode( $campaign->woo_tag_ids ?? '[]', true ) ?: [] ) );
					$products = AIEM_WooCommerce::get_recent_products( array_values( $cat_ids ), array_values( $tag_ids ) );
				}

				$result = AIEM_OpenAI::generate( $campaign->ai_prompt, $products );

				if ( ! is_wp_error( $result ) ) {
					$update = [ 'html_content' => $result['html'] ];
					if ( $result['subject'] )      { $update['subject']   = $result['subject']; }
					if ( $result['preview_text'] ) { $update['preheader'] = $result['preview_text']; }
					AIEM_DB::update_campaign( $id, $update );
				}
			}

			AIEM_DB::update_campaign( $id, [ 'status' => 'sending' ] );
			AIEM_Sender::enqueue_sends( $id );
			wp_schedule_single_event( time() + 2, 'aiem_process_batch', [ $id ] );
		}
	}

	public function process_workflows(): void {
		AIEM_Workflows::process_queue();
	}
}
