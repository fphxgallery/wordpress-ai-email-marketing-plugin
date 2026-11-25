<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_WooCommerce {

	/**
	 * Products for AI prompts. Event products (a date in the title, e.g.
	 * "Artist – 11.13.2026") are chosen by event date: past events are
	 * skipped and the soonest come first. Products without a date follow,
	 * newest first, unless "Upcoming events only" is on (the default): then
	 * only dated, upcoming events are used, so an old show whose date can't
	 * be read is never promoted. Returns at most "Products to include".
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
		if ( self::upcoming_only() ) {
			$others = [];
		}

		$result = [];
		foreach ( array_slice( array_merge( $events, $others ), 0, $count ) as [ $event_ts, $product ] ) {
			$result[] = self::product_data( $product, $event_ts );
		}
		return $result;
	}

	/** How far after the listing was created a year-corrected date may land. */
	const YEAR_FIX_DAYS = 120;

	/**
	 * Event date as a timestamp (site timezone, midnight) or null. Looks for
	 * MM.DD.YYYY (also MM.DD.YY, / or -) in the title first, then the short
	 * description, then the full description.
	 *
	 * A ticket listing is created before its show, so the product's created
	 * date catches typos: a title date that falls before it (e.g. 11.27.2025
	 * on a listing made in Nov 2026) gives way to a date in the description,
	 * or failing that is moved to the next year if that lands soon after the
	 * listing was made. A title date with no year ("11/29") gets the first
	 * such date on or after the listing. An old listing copied and left
	 * unedited stays in the past. The aiem_product_event_timestamp filter
	 * can supply the date from elsewhere.
	 */
	public static function event_timestamp( WC_Product $product ): ?int {
		$created = $product->get_date_created();
		$anchor  = $created
			? ( new DateTimeImmutable( '@' . $created->getTimestamp() ) )->setTimezone( wp_timezone() )->setTime( 0, 0 )->modify( '-2 days' )->getTimestamp()
			: null;

		$ts    = null;
		$stale = null; // a date found that falls before the listing was made
		foreach ( [ $product->get_name(), $product->get_short_description(), $product->get_description() ] as $i => $text ) {
			// Tags become spaces, so "10.09.2026</p><p>Main" stays separate words.
			$plain = wp_strip_all_tags( preg_replace( '/<[^>]*>/', ' ', (string) $text ) );
			// Year-less dates only in the title, where "Artist – 11/29" is a
			// date; in descriptions "9.30" is as likely a time or a price.
			$found = self::first_date_in( $plain, $i === 0 ? $anchor : null );
			if ( $found === null ) {
				continue;
			}
			if ( $anchor === null || $found >= $anchor ) {
				$ts = $found;
				break;
			}
			$stale = $stale ?? $found;
		}
		if ( $ts === null && $stale !== null ) {
			$ts = self::roll_forward( $stale, (int) $anchor ) ?? $stale;
		}

		$ts = apply_filters( 'aiem_product_event_timestamp', $ts, $product );
		return is_numeric( $ts ) ? (int) $ts : null;
	}

	/**
	 * Same month and day, first year on or after $anchor, if that lands
	 * within YEAR_FIX_DAYS of it; otherwise null.
	 */
	private static function roll_forward( int $ts, int $anchor ): ?int {
		$tz   = wp_timezone();
		$date = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
		$a    = ( new DateTimeImmutable( '@' . $anchor ) )->setTimezone( $tz );
		$m    = (int) $date->format( 'n' );
		$d    = (int) $date->format( 'j' );
		foreach ( [ (int) $a->format( 'Y' ), (int) $a->format( 'Y' ) + 1 ] as $year ) {
			if ( ! checkdate( $m, $d, $year ) ) {
				continue;
			}
			$try = ( new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $m, $d ), $tz ) )->getTimestamp();
			if ( $try >= $anchor ) {
				return $try - $anchor <= self::YEAR_FIX_DAYS * DAY_IN_SECONDS ? $try : null;
			}
		}
		return null;
	}

	/**
	 * First valid US-style date in the text, as a timestamp: MM.DD.YYYY or
	 * the short MM.DD.YY ("5.30.26" = May 30, 2026), with . / or - between.
	 * With $no_year_anchor, a date without a year ("11/29", "11.29") also
	 * counts, placed on the first such date on or after the anchor.
	 */
	public static function first_date_in( string $text, ?int $no_year_anchor = null ): ?int {
		if ( preg_match_all( '/(?<![\d.\/-])(\d{1,2})([.\/-])(\d{1,2})\2(\d{4}|\d{2})(?![\d.\/-]*\d)/', $text, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$month = (int) $m[1];
				$day   = (int) $m[3];
				$year  = strlen( $m[4] ) === 2 ? 2000 + (int) $m[4] : (int) $m[4];
				if ( checkdate( $month, $day, $year ) ) {
					return ( new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ), wp_timezone() ) )->getTimestamp();
				}
			}
		}
		if ( $no_year_anchor !== null && preg_match_all( '/(?<![\d.\/-])(\d{1,2})[.\/](\d{1,2})(?![\d.\/-]*\d)/', $text, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				if ( checkdate( (int) $m[1], (int) $m[2], 2000 ) ) { // 2000: allows Feb 29
					$guess = ( new DateTimeImmutable( sprintf( '2000-%02d-%02d', $m[1], $m[2] ), wp_timezone() ) )->getTimestamp();
					$ts    = self::roll_forward( $guess, $no_year_anchor );
					if ( $ts !== null ) {
						return $ts;
					}
				}
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

	/** "Upcoming events only" setting: never fall back to undated products. */
	public static function upcoming_only(): bool {
		return get_option( 'aiem_upcoming_only', '1' ) === '1';
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
