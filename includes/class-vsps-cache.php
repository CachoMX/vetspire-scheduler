<?php
/**
 * Thin transient-based cache so public traffic never hammers the Vetspire API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Cache {

	const PREFIX = 'vsps_';

	/**
	 * Returns cached value, or runs $callback, caches and returns its result.
	 * WP_Error results are never cached.
	 */
	public static function remember( $key_parts, $callback, $ttl = null ) {
		if ( null === $ttl ) {
			$settings = vsps_get_settings();
			$ttl      = max( 30, (int) $settings['cache_ttl'] );
		}
		$key    = self::PREFIX . md5( wp_json_encode( $key_parts ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = call_user_func( $callback );
		if ( ! is_wp_error( $value ) ) {
			set_transient( $key, $value, $ttl );
		}
		return $value;
	}

	/** Invalidates one cached entry (same key derivation as remember()). */
	public static function forget( $key_parts ) {
		delete_transient( self::PREFIX . md5( wp_json_encode( $key_parts ) ) );
	}

	/** Deletes all plugin transients (used on uninstall / settings save). */
	public static function flush() {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$like = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}
}
