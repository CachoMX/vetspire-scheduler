<?php
/**
 * Offline checks for v1.30.0: the pet's age in whole years and months (call
 * with Nicole and Abi, 2026-10-30). Vetspire gets an estimated birth year and
 * month (never a day); Nectar, which can't store an estimated age, gets it in
 * the appointment notes instead of an invented birthday. No WordPress, no network.
 *
 * php tests/age-unit.php
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

// Nectar requests are recorded, not sent.
$GLOBALS['vsps_sent'] = array();
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
require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';

$fails = 0;
function check( $label, $ok, $detail = '' ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  — ' . json_encode( $detail ) ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

/* ---------- the numbers the form sends ---------- */

echo "== whole numbers ==\n";
$cases = array(
	array( '3', 40, 3 ), array( 3, 40, 3 ), array( '0', 11, 0 ), array( ' 6 ', 11, 6 ),
	array( '', 40, '' ), array( '2.5', 40, 2 ), array( '1e2', 40, '' ), array( '2.', 40, '' ), array( '-1', 40, '' ),
	array( 'abc', 40, '' ), array( 2.5, 40, '' ), array( null, 40, '' ), array( array( 3 ), 40, '' ),
	array( '99', 40, 40 ), array( '14', 11, 11 ),
);
foreach ( $cases as $c ) {
	check( 'age_number(' . json_encode( $c[0] ) . ', max ' . $c[1] . ') → ' . json_encode( $c[2] ), $c[2] === VSPS_Fields::age_number( $c[0], $c[1] ) );
}

/* ---------- year + month counted back ---------- */

echo "== estimated birth month ==\n";
$nov = gmmktime( 12, 0, 0, 11, 3, 2026 );
$jan = gmmktime( 12, 0, 0, 1, 15, 2027 );
$months = array(
	array( 3, 0, $nov, 2023, 11 ), // "3 years old", not "3 years 10 months"
	array( 0, 6, $nov, 2026, 5 ),  // a 6-month-old kitten
	array( 1, 2, $nov, 2025, 9 ),
	array( '', 6, $nov, 2026, 5 ), // years left empty
	array( 0, 11, $nov, 2025, 12 ),
	array( 0, 0, $nov, 2026, 11 ),
	array( 0, 1, $jan, 2026, 12 ), // across new year
	array( 40, 11, $nov, 1985, 12 ),
);
foreach ( $months as $m ) {
	$got = VSPS_Fields::age_birth_month( $m[0], $m[1], $m[2] );
	check( json_encode( $m[0] ) . 'y ' . $m[1] . 'm on ' . gmdate( 'Y-m', $m[2] ) . ' → ' . $m[3] . '-' . $m[4], array( 'year' => $m[3], 'month' => $m[4] ) === $got, $got );
}
// Oct 31, 9 pm in Chicago is already Nov 1 in UTC: the clinic's month counts.
$edge = gmmktime( 2, 0, 0, 11, 1, 2026 );
check( 'clinic timezone: 6 months on Oct 31 (Chicago) → April, not May', array( 'year' => 2026, 'month' => 4 ) === VSPS_Fields::age_birth_month( 0, 6, $edge, 'America/Chicago' ) );
check( 'no timezone: UTC month (May)', array( 'year' => 2026, 'month' => 5 ) === VSPS_Fields::age_birth_month( 0, 6, $edge ) );
check( 'invalid timezone: falls back to UTC', array( 'year' => 2026, 'month' => 5 ) === VSPS_Fields::age_birth_month( 0, 6, $edge, 'Not/AZone' ) );

echo "== phrase for notes ==\n";
foreach ( array( array( 3, '', '3 years' ), array( '', 6, '6 months' ), array( 1, 2, '1 year 2 months' ), array( 0, 1, '1 month' ), array( 2, 0, '2 years' ), array( 0, 0, '0 months' ), array( 0, '', 'under 1 year' ) ) as $p ) {
	check( json_encode( $p[0] ) . ' + ' . json_encode( $p[1] ) . ' → "' . $p[2] . '"', $p[2] === VSPS_Fields::age_phrase( $p[0], $p[1] ) );
}

/* ---------- required = years OR months ---------- */

