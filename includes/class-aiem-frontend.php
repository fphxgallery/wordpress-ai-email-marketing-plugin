<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Frontend {

	public function __construct() {
		add_shortcode( 'aiem_subscribe', [ $this, 'subscribe_shortcode' ] );
	}

	public function subscribe_shortcode( array $atts ): string {
		$atts = shortcode_atts( [
			'list_id'     => get_option( 'aiem_default_list', 0 ),
			'button_text' => 'Subscribe',
			'show_name'   => 'yes',
			'form_id'     => 0,
		], $atts, 'aiem_subscribe' );

		$form_id = (int) $atts['form_id'];
		$form    = null;

		// Form-based config
		$show_first_name      = $atts['show_name'] === 'yes';
		$show_last_name       = $atts['show_name'] === 'yes';
		$show_gdpr            = false;
		$gdpr_text            = '';
		$first_name_ph        = 'First name';
		$last_name_ph         = 'Last name';
		$button_text          = $atts['button_text'];
		$form_title           = '';
		$form_description     = '';
		$button_color         = '';
		$button_text_color    = '';
		$list_id              = (int) $atts['list_id'];

		if ( $form_id ) {
			$form = AIEM_DB::get_form( $form_id );
			if ( $form ) {
				$list_id = (int) $form->list_id;

				$fields   = json_decode( $form->fields, true ) ?: [];
				$settings = json_decode( $form->settings, true ) ?: [];

				// Rebuild field flags from form config
				$show_first_name = false;
				$show_last_name  = false;
				foreach ( $fields as $field ) {
					$type = $field['type'] ?? '';
					if ( $type === 'first_name' ) {
						$show_first_name = true;
						$first_name_ph   = $field['placeholder'] ?? 'First name';
					}
					if ( $type === 'last_name' ) {
						$show_last_name = true;
						$last_name_ph   = $field['placeholder'] ?? 'Last name';
					}
					if ( $type === 'gdpr' ) {
						$show_gdpr = true;
						$gdpr_text = $field['text'] ?? 'I agree to receive email updates.';
					}
				}

				$button_text      = $settings['button_text']      ?? $button_text;
				$form_title       = $settings['title']            ?? '';
				$form_description = $settings['description']      ?? '';
				$button_color     = $settings['button_color']     ?? '';
				$button_text_color = $settings['button_text_color'] ?? '';
			}
		}

		if ( ! $list_id ) {
			return '<p class="aiem-error">No subscriber list configured for this form.</p>';
		}

		$list = AIEM_DB::get_list( $list_id );
		if ( ! $list ) {
			return '<p class="aiem-error">Subscriber list not found.</p>';
		}

		wp_enqueue_style( 'aiem-frontend', AIEM_PLUGIN_URL . 'assets/css/aiem-admin.css', [], AIEM_VERSION );
		wp_enqueue_script( 'aiem-frontend', AIEM_PLUGIN_URL . 'assets/js/aiem-frontend.js', [ 'jquery' ], AIEM_VERSION, true );
		wp_localize_script( 'aiem-frontend', 'aiemFrontend', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aiem_frontend_nonce' ),
			'listId'  => $list_id,
		] );

		ob_start();
		include AIEM_PLUGIN_DIR . 'templates/subscribe-form.php';
		return ob_get_clean();
	}
}
