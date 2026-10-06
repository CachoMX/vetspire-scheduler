<?php
/**
 * E2E check for the optional booking-form fields (Settings → Booking Form):
 * books a NEW client + NEW pet at TESTING LOCATION with every optional field
 * filled, reads the client/patient back from Vetspire to confirm each value
 * landed in its field, then deletes the appointment and deactivates the
 * test client (Vetspire has no deleteClient). Also unit-checks the
 * server-side required/hidden-field enforcement.
 *
 * Run: php -c <ini with curl+openssl> tests/fields-e2e.php   (reads VETSPIRE_API_TOKEN from ../.env)
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_remote_post( $url, $args ) {
	$h = '';
	foreach ( $args['headers'] as $k => $v ) { $h .= "$k: $v\r\n"; }
	$ctx  = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => $h, 'content' => $args['body'], 'timeout' => 30, 'ignore_errors' => true ) ) );
	$body = @file_get_contents( $url, false, $ctx );
	if ( false === $body ) { return new WP_Error( 'http_request_failed', 'stream request failed' ); }
	$code = 0;
	foreach ( $http_response_header as $line ) { if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) { $code = (int) $m[1]; } }
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
$GLOBALS['t'] = array();
function get_transient( $k ) { return isset( $GLOBALS['t'][ $k ] ) ? $GLOBALS['t'][ $k ] : false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['t'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $tag, $v ) { return $v; }
function __( $t, $d = null ) { return $t; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $s ) : ''; }
function wp_list_pluck( $list, $field ) { return array_map( function ( $r ) use ( $field ) { return $r[ $field ]; }, $list ); }
function vsps_get_settings() { return array( 'cache_ttl' => 60 ); }

require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-booking.php';
require __DIR__ . '/../includes/class-vsps-fields.php';

$env   = parse_ini_file( __DIR__ . '/../.env' );
$api   = new VSPS_Api( $env['VETSPIRE_API_TOKEN'], 'https://api.vetspire.com/graphql' );
function vsps_api() { global $api; return $api; }

const TEST_LOCATION = '23512'; // TESTING LOCATION (America/New_York)
const TEST_TYPE     = '9403';  // Wellness @ TESTING LOCATION

$pass = 0; $fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label" . ( '' !== $detail ? " — $detail" : '' ) . "\n"; }
	return $ok;
}

echo "== 1. Referral sources ==\n";
$sources = VSPS_Fields::referral_sources();
check( 'clinic referral list loads', count( $sources ) > 0, json_encode( $sources ) );
$google = null;
foreach ( $sources as $s ) { if ( 'Google' === $s['name'] ) { $google = $s['id']; } }

echo "== 2. Server-side enforcement (no API writes) ==\n";
$settings = array( 'fields' => array(
	'address' => array( 'show' => 1, 'req' => 1 ), 'weight' => array( 'show' => 1, 'req' => 1 ),
	'color' => array( 'show' => 1, 'req' => 0 ), 'reason' => array( 'show' => 0, 'req' => 1 ),
	'microchip' => array( 'show' => 0, 'req' => 0 ),
) );
$resolved = VSPS_Fields::resolve( $settings );
check( 'locked fields always shown + required', $resolved['email']['show'] && $resolved['email']['req'] && $resolved['species']['req'] );
check( 'reason is always shown, its required flag kept', $resolved['reason']['show'] && $resolved['reason']['req'] );
check( 'unset optional field hidden', ! $resolved['breed']['show'] && ! $resolved['breed']['req'] );
$legacy = VSPS_Fields::resolve( array( 'ask_breed' => 1, 'ask_age' => 1 ) );
check( 'old ask_* settings carry over (shown, optional)', $legacy['breed']['show'] && $legacy['age']['show'] && ! $legacy['sex']['show'] && ! $legacy['breed']['req'] );
$va = VSPS_Fields::resolve( $settings, 'a' );
check( 'variant "a" hides every optional field', ! $va['address']['show'] && ! $va['weight']['show'] && $va['reason']['show'] );

$base = function ( $client_type, $pet_is_new ) {
	$x = VSPS_Fields::parse_extras(
		array( 'address' => array( 'line1' => '1 Main', 'city' => 'Katy', 'state' => 'TX', 'postal' => '77449' ) ),
		array( 'weight' => '12.5', 'weight_unit' => 'lb', 'microchip' => '985-1120 0', 'color' => 'Tan' )
	);
	return array(
		'client_type' => $client_type, 'pet_is_new' => $pet_is_new, 'notes' => 'Itchy ears',
		'client'  => array_merge( array( 'given_name' => 'A', 'family_name' => 'B', 'email' => 'a@b.co', 'phone' => '5555550100' ), $x['client'] ),
		'patient' => array_merge( array( 'name' => 'P', 'species' => 'Canine', 'breed' => 'Lab', 'sex' => '', 'age' => 3, 'neutered' => '' ), $x['patient'] ),
	);
};
$args = $base( 'new', true );
check( 'complete new-client booking passes', null === VSPS_Fields::enforce( $args, $resolved ) );
check( 'hidden fields are blanked (microchip, breed, age)', '' === $args['patient']['microchip'] && '' === $args['patient']['breed'] && '' === $args['patient']['age'] );
$args0 = $base( 'new', true );
$args0['patient']['age'] = 0;
$res0  = VSPS_Fields::resolve( array( 'fields' => array_merge( $settings['fields'], array( 'age' => array( 'show' => 1, 'req' => 1 ) ) ) ) );
check( 'required age accepts 0 (under a year old)', null === VSPS_Fields::enforce( $args0, $res0 ) && 0 === $args0['patient']['age'] );
check( 'shown fields kept (address, weight, color)', 'Katy' === $args['client']['address']['city'] && 12.5 === $args['patient']['weight'] && 'Tan' === $args['patient']['color'] );
check( 'weight unit normalised to LB', 'LB' === $args['patient']['weight_unit'] );
$args = $base( 'new', true );
$args['client']['address']['postal'] = '';
$args['patient']['weight']           = 0;
$err = VSPS_Fields::enforce( $args, $resolved );
check( 'missing required fields are refused', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Address' ) && false !== strpos( $err->get_error_message(), 'Weight' ), is_wp_error( $err ) ? $err->get_error_message() : 'no error' );
$args = $base( 'new', true );
$args['notes'] = '';
$err = VSPS_Fields::enforce( $args, $resolved );
check( 'required reason for visit is enforced', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Reason for visit' ) );
$args = $base( 'existing', false );
$args['client']['address'] = array( 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal' => '' );
$args['patient']['weight'] = 0;
check( 'returning client + existing pet: owner/pet fields not required', null === VSPS_Fields::enforce( $args, $resolved ) );
$args = $base( 'existing', true );
$args['client']['address'] = array( 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal' => '' );
$args['patient']['weight'] = 0;
$err = VSPS_Fields::enforce( $args, $resolved );
check( 'returning client adding a pet: pet fields required, owner fields not', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Weight' ) && false === strpos( $err->get_error_message(), 'Address' ), is_wp_error( $err ) ? $err->get_error_message() : 'no error' );
$bad = VSPS_Fields::parse_extras( array( 'title' => 'King', 'pronouns' => 'xx', 'owner_dob' => '2999-01-01', 'referral_id' => '1' ), array( 'birth_date' => '2001-02-30', 'weight' => '-4', 'mixed' => 'maybe' ) );
check( 'invalid values are dropped', '' === $bad['client']['title'] && '' === $bad['client']['pronouns'] && '' === $bad['client']['owner_dob'] && '' === $bad['client']['referral_id'] && '' === $bad['patient']['birth_date'] && 0 === $bad['patient']['weight'] && '' === $bad['patient']['mixed'] );

echo "== 3. E2E: new client + new pet with every field (TESTING LOCATION) ==\n";
$email = 'zz-test-fields-' . time() . '@vetcelerator.com';
$x     = VSPS_Fields::parse_extras(
	array(
		'address' => array( 'line1' => '123 Test St', 'line2' => 'Suite 4', 'city' => 'Katy', 'state' => 'TX', 'postal' => '77449' ),
		'phone_alt' => '555 010 0199', 'email_secondary' => 'zz-test-fields-2nd@vetcelerator.com',
		'referral_id' => (string) $google, 'title' => 'Dr.', 'pronouns' => 'THEY', 'owner_dob' => '1985-04-12',
		'business_name' => 'ZZ Test Co', 'notes' => 'Client note: please call after 5pm',
	),
	array(
		'mixed' => 'yes', 'birth_date' => '2021-06-01', 'weight' => '23.4', 'weight_unit' => 'LB', 'color' => 'Brindle',
		'microchip' => '985112000000001', 'notes' => 'Pet note: nervous at the vet',
	)
);
$tomorrow = ( new DateTimeImmutable( '+1 day', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
$booking  = array(
	'location_id' => TEST_LOCATION, 'appointment_type_id' => TEST_TYPE, 'date' => $tomorrow, 'time' => '10:15',
	'provider_id' => '', 'schedule_id' => '', 'skip_slot_check' => true, 'client_type' => 'new', 'pet_is_new' => true,
	'notes'   => 'AUTOMATED FIELDS TEST — safe to delete',
	'client'  => array_merge( array( 'given_name' => 'ZZ-Fields', 'family_name' => 'Test', 'email' => $email, 'phone' => '555 010 0100' ), $x['client'] ),
	'patient' => array_merge( array( 'name' => 'FieldsPet', 'species' => 'Canine', 'breed' => 'Labrador', 'sex' => 'FEMALE', 'age' => 5, 'neutered' => 'yes' ), $x['patient'] ),
);
$res = VSPS_Booking::book( $api, $booking );
if ( ! check( 'booking created', ! is_wp_error( $res ) && ! empty( $res['appointment_id'] ), is_wp_error( $res ) ? $res->get_error_message() : json_encode( $res ) ) ) {
	echo "\n==== RESULT: $pass passed, $fail failed ====\n";
	exit( 1 );
}

$c = $api->request( 'query ($id: ID!) { client(id: $id) { givenName email secondaryEmail title pronouns dateOfBirth businessName notes clientReferralSourceId clientReferralSource { name } phoneNumbers { value name preferred } addresses { line1 line2 city state postalCode isPrimary } } }', array( 'id' => (string) $res['client_id'] ) );
$p = $api->request( 'query ($id: ID!) { patient(id: $id) { name species breed sex neutered isMixed birthDate color microchip notes latestWeight { value unit } } }', array( 'id' => (string) $res['patient_id'] ) );
$a = $api->request( 'query ($id: ID!) { appointment(id: $id) { reason } }', array( 'id' => (string) $res['appointment_id'] ) );
$c = is_wp_error( $c ) ? array() : $c['client'];
$p = is_wp_error( $p ) ? array() : $p['patient'];
$addr = isset( $c['addresses'][0] ) ? $c['addresses'][0] : array();
check( 'address saved', '123 Test St' === ( $addr['line1'] ?? '' ) && 'Suite 4' === ( $addr['line2'] ?? '' ) && 'Katy' === ( $addr['city'] ?? '' ) && 'TX' === ( $addr['state'] ?? '' ) && '77449' === ( $addr['postalCode'] ?? '' ), json_encode( $c['addresses'] ?? null ) );
$phones = array_column( $c['phoneNumbers'] ?? array(), 'value' );
check( 'main + alternate phone saved', count( $phones ) >= 2, json_encode( $c['phoneNumbers'] ?? null ) );
check( 'secondary email saved', 'zz-test-fields-2nd@vetcelerator.com' === ( $c['secondaryEmail'] ?? '' ), json_encode( $c['secondaryEmail'] ?? null ) );
check( 'referral source saved (Google)', 'Google' === ( $c['clientReferralSource']['name'] ?? '' ), json_encode( $c['clientReferralSource'] ?? null ) );
check( 'title saved', 'Dr.' === ( $c['title'] ?? '' ), json_encode( $c['title'] ?? null ) );
check( 'pronouns saved', 'THEY' === ( $c['pronouns'] ?? '' ), json_encode( $c['pronouns'] ?? null ) );
check( 'owner date of birth saved', '1985-04-12' === ( $c['dateOfBirth'] ?? '' ), json_encode( $c['dateOfBirth'] ?? null ) );
check( 'business name saved', 'ZZ Test Co' === ( $c['businessName'] ?? '' ), json_encode( $c['businessName'] ?? null ) );
check( 'client note saved', false !== strpos( (string) ( $c['notes'] ?? '' ), 'please call after 5pm' ), json_encode( $c['notes'] ?? null ) );
check( 'breed / sex / neutered saved', 'Labrador' === ( $p['breed'] ?? '' ) && 'FEMALE' === ( $p['sex'] ?? '' ) && true === ( $p['neutered'] ?? null ), json_encode( $p ) );
check( 'mixed breed saved', true === ( $p['isMixed'] ?? null ), json_encode( $p['isMixed'] ?? null ) );
check( 'birth date saved (beats age estimate)', '2021-06-01' === ( $p['birthDate'] ?? '' ), json_encode( $p['birthDate'] ?? null ) );
check( 'color saved', 'Brindle' === ( $p['color'] ?? '' ), json_encode( $p['color'] ?? null ) );
check( 'microchip saved', '985112000000001' === ( $p['microchip'] ?? '' ), json_encode( $p['microchip'] ?? null ) );
check( 'pet note saved', false !== strpos( (string) ( $p['notes'] ?? '' ), 'nervous at the vet' ), json_encode( $p['notes'] ?? null ) );
check( 'weight recorded (23.4 LB)', isset( $p['latestWeight']['value'] ) && abs( (float) $p['latestWeight']['value'] - 23.4 ) < 0.01 && 'LB' === strtoupper( (string) $p['latestWeight']['unit'] ), json_encode( $p['latestWeight'] ?? null ) );
check( 'reason for visit in the appointment', ! is_wp_error( $a ) && 'Vetcelerator booking: AUTOMATED FIELDS TEST — safe to delete' === $a['appointment']['reason'], json_encode( $a ) );

echo "== 4. Cleanup ==\n";
$del = $api->request( 'mutation ($id: ID!) { deleteAppointment(id: $id) { id deleted } }', array( 'id' => (string) $res['appointment_id'] ) );
check( 'test appointment deleted', ! is_wp_error( $del ) && ! empty( $del['deleteAppointment']['deleted'] ), json_encode( $del ) );
$off = $api->request( 'mutation ($id: ID!) { updateClient(id: $id, input: { isActive: false }) { id isActive } }', array( 'id' => (string) $res['client_id'] ) );
check( 'test client deactivated', ! is_wp_error( $off ) && false === $off['updateClient']['isActive'], json_encode( $off ) );

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit( $fail > 0 ? 1 : 0 );
