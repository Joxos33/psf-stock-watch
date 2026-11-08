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
define( 'PSFSW_LOCK', 'psfsw_lock' );
define( 'PSFSW_LOCK_TTL', 15 * MINUTE_IN_SECONDS ); // stale-lock cutoff
define( 'PSFSW_TIME_BUDGET', 120 );     // seconds of provider calls per run

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
				'edit_url'     => $order->get_edit_order_url(),
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
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! is_array( $result ) ) {
		return new WP_Error( 'psfsw_bad_provider', 'Distributor stock provider returned an invalid result.' );
	}

	// Normalise: keep only well-formed [ 'dist' => string, 'qty' => int ] entries.
	$clean = array();
	foreach ( $result as $s ) {
		if ( ! is_array( $s ) || ! isset( $s['qty'] ) || ! is_scalar( $s['qty'] ) ) {
			continue;
		}
		$clean[] = array(
			'dist' => isset( $s['dist'] ) && is_scalar( $s['dist'] ) ? (string) $s['dist'] : '?',
			'qty'  => max( 0, (int) $s['qty'] ),
		);
	}
	return $clean;
}

/**
 * Take the run lock.
 *
 * Uses raw SQL because add_option() is not atomic (it checks, then writes with
 * ON DUPLICATE KEY UPDATE). INSERT IGNORE lets exactly one caller create the
 * row. A lock older than PSFSW_LOCK_TTL is treated as left over from a crashed
 * run and is taken over with a compare-and-swap UPDATE on the timestamp we
 * read, so two callers that both see the same stale lock can't both win.
 */
function psfsw_acquire_lock() {
	global $wpdb;
	$now = time();

	$inserted = $wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
		PSFSW_LOCK,
		$now
	) );
	if ( $inserted ) {
		wp_cache_delete( PSFSW_LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return true;
	}

	$since = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
		PSFSW_LOCK
	) );
	if ( null === $since || ( $now - (int) $since ) < PSFSW_LOCK_TTL ) {
		return false; // held (or released this instant; the next run will pick it up)
	}

	$swapped = $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
		$now,
		PSFSW_LOCK,
		$since
	) );
	wp_cache_delete( PSFSW_LOCK, 'options' );
	return 1 === (int) $swapped;
}

function psfsw_release_lock() {
	delete_option( PSFSW_LOCK );
}

/**
 * Run the check, store results, email if the flagged set changed.
 */
function psfsw_run() {
	if ( ! psfsw_acquire_lock() ) {
		return new WP_Error( 'psfsw_locked', 'A Stock Watch run is already in progress.' );
	}
	try {
		return psfsw_run_locked();
	} finally {
		psfsw_release_lock();
	}
}

