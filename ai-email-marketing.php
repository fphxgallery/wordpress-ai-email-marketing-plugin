<?php
/**
 * Plugin Name: AI Email Marketing
 * Description: OpenAI-powered email marketing with WooCommerce product integration, subscriber management, import/export, open/click tracking, and scheduled campaigns.
 * Version:     1.1.6
 * Author:      @fPHXGallery
 * License:     GPL-2.0-or-later
 * Text Domain: ai-email-marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIEM_VERSION',    '1.1.6' );
define( 'AIEM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIEM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Real client IP. Behind Cloudflare, REMOTE_ADDR is the CF edge (shared by all
 * visitors), so prefer CF-Connecting-IP. Falls back to REMOTE_ADDR otherwise.
 * Origin should be locked to Cloudflare IP ranges so this header can't be spoofed.
 */
function aiem_client_ip(): string {
	$cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
	if ( $cf && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
		return $cf;
	}
	$remote = $_SERVER['REMOTE_ADDR'] ?? '';
	return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
}

require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-db.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-logs.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-openai.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-woocommerce.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-sender.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-workflows.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-tracker.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-cron.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-ajax.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-admin.php';
require_once AIEM_PLUGIN_DIR . 'includes/class-aiem-frontend.php';

final class AIEM_Plugin {

	private static ?AIEM_Plugin $instance = null;

	public static function instance(): AIEM_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		AIEM_Sender::init();
		new AIEM_Tracker();
		new AIEM_Admin();
		new AIEM_Frontend();
		new AIEM_Ajax();
		new AIEM_Cron();
	}
}

register_activation_hook( __FILE__, function (): void {
	AIEM_DB::create_tables();
} );

add_action( 'plugins_loaded', function (): void {
	// Run schema migrations when the stored DB version lags the plugin version.
	// dbDelta is idempotent: it creates missing tables and adds missing columns.
	if ( get_option( 'aiem_db_version' ) !== AIEM_VERSION ) {
		AIEM_DB::create_tables();
	}
	AIEM_Plugin::instance();
} );

add_action( 'transition_post_status', function ( string $new, string $old, WP_Post $post ): void {
	AIEM_Workflows::handle_post_published( $new, $old, $post );
}, 10, 3 );
