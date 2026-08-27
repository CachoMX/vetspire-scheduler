<?php
/**
 * Plugin Name: Vetspire Scheduler
 * Plugin URI:  https://vetcelerator.com
 * Description: Embeddable appointment scheduler powered by the Vetspire API. Shows live available times and books appointments on-site so analytics attribution is preserved.
 * Version:     1.5.0
 * Author:      Vetcelerator
 * License:     GPL-2.0+
 * Text Domain: vetspire-scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VSPS_VERSION', '1.5.0' );
define( 'VSPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VSPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSPS_OPTION_KEY', 'vsps_settings' );

require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-api.php';
require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-cache.php';
require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-booking.php';
require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-rest.php';
require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-settings.php';
require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-shortcode.php';
require_once VSPS_PLUGIN_DIR . 'includes/class-vsps-admin-schedule.php';

/**
 * Returns plugin settings merged with defaults.
 */
function vsps_get_settings() {
	$defaults = array(
		'api_token'         => '',
		'api_endpoint'      => 'https://api.vetspire.com/graphql',
		'cache_ttl'         => 300,
		'analytics_enabled' => 1,
		'default_location'  => '',
		'allowed_locations' => '',
		'primary_color'     => '#2f6f4f',
		'layout'            => 'full',
		'default_type'      => '',
	);
	$saved = get_option( VSPS_OPTION_KEY, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings = array_merge( $defaults, $saved );

	// Allow wp-config.php override so the key never has to live in the DB.
	if ( defined( 'VSPS_API_TOKEN' ) && VSPS_API_TOKEN ) {
		$settings['api_token'] = VSPS_API_TOKEN;
	}
	return $settings;
}

/**
 * Returns a configured API client, or null when no token is set.
 */
function vsps_api() {
	$settings = vsps_get_settings();
	if ( '' === $settings['api_token'] ) {
		return null;
	}
	return new VSPS_Api( $settings['api_token'], $settings['api_endpoint'] );
}

// Automatic updates from GitHub Releases (release assets carry the built zip).
if ( file_exists( VSPS_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once VSPS_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
	$vsps_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/CachoMX/vetspire-scheduler/',
		__FILE__,
		'vetspire-scheduler'
	);
	$vsps_update_checker->getVcsApi()->enableReleaseAssets();
}

// "Settings | Appointments" quick links on the Plugins list row.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '">Settings</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ) . '">Appointments</a>'
	);
	return $links;
} );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'vetspire-scheduler', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	VSPS_Settings::init();
	VSPS_Rest::init();
	VSPS_Shortcode::init();
	VSPS_Admin_Schedule::init();
} );