echo "== required ==\n";
function resolved( array $shown ) {
	$out = array();
	foreach ( array_keys( VSPS_Fields::REGISTRY ) as $key ) {
		$out[ $key ] = array( 'show' => isset( $shown[ $key ] ), 'req' => ! empty( $shown[ $key ] ) );
	}
	return $out;
}
function pet_args( $age, $months ) {
	return array(
		'client_type' => 'new',
		'pet_is_new'  => true,
		'notes'       => '',
		'client'      => array(
			'address' => array( 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal' => '' ),
			'phone_alt' => '', 'email_secondary' => '', 'referral_id' => '', 'title' => '', 'pronouns' => '',
			'owner_dob' => '', 'business_name' => '', 'notes' => '',
		),
		'patient'     => array(
			'breed' => '', 'mixed' => '', 'sex' => '', 'neutered' => '', 'age' => $age, 'age_months' => $months,
			'birth_date' => '', 'weight' => 0, 'color' => '', 'microchip' => '', 'notes' => '',
		),
	);
}
$req = resolved( array( 'age' => true ) );
$a   = pet_args( '', 6 );
check( 'required age: months only is enough (6-month kitten)', null === VSPS_Fields::enforce( $a, $req ) );
$a = pet_args( 3, '' );
check( 'required age: years only is enough', null === VSPS_Fields::enforce( $a, $req ) );
$a = pet_args( 0, '' );
check( 'required age: 0 years is an answer', null === VSPS_Fields::enforce( $a, $req ) );
$a   = pet_args( '', '' );
$err = VSPS_Fields::enforce( $a, $req );
check( 'required age: neither → vsps_required naming "Age"', is_wp_error( $err ) && 'vsps_required' === $err->get_error_code() && false !== strpos( $err->get_error_message(), 'Age' ) );
check( 'required age: the error points at the age field', is_wp_error( $err ) && array( 'age' ) === $err->get_error_data()['fields'] );
$a = pet_args( 3, 2 );
VSPS_Fields::enforce( $a, resolved( array() ) );
check( 'hidden age: years and months are both blanked', '' === $a['patient']['age'] && '' === $a['patient']['age_months'] );
$a = pet_args( 3, 2 );
$a['pet_is_new'] = false;
VSPS_Fields::enforce( $a, resolved( array( 'age' => false ) ) );
check( 'existing pet: age not sent', '' === $a['patient']['age'] && '' === $a['patient']['age_months'] );
check( 'Settings label', 'Age (years and months)' === VSPS_Fields::REGISTRY['age'][1] );

/* ---------- Vetspire: year + month, estimated, no day ---------- */

echo "== Vetspire ==\n";
class Capture_Api extends VSPS_Api {
	public $sent = null;
	public function create_patient( $client_id, $input ) {
		$this->sent = $input;
		return array( 'id' => '1', 'name' => $input['name'] );
	}
}
function vetspire_patient( array $patient ) {
	$api = new Capture_Api( 'tok', 'https://api.test/graphql' );
	$api->create_booking_patient( array( 'id' => '9' ), array( 'patient' => array_merge( pet_args( '', '' )['patient'], array( 'name' => 'Rex', 'species' => 'Canine' ), $patient ) ) );
	return $api->sent;
}
$now  = VSPS_Fields::age_birth_month( 3, 0 );
$sent = vetspire_patient( array( 'age' => 3 ) );
check( '3 years → birthYear + birthMonth, estimated', $now['year'] === $sent['birthYear'] && $now['month'] === $sent['birthMonth'] && true === $sent['isEstimatedAge'], $sent );
check( '3 years → no birth day or date invented', ! isset( $sent['birthDay'] ) && ! isset( $sent['birthDate'] ) );
$kit  = VSPS_Fields::age_birth_month( 0, 6 );
$sent = vetspire_patient( array( 'age_months' => 6 ) );
check( '6 months (years empty) → that month, estimated', $kit['year'] === $sent['birthYear'] && $kit['month'] === $sent['birthMonth'] && true === $sent['isEstimatedAge'], $sent );
$sent = vetspire_patient( array( 'age' => 3, 'birth_date' => '2023-04-12' ) );
check( 'a real birth date wins: no estimated year/month', '2023-04-12' === $sent['birthDate'] && false === $sent['isEstimatedAge'] && ! isset( $sent['birthYear'] ) && ! isset( $sent['birthMonth'] ), $sent );
$sent = vetspire_patient( array() );
check( 'no age → nothing about age sent', ! isset( $sent['birthYear'] ) && ! isset( $sent['birthMonth'] ) && ! isset( $sent['isEstimatedAge'] ), $sent );

/* ---------- Nectar: no invented birthday; age in the notes ---------- */

echo "== Nectar ==\n";
$nectar = VSPS_Nectar::via_hub( 'https://hub.test', 'vss_' . str_repeat( 'ab', 24 ), '', 'America/New_York', '1.30.0' );
function last_input() {
	$last = end( $GLOBALS['vsps_sent'] );
	return $last['body']['variables']['input'];
}
$GLOBALS['vsps_reply'] = array( 'data' => array( 'addPatient' => array( 'id' => 'p1', 'firstName' => 'Rex' ) ) );
$nectar->create_booking_patient( array( 'familyId' => 'f1', 'familyName' => 'Doe' ), array( 'patient' => array_merge( pet_args( 3, 2 )['patient'], array( 'name' => 'Rex', 'species' => 'Canine' ) ) ) );
$input = last_input();
check( 'age only → no dateOfBirth on the Nectar pet', ! isset( $input['dateOfBirth'] ), $input );
$nectar->create_booking_patient( array( 'familyId' => 'f1' ), array( 'patient' => array_merge( pet_args( 3, '' )['patient'], array( 'name' => 'Rex', 'species' => 'Canine', 'birth_date' => '2023-04-12' ) ) ) );
check( 'a real birth date still goes on the pet', '2023-04-12T12:00:00+00:00' === last_input()['dateOfBirth'] );

$GLOBALS['vsps_reply'] = array( 'data' => array(
	'addOnlineAppointment'          => array( 'id' => 'a1', 'appointmentId' => 'A1', 'startDatetime' => '2026-11-05T14:30:00+00:00', 'status' => 'ONLINE_HOLD' ),
	'scheduleAndRequestAppointment' => array( 'id' => 'a1', 'appointmentId' => 'A1', 'status' => 'ONLINE_REQUESTED', 'startDatetime' => '2026-11-05T14:30:00+00:00' ),
) );
function nectar_notes( $nectar, $pet_age ) {
	$GLOBALS['vsps_sent'] = array();
	$nectar->create_booking_appointment( array(
		'location_id' => 'clinic', 'appointment_type_id' => 't1', 'client_id' => 'c1', 'patient_id' => 'p1',
		'start' => '2026-11-05T09:30:00-05:00', 'duration' => 30, 'provider_id' => '', 'schedule_id' => '',
		'reason' => 'Vetcelerator booking: itchy ears', 'pet_age' => $pet_age,
	) );
	return $GLOBALS['vsps_sent'][0]['body']['variables']['input']['staffNotes'];
}
check( 'age → staff notes line', "Vetcelerator booking: itchy ears\nPet's age (owner's estimate): 1 year 2 months" === nectar_notes( $nectar, '1 year 2 months' ) );
check( 'no age → notes unchanged', 'Vetcelerator booking: itchy ears' === nectar_notes( $nectar, '' ) );

function js_length( $s ) { return (int) ( strlen( mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' ) ) / 2 ); }
function long_notes( $nectar, $reason, $pet_age ) {
	$GLOBALS['vsps_sent'] = array();
	$nectar->create_booking_appointment( array(
		'location_id' => 'clinic', 'appointment_type_id' => 't1', 'client_id' => 'c1', 'patient_id' => 'p1',
		'start' => '2026-11-05T09:30:00-05:00', 'duration' => 30, 'provider_id' => '', 'schedule_id' => '',
		'reason' => $reason, 'pet_age' => $pet_age,
	) );
	return $GLOBALS['vsps_sent'][0]['body']['variables']['input']['staffNotes'];
}
$notes = long_notes( $nectar, 'Vetcelerator booking: ' . str_repeat( 'a', 2000 ), '6 months' );
check( 'long reason: notes within the hub limit (1000)', js_length( $notes ) <= 1000, js_length( $notes ) );
check( 'long reason: the age line is kept', "\nPet's age (owner's estimate): 6 months" === substr( $notes, -strlen( "\nPet's age (owner's estimate): 6 months" ) ) );
$notes = long_notes( $nectar, 'Vetcelerator booking: ' . str_repeat( "\u{1F436}", 900 ), '' );
check( 'emoji reason: within the limit as JavaScript counts it', js_length( $notes ) <= 1000 && '…' === mb_substr( $notes, -1 ), js_length( $notes ) );
$notes = long_notes( $nectar, 'Vetcelerator booking: ' . str_repeat( 'b', 978 ), '' );
check( 'exactly 1000: left as is', 1000 === js_length( $notes ) && false === strpos( $notes, '…' ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
