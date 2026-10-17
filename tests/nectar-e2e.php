<?php
/**
 * Nectar adapter test (no WordPress needed).
 *  1. Real Nectar sandbox, read-only: token, appointment types, email lookup.
 *  2. Slot logic (events → slots, the 24 h booking window) on fixed data.
 *  3. The shared booking flow with an in-memory Nectar-like platform
 *     (duplicate owners on one email, request-based booking).
 *  4. Booking-form fields limited to what Nectar can store.
 * Nothing is written to Nectar: its booking mutations are not open to server
 * tokens yet (Harris, 2026-10-06).
 *
 * Run: php tests/nectar-e2e.php   (reads NECTAR_CLIENT_ID / NECTAR_CLIENT_SECRET from ../.env.local)
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
function wp_remote_post( $url, $args ) {
	$header_lines = '';
	foreach ( $args['headers'] as $k => $v ) { $header_lines .= "$k: $v\r\n"; }
	$ctx  = stream_context_create( array( 'http' => array(
		'method' => 'POST', 'header' => $header_lines, 'content' => $args['body'],
		'timeout' => isset( $args['timeout'] ) ? $args['timeout'] : 20, 'ignore_errors' => true,
	) ) );
	$body = @file_get_contents( $url, false, $ctx );
	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'stream request failed' );
	}
	$code = 0;
	foreach ( isset( $http_response_header ) ? $http_response_header : array() as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) { $code = (int) $m[1]; }
	}
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
$GLOBALS['vsps_transients'] = array();
function get_transient( $key ) { return isset( $GLOBALS['vsps_transients'][ $key ] ) ? $GLOBALS['vsps_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['vsps_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['vsps_transients'][ $key ] ); return true; }
function vsps_get_settings() { return array( 'cache_ttl' => 60, 'pims' => 'nectar' ); }

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';
require __DIR__ . '/../includes/class-vsps-booking.php';
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/contract-recorder.php';

$passed = 0; $failed = 0;
function check( $label, $ok, $detail = '' ) {
	global $passed, $failed;
	if ( $ok ) { $passed++; echo "  PASS  $label\n"; }
	else { $failed++; echo "  FAIL  $label" . ( $detail ? " — $detail" : '' ) . "\n"; }
}

/* ---------- 1. Real sandbox (read-only) ---------- */

$env = parse_ini_file( __DIR__ . '/../.env.local', false, INI_SCANNER_RAW );
if ( empty( $env['NECTAR_CLIENT_ID'] ) || empty( $env['NECTAR_CLIENT_SECRET'] ) ) {
	fwrite( STDERR, "NECTAR_CLIENT_ID / NECTAR_CLIENT_SECRET missing in .env.local\n" );
	exit( 1 );
}
$nectar = new VSPS_Nectar( $env['NECTAR_CLIENT_ID'], $env['NECTAR_CLIENT_SECRET'], '', 'America/Los_Angeles' );

echo "== 1. Nectar sandbox ==\n";
check( 'platform is nectar', 'nectar' === $nectar->platform() );
$conn = $nectar->connection();
check( 'connection works', ! is_wp_error( $conn ) && 1 === count( $conn['locations'] ), is_wp_error( $conn ) ? $conn->get_error_message() : '' );
check( 'one location, id "clinic", with the fallback timezone', ! is_wp_error( $conn ) && 'clinic' === $conn['locations'][0]['id'] && 'America/Los_Angeles' === $conn['locations'][0]['timezone'] );
$bad = ( new VSPS_Nectar( 'nope', 'nope' ) )->connection();
check( 'wrong credentials are refused', is_wp_error( $bad ) && 'vsps_auth' === $bad->get_error_code() );

$types = $nectar->get_bookable_types( 'clinic' );
$names = is_wp_error( $types ) ? array() : array_column( $types, 'name' );
check( 'types: only online-enabled, no deposit', ! is_wp_error( $types ) && $names && ! in_array( 'Surgery', $names, true ) && ! in_array( 'Boarding', $names, true ), implode( ', ', $names ) );
check( 'types carry id/name/duration', ! is_wp_error( $types ) && isset( $types[0]['id'], $types[0]['name'], $types[0]['duration'] ) && $types[0]['duration'] > 0 );

