<?php
/**
 * Remove everything PSF Stock Watch stored. The last report holds customer
 * names and order details, so it should not outlive the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'psfsw_last_report' );
delete_option( 'psfsw_last_sig' );
delete_option( 'psfsw_lock' );
delete_option( 'psfsw_checked_at' );
wp_clear_scheduled_hook( 'psfsw_run' );
