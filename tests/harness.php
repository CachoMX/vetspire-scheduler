<?php
/**
 * Standalone E2E test harness (no WordPress needed).
 * Stubs the WP functions our classes use, then exercises the real
 * Vetspire API end-to-end, including a booking at TESTING LOCATION
 * that is deleted afterwards.
 *
 * Run: php tests/harness.php   (reads VETSPIRE_API_TOKEN from ../.env)
 */

error_reporting( E_ALL );

/* ---------- Minimal WP stubs ---------- */

define( 'ABSPATH', __DIR__ . '/' );

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

function wp_remote_post( $url, $args ) {
	$header_lines = '';
	foreach ( $args['headers'] as $k => $v ) { $header_lines .= "$k: $v\r\n"; }
	$ctx = stream_context_create( array(
		'http' => array(
			'method'        => 'POST',
			'header'        => $header_lines,
			'content'       => $args['body'],
			'timeout'       => isset( $args['timeout'] ) ? $args['timeout'] : 20,
			'ignore_errors' => true,
		),
	) );
	$body = @file_get_contents( $url, false, $ctx );
	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'stream request failed' );
	}
	$code = 0;
	if ( isset( $http_response_header ) ) {
		foreach ( $http_response_header as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) { $code = (int) $m[1]; }
		}
	}
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

/* Transient stubs: in-memory */
$GLOBALS['vsps_transients'] = array();
function get_transient( $key ) {
	return isset( $GLOBALS['vsps_transients'][ $key ] ) ? $GLOBALS['vsps_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl ) { $GLOBALS['vsps_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['vsps_transients'][ $key ] ); return true; }
function get_option( $key, $default = false ) { return $default; }
function apply_filters( $tag, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function vsps_get_settings() {
	return array( 'cache_ttl' => 60 );
}

require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-booking.php';

/* ---------- Config ---------- */

$env = parse_ini_file( __DIR__ . '/../.env' );
$token = isset( $env['VETSPIRE_API_TOKEN'] ) ? $env['VETSPIRE_API_TOKEN'] : '';
if ( '' === $token ) {
	fwrite( STDERR, "VETSPIRE_API_TOKEN missing in .env\n" );
	exit( 1 );
}

const TEST_LOCATION  = '23512'; // TESTING LOCATION (America/New_York)
const TEST_TYPE      = '9403';  // Wellness Appointment @ TESTING LOCATION, canBookOnline
const LIVE_LOCATION  = '23539'; // easyvet Iowa Colony — read-only checks
const LIVE_TYPE      = '5541';  // Wellness @ Iowa Colony
const TEST_EMAIL     = 'zz-test-scheduler@vetcelerator.com';

$api = new VSPS_Api( $token, 'https://api.vetspire.com/graphql' );

$pass = 0; $fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label" . ( $detail ? " — $detail" : '' ) . "\n"; }
	return $ok;
}
function detail( $thing ) {
	return is_wp_error( $thing ) ? $thing->get_error_message() : json_encode( $thing );
}

echo "== 1. Connectivity ==\n";
$data = $api->get_org_and_locations();
check( 'org query', ! is_wp_error( $data ) && ! empty( $data['org']['name'] ), detail( $data ) );

echo "== 2. Location lookup ==\n";
$loc = $api->get_location( TEST_LOCATION );
check( 'location + timezone', ! is_wp_error( $loc ) && 'America/New_York' === $loc['timezone'], detail( $loc ) );

echo "== 3. Bookable types (live location) ==\n";
$types = $api->get_bookable_types( LIVE_LOCATION );
check( 'returns only canBookOnline', ! is_wp_error( $types ) && count( $types ) > 0
	&& 0 === count( array_filter( $types, function ( $t ) { return empty( $t['canBookOnline'] ); } ) ), detail( $types ) );

echo "== 4. Available times (live location, read-only) ==\n";
$found_slot = null; $probe_date = null;
for ( $i = 1; $i <= 7 && ! $found_slot; $i++ ) {
	$probe_date = ( new DateTimeImmutable( "+{$i} days", new DateTimeZone( 'America/Chicago' ) ) )->format( 'Y-m-d' );
	$slots = $api->get_available_times( LIVE_LOCATION, LIVE_TYPE, $probe_date );
	if ( ! is_wp_error( $slots ) && count( $slots ) ) { $found_slot = $slots[0]; }
}
check( 'found live slots within 7 days', null !== $found_slot, 'no slots found' );
if ( $found_slot ) {
	// scheduleId can legitimately be null — the booking flow treats it as optional.
	check( 'slot has time + providerId',
		! empty( $found_slot['time'] ) && ! empty( $found_slot['providerId'] ),
		json_encode( $found_slot ) );
}

echo "== 5. Cache layer ==\n";
$calls = 0;
$cb = function () use ( &$calls ) { $calls++; return array( 'x' => 1 ); };
VSPS_Cache::remember( array( 'k1' ), $cb );
VSPS_Cache::remember( array( 'k1' ), $cb );
check( 'second call served from cache', 1 === $calls, "callback ran $calls times" );
$err_calls = 0;
$cb_err = function () use ( &$err_calls ) { $err_calls++; return new WP_Error( 'x', 'boom' ); };
VSPS_Cache::remember( array( 'k2' ), $cb_err );
VSPS_Cache::remember( array( 'k2' ), $cb_err );
check( 'WP_Error is never cached', 2 === $err_calls, "callback ran $err_calls times" );

echo "== 6. Client dedupe lookup ==\n";
$missing = $api->find_client_by_email( 'no-such-client-xyz-12345@example.com' );
check( 'unknown email returns null', null === $missing, detail( $missing ) );

