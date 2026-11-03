<?php
/**
 * Nectar PRODUCTION, read-only. Included by tests/nectar-e2e.php when
 * VSPS_NECTAR_ENV=production. Never books, never creates an owner or a pet:
 * a real clinic would see the request.
 */

if ( ! isset( $env ) ) {
	exit( 1 );
}
$code = (string) getenv( 'VSPS_NECTAR_CLINIC' );
if ( empty( $env['NECTAR_CLIENT_PROD_ID'] ) || empty( $env['NECTAR_CLIENT_PROD_SECRET'] ) || '' === $code ) {
	fwrite( STDERR, "Production mode needs NECTAR_CLIENT_PROD_ID / NECTAR_CLIENT_PROD_SECRET in .env.local and VSPS_NECTAR_CLINIC\n" );
	exit( 1 );
}
$clinic = VSPS_Nectar::clinic_code( $code );
if ( ! is_string( $clinic ) || '' === $clinic ) {
	fwrite( STDERR, "VSPS_NECTAR_CLINIC is not a Nectar clinic code or address\n" );
	exit( 1 );
}

echo "== Nectar production, read-only: $clinic ==\n";
$nectar = new VSPS_Nectar( $env['NECTAR_CLIENT_PROD_ID'], $env['NECTAR_CLIENT_PROD_SECRET'], '', 'UTC', 'production', $clinic );

$conn = $nectar->connection();
check( 'connection works', ! is_wp_error( $conn ) && 1 === count( $conn['locations'] ), is_wp_error( $conn ) ? $conn->get_error_message() : '' );
check( "clinic name comes from Nectar (not the fallback)", ! is_wp_error( $conn ) && 'Nectar clinic' !== $conn['org'], is_wp_error( $conn ) ? '' : $conn['org'] );
$tz_name = is_wp_error( $conn ) ? '' : $conn['locations'][0]['timezone'];
check( "timezone comes from Nectar (not the fallback)", '' !== $tz_name && 'UTC' !== $tz_name, $tz_name );

$info = $nectar->get_location_info( VSPS_Nectar::LOCATION_ID );
check( 'clinic drawer has an address and a phone', is_array( $info ) && '' !== $info['addressString'] && '' !== $info['phoneNumber'] );

$types = $nectar->get_bookable_types( VSPS_Nectar::LOCATION_ID );
check( 'at least one online-bookable type', ! is_wp_error( $types ) && count( $types ) > 0, is_wp_error( $types ) ? $types->get_error_message() : '' );
echo '      (types: ' . ( is_wp_error( $types ) ? '-' : implode( ', ', array_map( function ( $t ) { return $t['name'] . ' ' . $t['duration'] . ' min'; }, $types ) ) ) . ")\n";

if ( ! is_wp_error( $types ) && $types && '' !== $tz_name ) {
	$tz    = new DateTimeZone( $tz_name );
	$today = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
	check( 'today (inside the 24 h window) returns no times', array() === $nectar->get_available_times( VSPS_Nectar::LOCATION_ID, $types[0]['id'], $today ) );
	$total = 0;
	$error = '';
	for ( $d = 1; $d <= 7; $d++ ) {
		$day   = ( new DateTimeImmutable( '+' . $d . ' days', $tz ) )->format( 'Y-m-d' );
		$slots = $nectar->get_available_times( VSPS_Nectar::LOCATION_ID, $types[0]['id'], $day );
		if ( is_wp_error( $slots ) ) {
			$error = $day . ': ' . $slots->get_error_message();
			break;
		}
		$total += count( $slots );
		if ( $slots && ! isset( $first ) ) {
			$first = $day . ' ' . $slots[0]['time'] . ' ' . $slots[0]['provider']['name'];
		}
	}
	check( 'the next 7 days of times load without an error', '' === $error, $error );
	echo '      (' . $total . ' times in 7 days' . ( isset( $first ) ? ', first ' . $first : '' ) . ")\n";
}

check( 'an unknown email finds no owner (read-only lookup)', null === $nectar->find_client_by_email( 'zz-nobody-' . time() . '@example.com' ) );

echo "\n$passed passed, $failed failed\n";
