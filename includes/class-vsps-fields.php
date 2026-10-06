<?php
/**
 * Booking-form fields: the registry of every visitor-fillable Vetspire field
 * the widget can ask for, and each clinic's "show" / "required" choices.
 *
 * Scopes decide when a field applies:
 *  - client: only on the new-client form (returning clients already have it in Vetspire);
 *  - pet:    only when a new pet is being created (new client, or returning client adding a pet);
 *  - visit:  every booking.
 * "Locked" fields cannot be hidden (and the core ones cannot be made optional):
 * Vetspire needs them to create a client, a patient and an appointment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Fields {

	/** key => [scope, label, locked_show, locked_required] — order is the Settings and form order. */
	const REGISTRY = array(
		'given_name'      => array( 'client', 'First name', true, true ),
		'family_name'     => array( 'client', 'Last name', true, true ),
		'email'           => array( 'client', 'Email', true, true ),
		'phone'           => array( 'client', 'Phone', true, true ),
		'address'         => array( 'client', 'Address (street, city, state, ZIP)', false, false ),
		'phone_alt'       => array( 'client', 'Alternate phone', false, false ),
		'email_secondary' => array( 'client', 'Secondary email', false, false ),
		'referral'        => array( 'client', 'How did you hear about us?', false, false ),
		'title'           => array( 'client', 'Title (Mr., Mrs., Dr.…)', false, false ),
		'pronouns'        => array( 'client', 'Pronouns', false, false ),
		'owner_dob'       => array( 'client', "Owner's date of birth", false, false ),
		'business_name'   => array( 'client', 'Business name', false, false ),
		'client_notes'    => array( 'client', 'Notes for the clinic', false, false ),
		'pet_name'        => array( 'pet', "Pet's name", true, true ),
		'species'         => array( 'pet', 'Species', true, true ),
		'breed'           => array( 'pet', 'Breed', false, false ),
		'mixed'           => array( 'pet', 'Mixed breed?', false, false ),
		'sex'             => array( 'pet', 'Sex', false, false ),
		'neutered'        => array( 'pet', 'Spayed / Neutered', false, false ),
		'age'             => array( 'pet', 'Age (years)', false, false ),
		'birth_date'      => array( 'pet', 'Birth date', false, false ),
		'weight'          => array( 'pet', 'Weight', false, false ),
		'color'           => array( 'pet', 'Color', false, false ),
		'microchip'       => array( 'pet', 'Microchip number', false, false ),
		'pet_notes'       => array( 'pet', 'Notes about the pet', false, false ),
		'reason'          => array( 'visit', 'Reason for visit', true, false ),
	);

	/** The four optional pet questions the plugin had before the full list (and variant="b"'s default). */
	const LEGACY = array( 'breed', 'sex', 'age', 'neutered' );

	const TITLES   = array( 'Mr.', 'Mrs.', 'Ms.', 'Mx.', 'Dr.' );
	const PRONOUNS = array( 'HE' => 'He/him', 'SHE' => 'She/her', 'THEY' => 'They/them', 'ZE' => 'Ze/zir', 'OTHER' => 'Other' );

	public static function locked_show( $key ) {
		return ! empty( self::REGISTRY[ $key ][2] );
	}

	public static function locked_required( $key ) {
		return ! empty( self::REGISTRY[ $key ][3] );
	}

	/**
	 * Every field's effective { show, req } for these settings. Locked flags
	 * always win; a hidden field is never required. Sites saved before the
	 * full list existed keep their four old pet questions (shown, optional).
	 */
	public static function resolve( array $settings, $variant = '' ) {
		$saved = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : null;
		$out   = array();
		foreach ( self::REGISTRY as $key => $def ) {
			if ( null !== $saved ) {
				$show = ! empty( $saved[ $key ]['show'] );
				$req  = ! empty( $saved[ $key ]['req'] );
			} else {
				$show = in_array( $key, self::LEGACY, true ) && ! empty( $settings[ 'ask_' . $key ] );
				$req  = false;
			}
			$show = self::locked_show( $key ) || $show;
			$req  = self::locked_required( $key ) || ( $show && $req );
			$out[ $key ] = array( 'show' => $show, 'req' => $req );
		}

		// A/B test: "a" = only the fields that can't be hidden; "b" = the configured
		// set, or the four classic pet questions when nothing optional is on.
		if ( 'a' === $variant ) {
			foreach ( $out as $key => $f ) {
				if ( ! self::locked_show( $key ) ) {
					$out[ $key ] = array( 'show' => false, 'req' => false );
				}
			}
		} elseif ( 'b' === $variant && ! self::any_optional_shown( $out ) ) {
			foreach ( self::LEGACY as $key ) {
				$out[ $key ]['show'] = true;
			}
		}
		return $out;
	}

	private static function any_optional_shown( array $resolved ) {
		foreach ( $resolved as $key => $f ) {
			if ( $f['show'] && ! self::locked_show( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/** Settings sanitize: posted [key => [show, req]] → stored map (locked flags forced). */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( self::REGISTRY as $key => $def ) {
			$row  = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$show = self::locked_show( $key ) || ! empty( $row['show'] );
			$req  = self::locked_required( $key ) || ( $show && ! empty( $row['req'] ) );
			$out[ $key ] = array( 'show' => $show ? 1 : 0, 'req' => $req ? 1 : 0 );
		}
		return $out;
	}

	/** Compact flags for the widget: key => 0 hidden | 1 optional | 2 required. */
	public static function for_widget( array $resolved ) {
		$out = array();
		foreach ( $resolved as $key => $f ) {
			$out[ $key ] = $f['show'] ? ( $f['req'] ? 2 : 1 ) : 0;
		}
		return $out;
	}

	/* ---------- booking request ---------- */

	/** Sanitized optional fields from the /book payload (empty string = not given). */
	public static function parse_extras( array $client, array $patient ) {
		$text = function ( $source, $key, $max ) {
			return self::cut( sanitize_text_field( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? (string) $source[ $key ] : '' ), $max );
		};
		$addr = isset( $client['address'] ) && is_array( $client['address'] ) ? $client['address'] : array();

		$title    = $text( $client, 'title', 10 );
		$pronouns = strtoupper( $text( $client, 'pronouns', 10 ) );
		$unit     = strtoupper( $text( $patient, 'weight_unit', 4 ) );
		$weight   = isset( $patient['weight'] ) && is_numeric( $patient['weight'] ) ? round( (float) $patient['weight'], 2 ) : 0;
		$mixed    = $text( $patient, 'mixed', 3 );
		$referral = $text( $client, 'referral_id', 20 );
		if ( '' !== $referral && ! in_array( $referral, wp_list_pluck( self::referral_sources(), 'id' ), true ) ) {
			$referral = '';
		}

		return array(
			'client'  => array(
				'address'         => array(
					'line1'  => $text( $addr, 'line1', 120 ),
					'line2'  => $text( $addr, 'line2', 120 ),
					'city'   => $text( $addr, 'city', 80 ),
					'state'  => $text( $addr, 'state', 40 ),
					'postal' => $text( $addr, 'postal', 12 ),
				),
				'phone_alt'       => substr( preg_replace( '/[^0-9+\-\s().]/', '', isset( $client['phone_alt'] ) && is_scalar( $client['phone_alt'] ) ? (string) $client['phone_alt'] : '' ), 0, 30 ),
				'email_secondary' => substr( sanitize_email( isset( $client['email_secondary'] ) && is_scalar( $client['email_secondary'] ) ? (string) $client['email_secondary'] : '' ), 0, 190 ),
				'referral_id'     => $referral,
				'title'           => in_array( $title, self::TITLES, true ) ? $title : '',
				'pronouns'        => isset( self::PRONOUNS[ $pronouns ] ) ? $pronouns : '',
				'owner_dob'       => self::valid_past_date( $text( $client, 'owner_dob', 10 ), 120 ),
				'business_name'   => $text( $client, 'business_name', 120 ),
				'notes'           => self::cut( sanitize_textarea_field( isset( $client['notes'] ) && is_scalar( $client['notes'] ) ? (string) $client['notes'] : '' ), 1000 ),
			),
			'patient' => array(
				'mixed'           => in_array( $mixed, array( 'yes', 'no' ), true ) ? $mixed : '',
				'birth_date'      => self::valid_past_date( $text( $patient, 'birth_date', 10 ), 40 ),
				'weight'          => $weight > 0 && $weight <= 2000 ? $weight : 0,
				'weight_unit'     => in_array( $unit, array( 'LB', 'KG' ), true ) ? $unit : 'LB',
				'color'           => $text( $patient, 'color', 60 ),
				'microchip'       => substr( preg_replace( '/[^A-Za-z0-9]/', '', isset( $patient['microchip'] ) && is_scalar( $patient['microchip'] ) ? (string) $patient['microchip'] : '' ), 0, 25 ),
				'notes'           => self::cut( sanitize_textarea_field( isset( $patient['notes'] ) && is_scalar( $patient['notes'] ) ? (string) $patient['notes'] : '' ), 1000 ),
			),
		);
	}

	/** Truncates without splitting a multibyte character (names, notes in any language). */
	private static function cut( $value, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
	}

	/** A Y-m-d date in the past, no older than $max_years; '' otherwise. */
	private static function valid_past_date( $value, $max_years ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		$today = gmdate( 'Y-m-d' );
		$floor = gmdate( 'Y-m-d', strtotime( '-' . (int) $max_years . ' years' ) );
		return ( $value <= $today && $value >= $floor ) ? $value : '';
	}

	/** Which value of $args a field reads (array path), or null for the locked core fields checked elsewhere. */
	private static function path( $key ) {
		$paths = array(
			'address'         => array( 'client', 'address', 'line1' ),
			'phone_alt'       => array( 'client', 'phone_alt' ),
			'email_secondary' => array( 'client', 'email_secondary' ),
			'referral'        => array( 'client', 'referral_id' ),
			'title'           => array( 'client', 'title' ),
			'pronouns'        => array( 'client', 'pronouns' ),
			'owner_dob'       => array( 'client', 'owner_dob' ),
			'business_name'   => array( 'client', 'business_name' ),
			'client_notes'    => array( 'client', 'notes' ),
			'breed'           => array( 'patient', 'breed' ),
			'mixed'           => array( 'patient', 'mixed' ),
			'sex'             => array( 'patient', 'sex' ),
			'neutered'        => array( 'patient', 'neutered' ),
			'age'             => array( 'patient', 'age' ),
			'birth_date'      => array( 'patient', 'birth_date' ),
			'weight'          => array( 'patient', 'weight' ),
			'color'           => array( 'patient', 'color' ),
			'microchip'       => array( 'patient', 'microchip' ),
			'pet_notes'       => array( 'patient', 'notes' ),
			'reason'          => array( 'notes' ),
		);
		return isset( $paths[ $key ] ) ? $paths[ $key ] : null;
	}

	private static function is_blank( array $args, $key ) {
		$value = $args;
		foreach ( self::path( $key ) as $part ) {
			$value = isset( $value[ $part ] ) ? $value[ $part ] : '';
		}
		if ( 'address' === $key ) {
			$a = $args['client']['address'];
			return '' === $a['line1'] || '' === $a['city'] || '' === $a['state'] || '' === $a['postal'];
		}
		if ( 'weight' === $key ) {
			return empty( $value );
		}
		return '' === $value || null === $value; // age 0 (under a year) is an answer
	}

	private static function blank( array &$args, $key ) {
		if ( 'address' === $key ) {
			$args['client']['address'] = array_fill_keys( array( 'line1', 'line2', 'city', 'state', 'postal' ), '' );
			return;
		}
		$path = self::path( $key );
		$ref  = &$args;
		foreach ( array_slice( $path, 0, -1 ) as $part ) {
			$ref = &$ref[ $part ];
		}
		$last         = end( $path );
		$ref[ $last ] = 'weight' === $key ? 0 : '';
		unset( $ref );
	}

	/**
	 * Data governance + required check, server-side (the form's own checks can
	 * be bypassed). Hidden fields, and fields outside this booking's scope, are
	 * blanked so they never reach Vetspire; a shown + required field that is
	 * empty fails the booking before anything is created.
	 *
	 * @return WP_Error|null
	 */
	public static function enforce( array &$args, array $resolved ) {
		$missing = array();
		foreach ( self::REGISTRY as $key => $def ) {
			if ( null === self::path( $key ) ) {
				continue; // core fields: validated by VSPS_Rest
			}
			$scope   = $def[0];
			$applies = 'visit' === $scope
				|| ( 'client' === $scope && 'new' === $args['client_type'] )
				|| ( 'pet' === $scope && ! empty( $args['pet_is_new'] ) );
			if ( ! $applies || ! $resolved[ $key ]['show'] ) {
				self::blank( $args, $key );
				continue;
			}
			// The widget hides "How did you hear about us?" when Vetspire returns no
			// list (none configured, or Vetspire unreachable): it can't be demanded then.
			if ( 'referral' === $key && $resolved[ $key ]['req'] && ! self::referral_sources() ) {
				continue;
			}
			if ( $resolved[ $key ]['req'] && self::is_blank( $args, $key ) ) {
				$missing[] = preg_replace( '/\s*\(.*\)$/', '', $def[1] );
			}
		}
		if ( $missing ) {
			return new WP_Error( 'vsps_required', 'Please fill in: ' . implode( ', ', $missing ) . '.', array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * The clinic's own "how did you hear about us" list from Vetspire (cached
	 * 12 h). Empty on failure: the widget then hides the question instead of
	 * offering choices it can't save.
	 */
	public static function referral_sources() {
		$api = vsps_api();
		if ( null === $api ) {
			return array();
		}
		if ( get_transient( VSPS_Cache::PREFIX . 'refsrc_fail' ) ) {
			return array();
		}
		$list = VSPS_Cache::remember( array( 'referral-sources' ), function () use ( $api ) {
			return $api->get_referral_sources();
		}, 12 * HOUR_IN_SECONDS );
		if ( ! is_array( $list ) ) {
			// Errors are never cached by VSPS_Cache; remember this one briefly so
			// every page view doesn't retry a slow or failing Vetspire.
			set_transient( VSPS_Cache::PREFIX . 'refsrc_fail', 1, 5 * MINUTE_IN_SECONDS );
			return array();
		}
		$out = array();
		foreach ( $list as $row ) {
			if ( isset( $row['id'], $row['name'] ) && '' !== (string) $row['name'] ) {
				$out[] = array( 'id' => (string) $row['id'], 'name' => (string) $row['name'] );
			}
		}
		return $out;
	}
}
