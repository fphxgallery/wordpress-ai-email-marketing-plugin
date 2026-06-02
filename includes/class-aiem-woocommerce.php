<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_WooCommerce {

	public static function get_recent_products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$count    = (int) get_option( 'aiem_product_count', 5 );
		$products = wc_get_products( [
			'status'  => 'publish',
			'orderby' => 'date',
			'order'   => 'DESC',
			'limit'   => $count,
		] );

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
}