$client = $nectar->find_client_by_email( '12PawsFoundation@GMAIL.com' );
check( 'email lookup is case-insensitive and exact', is_array( $client ) && '12pawsfoundation@gmail.com' === strtolower( $client['email'] ), is_wp_error( $client ) ? $client->get_error_message() : '' );
$owners = is_array( $client ) ? array_unique( array_column( $client['patients'], 'clientId' ) ) : array();
check( 'duplicate owners on one email are merged (pets tagged with their owner)', count( $owners ) >= 2, count( $owners ) . ' owner(s)' );
check( 'owner has a family id and phones', is_array( $client ) && '' !== $client['familyId'] && ! empty( $client['phoneNumbers'] ) );
check( 'a partial email does not match', null === $nectar->find_client_by_email( '12pawsfoundation@gmail.co' ) );
check( 'unknown email returns null', null === $nectar->find_client_by_email( 'zz-nobody-' . time() . '@example.com' ) );
// Nectar's REGEX filter takes the text literally: "." and "+" are not wildcards.
check( 'regex characters in an email are literal ("." is not a wildcard)', null === $nectar->find_client_by_email( '12pawsfoundation.gmail.com' ) );
check( 'regex characters in an email are literal ("+")', null === $nectar->find_client_by_email( '12pawsfoundation@gmai+l.com' ) );
$prod = ( new VSPS_Nectar( 'id', 'secret', '', '', 'production' ) )->connection();
check( 'production without a confirmed API address fails before sending the secret', is_wp_error( $prod ) && 'vsps_config' === $prod->get_error_code() );

$tz      = new DateTimeZone( 'America/Los_Angeles' );
$today   = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
$in_lead = $nectar->get_available_times( 'clinic', $types[0]['id'], $today );
check( 'today (inside the 24 h window) returns no times without asking Nectar', array() === $in_lead );
$later = $nectar->get_available_times( 'clinic', $types[0]['id'], ( new DateTimeImmutable( '+3 days', $tz ) )->format( 'Y-m-d' ) );
echo '      (availability in 3 days: ' . ( is_wp_error( $later ) ? 'not open yet → ' . $later->get_error_message() : count( $later ) . ' slots' ) . ")\n";

/* ---------- 2. Slot logic ---------- */

echo "== 2. Events → slots ==\n";
$not_before = new DateTimeImmutable( '2026-10-20 09:20:00', $tz );
$events     = array(
	array( 'type' => 'WORK_HOURS', 'scheduleId' => 's1', 'startDatetime' => '2026-10-20T09:00:00-07:00', 'endDatetime' => '2026-10-20T10:00:00-07:00', 'employee' => array( 'id' => 'e1', 'firstName' => 'Ann', 'lastName' => 'Lee' ) ),
	array( 'type' => 'TIME_OFF', 'scheduleId' => 's2', 'startDatetime' => '2026-10-20T11:00:00-07:00', 'endDatetime' => '2026-10-20T12:00:00-07:00', 'employee' => array( 'id' => 'e1' ) ),
	array( 'type' => 'WORK_HOURS', 'scheduleId' => 's3', 'startDatetime' => '2026-10-20T21:00:00Z', 'endDatetime' => '2026-10-20T21:20:00Z', 'employee' => array( 'id' => 'e2', 'firstName' => 'Bo' ) ),
	array( 'type' => 'WORK_HOURS', 'scheduleId' => 's4', 'startDatetime' => '2026-10-20T09:40:00-07:00', 'endDatetime' => '2026-10-20T10:00:00-07:00', 'employee' => array( 'id' => 'e1' ) ),
	array( 'type' => 'WORK_HOURS', 'scheduleId' => 's5', 'startDatetime' => '2026-10-21T09:00:00-07:00', 'endDatetime' => '2026-10-21T09:20:00-07:00', 'employee' => array( 'id' => 'e1' ) ),
);
$slots = VSPS_Nectar::events_to_slots( $events, $tz, '2026-10-20', $not_before, 20 );
$times = array_map( function ( $s ) { return $s['time'] . '/' . $s['providerId']; }, $slots );
check( 'a 1 h window is cut into 20 min slots, before the lead time dropped', in_array( '09:20/e1', $times, true ) && in_array( '09:40/e1', $times, true ) && ! in_array( '09:00/e1', $times, true ) );
check( 'TIME_OFF never becomes a slot', ! in_array( '11:00/e1', $times, true ) );
check( 'UTC times are shown in clinic time (21:00Z = 14:00 PDT)', in_array( '14:00/e2', $times, true ) );
check( 'a slot repeated by two events is listed once', 1 === count( array_keys( $times, '09:40/e1', true ) ) );
check( 'another day is not mixed in', 3 === count( $slots ), implode( ', ', $times ) );
check( 'provider name is first + last', 'Ann Lee' === $slots[0]['provider']['name'] && 's1' === $slots[0]['scheduleId'] );

