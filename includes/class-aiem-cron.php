<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Cron {

	public function __construct() {
		// Register custom intervals immediately so they're available for both
		// wp_schedule_event() validation and wp-cron.php execution contexts.
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );

		add_action( 'aiem_process_batch',      [ $this, 'process_batch' ] );
		add_action( 'aiem_check_scheduled',    [ $this, 'check_scheduled' ] );
		add_action( 'aiem_process_workflows',  [ $this, 'process_workflows' ] );
		add_action( 'init',                    [ $this, 'register_schedules' ] );
	}

	public function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules['aiem_15min'] ) ) {
			$schedules['aiem_15min'] = [ 'interval' => 900, 'display' => '15 Minutes' ];
		}
		if ( ! isset( $schedules['aiem_5min'] ) ) {
			$schedules['aiem_5min'] = [ 'interval' => 300, 'display' => '5 Minutes' ];
		}
		return $schedules;
	}

	public function register_schedules(): void {
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

	/**
	 * Recover work that was interrupted by a PHP timeout or crash, so nothing
	 * stays stuck in 'sending' / 'processing' forever.
	 */
	private function recover_stuck(): void {
		$failed_sends = AIEM_DB::fail_stale_sends( 15 );
		$failed_queue = AIEM_DB::fail_stale_workflow_queue( 15 );

		// A sending campaign must always have a next batch queued. If its
		// batch chain broke, restart it; process_batch() picks up whatever is
		// still pending, or marks the campaign complete.
		$restarted = [];
		foreach ( AIEM_DB::get_sending_campaigns() as $campaign ) {
			$id = (int) $campaign->id;
			if ( ! wp_next_scheduled( 'aiem_process_batch', [ $id ] ) ) {
				wp_schedule_single_event( time(), 'aiem_process_batch', [ $id ] );
				$restarted[] = $id;
			}
		}

		if ( $failed_sends || $failed_queue || $restarted ) {
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_CHECK, [
				'action'              => 'recovered_stuck',
				'failed_sends'        => $failed_sends,
				'failed_queue_items'  => $failed_queue,
				'restarted_campaigns' => $restarted,
			] );
		}
	}

	public function check_scheduled(): void {
		$this->recover_stuck();

		$campaigns = AIEM_DB::get_scheduled_due();

		AIEM_Logs::log( AIEM_Logs::EVENT_CRON_CHECK, [
			'found'        => count( $campaigns ),
			'current_time' => current_time( 'mysql' ),
			'ids'          => array_map( fn( $c ) => (int) $c->id, $campaigns ),
		] );

		foreach ( $campaigns as $campaign ) {
			$id = (int) $campaign->id;

			// Atomic claim, taken BEFORE the slow AI call, so an overlapping
			// cron run cannot pick up and send the same campaign.
			if ( ! AIEM_DB::claim_campaign_for_send( $id, 'scheduled' ) ) {
				continue;
			}

			if ( $campaign->recur_schedule && $campaign->ai_prompt ) {
				$regen = AIEM_OpenAI::regenerate_campaign( $campaign );

				if ( is_wp_error( $regen ) ) {
					// Do not send stale or broken content. Skip this occurrence
					// and move the campaign to its next scheduled date.
					AIEM_Logs::log( AIEM_Logs::EVENT_CRON_CHECK, [
						'campaign_id' => $id,
						'action'      => 'ai_generate_failed_send_skipped',
						'error'       => $regen->get_error_message(),
					] );
					AIEM_DB::schedule_next_recurrence( $id );
					continue;
				}
			}

			$enqueued = AIEM_Sender::enqueue_sends( $id );
			AIEM_Logs::log( AIEM_Logs::EVENT_CRON_CHECK, [
				'campaign_id' => $id,
				'action'      => 'enqueued',
				'sends'       => $enqueued,
			] );
			wp_schedule_single_event( time() + 2, 'aiem_process_batch', [ $id ] );
		}
	}

	public function process_workflows(): void {
		AIEM_Workflows::process_queue();
	}
}
