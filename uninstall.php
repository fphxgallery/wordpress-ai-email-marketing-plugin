<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Scheduled jobs are always removed. Subscribers, campaigns and settings are
 * only removed when "Delete all data on uninstall" is ticked in Settings, so
 * deleting the plugin by accident does not destroy a mailing list.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'aiem_check_scheduled', 'aiem_process_workflows', 'aiem_process_batch', 'aiem_daily_maintenance' ] as $aiem_hook ) {
	wp_unschedule_hook( $aiem_hook );
}

if ( get_option( 'aiem_delete_on_uninstall', '0' ) !== '1' ) {
	return;
}

global $wpdb;

$aiem_tables = [
	'aiem_click_events', 'aiem_sends', 'aiem_campaign_runs', 'aiem_campaigns',
	'aiem_subscribers', 'aiem_lists', 'aiem_segments', 'aiem_forms',
	'aiem_email_templates', 'aiem_workflow_queue', 'aiem_workflows', 'aiem_logs',
];
foreach ( $aiem_tables as $aiem_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$aiem_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aiem\\_%'" );
