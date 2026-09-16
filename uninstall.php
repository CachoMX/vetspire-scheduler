<?php
/**
 * Cleanup on uninstall: remove settings and cached transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'vsps_settings' );
delete_option( 'vsps_db_version' );
delete_option( 'vsps_log_backfill_done' );

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vsps_bookings' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( '_transient_vsps_', '_transient_timeout_vsps_', '_transient_vsps_rl_', '_transient_timeout_vsps_rl_' ) as $prefix ) {
	$like = $wpdb->esc_like( $prefix ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
}
