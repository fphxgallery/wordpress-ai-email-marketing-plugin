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
			. 'Exception: if multiple products are provided and the template contains a single product section (a repeated block pattern), '
			. 'duplicate that entire block once per additional product. Copy the block HTML exactly, only changing the text and image values per product. '
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

		$body = wp_json_encode( [
			'model'    => $model,
			'messages' => [
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user',   'content' => $user_content ],
			],
			'max_tokens'  => $template_html ? max( 4000, (int) get_option( 'aiem_max_tokens', 2500 ) ) : (int) get_option( 'aiem_max_tokens', 2500 ),
			'temperature' => 0.7,
		] );

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body' => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? 'Unknown OpenAI error';
			return new WP_Error( 'openai_error', $msg );
		}

		$raw     = $data['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( $raw, true );

		if ( ! is_array( $parsed ) || empty( $parsed['html'] ) ) {
			// Fallback: treat entire response as html body, leave subject/preview empty.
			return [
				'subject'      => '',
				'preview_text' => '',
				'html'         => $raw,
			];
		}

		return [
			'subject'      => $parsed['subject']      ?? '',
			'preview_text' => $parsed['preview_text'] ?? '',
			'html'         => $parsed['html']          ?? '',
		];
	}
}
