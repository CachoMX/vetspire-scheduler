<?php
/**
 * Offline checks for v1.28.0: a required-field error names its fields, so the
 * widget can show the error at each one (WCAG 3.3.1). No WordPress, no network.
 *
 * php tests/errors-unit.php
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function __( $t ) { return $t; }

require __DIR__ . '/../includes/class-vsps-fields.php';

$fails = 0;
function check( $label, $ok, $detail = '' ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  — ' . json_encode( $detail ) ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

/** A resolved field map: every field hidden except the ones given as key => required. */
function resolved( array $shown ) {
	$out = array();
	foreach ( array_keys( VSPS_Fields::REGISTRY ) as $key ) {
		$out[ $key ] = array( 'show' => isset( $shown[ $key ] ), 'req' => ! empty( $shown[ $key ] ) );
	}
	return $out;
}

function new_client_args() {
	return array(
		'client_type' => 'new',
		'pet_is_new'  => true,
		'client'      => array(
			'given_name' => 'Ana', 'family_name' => 'Ruiz', 'email' => 'ana@example.com', 'phone' => '5551234567',
			'address'    => array( 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal' => '' ),
			'phone_alt'  => '', 'email_secondary' => '', 'referral_id' => '', 'title' => '', 'pronouns' => '',
			'owner_dob'  => '', 'business_name' => '', 'notes' => '',
		),
		'patient'     => array(
			'name' => 'Rex', 'species' => 'Canine', 'breed' => '', 'mixed' => '', 'sex' => '', 'neutered' => '',
			'age' => '', 'birth_date' => '', 'weight' => 0, 'color' => '', 'microchip' => '', 'notes' => '',
		),
		'notes'       => '',
	);
}

// 1. Missing required fields: the error lists them by registry key.
$args = new_client_args();
$err  = VSPS_Fields::enforce( $args, resolved( array( 'address' => true, 'breed' => true, 'weight' => true, 'reason' => true, 'color' => false ) ) );
check( 'missing required fields → vsps_required', $err instanceof WP_Error && 'vsps_required' === $err->get_error_code() );
$data = $err instanceof WP_Error ? $err->get_error_data() : array();
check( 'error data keeps status 400', isset( $data['status'] ) && 400 === $data['status'], $data );
check( 'error data names the fields (registry keys, form order)', isset( $data['fields'] ) && array( 'address', 'breed', 'weight', 'reason' ) === $data['fields'], $data );

// 2. A filled form passes; an optional empty field is never listed.
$args                              = new_client_args();
$args['client']['address']         = array( 'line1' => '1 Main St', 'line2' => '', 'city' => 'Katy', 'state' => 'TX', 'postal' => '77494' );
$args['patient']['breed']          = 'Mutt';
$args['patient']['weight']         = 12.5;
$args['notes']                     = 'Checkup';
$err                               = VSPS_Fields::enforce( $args, resolved( array( 'address' => true, 'breed' => true, 'weight' => true, 'reason' => true, 'color' => false ) ) );
check( 'filled required fields → no error', null === $err, $err instanceof WP_Error ? $err->get_error_data() : '' );

// 3. Pet fields don't apply to a returning client's existing pet: not listed.
$args                = new_client_args();
$args['client_type'] = 'existing';
$args['pet_is_new']  = false;
$err                 = VSPS_Fields::enforce( $args, resolved( array( 'breed' => true, 'reason' => true ) ) );
$data                = $err instanceof WP_Error ? $err->get_error_data() : array();
check( 'existing pet: only the visit field (reason) is listed', isset( $data['fields'] ) && array( 'reason' ) === $data['fields'], $data );

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
