<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_WooCommerce {

	/**
	 * Products for AI prompts. Event products (a date in the title, e.g.
	 * "Artist – 11.13.2026") are chosen by event date: past events are
	 * skipped and the soonest come first. Products without a date follow,
	 * newest first. Returns at most the "Products to include" setting.
	 */
	public static function get_recent_products( array $category_ids = [], array $tag_ids = [] ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$count = max( 1, (int) get_option( 'aiem_product_count', 5 ) );
		$args  = [
			'status'  => 'publish',
			'orderby' => 'date',
			'order'   => 'DESC',
			// Look further back than $count: an event added weeks ago may be
			// this weekend's show, while newer products are later dates.
			'limit'   => max( 40, $count * 5 ),
		];

		$tax_query = [];
		if ( ! empty( $category_ids ) ) {
			$tax_query[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', $category_ids ),
			];
		}
		if ( ! empty( $tag_ids ) ) {
			$tax_query[] = [
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', $tag_ids ),
			];
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query;
		}

		$today  = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		$events = [];
		$others = [];
		foreach ( wc_get_products( $args ) as $product ) {
			$event_ts = self::event_timestamp( $product );
			if ( $event_ts !== null && $event_ts < $today ) {
				continue; // never promote a show that already happened
			}
			if ( $event_ts !== null ) {
				$events[] = [ $event_ts, $product ];
			} else {
				$others[] = [ null, $product ];
			}
		}
		usort( $events, fn( $x, $y ) => $x[0] <=> $y[0] );

		$result = [];
		foreach ( array_slice( array_merge( $events, $others ), 0, $count ) as [ $event_ts, $product ] ) {
			$result[] = self::product_data( $product, $event_ts );
		}
		return $result;
	}

	/**
	 * Event date as a timestamp (site timezone, midnight) or null. Looks for
	 * MM.DD.YYYY, MM-DD-YYYY or MM/DD/YYYY in the title first, then the
	 * short description, then the full description (first date found). The
	 * aiem_product_event_timestamp filter can supply it from elsewhere.
	 */
	public static function event_timestamp( WC_Product $product ): ?int {
		$ts = null;
		foreach ( [ $product->get_name(), $product->get_short_description(), $product->get_description() ] as $text ) {
			// Tags become spaces, so "10.09.2026</p><p>Main" stays separate words.
			$ts = self::first_date_in( wp_strip_all_tags( preg_replace( '/<[^>]*>/', ' ', (string) $text ) ) );
			if ( $ts !== null ) {
				break;
			}
		}
		$ts = apply_filters( 'aiem_product_event_timestamp', $ts, $product );
		return is_numeric( $ts ) ? (int) $ts : null;
	}

	/** First valid US-style date (MM.DD.YYYY etc.) in the text, as a timestamp. */
	private static function first_date_in( string $text ): ?int {
		if ( ! preg_match_all( '/(?<!\d)(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?!\d)/', $text, $all, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $all as $m ) {
			if ( checkdate( (int) $m[1], (int) $m[2], (int) $m[3] ) ) {
				return ( new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $m[3], $m[1], $m[2] ), wp_timezone() ) )->getTimestamp();
			}
		}
		return null;
	}

	private static function product_data( WC_Product $product, ?int $event_ts ): array {
		$image_id = $product->get_image_id();

		// Short description, or the start of the full one (where event pages
		// keep the lineup).
		$to_text = function ( string $html ): string {
			// Line breaks and block ends become spaces so words don't run together.
			$html = preg_replace( '/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', strip_shortcodes( $html ) );
			return wp_strip_all_tags( $html );
		};
		$desc = $to_text( $product->get_short_description() );
		if ( trim( $desc ) === '' ) {
			$desc = wp_trim_words( $to_text( $product->get_description() ), 80, '…' );
		}

		$ticket_url = $product->is_type( 'external' ) ? (string) $product->get_product_url() : '';

		return [
			'name'        => $product->get_name(),
			'price'       => trim( html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES ) ),
			'description' => trim( preg_replace( '/\s+/', ' ', $desc ) ),
			'url'         => get_permalink( $product->get_id() ),
			'ticket_url'  => $ticket_url,
			'event_date'  => $event_ts !== null ? wp_date( 'l, F j, Y', $event_ts ) : '',
			'image'       => $image_id ? ( wp_get_attachment_image_url( $image_id, 'medium' ) ?: '' ) : '',
		];
	}

	public static function get_product_categories(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		return get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		] ) ?: [];
	}

	public static function get_product_tags(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		return get_terms( [
			'taxonomy'   => 'product_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
		] ) ?: [];
	}
}