function psfsw_run_locked() {
	$items    = psfsw_unordered_items();
	$cache    = array();
	$flagged  = array();
	$errors   = array();
	$deadline = time() + (int) apply_filters( 'psfsw_time_budget', PSFSW_TIME_BUDGET );

	// Check the orders that have gone longest without a check first, so when a
	// run hits the time budget the next run picks up where this one left off
	// instead of skipping the same tail every time.
	$checked_at = get_option( 'psfsw_checked_at', array() );
	$checked_at = is_array( $checked_at ) ? $checked_at : array();
	foreach ( $items as $i => &$row ) {
		$row['_pos'] = $i;
	}
	unset( $row );
	usort( $items, function ( $a, $b ) use ( $checked_at ) {
		$ta = (int) ( $checked_at[ $a['order_id'] ] ?? 0 );
		$tb = (int) ( $checked_at[ $b['order_id'] ] ?? 0 );
		return $ta <=> $tb ?: $a['_pos'] <=> $b['_pos'];
	} );

	// Items flagged last time stay flagged if this run doesn't reach them, so a
	// partial run doesn't make an alert disappear and reappear.
	$prev         = get_option( 'psfsw_last_report' );
	$prev_flagged = array();
	foreach ( ( is_array( $prev ) && ! empty( $prev['flagged'] ) ) ? $prev['flagged'] : array() as $f ) {
		$prev_flagged[ $f['item_id'] ] = $f;
	}

	$now_checked = array();
	foreach ( $items as $row ) {
		unset( $row['_pos'] );
		if ( '' === $row['upc'] && '' === $row['sku'] ) {
			$errors[] = $row + array( 'error' => 'No UPC or SKU on product' );
			continue;
		}
		$ck = $row['upc'] ? $row['upc'] : 'sku:' . $row['sku'];
		if ( ! isset( $cache[ $ck ] ) ) {
			if ( time() >= $deadline ) {
				// Out of time: record the rest rather than dying mid-run with no report saved.
				if ( isset( $prev_flagged[ $row['item_id'] ] ) ) {
					$flagged[] = $prev_flagged[ $row['item_id'] ] + array( 'stale' => true );
				} else {
					$errors[] = $row + array( 'error' => 'Skipped: run time limit reached; will be checked first next run' );
				}
				continue;
			}
			$cache[ $ck ] = psfsw_distributor_stock( $row['upc'], $row );
		}
		$now_checked[ $row['order_id'] ] = time();
		$stock = $cache[ $ck ];

		if ( is_wp_error( $stock ) ) {
			$errors[] = $row + array( 'error' => $stock->get_error_message() );
			continue;
		}

		$total = array_sum( wp_list_pluck( $stock, 'qty' ) );
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

	// Remember when each order was last checked; drop orders no longer on layaway.
	$live = array_flip( wp_list_pluck( $items, 'order_id' ) );
	update_option( 'psfsw_checked_at', array_intersect_key( $now_checked + $checked_at, $live ), false );

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

function psfsw_order_edit_url( $r ) {
	if ( ! empty( $r['edit_url'] ) ) {
		return $r['edit_url'];
	}
	// Reports saved before edit_url was stored.
	return admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' );
}

/**
 * @param bool $show_customer Include customer names (admin screen yes, email no).
 */
function psfsw_table( $report, $show_customer = true ) {
	if ( empty( $report['flagged'] ) ) {
		return '<p>No unordered layaway items are below ' . PSFSW_THRESHOLD . ' combined distributor stock.</p>';
	}
	$h  = '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;font:13px sans-serif">';
	$h .= '<tr style="background:#f2f2f2"><th>Order</th>' . ( $show_customer ? '<th>Customer</th>' : '' ) . '<th>Item</th><th>UPC</th><th>Distributors</th><th>Total</th></tr>';
	foreach ( $report['flagged'] as $r ) {
		$dists = array();
		foreach ( $r['stock'] as $s ) {
			$dists[] = esc_html( $s['dist'] ) . ': ' . (int) $s['qty'];
		}
		$h .= sprintf(
			'<tr><td><a href="%s">#%s</a></td>%s<td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td style="color:#b00;font-weight:bold">%d</td></tr>',
			esc_url( psfsw_order_edit_url( $r ) ),
			esc_html( $r['order_number'] ),
			$show_customer ? '<td>' . esc_html( $r['customer'] ) . '</td>' : '',
			esc_html( $r['name'] ),
			esc_html( $r['sku'] ),
			esc_html( $r['upc'] ) . ( empty( $r['stale'] ) ? '' : '<br><small>from previous run</small>' ),
			$dists ? implode( '<br>', $dists ) : '<em>none listed</em>',
			(int) $r['total']
		);
	}
	return $h . '</table>';
}

function psfsw_email( $report ) {
	$to      = apply_filters( 'psfsw_email_to', get_option( 'admin_email' ) );
	$subject = sprintf( '[PSF] %d layaway item(s) low at distributors', count( $report['flagged'] ) );
	$body    = '<p>Unordered On Layaway items with fewer than ' . PSFSW_THRESHOLD . ' units across all distributors:</p>'
		. psfsw_table( $report, false ) // no customer PII in email; names are on the admin screen
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
	$result = psfsw_run();
	$url    = admin_url( 'admin.php?page=psf-stock-watch' );
	if ( is_wp_error( $result ) && 'psfsw_locked' === $result->get_error_code() ) {
		$url = add_query_arg( 'psfsw_locked', '1', $url );
	}
	wp_safe_redirect( $url );
	exit;
} );

function psfsw_admin_page() {
	$report = get_option( 'psfsw_last_report' );
	echo '<div class="wrap"><h1>Stock Watch</h1>';
	if ( ! empty( $_GET['psfsw_locked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display-only flag.
		echo '<div class="notice notice-warning"><p>A run is already in progress. Refresh in a minute to see the results.</p></div>';
	}
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
				esc_url( psfsw_order_edit_url( $e ) ),
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
