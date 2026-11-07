<?php
/**
 * Stock provider: g-FFL Cockpit.
 *
 * Uses the same Garidium call as Cockpit's "Fulfillment Options" box
 * (action get_fulfillment_summary), once per order, and returns every
 * distributor offer for the requested UPC.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'psfsw_distributor_stock', 'psfsw_cockpit_stock', 10, 3 );

function psfsw_cockpit_stock( $result, $upc, $row = array() ) {
	if ( null !== $result || empty( $row['order_id'] ) ) {
		return $result;
	}

	$options = psfsw_cockpit_order_options( (int) $row['order_id'] );
	if ( is_wp_error( $options ) ) {
		return $options;
	}

	$stock = array();
	foreach ( $options as $o ) {
		$o_upc = preg_replace( '/\D/', '', (string) ( $o['upc'] ?? '' ) );
		$match = ( $upc && $o_upc === $upc )
			|| ( ! $upc && ! empty( $row['sku'] ) && ( $o['order_line_item_sku'] ?? '' ) === $row['sku'] );
		if ( $match ) {
			$stock[] = array(
				'dist' => strtoupper( (string) ( $o['distid'] ?? '?' ) ),
				'qty'  => max( 0, (int) ( $o['qty_on_hand'] ?? 0 ) ),
			);
		}
	}
	return $stock; // empty array = no distributor carries it (total 0 → flagged)
}

/**
 * Fulfillment options for one order, cached for the run.
 */
function psfsw_cockpit_order_options( $order_id ) {
	static $cache = array();
	if ( isset( $cache[ $order_id ] ) ) {
		return $cache[ $order_id ];
	}

	$key   = get_option( 'g_ffl_cockpit_key' );
	$order = wc_get_order( $order_id );
	if ( ! $key || ! $order ) {
		return $cache[ $order_id ] = new WP_Error( 'psfsw_cockpit', 'Cockpit key or order missing.' );
	}

	$line_items = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			continue;
		}
		$line_items[] = array(
			'product_id'   => $item->get_product_id(),
			'variation_id' => $item->get_variation_id(),
			'quantity'     => $item->get_quantity(),
			'name'         => $item->get_name(),
			'sku'          => $product->get_sku(),
			'price'        => $item->get_total(),
			'upc'          => get_post_meta( $product->get_id(), 'upc', true ),
		);
	}

	$data = array(
		'order_id'   => (string) $order_id,
		'line_items' => $line_items,
	);
	// The key goes in the x-api-key header only; request bodies are more likely
	// to end up in logs. If the API turns out to require it in the body too,
	// re-enable with: add_filter( 'psfsw_cockpit_key_in_body', '__return_true' );
	if ( apply_filters( 'psfsw_cockpit_key_in_body', false ) ) {
		$data = array( 'api_key' => $key ) + $data;
	}

	$res = wp_remote_post( 'https://ffl-api.garidium.com', array(
		'timeout' => 15,
		'headers' => array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'x-api-key'    => $key,
		),
		'body'    => wp_json_encode( array(
			'action' => 'get_fulfillment_summary',
			'data'   => $data,
		) ),
	) );

	if ( is_wp_error( $res ) ) {
		return $cache[ $order_id ] = $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		return $cache[ $order_id ] = new WP_Error( 'psfsw_cockpit', 'Cockpit API returned HTTP ' . $code );
	}

	// fulfillment_options comes back as a JSON string (same as the metabox parses).
	$options = $data['fulfillment_options'] ?? array();
	if ( is_string( $options ) ) {
		$options = json_decode( $options, true );
	}
	return $cache[ $order_id ] = is_array( $options ) ? $options : array();
}
