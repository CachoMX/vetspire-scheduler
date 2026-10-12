<?php
/**
 * The practice management systems (PIMS) the scheduler can book into.
 *
 * Every platform adapter (VSPS_Api = Vetspire, VSPS_Nectar = Nectar) implements
 * VSPS_Pims and returns the same normalized shapes, so the REST layer, the
 * booking flow and the widget never need to know which platform is behind them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface VSPS_Pims {

	/** 'vetspire' | 'nectar' */
	public function platform();

	/**
	 * Connection check for Settings.
	 *
	 * @return array|WP_Error { org: string, locations: [ { id, name, timezone } ] }
	 */
	public function connection();

	/** @return array|null|WP_Error { id, name, timezone } */
	public function get_location( $location_id );

	/**
	 * Public clinic profile for the location drawer. Missing values are ''/null.
	 *
	 * @return array|null|WP_Error { id, name, displayName, timezone, addressString, phoneNumber,
	 *                              latitude, longitude, googleLink, url, locationHours }
	 */
	public function get_location_info( $location_id );

	/** Online-bookable appointment types. @return array|WP_Error [ { id, name, duration } ] */
	public function get_bookable_types( $location_id );

	/**
	 * Open times on one clinic-local date.
	 *
	 * @return array|WP_Error [ { time: 'HH:MM', providerId, scheduleId, provider: { id, name } } ]
	 */
	public function get_available_times( $location_id, $type_id, $date );

	/**
	 * Existing client by exact email (case-insensitive).
	 *
	 * @return array|null|WP_Error { id, email, givenName, familyName, phoneNumbers: [ { value } ],
	 *                              patients: [ { id, name, isActive, isDeceased, clientId? } ] }
	 */
	public function find_client_by_email( $email );

	/** Creates the pet owner from the sanitized booking args. @return array|WP_Error { id, ... } */
	public function create_booking_client( array $args );

	/** Creates the pet on that owner's account. @return array|WP_Error { id, name } */
	public function create_booking_patient( array $client, array $args );

	/**
	 * Creates the appointment (or, on request-based platforms, the appointment request).
	 *
	 * @param array $booking { location_id, appointment_type_id, client_id, patient_id, start (ISO 8601),
	 *                         duration (minutes), provider_id, schedule_id, reason }
	 * @return array|WP_Error { id, start }
	 */
	public function create_booking_appointment( array $booking );

	/** "How did you hear about us" options. @return array|WP_Error [ { id, name } ] */
	public function get_referral_sources();
}

/**
 * Static facts about each platform. Nothing here calls an API, so it is safe
 * on every page view (field lists, widget config, settings rendering).
 */
class VSPS_Pims_Registry {

	const DEFAULT_PLATFORM = 'vetspire';

	/** Optional booking-form fields each platform can store (VSPS_Fields keys). */
	const PLATFORMS = array(
		'vetspire' => array(
			'label'          => 'Vetspire',
			'booking'        => 'confirm', // the appointment is booked on the spot
			'min_lead_hours' => 0,
			'fields'         => array(
				'address', 'phone_alt', 'email_secondary', 'referral', 'title', 'pronouns', 'owner_dob',
				'business_name', 'client_notes', 'breed', 'mixed', 'sex', 'neutered', 'age', 'birth_date',
				'weight', 'color', 'microchip', 'pet_notes', 'reason',
			),
		),
		'nectar'   => array(
			'label'          => 'Nectar',
			'booking'        => 'request', // ONLINE_REQUESTED: the clinic confirms it
			'min_lead_hours' => 24,        // Nectar's default booking window (Harris, 2026-10-06)
			'fields'         => array(
				'address', 'phone_alt', 'owner_dob', 'breed', 'sex', 'neutered', 'age',
				'birth_date', 'weight', 'color', 'microchip', 'reason',
			),
		),
	);

	public static function platforms() {
		return array_keys( self::PLATFORMS );
	}

	/** A known platform key; anything else (old installs have none) is Vetspire. */
	public static function normalize( $platform ) {
		return isset( self::PLATFORMS[ $platform ] ) ? $platform : self::DEFAULT_PLATFORM;
	}

	public static function current() {
		$settings = vsps_get_settings();
		return self::normalize( isset( $settings['pims'] ) ? $settings['pims'] : '' );
	}

	public static function label( $platform = null ) {
		$platform = self::normalize( null === $platform ? self::current() : $platform );
		return self::PLATFORMS[ $platform ]['label'];
	}

	/** 'confirm' | 'request' */
	public static function booking_mode( $platform = null ) {
		$platform = self::normalize( null === $platform ? self::current() : $platform );
		return self::PLATFORMS[ $platform ]['booking'];
	}

	public static function min_lead_hours( $platform = null ) {
		$platform = self::normalize( null === $platform ? self::current() : $platform );
		return (int) self::PLATFORMS[ $platform ]['min_lead_hours'];
	}

	/** Whether an optional booking-form field can be saved on this platform. */
	public static function supports_field( $key, $platform = null ) {
		$platform = self::normalize( null === $platform ? self::current() : $platform );
		return in_array( $key, self::PLATFORMS[ $platform ]['fields'], true );
	}

	/**
	 * Platform record ids: Vetspire uses numbers, Nectar 24-hex MongoDB ids.
	 * Anything outside a short [A-Za-z0-9_-] token becomes '' (= missing).
	 */
	public static function sanitize_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value ) ? $value : '';
	}

	/**
	 * An id as the widget config carries it: Vetspire's stay numbers (so the
	 * analytics location_id keeps its old format), any other id is a string.
	 * '' / 0 stay falsy.
	 */
	public static function id_for_js( $value ) {
		$value = self::sanitize_id( $value );
		return ctype_digit( $value ) ? (int) $value : $value;
	}
}
