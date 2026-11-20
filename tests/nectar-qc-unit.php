<?php
/**
 * Offline checks for Nicole's Nectar QC (2026-11-19):
 *  - a typed US state becomes its 2-letter code ("Tenn" broke every later
 *    lookup of that client in Nectar), and Nectar never gets anything else;
 *  - Nectar has no age question (the birth date field feeds the chart);
 *  - upstream failures answer 424, not a 5xx Cloudflare would replace.
 * No WordPress, no network.
 *
 * php tests/nectar-qc-unit.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function __( $t ) { return $t; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_email( $t ) { return trim( (string) $t ); }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_key( $t ) { return preg_replace( "/[^a-z0-9_-]/", "", strtolower( (string) $t ) ); }
function wp_list_pluck( $list, $field ) { return array_map( function ( $r ) use ( $field ) { return $r[ $field ]; }, $list ); }

// Nectar requests are recorded, not sent.
$GLOBALS['vsps_sent']  = array();
$GLOBALS['vsps_reply'] = array();
function wp_remote_post( $url, $args ) {
	$GLOBALS['vsps_sent'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['vsps_reply'] ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl ) { return true; }
function delete_transient( $key ) { return true; }
function vsps_get_settings() { return array( 'cache_ttl' => 60, 'pims' => 'nectar' ); }

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';

$fails = 0;
function check( $label, $ok, $detail = '' ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  — ' . json_encode( $detail ) ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

echo "== US state → 2-letter code ==\n";
$cases = array(
	'Tenn' => 'TN', 'tenn.' => 'TN', 'Tennessee' => 'TN', 'tn' => 'TN', ' TN ' => 'TN',
	'Calif.' => 'CA', 'N.Y.' => 'NY', 'new york' => 'NY', 'West Virginia' => 'WV', 'W. Va.' => 'WV',
	'District of Columbia' => 'DC', 'Tex' => 'TX', 'fla' => 'FL', 'Puerto Rico' => 'PR',
	'wash' => 'WA', 'Washington' => 'WA', 'Va' => 'VA', 'W.Va' => 'WV', 'IN' => 'IN', 'or' => 'OR', 'Me' => 'ME',
	'Washington DC' => 'DC', 'Washington, D.C.' => 'DC', 'Georgia' => 'GA',
	'' => '', 'Ontario' => 'Ontario', 'XX' => 'XX', 'Tennesee' => 'Tennesee',
);
foreach ( $cases as $in => $want ) {
	$got = VSPS_Fields::us_state( $in );
	check( json_encode( $in ) . ' → ' . json_encode( $want ), $want === $got, $got );
}
$parsed = VSPS_Fields::parse_extras( array( 'address' => array( 'line1' => '123 Test Rd', 'city' => 'Testtown', 'state' => 'Tenn', 'postal' => '37923' ) ), array() );
check( 'the form parser stores the code (Vetspire gets TN too)', 'TN' === $parsed['client']['address']['state'], $parsed['client']['address'] );

echo "== Nectar new client: state only as a code ==\n";
$nectar = VSPS_Nectar::via_hub( 'https://hub.test', 'vss_' . str_repeat( 'ab', 24 ), '', 'America/Los_Angeles', '1.30.3' );
function new_family( $nectar, array $address ) {
	$GLOBALS['vsps_sent']  = array();
	$GLOBALS['vsps_reply'] = array( 'data' => array( 'addFamily' => array( 'id' => 'f1', 'primaryClient' => array( 'id' => 'c1' ) ) ) );
	$nectar->create_booking_client( array(
		'location_id' => 'clinic',
		'client'      => array(
			'given_name' => 'Zz', 'family_name' => 'Test', 'email' => 'zz@example.com', 'phone' => '865 855 9494',
			'phone_alt' => '', 'owner_dob' => '', 'address' => array_merge( array( 'line1' => '123 Test Rd', 'line2' => '', 'city' => 'Testtown', 'postal' => '37923' ), $address ),
		),
	) );
	$sent = end( $GLOBALS['vsps_sent'] );
	return $sent['body']['variables'] ?? ( $sent['body']['body']['variables'] ?? array() );
}
$v = new_family( $nectar, array( 'state' => 'Tenn' ) );
check( '"Tenn" is sent as TN', 'TN' === ( $v['state'] ?? null ), $v );
check( 'the rest of the address is sent', '123 Test Rd' === ( $v['street'] ?? null ) && 'Testtown' === ( $v['city'] ?? null ) && '37923' === ( $v['zip'] ?? null ), $v );
$v = new_family( $nectar, array( 'state' => 'Ontario' ) );
check( 'a state that is not a US code is left out (Nectar would reject or break)', ! isset( $v['state'] ) && '123 Test Rd' === ( $v['street'] ?? null ), $v );
$v = new_family( $nectar, array( 'state' => '' ) );
check( 'no state typed: street, city and ZIP still go', ! isset( $v['state'] ) && 'Testtown' === ( $v['city'] ?? null ), $v );
$v = new_family( $nectar, array( 'state' => 'TN', 'city' => '' ) );
check( 'an address without a city is not sent at all', ! isset( $v['street'] ) && ! isset( $v['state'] ), $v );

echo "== no age question on Nectar ==\n";
check( 'Nectar does not offer the age field', ! VSPS_Pims_Registry::supports_field( 'age', 'nectar' ) );
check( 'Nectar still offers the birth date field', VSPS_Pims_Registry::supports_field( 'birth_date', 'nectar' ) );
check( 'Vetspire keeps the age field', VSPS_Pims_Registry::supports_field( 'age', 'vetspire' ) );

echo "== upstream failure status ==\n";
$rest = file_get_contents( __DIR__ . '/../includes/class-vsps-rest.php' );
check( 'no endpoint answers 502/504 (Cloudflare replaces those with an HTML page)', ! preg_match( "/'status' => 50[24]/", $rest ) );
check( 'every upstream failure uses the constant', 4 <= substr_count( $rest, "'status' => self::UPSTREAM_STATUS" ), substr_count( $rest, "'status' => self::UPSTREAM_STATUS" ) );
preg_match( '/const UPSTREAM_STATUS = (\d+);/', $rest, $m );
check( 'upstream failures answer a 4xx', isset( $m[1] ) && (int) $m[1] >= 400 && (int) $m[1] < 500, $m );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
