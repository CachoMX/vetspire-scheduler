<?php
/**
 * E2E test of the admin-schedule API flow at TESTING LOCATION:
 * book online → appears in get_appointments → confirm → reschedule →
 * cancel → delete. Run: php tests/admin-flow.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_remote_post( $url, $args ) {
	$h = '';
	foreach ( $args['headers'] as $k => $v ) { $h .= "$k: $v\r\n"; }
	$ctx  = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => $h, 'content' => $args['body'], 'timeout' => 20, 'ignore_errors' => true ) ) );
	$body = @file_get_contents( $url, false, $ctx );
	if ( false === $body ) { return new WP_Error( 'http', 'request failed' ); }
	$code = 0;
	foreach ( ( isset( $http_response_header ) ? $http_response_header : array() ) as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) { $code = (int) $m[1]; }
	}
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function delete_transient( $k ) { return true; }
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $t, $v ) { return $v; }
function __( $t, $d = null ) { return $t; }
function vsps_get_settings() { return array( 'cache_ttl' => 60 ); }

require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-booking.php';

$env = parse_ini_file( __DIR__ . '/../.env' );
$api = new VSPS_Api( $env['VETSPIRE_API_TOKEN'], 'https://api.vetspire.com/graphql' );

$pass = 0; $fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label" . ( $detail ? " — $detail" : '' ) . "\n"; }
	return $ok;
}
function d( $t ) { return is_wp_error( $t ) ? $t->get_error_message() : json_encode( $t ); }

$tz       = new DateTimeZone( 'America/New_York' );
$date     = ( new DateTimeImmutable( '+2 days', $tz ) )->format( 'Y-m-d' );
$loc      = '23512';

echo "== 1. create online booking ==\n";
$result = VSPS_Booking::book( $api, array(
	'location_id'         => $loc,
	'appointment_type_id' => '9403',
	'date'                => $date,
	'time'                => '09:00',
	'provider_id'         => '',
	'schedule_id'         => '',
	'skip_slot_check'     => true,
	'notes'               => 'ADMIN-FLOW TEST — safe to delete',
	'client'              => array( 'given_name' => 'ZZ-Test', 'family_name' => 'Scheduler', 'email' => 'zz-test-scheduler@vetcelerator.com', 'phone' => '+1 555 010 0100' ),
	'patient'             => array( 'name' => 'TestPet', 'species' => 'Canine', 'breed' => '', 'sex' => '' ),
) );
if ( ! check( 'booking created', ! is_wp_error( $result ), d( $result ) ) ) { exit( 1 ); }
$id = $result['appointment_id'];

echo "== 2. appears in day view (get_appointments) ==\n";
$start = new DateTimeImmutable( $date . ' 00:00', $tz );
$appts = $api->get_appointments( $loc, $start->format( 'c' ), $start->modify( '+1 day' )->format( 'c' ) );
$mine  = null;
if ( ! is_wp_error( $appts ) ) {
	foreach ( $appts as $a ) { if ( (string) $a['id'] === (string) $id ) { $mine = $a; } }
}
check( 'found in day appointments', null !== $mine, d( $appts ) );
if ( $mine ) {
	check( 'bookedOnline flag readable', ! empty( $mine['bookedOnline'] ), json_encode( $mine['bookedOnline'] ) );
	check( 'client + pet data present', 'TestPet' === $mine['patient']['name'] && 'ZZ-Test' === $mine['patient']['client']['givenName'], json_encode( $mine['patient'] ) );
}

echo "== 3. confirm ==\n";
$up = $api->update_appointment( $id, array( 'isConfirmed' => true ) );
check( 'isConfirmed set', ! is_wp_error( $up ) && ! empty( $up['isConfirmed'] ), d( $up ) );

echo "== 4. reschedule (+1 hour) ==\n";
$new_start = new DateTimeImmutable( $date . ' 10:00', $tz );
$up = $api->update_appointment( $id, array( 'start' => $new_start->format( 'c' ) ) );
$ok = ! is_wp_error( $up ) && isset( $up['start'] );
if ( $ok ) {
	$stored = ( new DateTimeImmutable( $up['start'] ) )->setTimezone( $tz )->format( 'H:i' );
	check( 'start moved to 10:00 local', '10:00' === $stored, $up['start'] );
} else {
	check( 'reschedule', false, d( $up ) );
}

echo "== 5. cancel ==\n";
$up = $api->update_appointment( $id, array( 'status' => 'CANCELLED' ) );
check( 'status CANCELLED', ! is_wp_error( $up ) && 'CANCELLED' === $up['status'], d( $up ) );

echo "== 6. cleanup (delete) ==\n";
$del = $api->request( 'mutation ($id: ID!) { deleteAppointment(id: $id) { id } }', array( 'id' => $id ) );
check( 'deleted', ! is_wp_error( $del ), d( $del ) );

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit( $fail > 0 ? 1 : 0 );
