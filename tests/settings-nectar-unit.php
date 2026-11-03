<?php
/**
 * Offline checks for saving the Nectar production clinic address through the
 * real VSPS_Settings::sanitize() (connect card and full Settings saves).
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
		'nectar_client_id' => '', 'nectar_client_secret' => '', 'nectar_env' => 'sandbox', 'nectar_clinic' => '',
		'nectar_meta_location' => '', 'clinic_timezone' => '',
	), $GLOBALS['vsps_option'] );
}
class VSPS_Cache {
	public static function flush() {}
}
class VSPS_Hub {
	const KEY_PATTERN = '/^vss_[0-9a-f]{48}$/';
	public static function reset_after_key_change() {}
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

$connect = array( 'connect_only' => '1', 'pims' => 'nectar', 'nectar_client_id' => 'id', 'nectar_client_secret' => 'secret', 'nectar_env' => 'production' );

echo "== connect card ==\n";
$out = save( array(), $connect + array( 'nectar_clinic' => 'https://esteroeasyvet.app.prod.nectar.vet/' ) );
check( 'pasted app address → code stored', 'esteroeasyvet' === $out['nectar_clinic'] );
check( 'no error notice', array() === $GLOBALS['vsps_errors'] );
check( 'production + Nectar stored', 'production' === $out['nectar_env'] && 'nectar' === $out['pims'] );

$out = save( array( 'pims' => 'nectar', 'nectar_clinic' => 'esteroeasyvet' ), $connect + array( 'nectar_clinic' => 'https://evil.example/graphql' ) );
check( 'foreign address refused, previous code kept', 'esteroeasyvet' === $out['nectar_clinic'] );
check( 'refusal shows a notice', array( 'vsps_nectar_clinic' ) === $GLOBALS['vsps_errors'] );

$out = save( array( 'pims' => 'nectar', 'nectar_clinic' => 'esteroeasyvet' ), $connect );
check( 'field not posted → stored code kept', 'esteroeasyvet' === $out['nectar_clinic'] );

$out = save( array( 'pims' => 'nectar', 'nectar_clinic' => 'esteroeasyvet' ), $connect + array( 'nectar_clinic' => '' ) );
check( 'emptied field → code cleared', '' === $out['nectar_clinic'] );

$out = save( array( 'pims' => 'nectar', 'nectar_env' => 'production', 'nectar_clinic' => 'esteroeasyvet' ), array_merge( $connect, array( 'nectar_env' => 'sandbox' ) ) );
check( 'switching to sandbox keeps the production code for later', 'esteroeasyvet' === $out['nectar_clinic'] && 'sandbox' === $out['nectar_env'] );

echo "== full settings save ==\n";
$stored = array( 'pims' => 'nectar', 'nectar_env' => 'production', 'nectar_clinic' => 'esteroeasyvet', 'nectar_client_id' => 'id', 'nectar_client_secret' => 'secret' );
$out    = save( $stored, array( 'layout' => 'bar', 'primary_color' => '#112233', 'fields' => array() ) );
check( 'a Settings save without the field keeps the code', 'esteroeasyvet' === $out['nectar_clinic'] );
check( 'Nectar location stays the single clinic', VSPS_Nectar::LOCATION_ID === $out['default_location'] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
