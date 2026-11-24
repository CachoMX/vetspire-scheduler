<?php
/**
 * Offline checks for Nicole's QC round of 2026-11-24:
 *  - adding a pet to an account is proved with the PRIMARY phone only, and
 *    the pet goes to the owner record that number is on ("Radio" was added
 *    with the emergency number);
 *  - a failed check writes nothing;
 *  - Reason for visit is always required;
 *  - a required phone has ten digits;
 *  - the hub is told the clinic's CURRENT timezone, not the first one saved;
 *  - "Pet type" can come from the clinic's own species list;
 *  - a client already on file gets the blanks of their record filled, never
 *    a value replaced.
 * No WordPress, no network.
 *
 * php tests/qc-1124-unit.php
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

// Requests to the PIMS are recorded and answered from a queue, never sent.
$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array();
function wp_remote_post( $url, $args ) {
	$GLOBALS['vsps_sent'][] = json_decode( $args['body'], true );
	$reply                  = array_shift( $GLOBALS['vsps_replies'] );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( null === $reply ? array( 'data' => array() ) : $reply ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
$GLOBALS['vsps_transients'] = array();
function get_transient( $key ) { return isset( $GLOBALS['vsps_transients'][ $key ] ) ? $GLOBALS['vsps_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['vsps_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['vsps_transients'][ $key ] ); return true; }
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['vsps_settings'] = array( 'cache_ttl' => 60, 'pims' => 'nectar', 'default_location' => 'clinic' );
$GLOBALS['vsps_test_api'] = null;
function vsps_get_settings() { return $GLOBALS['vsps_settings']; }
$GLOBALS['vsps_options'] = array();
function get_option( $key, $default = false ) { return isset( $GLOBALS['vsps_options'][ $key ] ) ? $GLOBALS['vsps_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['vsps_options'][ $key ] = $value; return true; }
function vsps_api() { return $GLOBALS['vsps_test_api']; }

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';
require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-booking.php';
require __DIR__ . '/../includes/class-vsps-hub.php';

$fails = 0;
function check( $label, $ok, $detail = '' ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  — ' . json_encode( $detail ) ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

/** A platform in memory: records every write. */
class Fake_Pims implements VSPS_Pims {
	public $created = array();
	public $client  = null;
	public $name;
	public function __construct( $name = 'nectar' ) { $this->name = $name; }
	public function platform() { return $this->name; }
	public function connection() { return array( 'org' => 'Fake', 'locations' => array() ); }
	public function get_location( $id ) { return array( 'id' => $id, 'name' => 'Fake', 'timezone' => 'America/Chicago' ); }
	public function get_location_info( $id ) { return null; }
	public function get_bookable_types( $id ) { return array( array( 'id' => 't1', 'name' => 'Wellness', 'duration' => 20 ) ); }
	public function get_available_times( $l, $t, $d ) {
		return array( array( 'time' => '10:00', 'providerId' => 'vet1', 'scheduleId' => 's1', 'provider' => array( 'id' => 'vet1', 'name' => 'Dr. A' ) ) );
	}
	public function find_client_by_email( $email ) { return $this->client; }
	public function create_booking_client( array $args ) {
		$this->created[] = 'client';
		return array( 'id' => 'new', 'familyId' => 'fam-new', 'phoneNumbers' => array(), 'patients' => array() );
	}
	public function create_booking_patient( array $client, array $args ) {
		$this->created[] = 'patient@' . ( isset( $client['familyId'] ) ? $client['familyId'] : $client['id'] );
		return array( 'id' => 'newpet', 'name' => $args['patient']['name'] );
	}
	public function create_booking_appointment( array $b ) {
		$this->created[] = 'appointment:' . $b['client_id'] . ':' . $b['patient_id'];
		return array( 'id' => 'appt1', 'start' => $b['start'] );
	}
	public function get_referral_sources() { return array(); }
}

/** Vetspire-like: says which number is preferred through primary_phones(). */
class Fake_Vetspire extends Fake_Pims {
	public $primary = null;
	public function __construct() { parent::__construct( 'vetspire' ); }
	public function primary_phones( array $client ) { return $this->primary; }
}

