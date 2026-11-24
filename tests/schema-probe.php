<?php
/**
 * Read-only: prints the parts of the Vetspire and Nectar (sandbox) schemas
 * that the species list, the primary phone and "fill blanks on a client
 * already on file" depend on. Sends introspection queries only: no record is
 * read or written.
 *
 * php tests/schema-probe.php            (VETSPIRE_API_TOKEN in .env,
 *                                        NECTAR_CLIENT_ID / NECTAR_CLIENT_SECRET in .env.local)
 */

error_reporting( E_ALL );

function post_json( $url, array $headers, array $body ) {
	$lines = '';
	foreach ( $headers as $k => $v ) {
		$lines .= "$k: $v\r\n";
	}
	$raw = @file_get_contents( $url, false, stream_context_create( array( 'http' => array(
		'method' => 'POST', 'header' => $lines, 'content' => json_encode( $body ), 'timeout' => 30, 'ignore_errors' => true,
	) ) ) );
	return false === $raw ? null : json_decode( $raw, true );
}

const TYPE_REF = 'name kind ofType { name kind ofType { name kind ofType { name kind } } }';

function type_name( $ref ) {
	while ( is_array( $ref ) && empty( $ref['name'] ) && isset( $ref['ofType'] ) ) {
		$ref = $ref['ofType'];
	}
	return is_array( $ref ) && isset( $ref['name'] ) ? $ref['name'] : '?';
}

/** Root fields whose name matches $pattern, with their arguments and result type. */
function print_root( $label, callable $ask, $root, $pattern ) {
	$data   = $ask( '{ __schema { ' . $root . ' { fields { name args { name type { ' . TYPE_REF . ' } } type { ' . TYPE_REF . ' } } } } }' );
	$fields = isset( $data['data']['__schema'][ $root ]['fields'] ) ? $data['data']['__schema'][ $root ]['fields'] : null;
	if ( null === $fields ) {
		echo "  $label: introspection refused → " . json_encode( isset( $data['errors'] ) ? $data['errors'] : $data ) . "\n";
		return;
	}
	echo "  $label matching $pattern:\n";
	foreach ( $fields as $field ) {
		if ( preg_match( $pattern, $field['name'] ) ) {
			$args = array_map( function ( $a ) {
				return $a['name'] . ': ' . type_name( $a['type'] );
			}, $field['args'] );
			echo '    ' . $field['name'] . '(' . implode( ', ', $args ) . ') → ' . type_name( $field['type'] ) . "\n";
		}
	}
}

function print_type( callable $ask, $name ) {
	$data = $ask( '{ __type(name: "' . $name . '") { kind fields { name type { ' . TYPE_REF . ' } } inputFields { name type { ' . TYPE_REF . ' } } enumValues { name } } }' );
	$type = isset( $data['data']['__type'] ) ? $data['data']['__type'] : null;
	if ( ! $type ) {
		echo "  type $name: not found\n";
		return;
	}
	$names = array();
	foreach ( array( 'fields', 'inputFields' ) as $key ) {
		foreach ( is_array( $type[ $key ] ) ? $type[ $key ] : array() as $f ) {
			$names[] = $f['name'] . ': ' . type_name( $f['type'] );
		}
	}
	foreach ( is_array( $type['enumValues'] ) ? $type['enumValues'] : array() as $v ) {
		$names[] = $v['name'];
	}
	echo "  type $name ({$type['kind']}): " . implode( ', ', $names ) . "\n";
}

$pattern = '/species|update|edit|patient$|^client$|weight|phone/i';

echo "== Vetspire ==\n";
$env   = is_file( __DIR__ . '/../.env' ) ? parse_ini_file( __DIR__ . '/../.env' ) : array();
$token = isset( $env['VETSPIRE_API_TOKEN'] ) ? $env['VETSPIRE_API_TOKEN'] : '';
if ( '' === $token ) {
	echo "  VETSPIRE_API_TOKEN missing in .env: skipped\n";
} else {
	$ask = function ( $query ) use ( $token ) {
		return post_json( 'https://api.vetspire.com/graphql', array( 'Authorization' => $token, 'Content-Type' => 'application/json' ), array( 'query' => $query ) );
	};
	print_root( 'queries', $ask, 'queryType', $pattern );
	print_root( 'mutations', $ask, 'mutationType', $pattern );
	foreach ( array( 'PhoneNumber', 'ClientInput', 'PatientInput', 'Org', 'Location', 'Preferences' ) as $type ) {
		print_type( $ask, $type );
	}
}

echo "== Nectar (sandbox) ==\n";
$env = is_file( __DIR__ . '/../.env.local' ) ? parse_ini_file( __DIR__ . '/../.env.local', false, INI_SCANNER_RAW ) : array();
if ( empty( $env['NECTAR_CLIENT_ID'] ) || empty( $env['NECTAR_CLIENT_SECRET'] ) ) {
	echo "  NECTAR_CLIENT_ID / NECTAR_CLIENT_SECRET missing in .env.local: skipped\n";
} else {
	$login = post_json( 'https://nectarstaging.us.auth0.com/oauth/token', array( 'Content-Type' => 'application/json' ), array(
		'client_id' => $env['NECTAR_CLIENT_ID'], 'client_secret' => $env['NECTAR_CLIENT_SECRET'],
		'audience' => 'https://login.nectarvet.com', 'grant_type' => 'client_credentials',
	) );
	if ( empty( $login['access_token'] ) ) {
		echo "  sign-in refused\n";
	} else {
		$ask = function ( $query ) use ( $login ) {
			return post_json( 'https://dev-clinic.api.dev.nectar.vet/graphql', array(
				'Authorization' => 'Bearer ' . $login['access_token'], 'Content-Type' => 'application/json', 'X-Auth-Type' => 'server',
			), array( 'query' => $query ) );
		};
		print_root( 'queries', $ask, 'queryType', $pattern );
		print_root( 'mutations', $ask, 'mutationType', '/species|update|edit|patient|client|family|weight|vital/i' );
		foreach ( array( 'ClinicUserSchema', 'PatientSchema', 'PatientCreateSchema', 'SpeciesSchema' ) as $type ) {
			print_type( $ask, $type );
		}
	}
}
