<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Logs {

	const EVENT_SUBSCRIBE        = 'subscribe';
	const EVENT_CONFIRM          = 'confirm';
	const EVENT_UNSUBSCRIBE      = 'unsubscribe';
	const EVENT_OPEN             = 'open';
	const EVENT_CLICK            = 'click';
	const EVENT_SEND             = 'send';
	const EVENT_SEND_FAILED      = 'send_failed';
	const EVENT_BOUNCE           = 'bounce';
	const EVENT_WORKFLOW_QUEUED  = 'workflow_queued';
	const EVENT_WORKFLOW_SENT    = 'workflow_sent';
	const EVENT_WORKFLOW_FAILED  = 'workflow_failed';

	public static function log( string $event_type, array $data = [] ): void {
		AIEM_DB::insert_log( $event_type, $data );
	}
}