function add_pet_args( $last4, $client_type = 'existing', $phone = '' ) {
	return array(
		'client_type' => $client_type, 'pet_is_new' => true, 'phone_last4' => $last4,
		'location_id' => 'clinic', 'appointment_type_id' => 't1',
		'date' => '2026-12-01', 'time' => '10:00', 'provider_id' => '', 'schedule_id' => '', 'notes' => 'Checkup',
		'client'  => array( 'given_name' => 'Zz', 'family_name' => 'Test', 'email' => 'dup@example.com', 'phone' => $phone ),
		'patient' => array( 'name' => 'Radio', 'species' => 'Feline', 'breed' => '', 'sex' => '' ),
	);
}

/* ---------- 1. Nectar: the lookup says which number is primary ---------- */

echo "== Nectar lookup: primary and emergency numbers, per owner ==\n";
$node = function ( $id, $family, $home, $work ) {
	return array( 'node' => array(
		'id' => $id, 'firstName' => 'Zz', 'lastName' => 'Test', 'emailH' => 'dup@example.com', 'emailW' => '',
		'phoneH' => $home, 'phoneW' => $work, 'clientInfo' => array( 'familyId' => $family, 'clientStatus' => 'ACTIVE' ),
	) );
};
$GLOBALS['vsps_replies'] = array(
	array( 'data' => array( 'clientsPaginated' => array( 'edges' => array(
		$node( 'ownerA', 'famA', '8658559493', '8658559494' ),
		$node( 'ownerB', 'famB', '8628551111', '' ),
		$node( 'ownerC', 'famC', '', '8658552222' ),
	) ) ) ),
	array( 'data' => array( 'clientsPaginated' => array( 'edges' => array() ) ) ),
	array( 'data' => array( 'clientPatients' => array( array( 'id' => 'p1', 'firstName' => 'Chex', 'status' => 'ACTIVE' ) ) ) ),
	array( 'data' => array( 'clientPatients' => array() ) ),
	array( 'data' => array( 'clientPatients' => array() ) ),
);
$nectar = new VSPS_Nectar( 'id', 'secret', '', 'America/Chicago' );
// The first request signs in: its reply goes first.
$GLOBALS['vsps_replies'] = array_merge( array( array( 'access_token' => 'tok', 'expires_in' => 3600 ) ), $GLOBALS['vsps_replies'] );
$found                   = $nectar->find_client_by_email( 'dup@example.com' );
$by_value = array();
foreach ( is_array( $found ) ? $found['phoneNumbers'] : array() as $phone ) {
	$by_value[ $phone['value'] ] = $phone;
}
check( 'home phone is primary, emergency phone is not', ! empty( $by_value['8658559493']['primary'] ) && isset( $by_value['8658559494'] ) && false === $by_value['8658559494']['primary'], $by_value );
check( 'each number keeps its own owner and family', 'ownerB' === $by_value['8628551111']['clientId'] && 'famB' === $by_value['8628551111']['familyId'] && 'ownerC' === $by_value['8658552222']['clientId'], $by_value );

/* ---------- 2. Booking: primary phone only, on the verified owner ---------- */

echo "== adding a pet: primary phone of the right owner ==\n";
$three_owners = $found;

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '9494' ) );
check( 'emergency number is refused (the "Radio" case)', is_wp_error( $res ) && 'vsps_verify' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : $fake->created );
check( 'a refused check writes nothing: no pet, no appointment', array() === $fake->created, $fake->created );
check( 'the error names the primary phone', is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'last 4 digits of the primary phone number' ) );

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '2222' ) );
check( "another owner's emergency number is refused too", is_wp_error( $res ) && array() === $fake->created );

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '0992' ) );
check( 'a number on no record is refused', is_wp_error( $res ) && 'vsps_verify' === $res->get_error_code() && array() === $fake->created );

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '9493' ) );
check( 'primary number of the first owner: pet joins that family', ! is_wp_error( $res ) && array( 'patient@famA', 'appointment:ownerA:newpet' ) === $fake->created, is_wp_error( $res ) ? $res->get_error_code() : $fake->created );

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '1111' ) );
check( 'primary number of the second owner: pet and appointment go to THAT owner', ! is_wp_error( $res ) && array( 'patient@famB', 'appointment:ownerB:newpet' ) === $fake->created && 'ownerB' === $res['client_id'], is_wp_error( $res ) ? $res->get_error_code() : $fake->created );

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '', 'new', '(865) 855-9494' ) );
check( 'full form with the emergency number typed: refused, nothing written', is_wp_error( $res ) && 'vsps_verify' === $res->get_error_code() && array() === $fake->created );

