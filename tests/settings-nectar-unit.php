<?php
/**
 * Offline checks for saving Nectar settings through the real
 * VSPS_Settings::sanitize() (connect card and full Settings saves).
 * Nectar production goes through the Vetcelerator hub, so the connect card
 * takes the site's hub connection key instead of Nectar credentials.
 * No WordPress, no network.
 *
 * php tests/settings-nectar-unit.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'VSPS_OPTION_KEY', 'vsps_settings' );

class WP_Error {
	public function get_error_code() { return ''; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : null; }
function esc_url_raw( $s ) { return (string) $s; }
$GLOBALS['vsps_errors'] = array();
function add_settings_error( $setting, $code, $message, $type ) { $GLOBALS['vsps_errors'][] = $code; }
$GLOBALS['vsps_option'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['vsps_option']; }
function vsps_get_settings() {
	return array_merge( array(
		'pims' => 'vetspire', 'api_token' => '', 'api_endpoint' => 'https://api.vetspire.com/graphql', 'cache_ttl' => 300,
		'nectar_client_id' => '', 'nectar_client_secret' => '', 'nectar_env' => 'sandbox',
		'nectar_meta_location' => '', 'clinic_timezone' => '', 'hub_key' => '',
	), $GLOBALS['vsps_option'] );
}
class VSPS_Cache {
	public static function flush() {}
}
$GLOBALS['vsps_hub_resets'] = 0;
class VSPS_Hub {
	const KEY_PATTERN = '/^vss_[0-9a-f]{48}$/';
	public static function reset_after_key_change() { $GLOBALS['vsps_hub_resets']++; }
}
class VSPS_Shortcode {
	public static function valid_mobile_layout( $v ) { return ''; }
}

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';
require __DIR__ . '/../includes/class-vsps-settings.php';

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}
function save( array $stored, array $input ) {
	$GLOBALS['vsps_option'] = $stored;
	$GLOBALS['vsps_errors'] = array();
	return VSPS_Settings::sanitize( $input );
}

$key     = 'vss_' . str_repeat( 'ab', 24 );
$connect = array( 'connect_only' => '1', 'pims' => 'nectar', 'nectar_env' => 'production' );

echo "== connect card, Nectar production ==\n";
$out = save( array(), $connect + array( 'hub_key' => '  ' . strtoupper( $key ) . ' ' ) );
check( 'hub key from the connect card is stored (normalized)', $key === $out['hub_key'] );
check( 'production + Nectar stored', 'production' === $out['nectar_env'] && 'nectar' === $out['pims'] );
check( 'the single Nectar location is selected', VSPS_Nectar::LOCATION_ID === $out['default_location'] );
check( 'no Nectar credentials are needed or stored', '' === $out['nectar_client_id'] && '' === $out['nectar_client_secret'] );

$out = save( array( 'hub_key' => $key ), $connect + array( 'hub_key' => 'not-a-key' ) );
check( 'an invalid hub key is refused and the stored one kept', $key === $out['hub_key'] && array( 'vsps_hub_key' ) === $GLOBALS['vsps_errors'] );

$out = save( array( 'hub_key' => $key ), $connect );
check( 'a connect card without the hub key field keeps the stored key', $key === $out['hub_key'] );

echo "== connect card, Nectar sandbox ==\n";
$out = save( array(), array( 'connect_only' => '1', 'pims' => 'nectar', 'nectar_env' => 'sandbox', 'nectar_client_id' => 'id', 'nectar_client_secret' => 'secret' ) );
check( 'sandbox still stores its own credentials', 'id' === $out['nectar_client_id'] && 'secret' === $out['nectar_client_secret'] && 'sandbox' === $out['nectar_env'] );

echo "== full settings save ==\n";
$stored = array( 'pims' => 'nectar', 'nectar_env' => 'production', 'hub_key' => $key );
$out    = save( $stored, array( 'layout' => 'bar', 'primary_color' => '#112233', 'fields' => array() ) );
check( 'a Settings save without the hub key field keeps it', $key === $out['hub_key'] );
check( 'Nectar production stays selected', 'production' === $out['nectar_env'] && VSPS_Nectar::LOCATION_ID === $out['default_location'] );
check( 'no clinic-address setting is stored on the site', ! array_key_exists( 'nectar_clinic', $out ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
