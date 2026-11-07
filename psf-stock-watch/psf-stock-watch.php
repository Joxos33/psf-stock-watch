<?php
/**
 * Plugin Name: PSF Stock Watch
 * Description: Watches On Layaway orders for line items not yet ordered and alerts when combined distributor stock drops below the threshold.
 * Version:     0.1.0
 * Author:      Puget Sound Firearms
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'PSFSW_VER', '0.1.0' );
define( 'PSFSW_CRON', 'psfsw_run' );
define( 'PSFSW_STATUS', 'on-layaway' );
define( 'PSFSW_THRESHOLD', 3 );          // alert when combined qty < this
define( 'PSFSW_ORDERED_META', '_psflh_item_ordered' );
define( 'PSFSW_UPC_META', 'upc' );

/* -------------------------------------------------------------------------
 * Schedule
 * ---------------------------------------------------------------------- */

add_filter( 'cron_schedules', function ( $s ) {
	$s['psfsw_4h'] = array( 'interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Every 4 hours' );
	return $s;
} );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( PSFSW_CRON ) ) {
		wp_schedule_event( time() + 60, 'psfsw_4h', PSFSW_CRON );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( PSFSW_CRON );
} );

add_action( PSFSW_CRON, 'psfsw_run' );

/* -------------------------------------------------------------------------
 * Core
 * ---------------------------------------------------------------------- */

/**
 * Every unordered line item on an On Layaway order.
 *
 * @return array<int, array> rows: order_id, order_number, customer, item_id, name, sku, upc, qty
 */
function psfsw_unordered_items() {
	$orders = wc_get_orders( array(
		'status' => PSFSW_STATUS,
		'limit'  => -1,
		'type'   => 'shop_order',
	) );

	$rows = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( 'yes' === $item->get_meta( PSFSW_ORDERED_META ) ) {
				continue;
			}
			$product = $item->get_product();
			$upc     = $product ? (string) get_post_meta( $product->get_id(), PSFSW_UPC_META, true ) : '';

			$rows[] = array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'customer'     => trim( $order->get_formatted_billing_full_name() ),
				'item_id'      => $item_id,
				'name'         => $item->get_name(),
				'sku'          => $product ? $product->get_sku() : '',
				'upc'          => preg_replace( '/\D/', '', $upc ),
				'qty'          => (int) $item->get_quantity(),
			);
		}
	}
	return $rows;
}

/**
 * Distributor stock for one UPC.
 *
 * Returns a list of [ 'dist' => 'LIP', 'qty' => 16 ] or WP_Error.
 * The real lookup is supplied via the `psfsw_distributor_stock` filter
 * (Cockpit today, FFL Suite later) so the data source can be swapped
 * without touching the watcher.
 */
function psfsw_distributor_stock( $upc, $row = array() ) {
	$result = apply_filters( 'psfsw_distributor_stock', null, $upc, $row );
	if ( null === $result ) {
		return new WP_Error( 'psfsw_no_provider', 'No distributor stock provider is registered.' );
	}
	return $result;
}

/**
 * Run the check, store results, email if the flagged set changed.
 */
function psfsw_run() {
	$items   = psfsw_unordered_items();
	$cache   = array();
	$flagged = array();
	$errors  = array();

	foreach ( $items as $row ) {
		if ( '' === $row['upc'] && '' === $row['sku'] ) {
			$errors[] = $row + array( 'error' => 'No UPC or SKU on product' );
			continue;
		}
		$ck = $row['upc'] ? $row['upc'] : 'sku:' . $row['sku'];
		if ( ! isset( $cache[ $ck ] ) ) {
			$cache[ $ck ] = psfsw_distributor_stock( $row['upc'], $row );
		}
		$stock = $cache[ $ck ];

		if ( is_wp_error( $stock ) ) {
			$errors[] = $row + array( 'error' => $stock->get_error_message() );
			continue;
		}

		$total = array_sum( array_map( 'intval', wp_list_pluck( $stock, 'qty' ) ) );
		if ( $total < PSFSW_THRESHOLD ) {
			$flagged[] = $row + array( 'total' => $total, 'stock' => $stock );
		}
	}

	$report = array(
		'ran_at'  => current_time( 'mysql' ),
		'checked' => count( $items ),
		'flagged' => $flagged,
		'errors'  => $errors,
	);
	update_option( 'psfsw_last_report', $report, false );

	// Only email when the set of flagged line items changes.
	$sig = md5( wp_json_encode( wp_list_pluck( $flagged, 'item_id' ) ) . '|' . wp_json_encode( wp_list_pluck( $flagged, 'total' ) ) );
	if ( $flagged && get_option( 'psfsw_last_sig' ) !== $sig ) {
		psfsw_email( $report );
	}
	update_option( 'psfsw_last_sig', $flagged ? $sig : '', false );

	return $report;
}