$fake         = new Fake_Pims();
$fake->client = $three_owners;
$res          = VSPS_Booking::book( $fake, add_pet_args( '', 'new', '+1 (865) 855-9493' ) );
check( 'full form with the primary number typed: accepted, no second client', ! is_wp_error( $res ) && array( 'patient@famA', 'appointment:ownerA:newpet' ) === $fake->created, is_wp_error( $res ) ? $res->get_error_code() : $fake->created );

echo "== platforms that flag the preferred number separately (Vetspire) ==\n";
$on_file = array(
	'id' => '77', 'email' => 'dup@example.com',
	'phoneNumbers' => array( array( 'value' => '555-010-1111' ), array( 'value' => '555-010-2222' ) ),
	'patients' => array(),
);
$vet          = new Fake_Vetspire();
$vet->client  = $on_file;
$vet->primary = array( array( 'value' => '555-010-2222' ) );
$res          = VSPS_Booking::book( $vet, add_pet_args( '1111' ) );
check( 'a number that is not the preferred one is refused', is_wp_error( $res ) && array() === $vet->created );
$res = VSPS_Booking::book( $vet, add_pet_args( '2222' ) );
check( 'the preferred number is accepted', ! is_wp_error( $res ) && array( 'patient@77', 'appointment:77:newpet' ) === $vet->created, is_wp_error( $res ) ? $res->get_error_code() : $vet->created );

$vet          = new Fake_Vetspire();
$vet->client  = $on_file;
$vet->primary = null; // Vetspire could not say (error, or nothing flagged)
$res          = VSPS_Booking::book( $vet, add_pet_args( '1111' ) );
check( 'no preferred number known: any number on file still works (no lock-out)', ! is_wp_error( $res ) && 2 === count( $vet->created ) );
$vet          = new Fake_Vetspire();
$vet->client  = array_merge( $on_file, array( 'phoneNumbers' => array() ) );
$res          = VSPS_Booking::book( $vet, add_pet_args( '1111' ) );
check( 'no phone on file at all: refused (fail closed)', is_wp_error( $res ) && 'vsps_verify' === $res->get_error_code() && array() === $vet->created );

echo "== Vetspire adapter: preferred numbers ==\n";
$api                     = new VSPS_API( 'token', 'https://example.invalid/graphql' );
$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array( array( 'data' => array( 'clients' => array(
	array( 'id' => '12', 'phoneNumbers' => array( array( 'value' => '999', 'preferred' => true ) ) ),
	array( 'id' => '77', 'phoneNumbers' => array( array( 'value' => '555-010-1111', 'preferred' => false ), array( 'value' => '555-010-2222', 'preferred' => true ) ) ),
) ) ) );
$primary = $api->primary_phones( $on_file );
check( 'only the preferred number of THIS client comes back', array( array( 'value' => '555-010-2222' ) ) === $primary, $primary );
$GLOBALS['vsps_replies'] = array( array( 'errors' => array( array( 'message' => 'Cannot query field "preferred"' ) ) ) );
check( 'a refused query answers null (the caller falls back)', null === $api->primary_phones( $on_file ) );
$GLOBALS['vsps_replies'] = array( array( 'data' => array( 'clients' => array( array( 'id' => '77', 'phoneNumbers' => array( array( 'value' => '555-010-1111', 'preferred' => false ) ) ) ) ) ) );
check( 'nothing flagged answers null', null === $api->primary_phones( $on_file ) );

/* ---------- 3. Reason for visit, phone ---------- */

