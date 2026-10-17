<?php
/**
 * Shared by bin/extract-contracts.php and tests/contract-recorder.php: how a
 * GraphQL operation is written in contracts/*.graphql.
 */

/** The PHP string literal's value (single-quoted literals only use \' and \\). */
function vsps_unquote( $literal ) {
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

/** One line, single spaces: the same operation always compares equal. */
function vsps_normalize_operation( $text ) {
	$text = preg_replace( '/\s+/', ' ', trim( $text ) );
	$text = preg_replace( '/\s*([{}(),:!])\s*/', '$1', $text );
	// Keep one space where GraphQL needs a separator between names.
	return preg_replace( '/([{(,])/', '$1 ', preg_replace( '/([})])/', ' $1', $text ) );
}

/** Every string literal in a PHP file that is a GraphQL query or mutation. */
function vsps_operations_in( $file ) {
	$ops = array();
	foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			continue;
		}
		$value = trim( vsps_unquote( $token[1] ) );
		if ( preg_match( '/^(?:(?:query|mutation)\b[^{]*)?\{\s*[A-Za-z_]/', $value ) && substr_count( $value, '{' ) === substr_count( $value, '}' ) ) {
			$ops[ vsps_normalize_operation( $value ) ] = true;
		}
	}
	$ops = array_keys( $ops );
	sort( $ops );
	return $ops;
}