/* -------------------------------------------------------------------------
 * Output
 * ---------------------------------------------------------------------- */

function psfsw_table( $report ) {
	if ( empty( $report['flagged'] ) ) {
		return '<p>No unordered layaway items are below ' . PSFSW_THRESHOLD . ' combined distributor stock.</p>';
	}
	$h  = '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;font:13px sans-serif">';
	$h .= '<tr style="background:#f2f2f2"><th>Order</th><th>Customer</th><th>Item</th><th>UPC</th><th>Distributors</th><th>Total</th></tr>';
	foreach ( $report['flagged'] as $r ) {
		$dists = array();
		foreach ( $r['stock'] as $s ) {
			$dists[] = esc_html( $s['dist'] ) . ': ' . (int) $s['qty'];
		}
		$h .= sprintf(
			'<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td style="color:#b00;font-weight:bold">%d</td></tr>',
			esc_url( admin_url( 'post.php?post=' . $r['order_id'] . '&action=edit' ) ),
			esc_html( $r['order_number'] ),
			esc_html( $r['customer'] ),
			esc_html( $r['name'] ),
			esc_html( $r['sku'] ),
			esc_html( $r['upc'] ),
			$dists ? implode( '<br>', $dists ) : '<em>none listed</em>',
			$r['total']
		);
	}
	return $h . '</table>';
}

function psfsw_email( $report ) {
	$to      = apply_filters( 'psfsw_email_to', get_option( 'admin_email' ) );
	$subject = sprintf( '[PSF] %d layaway item(s) low at distributors', count( $report['flagged'] ) );
	$body    = '<p>Unordered On Layaway items with fewer than ' . PSFSW_THRESHOLD . ' units across all distributors:</p>'
		. psfsw_table( $report )
		. '<p style="color:#666;font-size:12px">Checked ' . (int) $report['checked'] . ' items at ' . esc_html( $report['ran_at'] ) . '.</p>';

	wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/* -------------------------------------------------------------------------
 * Admin page: WooCommerce → Stock Watch
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Stock Watch', 'Stock Watch', 'manage_woocommerce', 'psf-stock-watch', 'psfsw_admin_page' );
} );

add_action( 'admin_post_psfsw_run_now', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'psfsw_run_now' );
	psfsw_run();
	wp_safe_redirect( admin_url( 'admin.php?page=psf-stock-watch' ) );
	exit;
} );

function psfsw_admin_page() {
	$report = get_option( 'psfsw_last_report' );
	echo '<div class="wrap"><h1>Stock Watch</h1>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'psfsw_run_now' );
	echo '<input type="hidden" name="action" value="psfsw_run_now"><p><button class="button button-primary">Run now</button>';
	$next = wp_next_scheduled( PSFSW_CRON );
	echo ' <span style="color:#666">Next scheduled run: ' . ( $next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'M j, g:i a' ) ) : 'not scheduled' ) . '</span></p></form>';

	if ( ! $report ) {
		echo '<p>No runs yet.</p></div>';
		return;
	}

	printf( '<p>Last run %s — checked %d unordered layaway items.</p>', esc_html( $report['ran_at'] ), (int) $report['checked'] );
	echo psfsw_table( $report ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.

	if ( $report['errors'] ) {
		echo '<h2>Could not check</h2><ul>';
		foreach ( $report['errors'] as $e ) {
			printf(
				'<li><a href="%s">#%s</a> %s — %s</li>',
				esc_url( admin_url( 'post.php?post=' . $e['order_id'] . '&action=edit' ) ),
				esc_html( $e['order_number'] ),
				esc_html( $e['name'] ),
				esc_html( $e['error'] )
			);
		}
		echo '</ul>';
	}
	echo '</div>';
}

/* -------------------------------------------------------------------------
 * Stock provider: g-FFL Cockpit (swap for FFL Suite later via the same filter)
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/provider-cockpit.php';