echo "== reason for visit is always required ==\n";
$off = array();
foreach ( VSPS_Fields::REGISTRY as $key => $def ) {
	$off[ $key ] = array( 'show' => 0, 'req' => 0 );
}
foreach ( array( 'vetspire', 'nectar' ) as $platform ) {
	$r = VSPS_Fields::resolve( array( 'pims' => $platform, 'fields' => $off ) );
	check( "$platform: required even when saved as optional", $r['reason']['show'] && $r['reason']['req'] );
	$r = VSPS_Fields::resolve( array( 'pims' => $platform, 'fields' => $off ), 'a' );
	check( "$platform: required in variant a too", $r['reason']['show'] && $r['reason']['req'] );
	$saved = VSPS_Fields::sanitize( array(), array(), $platform );
	check( "$platform: Settings always stores it as required", 1 === $saved['reason']['req'] );
}
$args = array(
	'client_type' => 'existing', 'pet_is_new' => false, 'notes' => '',
	'client' => array( 'address' => array( 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal' => '' ) ),
	'patient' => array(),
);
$err = VSPS_Fields::enforce( $args, VSPS_Fields::resolve( array( 'pims' => 'nectar', 'fields' => $off ) ) );
$data = $err instanceof WP_Error ? $err->get_error_data() : array();
check( 'a booking without a reason is refused at that field', $err instanceof WP_Error && array( 'reason' ) === $data['fields'], $data );

echo "== emergency phone label ==\n";
check( 'Settings and form say "Emergency phone"', 'Emergency phone' === VSPS_Fields::REGISTRY['phone_alt'][1] );

echo "== a required phone has ten digits ==\n";
foreach ( array( '8658559494', '(865) 855-9494', '865.855.9494', '+1 865 855 9494', '1-865-855-9494' ) as $ok ) {
	check( "accepted: $ok", VSPS_Fields::phone_ok( $ok ) );
}
foreach ( array( '', 'a', '855-9494', '858559494', '86585594945', '+44 20 7946 0958 1' ) as $bad ) {
	check( 'refused: ' . ( '' === $bad ? '(empty)' : $bad ), ! VSPS_Fields::phone_ok( $bad ) );
}

echo "== pet type from the PIMS ==\n";
check( 'the same species typed twice is offered once, not in capitals', array( 'Feline', 'Canine', 'Mouse', 'Bovine' ) === VSPS_Fields::clean_species( array( 'FELINE', 'Canine', 'Mouse', 'Feline', ' ', 'Bovine', 'canine' ) ) );
check( 'a species is matched to the PIMS spelling', 'Feline' === VSPS_Fields::match_species( ' feline ', array( 'Canine', 'Feline' ) ) && '' === VSPS_Fields::match_species( 'Dragon', array( 'Canine', 'Feline' ) ) );

$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array( array( 'data' => array( 'publicSettings' => array( 'directOnlineSchedulingOptions' => array( 'speciesAllowed' => array( 'FELINE', 'Canine', 'Mouse', 'Feline', 'Bovine' ) ) ) ) ) );
$list                    = $nectar->get_species();
check( 'Nectar: Direct Online Booking → Species Allowed', array( 'FELINE', 'Canine', 'Mouse', 'Feline', 'Bovine' ) === $list && false !== strpos( $GLOBALS['vsps_sent'][0]['query'], 'publicSettings' ), $list );

check( 'Vetspire has no species list yet: its form keeps Dog / Cat / Other', ! method_exists( $api, 'get_species' ) );

$GLOBALS['vsps_test_api'] = $nectar;
$GLOBALS['vsps_replies']  = array( array( 'data' => array( 'publicSettings' => array( 'directOnlineSchedulingOptions' => array( 'speciesAllowed' => array( 'Canine' ) ) ) ) ) );
check( 'setting off: Dog / Cat / Other, the PIMS is not asked', array() === VSPS_Fields::species_options() && 1 === count( $GLOBALS['vsps_replies'] ) );
$GLOBALS['vsps_settings']['species_from_pims'] = 1;
check( 'setting on: the clinic list', array( 'Canine' ) === VSPS_Fields::species_options() );
check( 'cached: a second page view does not ask again', array( 'Canine' ) === VSPS_Fields::species_options() && 0 === count( $GLOBALS['vsps_replies'] ) );
// Forget the cached list, keep the sign-in.
$GLOBALS['vsps_transients'] = array_filter( $GLOBALS['vsps_transients'], function ( $k ) { return false !== strpos( $k, 'nectar_tok_' ); }, ARRAY_FILTER_USE_KEY );
$GLOBALS['vsps_replies']    = array( array( 'errors' => array( array( 'message' => '{"status_code":403,"context":"Forbidden"}' ) ) ) );
check( 'the PIMS refuses: the form falls back to Dog / Cat / Other', array() === VSPS_Fields::species_options() );
$GLOBALS['vsps_settings']['species_from_pims'] = 0;
$GLOBALS['vsps_test_api']                      = null;

echo "== blanks filled for a client already on file ==\n";
class Fake_Filler extends Fake_Pims {
	public $fills = array();
	public function fill_missing_details( $client_id, $patient_id, array $args ) {
		$this->fills[] = $client_id . ':' . $patient_id;
		return 'filled';
	}
}
$with_pet             = $three_owners;
$with_pet['patients'] = array( array( 'id' => 'petB', 'name' => 'Match', 'isActive' => true, 'isDeceased' => false, 'clientId' => 'ownerB' ) );
$book_match           = function ( $client_type, $phone, $pet = 'match' ) use ( $with_pet ) {
	$fake         = new Fake_Filler();
	$fake->client = $with_pet;
	$args         = add_pet_args( '', $client_type, $phone );
	$args['patient']['name'] = $pet;
	if ( 'existing' === $client_type ) {
		$args['pet_is_new'] = false;
	}
	$res = VSPS_Booking::book( $fake, $args );
	return array( is_wp_error( $res ) ? $res->get_error_code() : 'booked', $fake->fills );
};
check( "full form, the pet owner's primary phone: that owner and that pet", array( 'booked', array( 'ownerB:petB' ) ) === $book_match( 'new', '(862) 855-1111' ), $book_match( 'new', '(862) 855-1111' ) );
check( "another owner's primary phone: booked, nothing filled", array( 'booked', array() ) === $book_match( 'new', '865-855-9493' ) );
check( 'an emergency phone: booked, nothing filled', array( 'booked', array() ) === $book_match( 'new', '865-855-9494' ) );
check( 'returning-client flow (no details typed): nothing filled', array( 'booked', array() ) === $book_match( 'existing', '' ) );
check( 'a pet this booking created is not "filled" (it was just written whole)', array( 'booked', array( 'ownerA:' ) ) === $book_match( 'new', '865-855-9493', 'Radio' ) );

$typed = array(
	'client'  => array( 'phone_alt' => '(865) 855-9493', 'owner_dob' => '1999-09-09', 'address' => array( 'line1' => '1 Main St', 'line2' => '', 'city' => 'Estero', 'state' => 'Florida', 'postal' => '33928' ) ),
	'patient' => array( 'sex' => 'MALE', 'neutered' => 'yes', 'birth_date' => '2020-05-06', 'color' => 'Black', 'microchip' => '', 'weight' => 20.5, 'weight_unit' => 'LB' ),
);
$sent_inputs = function () {
	$out = array();
	foreach ( $GLOBALS['vsps_sent'] as $call ) {
		if ( 0 === strpos( ltrim( $call['query'] ), 'mutation' ) ) {
			$out[] = $call['variables'];
		}
	}
	return $out;
};

$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array(
	array( 'data' => array( 'client' => array( 'id' => 'ownerB', 'phoneW' => '', 'dateOfBirth' => '1980-01-01T12:00:00+00:00', 'street' => null, 'city' => null, 'state' => null, 'zipCode' => null ) ) ),
	array( 'data' => array( 'updateClient' => array( 'id' => 'ownerB' ) ) ),
	array( 'data' => array( 'patient' => array( 'id' => 'petB', 'gender' => 'UNKNOWN', 'spayNeuter' => null, 'dateOfBirth' => '2019-01-01T12:00:00+00:00', 'color' => '', 'microchip' => null, 'weightLbs' => null, 'weightKg' => null ) ) ),
	array( 'data' => array( 'updatePatient' => array( 'id' => 'petB' ) ) ),
);
$result = $nectar->fill_missing_details( 'ownerB', 'petB', $typed );
$writes = $sent_inputs();
check( 'Nectar: blanks are filled', 'filled' === $result && 2 === count( $writes ), $writes );
check( 'Nectar client: emergency phone and address added, the birth date on file kept', isset( $writes[0]['input'] ) && array( 'id' => 'ownerB', 'phoneW' => '8658559493', 'street' => '1 Main St', 'city' => 'Estero', 'state' => 'FL', 'zipCode' => '33928' ) === $writes[0]['input'], $writes );
check( 'Nectar pet: sex, neuter, color, weight added, the birth date on file kept', isset( $writes[1]['input'] ) && array( 'patientId' => 'petB', 'gender' => 'MALE', 'spayNeuter' => true, 'color' => 'Black', 'weightLbs' => 20.5 ) === $writes[1]['input'], $writes );

$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array(
	array( 'data' => array( 'client' => array( 'id' => 'ownerB', 'phoneW' => '5550100000', 'dateOfBirth' => '1980-01-01T12:00:00+00:00', 'street' => '9 Old Rd', 'city' => null, 'state' => null, 'zipCode' => null ) ) ),
	array( 'data' => array( 'patient' => array( 'id' => 'petB', 'gender' => 'FEMALE', 'spayNeuter' => false, 'dateOfBirth' => '2019-01-01T12:00:00+00:00', 'color' => 'White', 'microchip' => null, 'weightLbs' => 12.0, 'weightKg' => null ) ) ),
);
$result = $nectar->fill_missing_details( 'ownerB', 'petB', $typed );
check( 'Nectar: a record with every value on file gets no write at all', 'none' === $result && array() === $sent_inputs(), $sent_inputs() );

$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array(
	array( 'data' => array( 'client' => array( 'id' => '77', 'dateOfBirth' => null ) ) ),
	array( 'data' => array( 'updateClient' => array( 'id' => '77' ) ) ),
	array( 'data' => array( 'patient' => array( 'id' => '9', 'sex' => 'FEMALE', 'neutered' => null, 'birthDate' => null, 'color' => 'White', 'microchip' => '', 'latestWeight' => null ) ) ),
	array( 'data' => array( 'updatePatient' => array( 'id' => '9' ) ) ),
	array( 'data' => array( 'recordPatientWeight' => array( 'id' => '1' ) ) ),
);
$result = $api->fill_missing_details( '77', '9', $typed );
$writes = $sent_inputs();
check( 'Vetspire: owner birth date added', 'filled' === $result && isset( $writes[0] ) && array( 'id' => '77', 'input' => array( 'dateOfBirth' => '1999-09-09' ) ) === $writes[0], $writes );
check( 'Vetspire pet: neuter and birth date added; sex and color on file kept', isset( $writes[1] ) && array( 'id' => '9', 'input' => array( 'neutered' => true, 'birthDate' => '2020-05-06' ) ) === $writes[1], $writes );
check( 'Vetspire pet: a first weight is recorded', isset( $writes[2]['weight'] ) && '20.5' === $writes[2]['weight'] && 3 === count( $writes ), $writes );
$GLOBALS['vsps_sent']    = array();
$GLOBALS['vsps_replies'] = array( array( 'errors' => array( array( 'message' => 'down' ) ) ) );
check( 'a PIMS error comes back as an error, nothing written', is_wp_error( $api->fill_missing_details( '77', '9', $typed ) ) && array() === $sent_inputs() );

echo "== the hub gets the clinic's current timezone ==\n";
$locinfo = VSPS_Cache::PREFIX . md5( wp_json_encode( array( 'locinfo', 'clinic' ) ) );
update_option( VSPS_Hub::TZ_OPTION, array( 'loc' => 'clinic', 'tz' => 'America/New_York' ) );
check( 'nothing cached: the remembered zone is used', 'America/New_York' === VSPS_Hub::clinic_timezone() );
set_transient( $locinfo, array( 'timezone' => 'America/Los_Angeles' ), 300 );
check( 'the zone the plugin books in wins over the remembered one', 'America/Los_Angeles' === VSPS_Hub::clinic_timezone() );
delete_transient( $locinfo );
check( 'and replaces it for later pushes', 'America/Los_Angeles' === VSPS_Hub::clinic_timezone() );
update_option( VSPS_Hub::TZ_OPTION, array( 'loc' => 'other', 'tz' => 'America/New_York' ) );
check( "another location's zone is never sent", '' === VSPS_Hub::clinic_timezone() );

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