/* ---------- 3. Shared booking flow with a Nectar-like platform ---------- */

echo "== 3. Booking flow (in-memory Nectar) ==\n";

class Fake_Nectar implements VSPS_Pims {
	public $created = array();
	public $clients = array();
	public function platform() { return 'nectar'; }
	public function connection() { return array( 'org' => 'Fake', 'locations' => array() ); }
	public function get_location( $id ) { return array( 'id' => $id, 'name' => 'Fake', 'timezone' => 'America/Chicago' ); }
	public function get_location_info( $id ) { return null; }
	public function get_bookable_types( $id ) { return array( array( 'id' => '65b814d3c444b3e7b0de1e82', 'name' => 'Wellness', 'duration' => 20 ) ); }
	public function get_available_times( $l, $t, $d ) {
		return array( array( 'time' => '10:00', 'providerId' => 'vet1', 'scheduleId' => 's1', 'provider' => array( 'id' => 'vet1', 'name' => 'Dr. A' ) ) );
	}
	public function find_client_by_email( $email ) {
		return isset( $this->clients[ strtolower( $email ) ] ) ? $this->clients[ strtolower( $email ) ] : null;
	}
	public function create_booking_client( array $args ) {
		$this->created[] = 'client';
		return array( 'id' => 'newclient', 'familyId' => 'fam-new', 'familyName' => $args['client']['family_name'], 'phoneNumbers' => array(), 'patients' => array() );
	}
	public function create_booking_patient( array $client, array $args ) {
		$this->created[] = 'patient@' . $client['familyId'];
		return array( 'id' => 'newpet', 'name' => $args['patient']['name'] );
	}
	public function create_booking_appointment( array $b ) {
		$this->created[] = 'appointment:' . $b['client_id'] . ':' . $b['patient_id'] . ':' . $b['provider_id'] . ':' . $b['start'];
		return array( 'id' => 'appt1', 'start' => $b['start'] );
	}
	public function get_referral_sources() { return array(); }
}

$base = array(
	'client_type' => 'new', 'pet_is_new' => true, 'phone_last4' => '',
	'location_id' => 'clinic', 'appointment_type_id' => '65b814d3c444b3e7b0de1e82',
	'date' => '2026-10-20', 'time' => '10:00', 'provider_id' => '', 'schedule_id' => '', 'notes' => '',
	'client'  => array( 'given_name' => 'Zz', 'family_name' => 'Test', 'email' => 'zz@example.com', 'phone' => '555 010 1234' ),
	'patient' => array( 'name' => 'Rex', 'species' => 'Canine', 'breed' => '', 'sex' => '' ),
);

$fake = new Fake_Nectar();
$res  = VSPS_Booking::book( $fake, $base );
check( 'new client: hex type id accepted, client + pet + request created', ! is_wp_error( $res ) && array( 'client', 'patient@fam-new' ) === array_slice( $fake->created, 0, 2 ), is_wp_error( $res ) ? $res->get_error_message() : implode( ' | ', $fake->created ) );
check( 'Nectar bookings are flagged as requests', ! is_wp_error( $res ) && true === $res['request'] );
check( 'provider comes from the live slot, start in clinic time', ! is_wp_error( $res ) && false !== strpos( end( $fake->created ), ':vet1:2026-10-20T10:00:00-05:00' ), end( $fake->created ) );

$fake          = new Fake_Nectar();
$fake->clients = array( 'dup@example.com' => array(
	'id' => 'ownerA', 'email' => 'dup@example.com', 'givenName' => 'A', 'familyName' => 'Dup', 'familyId' => 'famA',
	'phoneNumbers' => array( array( 'value' => '5550109999' ) ),
	'patients' => array(
		array( 'id' => 'petA', 'name' => 'Luna', 'isActive' => true, 'isDeceased' => false, 'clientId' => 'ownerA' ),
		array( 'id' => 'petB', 'name' => 'Max ', 'isActive' => true, 'isDeceased' => false, 'clientId' => 'ownerB' ),
	),
) );
$ret = array_merge( $base, array( 'client_type' => 'existing', 'pet_is_new' => false ) );
$ret['client']  = array( 'given_name' => '', 'family_name' => '', 'email' => 'DUP@example.com', 'phone' => '' );
$ret['patient'] = array( 'name' => 'max', 'species' => '', 'breed' => '', 'sex' => '' );
$res = VSPS_Booking::book( $fake, $ret );
check( 'returning client: the request goes to the owner the pet belongs to', ! is_wp_error( $res ) && 'ownerB' === $res['client_id'] && 'petB' === $res['patient_id'] && 1 === count( $fake->created ), is_wp_error( $res ) ? $res->get_error_message() : implode( ' | ', $fake->created ) );
check( 'matched pet keeps Nectar\'s own name', ! is_wp_error( $res ) && 'Max ' === $res['patient_name'] );