echo "== 7. E2E booking at TESTING LOCATION ==\n";
$tomorrow = ( new DateTimeImmutable( '+1 day', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
$booking_args = array(
	'location_id'         => TEST_LOCATION,
	'appointment_type_id' => TEST_TYPE,
	'date'                => $tomorrow,
	'time'                => '10:30',
	'provider_id'         => '',
	'schedule_id'         => '',
	// TESTING LOCATION has no provider schedule → no availableTimes; skip the
	// slot re-check here (the re-check itself is tested in section 7b).
	'skip_slot_check'     => true,
	'notes'               => 'AUTOMATED TEST — safe to delete',
	'client'              => array(
		'given_name'  => 'ZZ-Test',
		'family_name' => 'Scheduler',
		'email'       => TEST_EMAIL,
		'phone'       => '+1 555 010 0100',
	),
	'patient'             => array(
		'name'    => 'TestPet',
		'species' => 'Canine',
		'breed'   => '',
		'sex'     => '',
	),
);

echo "== 7a. Guardrails (no writes should happen) ==\n";
// Non-online-bookable type must be rejected (Tech Appointment 5536 @ TESTING LOCATION).
$bad = $booking_args;
$bad['appointment_type_id'] = '5536';
$res = VSPS_Booking::book( $api, $bad );
check( 'non-bookable type rejected', is_wp_error( $res ) && 'vsps_type' === $res->get_error_code(), detail( $res ) );

// Forged time not in live availability must be rejected (live location, no skip flag).
$bad = $booking_args;
$bad['location_id']         = LIVE_LOCATION;
$bad['appointment_type_id'] = LIVE_TYPE;
$bad['date']                = $probe_date;
$bad['time']                = '03:07';
unset( $bad['skip_slot_check'] );
$res = VSPS_Booking::book( $api, $bad );
check( 'forged slot time rejected', is_wp_error( $res ) && 'vsps_slot' === $res->get_error_code(), detail( $res ) );

// DST spring-forward gap must be rejected (2:30 AM does not exist on 2027-03-14 ET).
$bad = $booking_args;
$bad['date'] = '2027-03-14';
$bad['time'] = '02:30';
$res = VSPS_Booking::book( $api, $bad );
check( 'DST-gap time rejected', is_wp_error( $res ) && 'vsps_datetime' === $res->get_error_code(), detail( $res ) );

echo "== 7b. Slot re-validation accepts a REAL live slot (dry check only) ==\n";
// We don't book at the live clinic; we just verify the matcher logic finds the real slot.
$live_slots = $api->get_available_times( LIVE_LOCATION, LIVE_TYPE, $probe_date );
$ref        = new ReflectionMethod( 'VSPS_Booking', 'match_slot' );
$ref->setAccessible( true );
$matched = $ref->invoke( null, $live_slots, $found_slot['time'], (string) $found_slot['providerId'] );
check( 'real slot matched (provider preferred)', null !== $matched && $matched['providerId'] === $found_slot['providerId'], detail( $matched ) );
$matched_any = $ref->invoke( null, $live_slots, $found_slot['time'], '' );
check( 'real slot matched (no provider hint)', null !== $matched_any, detail( $matched_any ) );

echo "== 7c. E2E booking (skip_slot_check @ TESTING LOCATION) ==\n";
$result = VSPS_Booking::book( $api, $booking_args );
$booked = check( 'booking created', ! is_wp_error( $result ) && ! empty( $result['appointment_id'] ), detail( $result ) );

$appt_id = null;
if ( $booked ) {
	$appt_id = $result['appointment_id'];
	echo "      appointment_id={$appt_id} start={$result['start']} client={$result['client_id']} patient={$result['patient_id']} existing_client=" . var_export( $result['existing_client'], true ) . "\n";

	// Verify the stored start matches the requested local time.
	$read = $api->request( 'query ($id: ID!) { appointment(id: $id) { id start status bookedOnline } }', array( 'id' => $appt_id ) );
	if ( ! is_wp_error( $read ) && ! empty( $read['appointment']['start'] ) ) {
		$stored = new DateTimeImmutable( $read['appointment']['start'], new DateTimeZone( 'UTC' ) );
		$local  = $stored->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i' );
		check( 'stored start == requested local 10:30', $local === $tomorrow . ' 10:30', "stored(local)=$local raw=" . $read['appointment']['start'] );
		check( 'bookedOnline flag set', ! empty( $read['appointment']['bookedOnline'] ), json_encode( $read['appointment'] ) );
	} else {
		check( 'read appointment back', false, detail( $read ) );
	}

	echo "== 8. Dedupe on second booking (same email) ==\n";
	$booking_args['time'] = '11:30';
	$result2 = VSPS_Booking::book( $api, $booking_args );
	$booked2 = check( 'second booking created', ! is_wp_error( $result2 ) && ! empty( $result2['appointment_id'] ), detail( $result2 ) );
	if ( $booked2 ) {
		check( 'client reused (existing_client=true)', true === $result2['existing_client'] );
		check( 'same client id', $result2['client_id'] === $result['client_id'] );
		check( 'patient reused by name', $result2['patient_id'] === $result['patient_id'] );
	}

	echo "== 9. Cleanup: delete test appointments ==\n";
	foreach ( array( $appt_id, $booked2 ? $result2['appointment_id'] : null ) as $id ) {
		if ( ! $id ) { continue; }
		$del = $api->request( 'mutation ($id: ID!) { deleteAppointment(id: $id) { id } }', array( 'id' => $id ) );
		check( "deleted appointment $id", ! is_wp_error( $del ), detail( $del ) );
	}
}

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit( $fail > 0 ? 1 : 0 );
