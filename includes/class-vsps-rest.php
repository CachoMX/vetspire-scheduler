<?php
/**
 * Public REST endpoints. These proxy the Vetspire API server-side so the
 * admin token never reaches the browser. Read endpoints are cached;
 * the booking endpoint is rate-limited per IP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Rest {

	const NS              = 'vetspire/v1';
	const BOOK_RATE_LIMIT = 5;      // bookings per IP...
	const BOOK_RATE_TTL   = 3600;   // ...per hour.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$numeric = array(
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && (int) $value > 0;
			},
		);

		register_rest_route( self::NS, '/types', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_types' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'location_id' => $numeric,
			),
		) );

		register_rest_route( self::NS, '/availability', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_availability' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'location_id'         => $numeric,
				'appointment_type_id' => $numeric,
				'start_date'          => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'days'                => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 7 ),
			),
		) );

		register_rest_route( self::NS, '/location-info', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_location_info' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'location_id' => $numeric,
			),
		) );

		register_rest_route( self::NS, '/lookup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'lookup_client' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/book', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'book' ),
			'permission_callback' => '__return_true',
		) );

	}

	private static function api_or_error() {
		$api = vsps_api();
		if ( null === $api ) {
			return new WP_Error( 'vsps_no_token', 'Vetspire API token is not configured.', array( 'status' => 503 ) );
		}
		return $api;
	}

	/**
	 * When the admin configured location(s), only those may be queried/booked
	 * (prevents exposing sibling locations of the same Vetspire org).
	 */
	private static function location_allowed( $location_id ) {
		// Admins may preview any org location from the settings page
		// (authenticated REST via X-WP-Nonce); visitors stay restricted.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$settings = vsps_get_settings();
		$allowed  = array();
		if ( '' !== (string) $settings['default_location'] ) {
			$allowed[] = absint( $settings['default_location'] );
		}
		if ( ! empty( $settings['allowed_locations'] ) ) {
			foreach ( explode( ',', (string) $settings['allowed_locations'] ) as $id ) {
				$id = absint( trim( $id ) );
				if ( $id ) {
					$allowed[] = $id;
				}
			}
		}
		// Nothing configured yet → no restriction (fresh install).
		return empty( $allowed ) || in_array( absint( $location_id ), $allowed, true );
	}

	public static function get_types( WP_REST_Request $request ) {
		$api = self::api_or_error();
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$location_id = $request['location_id'];
		if ( ! self::location_allowed( $location_id ) ) {
			return new WP_Error( 'vsps_location', 'This location is not enabled for online booking.', array( 'status' => 403 ) );
		}
		$types       = VSPS_Cache::remember( array( 'types', $location_id ), function () use ( $api, $location_id ) {
			return $api->get_bookable_types( $location_id );
		}, 3600 );
		if ( is_wp_error( $types ) ) {
			return new WP_Error( 'vsps_upstream', 'Could not load appointment types.', array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'types' => $types ) );
	}

	public static function get_availability( WP_REST_Request $request ) {
		$api = self::api_or_error();
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$location_id = $request['location_id'];
		if ( ! self::location_allowed( $location_id ) ) {
			return new WP_Error( 'vsps_location', 'This location is not enabled for online booking.', array( 'status' => 403 ) );
		}
		$type_id = $request['appointment_type_id'];
		$days    = min( 14, max( 1, (int) $request['days'] ) );

		$start = self::valid_date( $request['start_date'] );
		if ( null === $start ) {
			$start = new DateTimeImmutable( 'today', wp_timezone() );
		}
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		if ( $start < $today || $start > $today->modify( '+90 days' ) ) {
			$start = $today;
		}

		$result = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$date  = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
			$slots = VSPS_Cache::remember(
				array( 'avail', $location_id, $type_id, $date ),
				function () use ( $api, $location_id, $type_id, $date ) {
					return $api->get_available_times( $location_id, $type_id, $date );
				}
			);
			if ( is_wp_error( $slots ) ) {
				$slots = array();
			}
			usort( $slots, function ( $a, $b ) {
				return strcmp( $a['time'], $b['time'] );
			} );
			$result[] = array(
				'date'  => $date,
				'slots' => $slots,
			);
		}
		return rest_ensure_response( array( 'days' => $result ) );
	}

	/** Clinic profile + computed open/closed status for the location drawer. */
	public static function get_location_info( WP_REST_Request $request ) {
		$api = self::api_or_error();
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$location_id = $request['location_id'];
		if ( ! self::location_allowed( $location_id ) ) {
			return new WP_Error( 'vsps_location', 'This location is not enabled for online booking.', array( 'status' => 403 ) );
		}
		$info = VSPS_Cache::remember( array( 'locinfo', (int) $location_id ), function () use ( $api, $location_id ) {
			return $api->get_location_info( $location_id );
		}, 300 );
		if ( is_wp_error( $info ) || null === $info ) {
			return new WP_Error( 'vsps_upstream', 'Could not load location info.', array( 'status' => 502 ) );
		}

		$hours  = isset( $info['locationHours'] ) ? $info['locationHours'] : null;
		$status = self::open_status( $hours, $info['timezone'] );

		// Only public-safe fields leave the server.
		return rest_ensure_response( array(
			'name'       => $info['displayName'] ? $info['displayName'] : $info['name'],
			'address'    => (string) $info['addressString'],
			'phone'      => (string) $info['phoneNumber'],
			'googleLink' => (string) $info['googleLink'],
			'website'    => (string) $info['url'],
			'latitude'   => $info['latitude'],
			'longitude'  => $info['longitude'],
			'status'     => $status,
			'weekly'     => self::weekly_hours( $hours ),
		) );
	}

	/** Formats minute-of-day ranges into an "open now / opens next" status. */
	private static function open_status( $hours, $timezone ) {
		if ( empty( $hours ) || empty( $timezone ) ) {
			return null;
		}
		try {
			$now = new DateTimeImmutable( 'now', new DateTimeZone( $timezone ) );
		} catch ( Exception $e ) {
			return null;
		}
		$keys    = array( 'sundayRanges', 'mondayRanges', 'tuesdayRanges', 'wednesdayRanges', 'thursdayRanges', 'fridayRanges', 'saturdayRanges' );
		$minutes = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		$dow     = (int) $now->format( 'w' );

		$today = isset( $hours[ $keys[ $dow ] ] ) && is_array( $hours[ $keys[ $dow ] ] ) ? $hours[ $keys[ $dow ] ] : array();
		foreach ( $today as $range ) {
			if ( $minutes >= $range[0] && $minutes < $range[1] ) {
				return array(
					'open'  => true,
					'label' => 'Open Today Until ' . self::fmt_minutes( $range[1] ),
				);
			}
		}
		// Closed: find the next opening within a week.
		foreach ( $today as $range ) {
			if ( $minutes < $range[0] ) {
				return array(
					'open'  => false,
					'label' => 'Closed · Opens Today at ' . self::fmt_minutes( $range[0] ),
				);
			}
		}
		for ( $i = 1; $i <= 7; $i++ ) {
			$next   = ( $dow + $i ) % 7;
			$ranges = isset( $hours[ $keys[ $next ] ] ) && is_array( $hours[ $keys[ $next ] ] ) ? $hours[ $keys[ $next ] ] : array();
			if ( ! empty( $ranges ) ) {
				$day = 1 === $i ? 'Tomorrow' : $now->modify( "+{$i} days" )->format( 'l' );
				return array(
					'open'  => false,
					'label' => 'Closed · Opens ' . $day . ' at ' . self::fmt_minutes( $ranges[0][0] ),
				);
			}
		}
		return null;
	}

	/** Weekly hours as [day, "9:00 AM – 7:00 PM"|"Closed"] rows, Monday first. */
	private static function weekly_hours( $hours ) {
		if ( empty( $hours ) ) {
			return array();
		}
		$days = array(
			'Monday'    => 'mondayRanges',
			'Tuesday'   => 'tuesdayRanges',
			'Wednesday' => 'wednesdayRanges',
			'Thursday'  => 'thursdayRanges',
			'Friday'    => 'fridayRanges',
			'Saturday'  => 'saturdayRanges',
			'Sunday'    => 'sundayRanges',
		);
		$out = array();
		foreach ( $days as $label => $key ) {
			$ranges = isset( $hours[ $key ] ) && is_array( $hours[ $key ] ) ? $hours[ $key ] : array();
			$parts  = array();
			foreach ( $ranges as $range ) {
				$parts[] = self::fmt_minutes( $range[0] ) . ' – ' . self::fmt_minutes( $range[1] );
			}
			$out[] = array( $label, $parts ? implode( ', ', $parts ) : 'Closed' );
		}
		return $out;
	}

	private static function fmt_minutes( $mins ) {
		$mins = (int) $mins;
		$h    = intdiv( $mins, 60 ) % 24;
		$m    = $mins % 60;
		$suffix = $h >= 12 ? 'PM' : 'AM';
		$h12    = 0 === $h % 12 ? 12 : $h % 12;
		return $h12 . ':' . str_pad( (string) $m, 2, '0', STR_PAD_LEFT ) . ' ' . $suffix;
	}

	/**
	 * Returning-client lookup: email in, active pet NAMES out. Never returns
	 * ids, owner name or phone - the booking path re-resolves everything
	 * server-side from the email. Hard rate-limited (enumeration protection).
	 */
	public static function lookup_client( WP_REST_Request $request ) {
		$api = self::api_or_error();
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		if ( '' !== (string) $request->get_param( 'vsps_hp' ) ) {
			return new WP_Error( 'vsps_spam', 'Lookup rejected.', array( 'status' => 400 ) );
		}
		$location_id = absint( $request->get_param( 'location_id' ) );
		if ( ! $location_id || ! self::location_allowed( $location_id ) ) {
			return new WP_Error( 'vsps_location', 'This location is not enabled for online booking.', array( 'status' => 403 ) );
		}
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'vsps_invalid', 'A valid email is required.', array( 'status' => 400 ) );
		}
		if ( ! self::rate_limit_ok( 'lk_' . md5( self::client_ip() ), 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'vsps_rate', 'Too many lookups. Please try again later or continue as a new client.', array( 'status' => 429 ) );
		}

		$client = $api->find_client_by_email( $email );
		if ( is_wp_error( $client ) ) {
			return new WP_Error( 'vsps_upstream', 'Lookup failed. Please continue as a new client.', array( 'status' => 502 ) );
		}
		$pets = array();
		if ( $client && ! empty( $client['patients'] ) ) {
			foreach ( $client['patients'] as $patient ) {
				$active   = ! isset( $patient['isActive'] ) || $patient['isActive'];
				$deceased = ! empty( $patient['isDeceased'] );
				if ( $active && ! $deceased ) {
					$pets[] = (string) $patient['name'];
				}
			}
		}
		return rest_ensure_response( array(
			'found' => null !== $client,
			'pets'  => $pets,
		) );
	}

	public static function book( WP_REST_Request $request ) {
		$api = self::api_or_error();
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		// Honeypot: bots fill every field; humans never see this one. The field
		// name is deliberately meaningless — browser autofill matches fields
		// named "website" and was rejecting real bookings.
		if ( '' !== (string) $request->get_param( 'vsps_hp' ) ) {
			return new WP_Error( 'vsps_spam', 'Booking rejected.', array( 'status' => 400 ) );
		}

		// Validate BEFORE consuming rate-limit quota, so typos don't lock out real users.
		$args = self::validate_booking( $request );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( ! self::location_allowed( $args['location_id'] ) ) {
			return new WP_Error( 'vsps_location', 'This location is not enabled for online booking.', array( 'status' => 403 ) );
		}

		if ( ! self::rate_limit_ok( 'ip_' . md5( self::client_ip() ), self::BOOK_RATE_LIMIT, self::BOOK_RATE_TTL )
			|| ! self::rate_limit_ok( 'em_' . md5( strtolower( $args['client']['email'] ) ), self::BOOK_RATE_LIMIT, DAY_IN_SECONDS ) ) {
			return new WP_Error( 'vsps_rate', 'Too many booking attempts. Please call the clinic.', array( 'status' => 429 ) );
		}

		$settings = vsps_get_settings();
		$args['source_label'] = isset( $settings['source_label'] ) && '' !== trim( (string) $settings['source_label'] )
			? substr( sanitize_text_field( $settings['source_label'] ), 0, 40 ) : 'Online';

		$result = VSPS_Booking::book( $api, $args );
		if ( is_wp_error( $result ) ) {
			// Slot-taken / type-not-bookable are safe, actionable messages for the visitor.
			$code = $result->get_error_code();
			error_log( '[vetspire-scheduler] booking failed (' . $code . '): ' . self::redact( $result->get_error_message() ) );
			if ( in_array( $code, array( 'vsps_slot', 'vsps_type', 'vsps_datetime', 'vsps_client_missing', 'vsps_pet_missing' ), true ) ) {
				return new WP_Error( $code, $result->get_error_message(), array( 'status' => 409 ) );
			}
			return new WP_Error( 'vsps_booking', 'We could not complete the booking. Please try another time or call the clinic.', array( 'status' => 502 ) );
		}

		// The just-booked slot is gone: refresh this day's cached availability.
		VSPS_Cache::forget( array( 'avail', (int) $args['location_id'], (int) $args['appointment_type_id'], $args['date'] ) );

		// Booked during vs after office hours (computed server-side, clinic timezone).
		$after_hours = null;
		$booked_at   = null;
		$info        = VSPS_Cache::remember( array( 'locinfo', (int) $args['location_id'] ), function () use ( $api, $args ) {
			return $api->get_location_info( $args['location_id'] );
		}, 300 );
		if ( ! is_wp_error( $info ) && null !== $info && ! empty( $info['timezone'] ) ) {
			try {
				$booked_at = ( new DateTimeImmutable( 'now', new DateTimeZone( $info['timezone'] ) ) )->format( 'c' );
			} catch ( Exception $e ) {
				$booked_at = null;
			}
			$status = self::open_status( isset( $info['locationHours'] ) ? $info['locationHours'] : null, $info['timezone'] );
			if ( null !== $status ) {
				$after_hours = ! $status['open'];
			}
		}

		return rest_ensure_response( array(
			'success'        => true,
			'appointment_id' => $result['appointment_id'],
			'start'          => $result['start'],
			'after_hours'    => $after_hours,
			'booked_at'      => $booked_at,
		) );
	}

	/** Validates and sanitizes the booking payload. */
	private static function validate_booking( WP_REST_Request $request ) {
		$client  = (array) $request->get_param( 'client' );
		$patient = (array) $request->get_param( 'patient' );

		$client_type = 'existing' === (string) $request->get_param( 'client_type' ) ? 'existing' : 'new';

		$args = array(
			'client_type'         => $client_type,
			// Pending product sign-off: returning clients may only book for pets
			// already on file — never create records with just an email (QA batch 3).
			'pet_is_new'          => 'existing' === $client_type ? false : (bool) $request->get_param( 'pet_is_new' ),
			'location_id'         => absint( $request->get_param( 'location_id' ) ),
			'appointment_type_id' => absint( $request->get_param( 'appointment_type_id' ) ),
			'date'                => sanitize_text_field( (string) $request->get_param( 'date' ) ),
			'time'                => sanitize_text_field( (string) $request->get_param( 'time' ) ),
			'provider_id'         => sanitize_text_field( (string) $request->get_param( 'provider_id' ) ),
			'schedule_id'         => sanitize_text_field( (string) $request->get_param( 'schedule_id' ) ),
			'notes'               => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'client'              => array(
				'given_name'  => sanitize_text_field( isset( $client['given_name'] ) ? $client['given_name'] : '' ),
				'family_name' => sanitize_text_field( isset( $client['family_name'] ) ? $client['family_name'] : '' ),
				'email'       => sanitize_email( isset( $client['email'] ) ? $client['email'] : '' ),
				'phone'       => preg_replace( '/[^0-9+\-\s().]/', '', isset( $client['phone'] ) ? $client['phone'] : '' ),
			),
			'patient'             => array(
				'name'     => sanitize_text_field( isset( $patient['name'] ) ? $patient['name'] : '' ),
				'species'  => sanitize_text_field( isset( $patient['species'] ) ? $patient['species'] : '' ),
				'breed'    => sanitize_text_field( isset( $patient['breed'] ) ? $patient['breed'] : '' ),
				'sex'      => self::valid_sex( isset( $patient['sex'] ) ? $patient['sex'] : '' ),
				'age'      => min( 40, absint( isset( $patient['age'] ) ? $patient['age'] : 0 ) ),
				'neutered' => in_array( isset( $patient['neutered'] ) ? $patient['neutered'] : '', array( 'yes', 'no' ), true ) ? $patient['neutered'] : '',
			),
		);

		if ( ! $args['location_id'] || ! $args['appointment_type_id'] ) {
			return new WP_Error( 'vsps_invalid', 'Missing location or appointment type.', array( 'status' => 400 ) );
		}
		$booking_date = self::valid_date( $args['date'] );
		if ( null === $booking_date || ! preg_match( '/^\d{2}:\d{2}$/', $args['time'] ) ) {
			return new WP_Error( 'vsps_invalid', 'Invalid date or time.', array( 'status' => 400 ) );
		}
		// Defense-in-depth window (slot re-validation is the real guard). The
		// -1 day lower bound absorbs WP-vs-clinic timezone skew at midnight.
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		if ( $booking_date < $today->modify( '-1 day' ) || $booking_date > $today->modify( '+90 days' ) ) {
			return new WP_Error( 'vsps_invalid', 'Booking date out of range.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $args['client']['email'] ) ) {
			return new WP_Error( 'vsps_invalid', 'A valid email is required.', array( 'status' => 400 ) );
		}
		if ( 'existing' === $client_type ) {
			// Returning clients only need email + pet; name/phone live in Vetspire.
			if ( '' === $args['patient']['name'] ) {
				return new WP_Error( 'vsps_invalid', 'Pet name is required.', array( 'status' => 400 ) );
			}
			if ( $args['pet_is_new'] && '' === $args['patient']['species'] ) {
				return new WP_Error( 'vsps_invalid', 'Pet species is required.', array( 'status' => 400 ) );
			}
			return $args;
		}
		if ( '' === $args['client']['given_name'] || '' === $args['client']['family_name'] ) {
			return new WP_Error( 'vsps_invalid', 'First and last name are required.', array( 'status' => 400 ) );
		}
		if ( strlen( preg_replace( '/\D/', '', $args['client']['phone'] ) ) < 7 ) {
			return new WP_Error( 'vsps_invalid', 'A valid phone number is required.', array( 'status' => 400 ) );
		}
		if ( '' === $args['patient']['name'] || '' === $args['patient']['species'] ) {
			return new WP_Error( 'vsps_invalid', 'Pet name and species are required.', array( 'status' => 400 ) );
		}
		return $args;
	}

	private static function valid_sex( $sex ) {
		$allowed = array( 'MALE', 'FEMALE', 'UNKNOWN' );
		$sex     = strtoupper( sanitize_text_field( $sex ) );
		return in_array( $sex, $allowed, true ) ? $sex : '';
	}

	private static function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		try {
			return new DateTimeImmutable( $date, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Visitor IP for rate limiting. REMOTE_ADDR by default (never trusts
	 * spoofable headers). Sites behind Cloudflare where REMOTE_ADDR is the
	 * edge IP can hook `vsps_client_ip` to return CF-Connecting-IP after
	 * verifying the peer is a Cloudflare range.
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return apply_filters( 'vsps_client_ip', $ip );
	}

	/** Strips email-like strings before a message reaches error_log. */
	private static function redact( $message ) {
		return preg_replace( '/[^\s@]+@[^\s@]+/', '[email]', (string) $message );
	}

	private static function rate_limit_ok( $bucket, $limit, $ttl ) {
		$key   = 'vsps_rl_' . $bucket;
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		// Transients aren't atomic; a burst can slightly exceed the cap. Acceptable
		// here — the cap is a soft brake, and slot re-validation limits real damage.
		set_transient( $key, $count + 1, $ttl );
		return true;
	}
}
