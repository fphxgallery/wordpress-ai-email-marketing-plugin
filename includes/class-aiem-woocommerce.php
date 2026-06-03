<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_WooCommerce {

	public static function get_recent_products( array $category_ids = [], array $tag_ids = [] ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$count = (int) get_option( 'aiem_product_count', 5 );
		$args  = [
			'status'  => 'publish',
			'orderby' => 'date',
			'order'   => 'DESC',
			'limit'   => $count,
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

		$products = wc_get_products( $args );

		$result = [];
		foreach ( $products as $product ) {
			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

			$result[] = [
				'name'        => $product->get_name(),
				'price'       => strip_tags( $product->get_price_html() ),
				'description' => wp_strip_all_tags( $product->get_short_description() ),
				'url'         => get_permalink( $product->get_id() ),
				'image'       => $image_url ?: '',
			];
		}

		return $result;
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