$fake       = new Fake_Nectar();
$fake->clients = array( 'dup@example.com' => array( 'id' => 'ownerA', 'familyId' => 'famA', 'phoneNumbers' => array( array( 'value' => '5550109999' ) ), 'patients' => array() ) );
$add            = array_merge( $ret, array( 'pet_is_new' => true, 'phone_last4' => '0000' ) );
$add['patient'] = array( 'name' => 'Nova', 'species' => 'Feline', 'breed' => '', 'sex' => '' );
$res            = VSPS_Booking::book( $fake, $add );
check( 'adding a pet with the wrong phone digits is refused, nothing created', is_wp_error( $res ) && 'vsps_verify' === $res->get_error_code() && ! $fake->created );
$add['phone_last4'] = '9999';
$res                = VSPS_Booking::book( $fake, $add );
check( 'right digits: the pet joins the existing family', ! is_wp_error( $res ) && 'patient@famA' === $fake->created[0] );

$forged         = $base;
$forged['time'] = '10:20';
$fake           = new Fake_Nectar();
$res            = VSPS_Booking::book( $fake, $forged );
check( 'a time Nectar did not offer is refused', is_wp_error( $res ) && 'vsps_slot' === $res->get_error_code() && ! $fake->created );

/* ---------- 4. Booking-form fields on Nectar ---------- */

echo "== 4. Fields ==\n";
$all = array();
foreach ( VSPS_Fields::REGISTRY as $key => $def ) {
	$all[ $key ] = array( 'show' => 1, 'req' => 1 );
}
$n = VSPS_Fields::resolve( array( 'pims' => 'nectar', 'fields' => $all ) );
$v = VSPS_Fields::resolve( array( 'pims' => 'vetspire', 'fields' => $all ) );
check( 'fields Nectar cannot store are never asked', ! $n['referral']['show'] && ! $n['pronouns']['show'] && ! $n['email_secondary']['show'] && ! $n['pet_notes']['show'] && ! $n['referral']['req'] );
check( 'fields Nectar stores stay as configured', $n['weight']['show'] && $n['weight']['req'] && $n['microchip']['show'] && $n['address']['show'] );
check( 'locked fields stay on for Nectar', $n['email']['show'] && $n['species']['req'] );
check( 'Vetspire keeps every field', $v['referral']['show'] && $v['pronouns']['show'] && $v['pet_notes']['show'] );
check( 'old installs (no pims stored) are Vetspire', $v === VSPS_Fields::resolve( array( 'fields' => $all ) ) );
$stored = VSPS_Fields::sanitize( $all, array(), 'vetspire' );
$posted = array( 'weight' => array( 'show' => 1 ) ); // the Nectar table posts only what Nectar stores
$saved  = VSPS_Fields::sanitize( $posted, $stored, 'nectar' );
check( 'saving on Nectar keeps the Vetspire-only field choices', 1 === $saved['referral']['show'] && 1 === $saved['pronouns']['req'] && 0 === $saved['microchip']['show'] && 0 === $saved['weight']['req'] );
check( 'ids: numbers and hex accepted, junk refused', '5541' === VSPS_Pims_Registry::sanitize_id( 5541 ) && '65b814d3c444b3e7b0de1e82' === VSPS_Pims_Registry::sanitize_id( '65b814d3c444b3e7b0de1e82' ) && '' === VSPS_Pims_Registry::sanitize_id( "1; DROP" ) && '' === VSPS_Pims_Registry::sanitize_id( array( 1 ) ) );
check( 'widget ids: Vetspire stays numeric', 23539 === VSPS_Pims_Registry::id_for_js( '23539' ) && 'clinic' === VSPS_Pims_Registry::id_for_js( 'clinic' ) );

echo "\n==== RESULT: $passed passed, $failed failed ====\n";
exit( $failed ? 1 : 0 );
