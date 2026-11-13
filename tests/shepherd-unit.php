<?php
/**
 * Offline checks for the Shepherd adapter (Open API 2.246.0.33 shapes).
 * Mocked HTTP only: no WordPress, no network, no live clinic, no create
 * against Shepherd.
 *
 * php tests/shepherd-unit.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'VSPS_VERSION', '1.29.0' );
define( 'VSPS_OPTION_KEY', 'vsps_settings' );

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
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : null; }
function esc_url_raw( $s ) { return (string) $s; }
function add_settings_error( $setting, $code, $message, $type ) { $GLOBALS['vsps_errors'][] = $code; }

$GLOBALS['vsps_sent']  = array();
$GLOBALS['vsps_queue'] = array();
$GLOBALS['vsps_transients'] = array();
function wp_remote_post( $url, $args ) {
	$GLOBALS['vsps_sent'][] = array(
		'url'         => $url,
		'body'        => json_decode( $args['body'], true ),
		'headers'     => $args['headers'],
		'redirection' => isset( $args['redirection'] ) ? $args['redirection'] : null,
	);
	if ( $GLOBALS['vsps_queue'] ) {
		$next = array_shift( $GLOBALS['vsps_queue'] );
		$body = is_string( $next['body'] ) ? $next['body'] : json_encode( $next['body'] );
		return array( 'response' => array( 'code' => $next['code'] ), 'body' => $body );
	}
	$path  = parse_url( $url, PHP_URL_PATH );
	$reply = call_user_func( $GLOBALS['vsps_router'], (string) $path, json_decode( $args['body'], true ) );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $reply ) );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function get_transient( $key ) { return isset( $GLOBALS['vsps_transients'][ $key ] ) ? $GLOBALS['vsps_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['vsps_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['vsps_transients'][ $key ] ); return true; }
function vsps_get_settings() { return array( 'cache_ttl' => 60, 'pims' => 'shepherd' ); }

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-shepherd.php';

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}
function reset_state() {
	$GLOBALS['vsps_sent']       = array();
	$GLOBALS['vsps_queue']      = array();
	$GLOBALS['vsps_transients'] = array();
	$GLOBALS['vsps_router']     = function () { return array( 'item' => array() ); };
}

const CLINIC = '9890662f-52db-4a41-a5c1-b2e300d400b4';
const TYPE   = '8fc9c6d1-a829-4b93-a5ea-1c83db96a1b4';
const PROV   = '5a8d0cfc-c7c4-44a0-bdab-44f4e5f6b219';
const SPEC   = '11111111-1111-4111-8111-111111111111';
const MIXED  = '22222222-2222-4222-8222-222222222222';
const LAB    = '33333333-3333-4333-8333-333333333333';
const MALE   = '44444444-4444-4444-8444-444444444444';
const NEUT   = '55555555-5555-4555-8555-555555555555';
const ACTIVE = '66666666-6666-4666-8666-666666666666';
const US     = '77777777-7777-4777-8777-777777777777';
const MOBILE = '88888888-8888-4888-8888-888888888888';
const LBS    = '99999999-9999-4999-8999-999999999999';
const CLIENT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const PET    = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const APPT   = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

function api( $env = 'sandbox' ) {
	return new VSPS_Shepherd( 'pub-key-value', 'priv-key-value', CLINIC, $env, 'America/Chicago' );
}
function sent_paths() {
	$out = array();
	foreach ( $GLOBALS['vsps_sent'] as $call ) {
		$out[] = parse_url( $call['url'], PHP_URL_PATH );
	}
	return $out;
}

echo "== registry and credentials ==\n";
check( 'shepherd is a platform', in_array( 'shepherd', VSPS_Pims_Registry::platforms(), true ) );
check( 'unknown platform stays Vetspire', 'vetspire' === VSPS_Pims_Registry::normalize( 'nope' ) );
check( 'shepherd books on the spot', 'confirm' === VSPS_Pims_Registry::booking_mode( 'shepherd' ) );
check( 'shepherd stores breed, age, and referral', VSPS_Pims_Registry::supports_field( 'breed', 'shepherd' ) && VSPS_Pims_Registry::supports_field( 'age', 'shepherd' ) && VSPS_Pims_Registry::supports_field( 'referral', 'shepherd' ) );
check( 'shepherd does not store owner date of birth (not on ClientWriteModel)', ! VSPS_Pims_Registry::supports_field( 'owner_dob', 'shepherd' ) );
check( 'nectar field list is unchanged', VSPS_Pims_Registry::supports_field( 'owner_dob', 'nectar' ) && ! VSPS_Pims_Registry::supports_field( 'referral', 'nectar' ) );
check( 'configured needs both keys and a UUID', VSPS_Shepherd::configured( array(
	'shepherd_public_key' => 'pub', 'shepherd_private_key' => 'priv', 'shepherd_clinic_id' => CLINIC,
) ) );
check( 'missing private key is not configured', ! VSPS_Shepherd::configured( array(
	'shepherd_public_key' => 'pub', 'shepherd_private_key' => '', 'shepherd_clinic_id' => CLINIC,
) ) );
check( 'a non-UUID clinic id is refused', ! VSPS_Shepherd::is_clinic_id( 'clinic' ) && ! VSPS_Shepherd::is_clinic_id( '9890662f-52db-4a41-a5c1-b2e300d400b4-extra' ) );

echo "== timezone names ==\n";
check( 'IANA passes through', 'America/Chicago' === VSPS_Shepherd::to_iana( 'America/Chicago' ) );
check( 'Windows Eastern maps to New York', 'America/New_York' === VSPS_Shepherd::to_iana( 'Eastern Standard Time' ) );
check( 'an unknown zone is empty', '' === VSPS_Shepherd::to_iana( 'Central Time (US)' ) );

echo "== pet mapping ==\n";
$species = array(
	array( 'id' => SPEC, 'name' => 'Canine', 'abrv' => 'canine' ),
	array( 'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'name' => 'Feline', 'abrv' => 'feline' ),
);
check( 'Canine matches the species lookup', SPEC === VSPS_Shepherd::match_species_id( $species, 'Canine' ) );
check( 'dog matches canine', SPEC === VSPS_Shepherd::match_species_id( $species, 'dog' ) );
$breeds = array(
	array( 'id' => LAB, 'name' => 'Labrador Retriever' ),
	array( 'id' => MIXED, 'name' => 'Mixed' ),
);
check( 'a typed breed matches by name', LAB === VSPS_Shepherd::match_breed_id( $breeds, 'Labrador Retriever' ) );
check( 'a typed breed that is not listed is not swapped for Mixed', '' === VSPS_Shepherd::match_breed_id( $breeds, 'Poodle' ) );
check( 'an empty breed may use Mixed', MIXED === VSPS_Shepherd::match_breed_id( $breeds, '' ) );
$sexes = array(
	array( 'id' => MALE, 'name' => 'Male', 'abrv' => 'male' ),
	array( 'id' => NEUT, 'name' => 'Neutered Male', 'abrv' => 'neutered-male' ),
	array( 'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'name' => 'Female' ),
);
check( 'neutered male uses the neutered lookup', NEUT === VSPS_Shepherd::match_sex_id( $sexes, 'MALE', 'yes' ) );
check( 'male without neutered stays Male', MALE === VSPS_Shepherd::match_sex_id( $sexes, 'MALE', 'no' ) );
check( 'active status', ACTIVE === VSPS_Shepherd::match_status_id( array(
	array( 'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'name' => 'Deceased', 'abrv' => 'deceased' ),
	array( 'id' => ACTIVE, 'name' => 'Active', 'abrv' => 'active' ),
) ) );
check( 'pounds', LBS === VSPS_Shepherd::match_weight_unit_id( array(
	array( 'id' => LBS, 'name' => 'lbs', 'abrv' => 'lb' ),
	array( 'id' => '12121212-1212-4121-8121-121212121212', 'name' => 'kg' ),
), 'LB' ) );
$dob = VSPS_Shepherd::date_of_birth( array( 'birth_date' => '2019-04-12', 'age' => 3 ) );
check( 'an exact birth date beats age', '2019-04-12T00:00:00Z' === $dob );
$aged = VSPS_Shepherd::date_of_birth( array( 'birth_date' => '', 'age' => 0 ) );
check( 'age 0 is a date of birth (under a year), not an error', ! is_wp_error( $aged ) && preg_match( '/^\d{4}-\d{2}-\d{2}T00:00:00Z$/', $aged ) );
$missing = VSPS_Shepherd::date_of_birth( array( 'birth_date' => '', 'age' => '' ) );
check( 'neither age nor birth date is an error', is_wp_error( $missing ) && false !== stripos( $missing->get_error_message(), 'date of birth' ) );
check( 'duration rounds up to a 5-minute boundary', 30 === VSPS_Shepherd::slot_minutes( 30 ) && 15 === VSPS_Shepherd::slot_minutes( 12 ) && 30 === VSPS_Shepherd::slot_minutes( 0 ) );

echo "== email lookup is exact ==\n";
$found = VSPS_Shepherd::exact_email_clients( array(
	array( 'id' => CLIENT, 'email' => 'Ada@Example.com', 'deleted' => false ),
	array( 'id' => 'abababab-abab-4aba-8aba-abababababab', 'email' => 'ada@example.com.other', 'deleted' => false ),
	array( 'id' => 'cdcdcdcd-cdcd-4cdc-8cdc-cdcdcdcdcdcd', 'email' => 'ada@example.com', 'deleted' => true ),
), 'ada@example.com' );
check( 'only the exact, not-deleted email', 1 === count( $found ) && CLIENT === $found[0]['id'] );

echo "== client and appointment bodies ==\n";
$body = VSPS_Shepherd::client_body( array(
	'given_name' => 'Ada', 'family_name' => 'Lovelace', 'email' => 'ada@example.com', 'phone' => '555-0100',
	'phone_alt' => '', 'notes' => '', 'referral_id' => '',
	'address' => array( 'line1' => '1 Main', 'line2' => '', 'city' => 'Estero', 'state' => 'FL', 'postal' => '33928' ),
), US, MOBILE );
check( 'client write has the required phone lookup ids', US === $body['clientPhones'][0]['countryId'] && MOBILE === $body['clientPhones'][0]['phoneTypeId'] && true === $body['clientPhones'][0]['isPrimary'] );
check( 'a complete address is clientInfo', 'Estero' === $body['clientInfo']['city'] && '33928' === $body['clientInfo']['zipCode'] );

echo "== timeslots ==\n";
$tz    = new DateTimeZone( 'America/New_York' );
$slots = VSPS_Shepherd::timeslots_to_slots(
	array( 'item' => array( array(
		'providerId' => PROV,
		'available'  => array( array( 'start' => '2026-05-22T14:00:00', 'end' => '2026-05-22T15:00:00' ) ),
		'break'      => array( array( 'min' => '2026-05-22T14:30:00', 'max' => '2026-05-22T14:45:00' ) ),
	) ) ),
	array( strtolower( PROV ) => array( 'id' => PROV, 'name' => 'Dr. Alex Rivera' ) ),
	$tz,
	'2026-05-22',
	30,
	new DateTimeImmutable( '2026-05-22T13:00:00', $tz )
);
check( 'a 30-minute break removes the overlapping slot', 1 === count( $slots ) && '14:00' === $slots[0]['time'] && PROV === $slots[0]['providerId'] && 'Dr. Alex Rivera' === $slots[0]['provider']['name'] );
$utc = VSPS_Shepherd::timeslots_to_slots(
	array( 'item' => array(
		'providerId' => PROV,
		'available'  => array( 'startDate' => '2026-05-22T18:00:00Z', 'endDate' => '2026-05-22T18:30:00Z' ),
	) ),
	array(),
	$tz,
	'2026-05-22',
	30,
	new DateTimeImmutable( '2026-05-22T12:00:00', $tz )
);
check( 'a UTC range becomes clinic-local (18:00Z is 14:00 in May)', 1 === count( $utc ) && '14:00' === $utc[0]['time'] );

echo "== transport: hosts, headers, no retry on writes ==\n";
reset_state();
$none = new VSPS_Shepherd( 'pub-key-value', 'priv-key-value', 'not-a-uuid', 'production', '' );
$err  = $none->post( 'open-api-clinics', array( 'page' => 1 ) );
check( 'a bad clinic id never leaves the server', is_wp_error( $err ) && array() === $GLOBALS['vsps_sent'] );

reset_state();
$GLOBALS['vsps_queue'] = array( array( 'code' => 200, 'body' => array( 'item' => array(), 'totalRecords' => 0 ) ) );
$sandbox = api( 'sandbox' );
$sandbox->post( 'open-api-species', array( 'rpp' => 1 ) );
$call = $GLOBALS['vsps_sent'][0];
check( 'sandbox uses the demo host', 'https://demo-open-api.shepherd.vet/pav2/open-api-species' === $call['url'] );
check( 'both keys and the clinic id are headers', 'pub-key-value' === $call['headers']['X-Integration-Public-Key'] && 'priv-key-value' === $call['headers']['X-Integration-Private-Key'] && CLINIC === $call['headers']['X-Clinic-Id'] );
check( 'keys are not in the body', false === strpos( json_encode( $call['body'] ), 'priv-key-value' ) );
check( 'redirects are disabled', 0 === $call['redirection'] );

reset_state();
$GLOBALS['vsps_queue'] = array( array( 'code' => 200, 'body' => array( 'item' => array() ) ) );
api( 'production' )->post( 'open-api-clinics', array( 'page' => 1, 'rpp' => 1 ) );
check( 'production uses the live host only when that environment is selected', 'https://open-api.shepherd.vet/pav2/open-api-clinics' === $GLOBALS['vsps_sent'][0]['url'] );

reset_state();
$GLOBALS['vsps_queue'] = array(
	array( 'code' => 502, 'body' => '"down"' ),
	array( 'code' => 200, 'body' => array( 'item' => array( array( 'id' => SPEC, 'name' => 'Canine' ) ), 'totalRecords' => 1 ) ),
);
$rows = api()->post( 'open-api-species', array( 'rpp' => 1 ) );
check( 'a read retries once on 502', ! is_wp_error( $rows ) && 2 === count( $GLOBALS['vsps_sent'] ) );

reset_state();
$GLOBALS['vsps_queue'] = array( array( 'code' => 500, 'body' => '{"message":"The request is invalid."}' ) );
$wrote = api()->post( 'open-api-appointments/write', array( 'providerId' => PROV ), true );
check( 'a create is not retried', is_wp_error( $wrote ) && 1 === count( $GLOBALS['vsps_sent'] ) );
check( 'the error quotes Shepherd and not the private key', false === strpos( $wrote->get_error_message(), 'priv-key-value' ) && false !== strpos( $wrote->get_error_message(), 'The request is invalid' ) );

echo "== mocked clinic, types, booking ==\n";
reset_state();
$GLOBALS['vsps_router'] = function ( $path, $body ) {
	if ( false !== strpos( $path, 'open-api-clinics' ) ) {
		return array( 'item' => array( array(
			'id' => CLINIC, 'name' => 'EasyVet Midlothian', 'phone' => '5550100',
			'clinicSetting' => array( 'timeZone' => 'Eastern Standard Time' ),
			'clinicAddress' => array( 'address1' => '1 Main', 'city' => 'Midlothian', 'state' => 'TX', 'zipCode' => '76065' ),
		) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-appointment-types' ) ) {
		return array( 'item' => array(
			array( 'id' => TYPE, 'name' => 'Wellness', 'duration' => 30, 'directBookingEnabled' => true, 'isDeleted' => false ),
			array( 'id' => '12121212-1212-4121-8121-121212121212', 'name' => 'Surgery', 'duration' => 60, 'directBookingEnabled' => false, 'isDeleted' => false ),
			array( 'id' => '13131313-1313-4131-8131-131313131313', 'name' => 'Old', 'duration' => 15, 'directBookingEnabled' => true, 'isDeleted' => true ),
		), 'totalRecords' => 3 );
	}
	if ( false !== strpos( $path, 'open-api-providers' ) ) {
		return array( 'item' => array(
			array( 'id' => PROV, 'name' => 'Dr. Alex Rivera', 'isArchived' => false ),
			array( 'id' => '14141414-1414-4141-8141-141414141414', 'name' => 'Archived', 'isArchived' => true ),
		), 'totalRecords' => 2 );
	}
	if ( false !== strpos( $path, 'timeslots' ) ) {
		return array( 'item' => array( array(
			'providerId' => PROV,
			'available'  => array( array( 'start' => '2027-06-02T09:00:00', 'end' => '2027-06-02T10:00:00' ) ),
		) ) );
	}
	if ( false !== strpos( $path, 'open-api-clients/write' ) ) {
		return array( 'id' => CLIENT, 'firstName' => 'Ada', 'lastName' => 'Lovelace' );
	}
	if ( false !== strpos( $path, 'open-api-clients' ) ) {
		return array( 'item' => array(
			array( 'id' => CLIENT, 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.com', 'deleted' => false, 'clientPhones' => array( array( 'phoneNumber' => '5550100' ) ) ),
			array( 'id' => '15151515-1515-4151-8151-151515151515', 'firstName' => 'Other', 'lastName' => 'Person', 'email' => 'someone@example.com', 'deleted' => false ),
		), 'totalRecords' => 2 );
	}
	if ( false !== strpos( $path, 'open-api-patients/write' ) ) {
		$GLOBALS['vsps_patient_body'] = $body;
		return array( 'id' => PET, 'name' => $body['name'] );
	}
	if ( false !== strpos( $path, 'open-api-patients' ) ) {
		return array( 'item' => array( array( 'id' => PET, 'name' => 'Rex', 'deleted' => false, 'dateDeceased' => null ) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-appointments/write' ) ) {
		$GLOBALS['vsps_appt_body'] = $body;
		return array( 'id' => APPT, 'startDate' => $body['startDate'] );
	}
	if ( false !== strpos( $path, 'open-api-species' ) ) {
		return array( 'item' => array( array( 'id' => SPEC, 'name' => 'Canine', 'abrv' => 'canine' ) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-breeds' ) ) {
		return array( 'item' => array(
			array( 'id' => LAB, 'name' => 'Labrador Retriever' ),
			array( 'id' => MIXED, 'name' => 'Mixed' ),
		), 'totalRecords' => 2 );
	}
	if ( false !== strpos( $path, 'open-api-patient-sexes' ) ) {
		return array( 'item' => array( array( 'id' => NEUT, 'name' => 'Neutered Male' ), array( 'id' => MALE, 'name' => 'Male' ) ), 'totalRecords' => 2 );
	}
	if ( false !== strpos( $path, 'open-api-patient-statuses' ) ) {
		return array( 'item' => array( array( 'id' => ACTIVE, 'name' => 'Active' ) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-countries' ) ) {
		return array( 'item' => array( array( 'id' => US, 'name' => 'United States', 'abrv' => 'US' ) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-phone-types' ) ) {
		return array( 'item' => array( array( 'id' => MOBILE, 'name' => 'Mobile' ) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-weight-units' ) ) {
		return array( 'item' => array( array( 'id' => LBS, 'name' => 'lbs', 'abrv' => 'lb' ) ), 'totalRecords' => 1 );
	}
	if ( false !== strpos( $path, 'open-api-referral-source' ) ) {
		return array( 'item' => array( array( 'id' => '16161616-1616-4161-8161-161616161616', 'name' => 'Google', 'isActive' => true ) ), 'totalRecords' => 1 );
	}
	return array( 'item' => array() );
};

$shepherd = api();
$conn     = $shepherd->connection();
check( 'connection reads the clinic name', ! is_wp_error( $conn ) && 'EasyVet Midlothian' === $conn['org'] );
check( 'Windows timezone on the clinic becomes IANA', 'America/New_York' === $conn['locations'][0]['timezone'] );
$again = count( $GLOBALS['vsps_sent'] );
$shepherd->get_location( CLINIC );
check( 'the clinic profile is cached', count( $GLOBALS['vsps_sent'] ) === $again );

$types = $shepherd->get_bookable_types( CLINIC );
check( 'only direct-booking types are offered', 1 === count( $types ) && 'Wellness' === $types[0]['name'] && 30 === $types[0]['duration'] );

$times = $shepherd->get_available_times( CLINIC, TYPE, '2027-06-02' );
check( 'availability becomes provider slots', ! is_wp_error( $times ) && count( $times ) >= 1 && '09:00' === $times[0]['time'] && 'Dr. Alex Rivera' === $times[0]['provider']['name'] );
$provider_call = null;
foreach ( $GLOBALS['vsps_sent'] as $call ) {
	if ( false !== strpos( $call['url'], 'open-api-providers' ) ) {
		$provider_call = $call;
	}
}
check( 'providers are filtered to this appointment type', is_array( $provider_call ) && TYPE === $provider_call['body']['appointmentTypeIds'] );

$client = $shepherd->find_client_by_email( 'ada@example.com' );
check( 'email lookup keeps the exact client and their pet', ! is_wp_error( $client ) && CLIENT === $client['id'] && 'Rex' === $client['patients'][0]['name'] );

$before = count( $GLOBALS['vsps_sent'] );
$made   = $shepherd->create_booking_client( array( 'client' => array(
	'given_name' => 'Ada', 'family_name' => 'Lovelace', 'email' => 'ada@example.com', 'phone' => '5550100',
	'phone_alt' => '', 'notes' => '', 'referral_id' => '', 'address' => array(),
) ) );
$writes = array_values( array_filter( array_slice( sent_paths(), $before ), function ( $path ) {
	return false !== strpos( $path, '/write' );
} ) );
check( 'creating a client is one write', ! is_wp_error( $made ) && CLIENT === $made['id'] && array( '/pav2/open-api-clients/write' ) === $writes );

$before = count( $GLOBALS['vsps_sent'] );
$pet    = $shepherd->create_booking_patient( array( 'id' => CLIENT, 'familyName' => 'Lovelace' ), array( 'patient' => array(
	'name' => 'Rex', 'species' => 'Canine', 'breed' => 'Labrador Retriever', 'sex' => 'MALE', 'neutered' => 'yes',
	'age' => '', 'birth_date' => '2019-04-12', 'weight' => 23.4, 'weight_unit' => 'LB', 'color' => 'Yellow',
	'microchip' => '123456789012345999', 'notes' => 'Shy',
) ) );
$writes = array_values( array_filter( array_slice( sent_paths(), $before ), function ( $path ) {
	return false !== strpos( $path, '/write' );
} ) );
check( 'creating a pet is one write', ! is_wp_error( $pet ) && PET === $pet['id'] && array( '/pav2/open-api-patients/write' ) === $writes );
$pb = $GLOBALS['vsps_patient_body'];
check( 'the pet write uses lookup ids and the exact birth date', LAB === $pb['breedId'] && NEUT === $pb['sexId'] && ACTIVE === $pb['statusId'] && '2019-04-12T00:00:00Z' === $pb['dateOfBirth'] );
check( 'microchip is capped at 15 characters', 15 === strlen( $pb['microchipNumber'] ) );
check( 'weight is a patientWeight block', 23.4 === $pb['patientWeight']['weight'] && LBS === $pb['patientWeight']['weightUnitId'] );

$before = count( $GLOBALS['vsps_sent'] );
$appt   = $shepherd->create_booking_appointment( array(
	'start' => '2026-06-02T09:00:00-04:00', 'duration' => 30, 'provider_id' => PROV,
	'appointment_type_id' => TYPE, 'client_id' => CLIENT, 'patient_id' => PET,
	'reason' => str_repeat( 'r', 1200 ),
) );
$writes = array_values( array_filter( array_slice( sent_paths(), $before ), function ( $path ) {
	return false !== strpos( $path, '/write' );
} ) );
check( 'creating an appointment is one write', ! is_wp_error( $appt ) && APPT === $appt['id'] && array( '/pav2/open-api-appointments/write' ) === $writes );
$ab = $GLOBALS['vsps_appt_body'];
check( 'the appointment is UTC, on a type, and does not message the client', '2026-06-02T13:00:00Z' === $ab['startDate'] && '2026-06-02T13:30:00Z' === $ab['endDate'] && false === $ab['sendEmailNotification'] && false === $ab['sendSmsNotification'] && false === $ab['sendFormEmailNotification'] );
check( 'visit reason is clipped to 1000 characters', 1000 === strlen( $ab['visitReason'] ) );
check( 'patientIds is a list', array( PET ) === $ab['patientIds'] );

$held = $shepherd->find_client_appointment_at( CLINIC, '2026-06-02T13:00:00Z', 'ada@example.com', TYPE, 'Rex' );
check( 'a second submit can see the appointment already held (read only)', null === $held || APPT === $held );
// The router has no appointments list yet, so this is null. Point it at one and confirm no write.
$GLOBALS['vsps_router_appointments'] = true;
$prev = $GLOBALS['vsps_router'];
$GLOBALS['vsps_router'] = function ( $path, $body ) use ( $prev ) {
	if ( false !== strpos( $path, 'open-api-appointments' ) && false === strpos( $path, '/write' ) ) {
		return array( 'item' => array( array(
			'id' => APPT, 'clientId' => CLIENT, 'appointmentTypeId' => TYPE,
			'startDate' => '2026-06-02T13:00:00Z', 'isDeleted' => false,
		) ), 'totalRecords' => 1 );
	}
	return call_user_func( $prev, $path, $body );
};
$before = count( array_filter( sent_paths(), function ( $path ) { return false !== strpos( $path, '/write' ); } ) );
$held   = $shepherd->find_client_appointment_at( CLINIC, '2026-06-02T13:00:00Z', 'ada@example.com', TYPE, 'Rex' );
$after  = count( array_filter( sent_paths(), function ( $path ) { return false !== strpos( $path, '/write' ); } ) );
check( 'the held appointment is found without another create', APPT === $held && $before === $after );

echo "== settings keep Shepherd off Vetspire and Nectar ==\n";
require __DIR__ . '/../includes/class-vsps-fields.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';
class VSPS_Hub {
	const KEY_PATTERN = '/^vss_[0-9a-f]{48}$/';
	public static function reset_after_key_change() {}
}
class VSPS_Shortcode {
	public static function valid_mobile_layout( $v ) { return ''; }
}
$GLOBALS['vsps_option'] = array();
$GLOBALS['vsps_errors'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['vsps_option']; }
class VSPS_Wpdb_Stub {
	public $options = 'wp_options';
	public function esc_like( $s ) { return (string) $s; }
	public function prepare( $sql, $like ) { return (string) $sql; }
	public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new VSPS_Wpdb_Stub();
require __DIR__ . '/../includes/class-vsps-settings.php';
function save_settings( array $stored, array $input ) {
	$GLOBALS['vsps_option'] = $stored;
	$GLOBALS['vsps_errors'] = array();
	return VSPS_Settings::sanitize( $input );
}
$out = save_settings( array( 'pims' => 'vetspire', 'api_token' => 'vet-token' ), array( 'layout' => 'bar', 'primary_color' => '#112233', 'fields' => array() ) );
check( 'a Vetspire save does not switch platform', 'vetspire' === $out['pims'] && 'vet-token' === $out['api_token'] );
check( 'Shepherd stays on sandbox until chosen', 'sandbox' === $out['shepherd_env'] && '' === $out['shepherd_clinic_id'] );

$out = save_settings( array(), array(
	'connect_only' => '1', 'pims' => 'shepherd', 'shepherd_env' => 'sandbox',
	'shepherd_public_key' => 'pub', 'shepherd_private_key' => 'priv', 'shepherd_clinic_id' => '  ' . strtoupper( CLINIC ) . ' ',
) );
check( 'connect card stores the keys and clinic id', 'shepherd' === $out['pims'] && 'pub' === $out['shepherd_public_key'] && 'priv' === $out['shepherd_private_key'] && strtoupper( CLINIC ) === $out['shepherd_clinic_id'] );
check( 'the clinic id becomes the only location', strtoupper( CLINIC ) === $out['default_location'] && strtoupper( CLINIC ) === $out['allowed_locations'] );

$out = save_settings( array( 'pims' => 'shepherd', 'shepherd_clinic_id' => CLINIC, 'shepherd_private_key' => 'priv' ), array(
	'pims' => 'shepherd', 'shepherd_clinic_id' => 'not-a-uuid', 'shepherd_private_key' => str_repeat( '•', 12 ) . 'priv',
) );
check( 'an invalid clinic id is kept, and a masked key is kept', CLINIC === $out['shepherd_clinic_id'] && 'priv' === $out['shepherd_private_key'] && in_array( 'vsps_shepherd_clinic', $GLOBALS['vsps_errors'], true ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
