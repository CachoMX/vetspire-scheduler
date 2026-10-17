<?php
/**
 * Records which input-object fields each GraphQL operation sends, while the
 * real-API tests run (tests/harness.php, fields-e2e.php, nectar-e2e.php).
 * Only key NAMES are kept (e.g. "phoneNumbers[].value"), never values.
 *
 * The hub checks these paths against the live schema every 6 hours, so a field
 * Vetspire or Nectar drops from an input type (e.g. ClientInput.secondaryEmail)
 * raises an alert even though the query text itself still validates.
 *
 *   default                    fail the run if a recorded path is missing from
 *                              contracts/<pims>.inputs.json
 *   VSPS_UPDATE_CONTRACTS=1    merge what was recorded into those files
 */

require_once __DIR__ . '/../bin/contracts-lib.php';

$GLOBALS['vsps_recorded_inputs'] = array();

/** "a", "a.b", "list[]", "list[].c" for every key under an input value. */
function vsps_input_paths( $value, $prefix = '' ) {
	$paths = array();
	if ( ! is_array( $value ) ) {
		return $paths;
	}
	$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
	foreach ( $value as $key => $child ) {
		$path = $is_list ? $prefix . '[]' : ( '' === $prefix ? (string) $key : $prefix . '.' . $key );
		if ( ! $is_list ) {
			$paths[] = $path;
		}
		$paths = array_merge( $paths, vsps_input_paths( $child, $path ) );
	}
	return $paths;
}

/** Called from VSPS_Api::request() / VSPS_Nectar::request() when this file is loaded. */
function vsps_record_operation( $pims, $query, $variables ) {
	// Queries the tests send themselves (read-backs, cleanup) are not the plugin's contract.
	$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
	$caller = isset( $trace[1]['file'] ) ? realpath( dirname( $trace[1]['file'] ) ) : '';
	if ( realpath( __DIR__ ) === $caller ) {
		return;
	}
	$op = vsps_normalize_operation( $query );
	foreach ( (array) $variables as $name => $value ) {
		foreach ( vsps_input_paths( $value ) as $path ) {
			$GLOBALS['vsps_recorded_inputs'][ $pims ][ $op ][ $name ][ $path ] = true;
		}
	}
	if ( ! isset( $GLOBALS['vsps_recorded_inputs'][ $pims ][ $op ] ) ) {
		$GLOBALS['vsps_recorded_inputs'][ $pims ][ $op ] = array();
	}
}

function vsps_contract_inputs_file( $pims ) {
	return dirname( __DIR__ ) . '/contracts/' . $pims . '.inputs.json';
}

register_shutdown_function( function () {
	$update  = '1' === getenv( 'VSPS_UPDATE_CONTRACTS' );
	$missing = array();
	foreach ( $GLOBALS['vsps_recorded_inputs'] as $pims => $ops ) {
		$file      = vsps_contract_inputs_file( $pims );
		$committed = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
		$committed = is_array( $committed ) ? $committed : array();
		$static    = is_file( dirname( $file ) . '/' . $pims . '.graphql' ) ? file_get_contents( dirname( $file ) . '/' . $pims . '.graphql' ) : '';
		foreach ( $ops as $op => $vars ) {
			if ( false === strpos( $static, $op ) ) {
				$missing[] = $pims . ': operation not in contracts/' . $pims . '.graphql (run php bin/extract-contracts.php): ' . substr( $op, 0, 80 );
			}
			foreach ( $vars as $var => $paths ) {
				foreach ( array_keys( $paths ) as $path ) {
					if ( empty( $committed[ $op ][ $var ] ) || ! in_array( $path, $committed[ $op ][ $var ], true ) ) {
						$missing[] = $pims . ': $' . $var . '.' . $path . ' in ' . substr( $op, 0, 60 );
						$committed[ $op ][ $var ][] = $path;
					}
				}
			}
		}
		if ( $update ) {
			ksort( $committed );
			foreach ( $committed as $op => $vars ) {
				ksort( $vars );
				foreach ( $vars as $var => $paths ) {
					$paths = array_values( array_unique( $paths ) );
					sort( $paths );
					$vars[ $var ] = $paths;
				}
				$committed[ $op ] = $vars;
			}
			file_put_contents( $file, json_encode( $committed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		}
	}
	if ( $update ) {
		echo "\n[contracts] inputs merged into contracts/*.inputs.json\n";
		return;
	}
	if ( $missing ) {
		echo "\n[contracts] FAIL: input fields sent but not in contracts/*.inputs.json (re-run with VSPS_UPDATE_CONTRACTS=1):\n  - " . implode( "\n  - ", array_slice( $missing, 0, 30 ) ) . "\n";
		exit( 1 );
	}
	echo "\n[contracts] input fields match contracts/*.inputs.json\n";
} );
