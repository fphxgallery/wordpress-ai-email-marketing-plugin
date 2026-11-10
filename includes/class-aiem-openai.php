<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_OpenAI {

	/**
	 * Returns array{ subject: string, preview_text: string, html: string } or WP_Error.
	 */
	public static function default_template_prompt(): string {
		return 'You are an expert email marketing copywriter. You will be given an HTML email template and a content brief. '
			. 'Your ONLY job is to fill in the content — do NOT change any HTML tags, attributes, or style properties. '
			. 'Copy every tag, every attribute name, and every attribute value character-for-character from the template, with these two exceptions only: '
			. '(1) Text nodes between tags — replace placeholder text ("fillmorephx title", "product title", "product text", "Your footer", "Your Heading", "Your text goes here", "Click Here", etc.) with compelling real content based on the brief. '
			. '(2) The src and alt attributes on <img> tags — if src is empty, supply a relevant publicly accessible image URL; always set a descriptive alt. '
			. 'Critical: styled button links look like <a href="..." style="...">Label</a> — you must keep the entire style attribute exactly as-is and only change the visible label text and href URL. Never strip or simplify a style attribute. '
			. 'For footer placeholder text: replace with site name, a short tagline, and the current year. '
			. 'Multi-product layout rule: if multiple products are provided, treat everything before the first <hr> as the "product block" and everything after the last <hr> as the "footer block". '
			. 'Fill the first product block with product 1. Then for each additional product, append an <hr> (copied exactly from the template) followed by a copy of the product block filled with that product\'s details. '
			. 'Finally append the footer block at the end. Keep all HTML and styles exactly as-is. '
			. 'Return the complete filled template as the html field.';
	}

	public static function generate( string $prompt, array $products = [], string $template_html = '' ): array|WP_Error {
		$api_key = get_option( 'aiem_openai_key', '' );
		if ( ! $api_key ) {
			return new WP_Error( 'no_key', 'OpenAI API key not configured.' );
		}

		$model = get_option( 'aiem_openai_model', 'gpt-4o' );

		if ( $template_html ) {
			$body_instructions = get_option( 'aiem_template_system_prompt', self::default_template_prompt() );
		} else {
			$body_instructions = get_option(
				'aiem_system_prompt',
				'You are an expert email marketing copywriter. Generate ONLY the HTML email body content — no <html>, <body>, or <head> tags. Use inline CSS for all styling. Create compelling, conversion-focused copy. Structure: an attention-grabbing H1 headline, a brief intro paragraph, product highlights (if products provided), and a clear CTA button.'
			);
		}

		$system_prompt = $body_instructions . "\n\n"
			. "Always respond with a single JSON object (no markdown, no code fences) containing exactly three keys:\n"
			. "  \"subject\"      — a compelling email subject line (under 60 characters)\n"
			. "  \"preview_text\" — inbox preview text that complements the subject (under 100 characters)\n"
			. "  \"html\"         — the full HTML email body as described above";

		$user_content = $prompt;

		if ( $template_html ) {
			$user_content .= "\n\nEmail template to fill:\n" . $template_html;
		}

		if ( ! empty( $products ) ) {
			$user_content .= "\n\nFeatured products to highlight:\n";
			foreach ( $products as $p ) {
				$user_content .= sprintf(
					"\n- **%s** — %s\n  Description: %s\n  URL: %s\n  Image: %s\n",
					$p['name'],
					$p['price'],
					$p['description'] ?: '(no description)',
					$p['url'],
					$p['image'] ?: '(no image)'
				);
			}
		}

		$payload = [
			'model'    => $model,
			'messages' => [
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user',   'content' => $user_content ],
			],
			'max_tokens'      => $template_html ? max( 4000, (int) get_option( 'aiem_max_tokens', 2500 ) ) : (int) get_option( 'aiem_max_tokens', 2500 ),
			'temperature'     => 0.7,
			// JSON mode: the model must return one valid JSON object.
			'response_format' => [ 'type' => 'json_object' ],
		];

		$result = self::request( $api_key, $payload );

		// Older models reject response_format; retry once without it.
		if ( is_wp_error( $result ) && stripos( $result->get_error_message(), 'response_format' ) !== false ) {
			unset( $payload['response_format'] );
			$result = self::request( $api_key, $payload );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$choice = $result['choices'][0] ?? [];
		$raw    = trim( (string) ( $choice['message']['content'] ?? '' ) );

		// Output hit the token limit: the JSON/HTML is cut off mid-way. Never
		// treat a truncated response as a finished email.
		if ( ( $choice['finish_reason'] ?? '' ) === 'length' ) {
			return new WP_Error( 'ai_truncated', 'AI output was cut off at the token limit. Increase "Max Output Tokens" in Settings and try again.' );
		}
		if ( $raw === '' ) {
			return new WP_Error( 'ai_empty', 'AI returned an empty response.' );
		}

		// Strip ```json fences some models add despite instructions.
		$clean  = preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $raw );
		$parsed = json_decode( $clean, true );

		if ( is_array( $parsed ) && ! empty( $parsed['html'] ) && is_string( $parsed['html'] ) ) {
			return [
				'subject'      => (string) ( $parsed['subject']      ?? '' ),
				'preview_text' => (string) ( $parsed['preview_text'] ?? '' ),
				'html'         => $parsed['html'],
			];
		}

		// Not JSON. Accept it as the body only if it is plainly HTML; anything
		// else (broken JSON, an apology, etc.) must not be emailed to a list.
		if ( $clean !== '' && $clean[0] === '<' ) {
			return [
				'subject'      => '',
				'preview_text' => '',
				'html'         => $clean,
			];
		}

		return new WP_Error( 'ai_bad_format', 'AI response was not valid JSON or HTML. Try generating again.' );
	}

	/**
	 * POST to the chat completions endpoint. Returns the decoded body or WP_Error.
	 */
	private static function request( string $api_key, array $payload ): array|WP_Error {
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body' => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! is_array( $data ) ) {
			return new WP_Error( 'openai_error', $data['error']['message'] ?? 'Unknown OpenAI error' );
		}

		return $data;
	}

	/**
	 * Regenerate a recurring AI campaign's content from its saved prompt,
	 * products and template, and store it. Returns true, or WP_Error when
	 * generation failed (in which case the campaign content is left untouched
	 * and the caller must NOT send).
	 */
	public static function regenerate_campaign( object $campaign ): bool|WP_Error {
		$products = [];
		if ( function_exists( 'wc_get_products' ) ) {
			$cat_ids  = array_filter( array_map( 'intval', json_decode( $campaign->woo_category_ids ?? '[]', true ) ?: [] ) );
			$tag_ids  = array_filter( array_map( 'intval', json_decode( $campaign->woo_tag_ids ?? '[]', true ) ?: [] ) );
			$products = AIEM_WooCommerce::get_recent_products( array_values( $cat_ids ), array_values( $tag_ids ) );
		}

		$tpl_html = '';
		if ( ! empty( $campaign->template_id ) ) {
			$tpl = AIEM_DB::get_email_template( (int) $campaign->template_id );
			if ( $tpl ) {
				$tpl_html = (string) $tpl->html_content;
			}
		}

		$result = self::generate( (string) $campaign->ai_prompt, $products, $tpl_html );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = [ 'html_content' => $result['html'] ];
		if ( $result['subject'] )      { $update['subject']   = $result['subject']; }
		if ( $result['preview_text'] ) { $update['preheader'] = $result['preview_text']; }
		AIEM_DB::update_campaign( (int) $campaign->id, $update );

		return true;
	}
}
