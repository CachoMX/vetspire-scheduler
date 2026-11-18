<?php
/**
 * Offline checks: a client already on file who books through the NEW-client
 * form (QC 2026-11-17, Nicole's recording) gets the address they typed, but
 * only when the phone they typed matches the one on file and the client has
 * no address yet. An address on file is never replaced from the website.
 * No WordPress, no network.
 *
 * php tests/address-unit.php
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
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl ) { return true; }
function delete_transient( $key ) { return true; }
function vsps_get_settings() { return array( 'cache_ttl' => 60, 'pims' => 'vetspire' ); }

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-booking.php';

$fails = 0;
function check( $label, $ok, $detail = '' ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  — ' . json_encode( $detail ) ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

/** Records every GraphQL call instead of sending it. */
class Recording_Api extends VSPS_Api {
	public $calls = array();
	public $fail  = false;
	public function request( $query, $variables = array() ) {
		$this->calls[] = array( 'query' => $query, 'variables' => $variables );
		return $this->fail ? new WP_Error( 'vsps_http', 'down' ) : array( 'addClientAddress' => array( 'id' => '1' ) );
	}
}

$fill = new ReflectionMethod( 'VSPS_Booking', 'fill_missing_address' );
$fill->setAccessible( true );

const ADDRESS = array( 'line1' => '123 Test Rd', 'line2' => '', 'city' => 'Testtown', 'state' => 'TN', 'postal' => '37923' );

function client_on_file( array $over = array() ) {
	return array_merge( array(
		'id'           => '200',
		'phoneNumbers' => array( array( 'value' => '(865) 855-9494' ) ),
		'addresses'    => array(),
		'patients'     => array(),
	), $over );
}
function form_args( array $over = array(), array $client_over = array() ) {
	return array_merge( array(
		'client_type' => 'new',
		'client'      => array_merge( array( 'email' => 'zz@vetcelerator.com', 'phone' => '865 855 9494', 'address' => ADDRESS ), $client_over ),
	), $over );
}
function run_fill( $fill, $client, $args, $existing = true, $fail = false ) {
	$api       = new Recording_Api( 'tok', 'https://api.test/graphql' );
	$api->fail = $fail;
	$fill->invoke( null, $api, $client, $args, $existing );
	return $api->calls;
}

echo "== existing client on the new-client form ==\n";
$calls = run_fill( $fill, client_on_file(), form_args() );
check( 'no address on file + phone matches → addClientAddress', 1 === count( $calls ) && false !== strpos( $calls[0]['query'], 'addClientAddress' ) );
$input = $calls ? $calls[0]['variables']['input'] : array();
check( 'sends the typed address as the primary HOME address', array( 'line1' => '123 Test Rd', 'city' => 'Testtown', 'state' => 'TN', 'postalCode' => '37923', 'isPrimary' => true, 'use' => 'HOME' ) === $input, $input );
check( 'on the right client', $calls && '200' === $calls[0]['variables']['clientId'] );

check( 'an address already on file is never replaced', array() === run_fill( $fill, client_on_file( array( 'addresses' => array( array( 'id' => '9' ) ) ) ), form_args() ) );
check( 'lookup without address info → nothing sent (no duplicates)', array() === run_fill( $fill, client_on_file( array( 'addresses' => null ) ), form_args() ) );
check( 'phone does not match the one on file → nothing sent', array() === run_fill( $fill, client_on_file(), form_args( array(), array( 'phone' => '865 855 9493' ) ) ) );
check( 'same last 4 digits, different number → nothing sent (full number required)', array() === run_fill( $fill, client_on_file(), form_args( array(), array( 'phone' => '212 555 9494' ) ) ) );
check( 'only the last 4 typed → nothing sent', array() === run_fill( $fill, client_on_file(), form_args( array(), array( 'phone' => '9494' ) ) ) );
check( 'country code and formatting ignored (+1 (865) 855-9494)', 1 === count( run_fill( $fill, client_on_file(), form_args( array(), array( 'phone' => '+1 (865) 855-9494' ) ) ) ) );
check( 'second phone on file matches', 1 === count( run_fill( $fill, client_on_file( array( 'phoneNumbers' => array( array( 'value' => '555 010 0100' ), array( 'value' => '865-855-9494' ) ) ) ), form_args() ) ) );
check( 'no phone on file → nothing sent (fail closed)', array() === run_fill( $fill, client_on_file( array( 'phoneNumbers' => array() ) ), form_args() ) );
check( 'incomplete address (no city) → nothing sent', array() === run_fill( $fill, client_on_file(), form_args( array(), array( 'address' => array_merge( ADDRESS, array( 'city' => '' ) ) ) ) ) );
check( 'no address typed → nothing sent', array() === run_fill( $fill, client_on_file(), form_args( array(), array( 'address' => array() ) ) ) );
check( 'returning-client flow → nothing sent', array() === run_fill( $fill, client_on_file(), form_args( array( 'client_type' => 'existing' ) ) ) );
check( 'brand-new client (address went on createClient) → nothing sent', array() === run_fill( $fill, client_on_file(), form_args(), false ) );

$line2 = run_fill( $fill, client_on_file(), form_args( array(), array( 'address' => array_merge( ADDRESS, array( 'line2' => 'Apt 4' ) ) ) ) );
check( 'apartment line is sent when typed', $line2 && 'Apt 4' === $line2[0]['variables']['input']['line2'] );

$log = tempnam( sys_get_temp_dir(), 'vsps' );
ini_set( 'error_log', $log );
$calls = run_fill( $fill, client_on_file(), form_args(), true, true );
$logged = (string) file_get_contents( $log );
@unlink( $log );
check( 'a Vetspire error is logged, never thrown (the booking already exists)', 1 === count( $calls ) && false !== strpos( $logged, 'could not add the address to client 200' ) );
check( 'the log never carries the address', false === strpos( $logged, 'Test Rd' ) );

echo "== new client: unchanged ==\n";
$new = new ReflectionMethod( 'VSPS_Api', 'client_input' );
$new->setAccessible( true );
$in = $new->invoke( null, array( 'location_id' => '1', 'client' => array( 'given_name' => 'A', 'family_name' => 'B', 'email' => 'a@b.c', 'phone' => '555 010 0100', 'address' => ADDRESS ) ) );
check( 'createClient still carries the address', isset( $in['addresses'][0] ) && '37923' === $in['addresses'][0]['postalCode'] && true === $in['addresses'][0]['isPrimary'], $in );
$in = $new->invoke( null, array( 'location_id' => '1', 'client' => array( 'given_name' => 'A', 'family_name' => 'B', 'email' => 'a@b.c', 'phone' => '555 010 0100', 'address' => array_merge( ADDRESS, array( 'state' => '' ) ) ) ) );
check( 'createClient skips a half-filled address', ! isset( $in['addresses'] ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
