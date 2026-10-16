<?php
/**
 * Offline checks for the per-device layout (v1.27.0): the shortcode's layout
 * config and where the phone layout comes from. No WordPress, no network.
 *
 * php tests/layout-unit.php
 */

define( 'ABSPATH', __DIR__ );
define( 'VSPS_PLUGIN_URL', 'https://site.test/wp-content/plugins/vetspire-scheduler/' );
define( 'VSPS_VERSION', 'test' );

function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function shortcode_atts( $pairs, $atts ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function add_shortcode() {}
function add_action() {}
function __( $t ) { return $t; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url_raw( $s ) { return (string) $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script() {}
function current_user_can() { return false; }
function rest_url( $p = '' ) { return 'https://site.test/wp-json/' . $p; }

$GLOBALS['vsps_settings'] = array();
function vsps_get_settings() { return $GLOBALS['vsps_settings']; }

class VSPS_Pims_Registry {
	public static function id_for_js( $id ) { return '' === (string) $id ? '' : (string) $id; }
	public static function booking_mode() { return 'direct'; }
}
class VSPS_Fields {
	const PRONOUNS = array();
	const TITLES   = array();
	public static function resolve() { return array( 'referral' => array( 'show' => false ) ); }
	public static function for_widget() { return array(); }
	public static function referral_sources() { return array(); }
}
class VSPS_Hub {
	public static function key() { return ''; }
}

require __DIR__ . '/../includes/class-vsps-shortcode.php';

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

// valid_mobile_layout()
check( 'mobile layout: known values kept (case-insensitive)', 'bar' === VSPS_Shortcode::valid_mobile_layout( 'bar' ) && 'float' === VSPS_Shortcode::valid_mobile_layout( 'FLOAT' ) );
check( 'mobile layout: empty / unknown / null mean "same as desktop"', '' === VSPS_Shortcode::valid_mobile_layout( '' ) && '' === VSPS_Shortcode::valid_mobile_layout( '<script>' ) && '' === VSPS_Shortcode::valid_mobile_layout( null ) );

// layout_config()
check( 'config: no phone layout -> layout only', array( 'layout' => 'float' ) === VSPS_Shortcode::layout_config( 'float', '' ) );
check( 'config: different phone layout -> both keys', array( 'layout' => 'float', 'layoutMobile' => 'bar' ) === VSPS_Shortcode::layout_config( 'float', 'bar' ) );
check( 'config: phone layout equal to desktop -> dropped', array( 'layout' => 'bar' ) === VSPS_Shortcode::layout_config( 'bar', 'bar' ) );
check( 'config: unknown desktop layout falls back to full', array( 'layout' => 'full', 'layoutMobile' => 'bar' ) === VSPS_Shortcode::layout_config( 'nope', 'bar' ) );

// render(): where the phone layout comes from
function widget_config( $atts ) {
	$html = VSPS_Shortcode::render( $atts );
	preg_match( '/data-vsps-config="([^"]*)"/', $html, $m );
	return json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}
$GLOBALS['vsps_settings'] = array(
	'default_location'  => '23539',
	'layout'            => 'float',
	'layout_mobile'     => 'bar',
	'default_type'      => '',
	'primary_color'     => '#2f6f4f',
	'analytics_enabled' => 1,
	'pims'              => 'vetspire',
);
$c = widget_config( '' );
check( 'render: bare shortcode uses Settings desktop + phone layouts', 'float' === $c['layout'] && 'bar' === $c['layoutMobile'] );
$c = widget_config( array( 'layout' => 'full' ) );
check( 'render: own layout="full" ignores the Settings phone layout', 'full' === $c['layout'] && ! isset( $c['layoutMobile'] ) );
$c = widget_config( array( 'layout' => 'float' ) );
check( 'render: layout="float" equal to Settings still follows the Settings phone layout', 'float' === $c['layout'] && 'bar' === $c['layoutMobile'] );
$c = widget_config( array( 'layout' => 'FULL' ) );
check( 'render: layout is case-insensitive (FULL -> full, own layout)', 'full' === $c['layout'] && ! isset( $c['layoutMobile'] ) );
$c = widget_config( array( 'layout' => 'calendar', 'layout_mobile' => 'bar' ) );
check( 'render: own layout + layout_mobile attribute', 'calendar' === $c['layout'] && 'bar' === $c['layoutMobile'] );
$c = widget_config( array( 'layout_mobile' => 'float' ) );
check( 'render: layout_mobile attribute overrides the Settings value', 'float' === $c['layout'] && ! isset( $c['layoutMobile'] ) );
$GLOBALS['vsps_settings']['layout_mobile'] = '';
$c = widget_config( '' );
check( 'render: Settings "Same as above" -> no phone layout (live sites unchanged)', 'float' === $c['layout'] && ! isset( $c['layoutMobile'] ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
