<?php
/**
 * Offline checks (QC 2026-11-06, Scheduler Edits List item 7): the booking
 * log's "new pet" says what the booking DID in the PIMS, not which form was
 * used. "I'm new" with a pet already on file books that pet: not a new pet.
 * No WordPress, no network.
 *
 * php tests/pet-new-unit.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

function current_time( $type, $gmt = 0 ) { return '2026-11-18 12:00:00'; }
function wp_generate_uuid4() { return 'uuid'; }
function vsps_get_settings() { return array( 'pims' => 'vetspire' ); }

/** Captures the row the log would write. */
class Fake_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 1;
	public $rows       = array();
	public function insert( $table, $row ) {
		$this->rows[] = $row;
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-events.php';
require __DIR__ . '/../includes/class-vsps-log.php';
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/../includes/class-vsps-booking.php';

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

$fails = 0;
function check( $label, $ok, $detail = '' ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  — ' . json_encode( $detail ) ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

function logged( array $args, array $result ) {
	global $wpdb;
	$wpdb->rows = array();
	VSPS_Log::record_booking( $args, array_merge( array( 'appointment_id' => '1', 'start' => '2026-11-20T10:00:00-05:00' ), $result ) );
	return end( $wpdb->rows );
}
$new_form       = array( 'client_type' => 'new', 'pet_is_new' => true, 'client' => array( 'email' => 'a@b.c' ), 'patient' => array( 'name' => 'Widget' ) );
$returning_add  = array( 'client_type' => 'existing', 'pet_is_new' => true, 'client' => array( 'email' => 'a@b.c' ), 'patient' => array( 'name' => 'Mojo' ) );
$returning_pick = array( 'client_type' => 'existing', 'pet_is_new' => false, 'client' => array( 'email' => 'a@b.c' ), 'patient' => array( 'name' => 'Mojo' ) );

$row = logged( $new_form, array( 'existing_client' => true, 'pet_created' => false ) );
check( '"I\'m new" + client and pet on file → existing client, NOT a new pet (Nicole\'s Widget)', 'existing' === $row['client_type'] && 0 === $row['pet_is_new'], $row );
$row = logged( $new_form, array( 'existing_client' => false, 'pet_created' => true ) );
check( 'brand-new client → new client, new pet', 'new' === $row['client_type'] && 1 === $row['pet_is_new'], $row );
$row = logged( $new_form, array( 'existing_client' => true, 'pet_created' => true ) );
check( '"I\'m new" + client on file, pet not on file → existing client, new pet', 'existing' === $row['client_type'] && 1 === $row['pet_is_new'], $row );
$row = logged( $returning_add, array( 'existing_client' => true, 'pet_created' => false ) );
check( 'returning "add a pet" with a name already on file → not a new pet (Mojo)', 0 === $row['pet_is_new'], $row );
$row = logged( $returning_add, array( 'existing_client' => true, 'pet_created' => true ) );
check( 'returning "add a pet" that was created → new pet', 1 === $row['pet_is_new'], $row );
$row = logged( $returning_pick, array( 'existing_client' => true, 'pet_created' => false ) );
check( 'returning, picked a pet chip → not a new pet', 0 === $row['pet_is_new'], $row );
$row = logged( $new_form, array( 'existing_client' => false ) );
check( 'a result without pet_created (older adapter) falls back to the form', 1 === $row['pet_is_new'], $row );

global $wpdb;
$wpdb->rows = array();
VSPS_Log::record_failure( $new_form, 'vsps_slot', 'gone' );
check( 'a failed attempt still records what the form asked for', 1 === end( $wpdb->rows )['pet_is_new'] );

/* ---------- book() itself, with a fake PIMS ---------- */

echo "== book() reports pet_created ==\n";
class Stub_Pims implements VSPS_Pims {
	public $client;
	public $created_patients = 0;
	public function __construct( $client ) { $this->client = $client; }
	public function platform() { return 'vetspire'; }
	public function connection() { return array(); }
	public function get_location( $id ) { return array( 'id' => $id, 'timezone' => 'America/Chicago' ); }
	public function get_location_info( $id ) { return array(); }
	public function get_bookable_types( $id ) { return array( array( 'id' => '7', 'name' => 'Wellness', 'duration' => 30 ) ); }
	public function get_available_times( $l, $t, $d ) { return array(); }
	public function find_client_by_email( $email ) { return $this->client; }
	public function create_booking_client( array $args ) { return array( 'id' => '500', 'patients' => array() ); }
	public function create_booking_patient( array $client, array $args ) { $this->created_patients++; return array( 'id' => '900', 'name' => $args['patient']['name'] ); }
	public function create_booking_appointment( array $b ) { return array( 'id' => '42', 'start' => $b['start'] ); }
	public function get_referral_sources() { return array(); }
}
function booking( $client_type, $pet ) {
	return array(
		'location_id' => '1', 'appointment_type_id' => '7', 'date' => '2026-11-20', 'time' => '10:00',
		'provider_id' => '', 'schedule_id' => '', 'skip_slot_check' => true, 'notes' => '',
		'client_type' => $client_type, 'pet_is_new' => true, 'phone_last4' => '',
		'client'  => array( 'given_name' => 'N', 'family_name' => 'T', 'email' => 'n@v.c', 'phone' => '865 855 9494' ),
		'patient' => array( 'name' => $pet, 'species' => 'Canine', 'breed' => '', 'sex' => '', 'age' => '', 'age_months' => '', 'neutered' => '' ),
	);
}
$on_file = array( 'id' => '200', 'phoneNumbers' => array( array( 'value' => '(865) 855-9494' ) ), 'addresses' => array( array( 'id' => '1' ) ),
	'patients' => array( array( 'id' => '300', 'name' => 'Widget ', 'isActive' => true, 'isDeceased' => false ) ) );

$api = new Stub_Pims( $on_file );
$res = VSPS_Booking::book( $api, booking( 'new', 'widget' ) );
check( '"I\'m new" + pet on file → books pet 300, pet_created false, no patient created', is_array( $res ) && '300' === $res['patient_id'] && false === $res['pet_created'] && 0 === $api->created_patients, $res );
$api = new Stub_Pims( $on_file );
$res = VSPS_Booking::book( $api, booking( 'new', 'Rex' ) );
check( '"I\'m new" + client on file, new pet (phone matches) → pet_created true', is_array( $res ) && '900' === $res['patient_id'] && true === $res['pet_created'] && 1 === $api->created_patients, $res );
$api = new Stub_Pims( null );
$res = VSPS_Booking::book( $api, booking( 'new', 'Rex' ) );
check( 'brand-new client → pet_created true, existing_client false', is_array( $res ) && true === $res['pet_created'] && false === $res['existing_client'], $res );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
