<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_OpenAI {

	/** Default instructions for "Generate with AI" without a template. */
	public static function default_body_prompt(): string {
		return 'You are an expert email marketing copywriter. Write the HTML for the body of a marketing email — no <html>, <head> or <body> tags, no <style> blocks, no scripts. '
			. 'Put inline CSS on every element and keep the layout email-safe: one centered column no wider than 600px, tables where layout matters, web-safe fonts. '
			. 'Structure: an attention-grabbing <h1> headline, a short intro paragraph, product highlights if products are provided (image, name, price, a one-line pitch and a link), '
			. 'and one clear call-to-action button made as a styled <a> link. Keep the copy concise and conversion-focused.';
	}

	/** Default instructions for "Generate with AI" when an Email Editor template is selected. */
	public static function default_template_prompt(): string {
		return 'You are an expert email marketing copywriter. You will be given an HTML email template and a content brief. '
			. 'Your ONLY job is to fill in the content — do NOT change any HTML tags, attributes, or style properties. '
			. 'Copy every tag, every attribute name, and every attribute value character-for-character from the template, with these exceptions only: '
			. '(1) Text nodes between tags — replace placeholder text ("fillmorephx title", "product title", "product text", "Your footer", "Your Heading", "Your text goes here", "Click Here", etc.) with compelling real content based on the brief. '
			. '(2) The src and alt attributes on <img> tags — use the product image URL when one is provided; never invent an image URL (if there is none, keep src as it is in the template); always write a descriptive alt. '
			. '(3) The href of links and buttons — use the product URLs provided, or the site URL; never invent a URL. '
			. 'Critical: styled button links look like <a href="..." style="...">Label</a> — keep the entire style attribute exactly as-is and only change the visible label and the href. Never strip or simplify a style attribute. '
			. 'For footer placeholder text: use the site name, a short tagline, and the current year. '
			. 'Multi-product layout rule: if multiple products are provided, treat everything before the first <hr> as the "product block" and everything after the last <hr> as the "footer block". '
			. 'Fill the first product block with product 1. Then for each additional product, append an <hr> (copied exactly from the template) followed by a copy of the product block filled with that product\'s details. '
			. 'Finally append the footer block at the end. Keep all HTML and styles exactly as-is. '
			. 'Return the complete filled template as the html field.';
	}

	/**
	 * Clean a prompt typed into Settings. Prompts are plain text sent to the
	 * API, so tags like <img> are kept (sanitize_textarea_field() strips them,
	 * which garbled the instructions). Admin-only input; escaped on output.
	 */
	public static function sanitize_prompt( string $text ): string {
		$text = wp_check_invalid_utf8( $text );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		return trim( $text );
	}

	/**
	 * Old default prompts, used once to repair copies that Settings saved
	 * with their HTML tag names stripped out (before 1.3.8).
	 */
	public static function legacy_default_prompts(): array {
		return [
			'aiem_system_prompt'          => 'You are an expert email marketing copywriter. Generate ONLY the HTML email body content — no <html>, <body>, or <head> tags. Use inline CSS for all styling. Create compelling, conversion-focused copy. Structure: an attention-grabbing H1 headline, a brief intro paragraph, product highlights (if products provided), and a clear CTA button.',
			'aiem_template_system_prompt' => 'You are an expert email marketing copywriter. You will be given an HTML email template and a content brief. Your ONLY job is to fill in the content — do NOT change any HTML tags, attributes, or style properties. Copy every tag, every attribute name, and every attribute value character-for-character from the template, with these two exceptions only: (1) Text nodes between tags — replace placeholder text ("fillmorephx title", "product title", "product text", "Your footer", "Your Heading", "Your text goes here", "Click Here", etc.) with compelling real content based on the brief. (2) The src and alt attributes on <img> tags — if src is empty, supply a relevant publicly accessible image URL; always set a descriptive alt. Critical: styled button links look like <a href="..." style="...">Label</a> — you must keep the entire style attribute exactly as-is and only change the visible label text and href URL. Never strip or simplify a style attribute. For footer placeholder text: replace with site name, a short tagline, and the current year. Multi-product layout rule: if multiple products are provided, treat everything before the first <hr> as the "product block" and everything after the last <hr> as the "footer block". Fill the first product block with product 1. Then for each additional product, append an <hr> (copied exactly from the template) followed by a copy of the product block filled with that product\'s details. Finally append the footer block at the end. Keep all HTML and styles exactly as-is. Return the complete filled template as the html field.',
		];
	}

	/** Model used when none has been saved in Settings. */
	const DEFAULT_MODEL = 'gpt-6.1-sol';

	/**
	 * Models offered in Settings: id => label. Checked against OpenAI's model
	 * and deprecation pages on 2026-11-10.
	 */
	public static function models(): array {
		return [
			'gpt-6.1-sol'   => 'GPT-6.1 Sol — balanced (recommended)',
			'gpt-6.1-terra' => 'GPT-6.1 Terra — efficient',
			'gpt-6-luna'    => 'GPT-6 Luna — fastest, lowest cost',
			'gpt-6-astra'   => 'GPT-6 Astra — most capable, highest cost',
			'gpt-4o'        => 'GPT-4o — legacy, retires Jan 14, 2027',
			'gpt-4o-mini'   => 'GPT-4o mini — legacy, retires Jan 14, 2027',
		];
	}

	/**
	 * What to use when OpenAI reports a model no longer exists, so a retired
	 * model in Settings does not silently stop AI generation.
	 */
	public static function replacement_for( string $model ): string {
		$map = [
			'gpt-4-turbo'   => 'gpt-6.1-sol',
			'gpt-3.5-turbo' => 'gpt-6.1-terra',
			'gpt-4o'        => 'gpt-6.1-terra',
			'gpt-4o-mini'   => 'gpt-6-luna',
		];
		return $map[ $model ] ?? '';
	}

	public static function model(): string {
		return (string) ( get_option( 'aiem_openai_model', self::DEFAULT_MODEL ) ?: self::DEFAULT_MODEL );
	}

	/**
	 * GPT-4-era and older models take max_tokens + temperature. Everything
	 * newer is a reasoning model with a different parameter set.
	 */
	private static function is_legacy_model( string $model ): bool {
		return (bool) preg_match( '/^gpt-(3\.5|4)/', $model );
	}

	private static function build_payload( string $model, array $messages, int $max_output, float $temperature ): array {
		$payload = [
			'model'           => $model,
			'messages'        => $messages,
			// JSON mode: the model must return one valid JSON object.
			'response_format' => [ 'type' => 'json_object' ],
		];
		if ( self::is_legacy_model( $model ) ) {
			$payload['max_tokens']  = $max_output;
			$payload['temperature'] = $temperature;
		} else {
			// Reasoning models: the cap covers hidden reasoning tokens as well
			// as the visible answer, so leave room for both. Email copy needs
			// little reasoning; "low" keeps it fast and cheap.
			$payload['max_completion_tokens'] = $max_output + max( 2000, $max_output );
			$payload['reasoning_effort']      = 'low';
		}
		return $payload;
	}

	/**
	 * Send a chat request with the model chosen in Settings and return the
	 * decoded response. Adapts when the API rejects a parameter the model does
	 * not support, and falls back when a retired model no longer exists.
	 */
	public static function chat( array $messages, int $max_output, float $temperature = 0.7 ): array|WP_Error {
		$api_key = get_option( 'aiem_openai_key', '' );
		if ( ! $api_key ) {
			return new WP_Error( 'no_key', 'OpenAI API key not configured.' );
		}

		$model   = self::model();
		$payload = self::build_payload( $model, $messages, $max_output, $temperature );
		$result  = null;

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$result = self::request( $api_key, $payload );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$msg  = $result->get_error_message();
			$code = (string) ( $result->get_error_data()['code'] ?? '' );

			// Retired or unknown model: switch to its replacement once.
			$fallback = self::replacement_for( $payload['model'] );
			if ( $fallback && ( $code === 'model_not_found' || preg_match( '/does not exist|has been (deprecated|retired|shut ?down)|no longer (available|supported)/i', $msg ) ) ) {
				AIEM_Logs::log( AIEM_Logs::EVENT_CRON_CHECK, [
					'action' => 'ai_model_unavailable',
					'model'  => $payload['model'],
					'using'  => $fallback,
					'error'  => $msg,
				] );
				$payload = self::build_payload( $fallback, $messages, $max_output, $temperature );
				continue;
			}

			// Token-limit parameter under the wrong name for this model.
			if ( isset( $payload['max_tokens'] ) && stripos( $msg, 'max_tokens' ) !== false ) {
				$payload['max_completion_tokens'] = $payload['max_tokens'] + max( 2000, $payload['max_tokens'] );
				unset( $payload['max_tokens'] );
				continue;
			}
			if ( isset( $payload['max_completion_tokens'] ) && stripos( $msg, 'max_completion_tokens' ) !== false ) {
				$payload['max_tokens'] = $max_output;
				unset( $payload['max_completion_tokens'] );
				continue;
			}
			// Optional parameters this model does not accept: drop and retry.
			foreach ( [ 'temperature', 'reasoning_effort', 'response_format' ] as $param ) {
				if ( isset( $payload[ $param ] ) && stripos( $msg, $param ) !== false ) {
					unset( $payload[ $param ] );
					continue 2;
				}
			}
			break;
		}

		return $result;
	}

	public static function generate( string $prompt, array $products = [], string $template_html = '' ): array|WP_Error {
		if ( $template_html ) {
			$body_instructions = get_option( 'aiem_template_system_prompt', self::default_template_prompt() );
		} else {
			$body_instructions = get_option( 'aiem_system_prompt', self::default_body_prompt() );
		}

		// Facts the model cannot know, and a guard against made-up details.
		// Added to every prompt, including custom ones saved in Settings.
		$system_prompt = $body_instructions . "\n\n"
			. 'Context: today is ' . wp_date( 'l, F j, Y' ) . '. The sender is ' . get_bloginfo( 'name' ) . ' (' . home_url( '/' ) . '). '
			. "Only use prices, dates, offers and URLs that appear in the brief or the product list; never invent them.\n\n"
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

		$max_output = (int) get_option( 'aiem_max_tokens', 2500 );
		if ( $template_html ) {
			$max_output = max( 4000, $max_output );
		}

		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_content ],
		];
		$parsed = self::parse_email_response( self::chat( $messages, $max_output, 0.7 ) );

		// With a template, the model must keep the layout. If it rebuilt the
		// email instead, ask once more; never hand back a different design.
		if ( $template_html && is_array( $parsed ) && ! self::template_kept( $template_html, $parsed['html'] ) ) {
			$messages[] = [ 'role' => 'assistant', 'content' => wp_json_encode( $parsed ) ];
			$messages[] = [ 'role' => 'user', 'content' => 'Your html changed the template\'s tags and style attributes. Start again from the template I gave you: copy it exactly, changing only text between tags, img src/alt and link href. Return the same JSON object.' ];
			$parsed = self::parse_email_response( self::chat( $messages, $max_output, 0.4 ) );
			if ( is_array( $parsed ) && ! self::template_kept( $template_html, $parsed['html'] ) ) {
				return new WP_Error( 'ai_template_changed', 'The AI did not keep your template\'s layout, so nothing was replaced. Try Generate again, or simplify the brief.' );
			}
		}

		return $parsed;
	}

	/**
	 * True when the generated HTML still uses (most of) the template's
	 * styling. Compares the template's distinct inline style values; a model
	 * that rebuilt the email from scratch loses nearly all of them.
	 */
	public static function template_kept( string $template_html, string $html ): bool {
		$styles = function ( string $h ): array {
			preg_match_all( '/\bstyle\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $h, $m, PREG_SET_ORDER );
			$out = [];
			foreach ( $m as $x ) {
				$v = html_entity_decode( $x[2] !== '' ? $x[2] : ( $x[3] ?? '' ), ENT_QUOTES );
				$v = strtolower( preg_replace( [ '/\s*([:;,])\s*/', '/\s+/', '/;$/' ], [ '$1', ' ', '' ], trim( $v ) ) );
				if ( $v !== '' ) {
					$out[ $v ] = true;
				}
			}
			return $out;
		};
		$want = $styles( $template_html );
		if ( count( $want ) < 3 ) {
			return true; // too little styling to judge
		}
		$have = $styles( $html );
		$kept = count( array_intersect_key( $want, $have ) );
		return $kept / count( $want ) >= 0.6;
	}

	/** Turn a chat() result into [subject, preview_text, html] or WP_Error. */
	private static function parse_email_response( array|WP_Error $result ): array|WP_Error {
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
	 * Rewrite one piece of email copy. Returns the new text or WP_Error.
	 */
	public static function rewrite( string $text, string $instruction = '', bool $is_html = false ): string|WP_Error {
		$system = 'You are an expert email marketing copywriter. Rewrite the text you are given. '
			. 'Keep it about the same length unless told otherwise, keep any {{merge_tags}} exactly as written, and do not add a greeting or sign-off that was not there. '
			. ( $is_html
				? 'The text is an HTML fragment: keep its tags (links, bold, line breaks) and only change the wording. '
				: 'Return plain text with no HTML and no surrounding quotes. ' )
			. 'Respond with a single JSON object containing exactly one key, "text".';

		$user = ( $instruction !== '' ? "Instruction: {$instruction}\n\n" : '' ) . "Text to rewrite:\n{$text}";

		$result = self::chat(
			[
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user',   'content' => $user ],
			],
			800,
			0.8
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw    = trim( (string) ( $result['choices'][0]['message']['content'] ?? '' ) );
		$clean  = preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $raw );
		$parsed = json_decode( $clean, true );
		$out    = is_array( $parsed ) ? trim( (string) ( $parsed['text'] ?? '' ) ) : '';

		if ( $out === '' ) {
			return new WP_Error( 'ai_bad_format', 'AI did not return usable text. Try again.' );
		}
		return $out;
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
			return new WP_Error(
				'openai_error',
				$data['error']['message'] ?? 'Unknown OpenAI error',
				[ 'code' => (string) ( $data['error']['code'] ?? '' ), 'status' => (int) $code ]
			);
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
