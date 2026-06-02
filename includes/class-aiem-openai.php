<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_OpenAI {

	public static function generate( string $prompt, array $products = [] ): string|WP_Error {
		$api_key = get_option( 'aiem_openai_key', '' );
		if ( ! $api_key ) {
			return new WP_Error( 'no_key', 'OpenAI API key not configured.' );
		}

		$model = get_option( 'aiem_openai_model', 'gpt-4o' );

		$system_prompt = get_option(
			'aiem_system_prompt',
			'You are an expert email marketing copywriter. Generate ONLY the HTML email body content — no <html>, <body>, or <head> tags. Use inline CSS for all styling. Create compelling, conversion-focused copy. Structure: an attention-grabbing H1 headline, a brief intro paragraph, product highlights (if products provided), and a clear CTA button.'
		);

		$user_content = $prompt;

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
			'max_tokens'  => 2000,
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

		return $data['choices'][0]['message']['content'] ?? '';
	}
}
