<?php
/**
 * Nectar (NectarVet) adapter: server-side GraphQL client for the Nectar API.
 *
 * Auth is OAuth client credentials (Auth0); the access token is cached in a
 * transient and never reaches the browser. One set of credentials = one clinic,
 * so Nectar exposes a single "location" (id LOCATION_ID); multi-location clinics
 * pass their metaLocation code.
 *
 * Verified against the Nectar sandbox (2026-10-12): token, appointmentTypes,
 * clientsPaginated (email lookup), clientPatients, species.
 * NOT yet callable with a server token (Nectar is enabling them, Harris
 * 2026-10-06): getAvailableAppointmentSlots, addOnlineAppointment,
 * scheduleAndRequestAppointment. `clinics` answers 403 until whitelisted, so the
 * clinic timezone falls back to the one chosen in Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Nectar implements VSPS_Pims {

	const LOCATION_ID         = 'clinic';
	const AUTH_AUDIENCE       = 'https://login.nectarvet.com';
	const SANDBOX_AUTH_URL    = 'https://nectarstaging.us.auth0.com/oauth/token';
	const SANDBOX_API_URL     = 'https://dev-clinic.api.dev.nectar.vet/graphql';
	const PRODUCTION_AUTH_URL = 'https://nectarvet.us.auth0.com/oauth/token';
	// Production GraphQL address: not published yet; set VSPS_NECTAR_API_URL in wp-config.php.

	private $client_id;
	private $client_secret;
	private $meta_location;
	private $fallback_timezone;
	private $auth_url;
	private $api_url;

	/**
	 * @param string $environment 'sandbox' (Nectar's test clinic) or 'production'. Each
	 *                            environment only ever receives its own credentials.
	 */
	public function __construct( $client_id, $client_secret, $meta_location = '', $fallback_timezone = '', $environment = 'sandbox' ) {
		$this->client_id         = (string) $client_id;
		$this->client_secret     = (string) $client_secret;
		$this->meta_location     = (string) $meta_location;
		$this->fallback_timezone = (string) $fallback_timezone;
		if ( 'production' === $environment ) {
			$this->auth_url = defined( 'VSPS_NECTAR_AUTH_URL' ) && VSPS_NECTAR_AUTH_URL ? VSPS_NECTAR_AUTH_URL : self::PRODUCTION_AUTH_URL;
			$this->api_url  = defined( 'VSPS_NECTAR_API_URL' ) && VSPS_NECTAR_API_URL ? VSPS_NECTAR_API_URL : '';
		} else {
			$this->auth_url = self::SANDBOX_AUTH_URL;
			$this->api_url  = self::SANDBOX_API_URL;
		}
	}

	public function platform() {
		return 'nectar';
	}

	/* ---------- transport ---------- */

	private function token_key() {
		return VSPS_Cache::PREFIX . 'nectar_tok_' . md5( $this->client_id . '|' . $this->auth_url );
	}

	/** Cached OAuth access token (client credentials). */
	private function access_token( $force = false ) {
		if ( '' === $this->api_url ) {
			// Fail before any secret leaves the site.
			return new WP_Error( 'vsps_config', 'Nectar production is not enabled yet: its API address has not been confirmed by Nectar.' );
		}
		$key = $this->token_key();
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		$response = wp_remote_post( $this->auth_url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'audience'      => self::AUTH_AUDIENCE,
				'grant_type'    => 'client_credentials',
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $json['access_token'] ) ) {
			return new WP_Error( 'vsps_auth', 'Nectar did not accept these credentials (HTTP ' . $code . ').' );
		}
		$ttl = isset( $json['expires_in'] ) ? (int) $json['expires_in'] : 3600;
		set_transient( $key, $json['access_token'], max( 60, $ttl - 120 ) );
		return $json['access_token'];
	}

	/**
	 * Executes a GraphQL request. Nectar puts a JSON blob in errors[].message
	 * ({"exception","status_code","context"}); it is unpacked into the WP_Error.
	 *
	 * @return array|WP_Error Decoded `data` on success.
	 */
	public function request( $query, $variables = array(), $retry = true ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		// Tests record which input fields each operation sends (contracts/*.inputs.json);
		// the function only exists in the test harness.
		if ( function_exists( 'vsps_record_operation' ) ) {
			vsps_record_operation( 'nectar', $query, $variables );
		}
		$body = array( 'query' => $query );
		if ( ! empty( $variables ) ) {
			$body['variables'] = $variables;
		}
		$response = wp_remote_post( $this->api_url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'X-Auth-Type'   => 'server',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code && $retry ) {
			$this->access_token( true ); // expired or revoked token: fetch a new one once
			return $this->request( $query, $variables, false );
		}
		if ( 200 !== $code || null === $json ) {
			return new WP_Error( 'vsps_http', 'Nectar API returned HTTP ' . $code );
		}
		if ( ! empty( $json['errors'] ) ) {
			$message = isset( $json['errors'][0]['message'] ) ? (string) $json['errors'][0]['message'] : 'GraphQL error';
			$status  = 0;
			$inner   = json_decode( $message, true );
			if ( is_array( $inner ) ) {
				$status  = isset( $inner['status_code'] ) ? (int) $inner['status_code'] : 0;
				$message = isset( $inner['context'] ) ? (string) $inner['context'] : $message;
			}
			return new WP_Error( 'vsps_graphql', $message, array( 'status' => $status, 'errors' => $json['errors'] ) );
		}
		return isset( $json['data'] ) ? $json['data'] : array();
	}

	/* ---------- clinic ---------- */

	/**
	 * The clinic's profile from `clinics` (cached 12 h). Null when Nectar does
	 * not let this token read it (403 until the endpoint is whitelisted).
	 */
	private function clinic_profile() {
		$data = VSPS_Cache::remember( array( 'nectar-clinic', md5( $this->client_id ), $this->meta_location ), function () {
			$data = $this->request( '{ clinics { id name street city state zipCode phone email timezone metaLocation } }' );
			if ( is_wp_error( $data ) ) {
				$status = $data->get_error_data();
				// A permission refusal is an answer, not an outage: remember it.
				return isset( $status['status'] ) && 403 === (int) $status['status'] ? array( 'clinics' => array() ) : $data;
			}
			return $data;
		}, 12 * HOUR_IN_SECONDS );
		if ( is_wp_error( $data ) || empty( $data['clinics'] ) || ! is_array( $data['clinics'] ) ) {
			return null;
		}
		foreach ( $data['clinics'] as $clinic ) {
			if ( '' !== $this->meta_location && isset( $clinic['metaLocation'] ) && $clinic['metaLocation'] === $this->meta_location ) {
				return $clinic;
			}
		}
		return $data['clinics'][0];
	}

	private function timezone() {
		$clinic = $this->clinic_profile();
		$tz     = $clinic && ! empty( $clinic['timezone'] ) ? (string) $clinic['timezone'] : $this->fallback_timezone;
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '';
	}

	public function connection() {
		// The appointment types are the first thing the widget needs: a token
		// that can't read them is not a working connection.
		$types = $this->request( '{ appointmentTypes { id } }' );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		$clinic = $this->clinic_profile();
		$name   = $clinic && ! empty( $clinic['name'] ) ? (string) $clinic['name'] : 'Nectar clinic';
		return array(
			'org'       => $name,
			'locations' => array(
				array(
					'id'       => self::LOCATION_ID,
					'name'     => $name,
					'timezone' => $this->timezone(),
				),
			),
		);
	}

	public function get_location( $location_id ) {
		if ( self::LOCATION_ID !== (string) $location_id ) {
			return null;
		}
		$clinic = $this->clinic_profile();
		return array(
			'id'       => self::LOCATION_ID,
			'name'     => $clinic && ! empty( $clinic['name'] ) ? (string) $clinic['name'] : 'Nectar clinic',
			'timezone' => $this->timezone(),
		);
	}

	public function get_location_info( $location_id ) {
		if ( self::LOCATION_ID !== (string) $location_id ) {
			return null;
		}
		$clinic  = $this->clinic_profile();
		$name    = $clinic && ! empty( $clinic['name'] ) ? (string) $clinic['name'] : '';
		$address = '';
		if ( $clinic ) {
			$city_line = trim( implode( ', ', array_filter( array( isset( $clinic['city'] ) ? $clinic['city'] : '', trim( ( isset( $clinic['state'] ) ? $clinic['state'] : '' ) . ' ' . ( isset( $clinic['zipCode'] ) ? $clinic['zipCode'] : '' ) ) ) ) ) );
			$address   = implode( ', ', array_filter( array( isset( $clinic['street'] ) ? $clinic['street'] : '', $city_line ) ) );
		}
		return array(
			'id'            => self::LOCATION_ID,
			'name'          => $name,
			'displayName'   => $name,
			'timezone'      => $this->timezone(),
			'addressString' => $address,
			'phoneNumber'   => $clinic && ! empty( $clinic['phone'] ) ? (string) $clinic['phone'] : '',
			'latitude'      => null,
			'longitude'     => null,
			'googleLink'    => '' !== $address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $name . ' ' . $address ) : '',
			'url'           => '',
			'locationHours' => null, // Nectar exposes no opening hours
		);
	}

	/* ---------- appointment types + availability ---------- */

	/**
	 * Online-bookable types only: directOnlineEnabled, no deposit (a deposit
	 * needs a payment we can't take), not deleted, and not a GENERAL block
	 * (lunch, meetings; the category enum is only MEDICAL | GENERAL).
	 */
	public function get_bookable_types( $location_id ) {
		$data = $this->request( '{ appointmentTypes { id name category defaultMinutes directOnlineEnabled depositRequired deletedAt order } }' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$types = isset( $data['appointmentTypes'] ) && is_array( $data['appointmentTypes'] ) ? $data['appointmentTypes'] : array();
		$types = array_values( array_filter( $types, function ( $t ) {
			return ! empty( $t['directOnlineEnabled'] ) && empty( $t['depositRequired'] ) && empty( $t['deletedAt'] )
				&& ( empty( $t['category'] ) || 'GENERAL' !== $t['category'] );
		} ) );
		usort( $types, function ( $a, $b ) {
			$oa = isset( $a['order'] ) ? (int) $a['order'] : PHP_INT_MAX;
			$ob = isset( $b['order'] ) ? (int) $b['order'] : PHP_INT_MAX;
			return $oa === $ob ? strcasecmp( $a['name'], $b['name'] ) : $oa - $ob;
		} );
		return array_map( function ( $t ) {
			return array(
				'id'       => (string) $t['id'],
				'name'     => (string) $t['name'],
				'duration' => isset( $t['defaultMinutes'] ) ? (int) $t['defaultMinutes'] : 0,
			);
		}, $types );
	}

	/**
	 * Open times on one clinic-local date. Nectar refuses a window that starts
	 * before the clinic's booking window (default 24 h ahead), so the start is
	 * clamped to it and a day entirely inside it returns nothing without a call.
	 *
	 * getAvailableAppointmentSlots returns events (start/end + employee). An
	 * event longer than the type's duration is a free window and is cut into
	 * back-to-back slots of that duration.
	 */
	public function get_available_times( $location_id, $type_id, $date ) {
		$tz_name = $this->timezone();
		if ( '' === $tz_name ) {
			return new WP_Error( 'vsps_location', 'The clinic timezone is not set.' );
		}
		$duration = $this->type_duration( $type_id );
		if ( is_wp_error( $duration ) ) {
			return $duration;
		}
		try {
			$tz        = new DateTimeZone( $tz_name );
			$day_start = new DateTimeImmutable( $date . ' 00:00:00', $tz );
			$day_end   = $day_start->modify( '+1 day' );
			$earliest  = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+' . VSPS_Pims_Registry::min_lead_hours( 'nectar' ) . ' hours +5 minutes' );
		} catch ( Exception $e ) {
			return new WP_Error( 'vsps_datetime', 'Invalid date.' );
		}
		$window_start = $earliest > $day_start ? $earliest : $day_start;
		if ( $window_start >= $day_end ) {
			return array();
		}

		$vars = array(
			'start' => $window_start->format( 'c' ),
			'end'   => $day_end->format( 'c' ),
			'type'  => (string) $type_id,
		);
		if ( '' !== $this->meta_location ) {
			$vars['meta'] = $this->meta_location;
		}
		$data = $this->request(
			'query ($start: datetime!, $end: datetime!, $type: ID!, $meta: String) {
				getAvailableAppointmentSlots(startDatetime: $start, endDatetime: $end, type: $type, metaLocation: $meta) {
					type scheduleId startDatetime endDatetime employee { id firstName lastName }
				}
			}',
			$vars
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$events = isset( $data['getAvailableAppointmentSlots'] ) && is_array( $data['getAvailableAppointmentSlots'] ) ? $data['getAvailableAppointmentSlots'] : array();
		return self::events_to_slots( $events, $tz, $date, $window_start, $duration );
	}

	/** Events → [{ time, providerId, scheduleId, provider }] on $date, from $not_before, deduped. */
	public static function events_to_slots( array $events, DateTimeZone $tz, $date, DateTimeImmutable $not_before, $duration ) {
		$step  = max( 5, (int) $duration ) * 60;
		$slots = array();
		$seen  = array();
		foreach ( $events as $event ) {
			if ( isset( $event['type'] ) && 'TIME_OFF' === $event['type'] ) {
				continue;
			}
			try {
				$start = ( new DateTimeImmutable( (string) $event['startDatetime'] ) )->setTimezone( $tz );
				$end   = ( new DateTimeImmutable( (string) $event['endDatetime'] ) )->setTimezone( $tz );
			} catch ( Exception $e ) {
				continue;
			}
			$employee = isset( $event['employee'] ) && is_array( $event['employee'] ) ? $event['employee'] : array();
			$provider = array(
				'id'   => isset( $employee['id'] ) ? (string) $employee['id'] : '',
				'name' => trim( ( isset( $employee['firstName'] ) ? $employee['firstName'] : '' ) . ' ' . ( isset( $employee['lastName'] ) ? $employee['lastName'] : '' ) ),
			);
			$length = $end->getTimestamp() - $start->getTimestamp();
			// A slot exactly the type's length (or shorter) is offered as is.
			$count = $length > $step ? (int) floor( $length / $step ) : 1;
			for ( $i = 0; $i < $count && $i < 288; $i++ ) {
				$at = $start->modify( '+' . ( $i * $step ) . ' seconds' );
				if ( $at < $not_before || $at->format( 'Y-m-d' ) !== $date ) {
					continue;
				}
				$time = $at->format( 'H:i' );
				$key  = $time . '|' . $provider['id'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$slots[]      = array(
					'time'       => $time,
					'providerId' => $provider['id'],
					'scheduleId' => isset( $event['scheduleId'] ) ? (string) $event['scheduleId'] : '',
					'provider'   => $provider,
				);
			}
		}
		return $slots;
	}

	private function type_duration( $type_id ) {
		$types = VSPS_Cache::remember( array( 'nectar-types', md5( $this->client_id ) ), function () {
			return $this->get_bookable_types( self::LOCATION_ID );
		}, HOUR_IN_SECONDS );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		foreach ( $types as $type ) {
			if ( (string) $type['id'] === (string) $type_id ) {
				return (int) $type['duration'];
			}
		}
		return new WP_Error( 'vsps_type', 'This appointment type cannot be booked online.' );
	}

	/* ---------- clients + patients ---------- */

	/**
	 * Every active client whose home or work email is exactly $email (Nectar
	 * keeps duplicates: one email can sit on several owner records). Their pets
	 * are merged, each tagged with the owner it belongs to.
	 */
	public function find_client_by_email( $email ) {
		$email   = trim( (string) $email );
		$matches = array();
		foreach ( array( 'emailH', 'emailW' ) as $field ) {
			// REGEX = case-insensitive "contains" of the literal text (Nectar escapes
			// it), so the exact comparison happens here.
			$data = $this->request(
				'query ($f: ClinicUserFilterSchema, $p: PaginationInfo) {
					clientsPaginated(filter: $f, paginationInfo: $p) {
						edges { node { id firstName lastName emailH emailW phoneH phoneW clientInfo { familyId clientStatus } } }
					}
				}',
				array(
					'f' => array( $field => array( 'value1' => $email, 'operator' => 'REGEX' ) ),
					'p' => array( 'perPage' => 20, 'cursor' => '', 'mode' => 'FORWARD' ),
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$edges = isset( $data['clientsPaginated']['edges'] ) && is_array( $data['clientsPaginated']['edges'] ) ? $data['clientsPaginated']['edges'] : array();
			foreach ( $edges as $edge ) {
				$node   = isset( $edge['node'] ) ? $edge['node'] : array();
				$status = isset( $node['clientInfo']['clientStatus'] ) ? $node['clientInfo']['clientStatus'] : 'ACTIVE';
				$exact  = ( isset( $node['emailH'] ) && 0 === strcasecmp( (string) $node['emailH'], $email ) )
					|| ( isset( $node['emailW'] ) && 0 === strcasecmp( (string) $node['emailW'], $email ) );
				if ( $exact && 'ACTIVE' === $status && ! empty( $node['id'] ) ) {
					$matches[ $node['id'] ] = $node;
				}
			}
		}
		if ( ! $matches ) {
			return null;
		}

		$first    = reset( $matches );
		$phones   = array();
		$patients = array();
		foreach ( array_slice( $matches, 0, 5 ) as $node ) {
			foreach ( array( 'phoneH', 'phoneW' ) as $phone_field ) {
				if ( ! empty( $node[ $phone_field ] ) ) {
					$phones[] = array( 'value' => (string) $node[ $phone_field ] );
				}
			}
			$data = $this->request(
				'query ($id: ID!) { clientPatients(clientId: $id) { id firstName status } }',
				array( 'id' => (string) $node['id'] )
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( isset( $data['clientPatients'] ) && is_array( $data['clientPatients'] ) ? $data['clientPatients'] : array() as $patient ) {
				$status     = isset( $patient['status'] ) ? $patient['status'] : 'ACTIVE';
				$patients[] = array(
					'id'         => (string) $patient['id'],
					'name'       => (string) $patient['firstName'],
					'isActive'   => 'ACTIVE' === $status,
					'isDeceased' => 'DECEASED' === $status,
					'clientId'   => (string) $node['id'],
				);
			}
		}
		return array(
			'id'           => (string) $first['id'],
			'email'        => $email,
			'givenName'    => (string) $first['firstName'],
			'familyName'   => (string) $first['lastName'],
			'familyId'     => isset( $first['clientInfo']['familyId'] ) ? (string) $first['clientInfo']['familyId'] : '',
			'phoneNumbers' => $phones,
			'patients'     => $patients,
		);
	}

	/** A new family with this owner as its primary contact (Nectar's "new client"). */
	public function create_booking_client( array $args ) {
		$c    = $args['client'];
		$a    = isset( $c['address'] ) ? $c['address'] : array();
		$vars = array(
			'familyName' => $c['family_name'],
			'first'      => $c['given_name'],
			'last'       => $c['family_name'],
			'phone'      => self::digits( $c['phone'] ),
			'email'      => $c['email'],
		);
		$optional = array(
			'phoneW' => ! empty( $c['phone_alt'] ) ? self::digits( $c['phone_alt'] ) : '',
			'dob'    => ! empty( $c['owner_dob'] ) ? self::date_time( $c['owner_dob'] ) : '',
		);
		// Only a complete address (an optional one can be half filled in).
		if ( ! empty( $a['line1'] ) && ! empty( $a['city'] ) && ! empty( $a['state'] ) && ! empty( $a['postal'] ) ) {
			$optional['street'] = trim( $a['line1'] . ( ! empty( $a['line2'] ) ? ' ' . $a['line2'] : '' ) );
			$optional['city']   = $a['city'];
			$optional['state']  = $a['state'];
			$optional['zip']    = $a['postal'];
		}
		$vars = array_merge( $vars, array_filter( $optional, function ( $v ) {
			return '' !== $v;
		} ) );

		$data = $this->request(
			'mutation ($familyName: String!, $first: String!, $last: String!, $phone: String!, $email: String,
				$phoneW: String, $dob: datetime, $street: String, $city: String, $state: String, $zip: String) {
				addFamily(familyName: $familyName, clientFirstName: $first, clientLastName: $last, clientPhone: $phone,
					clientEmail: $email, clientPhoneW: $phoneW, clientDateOfBirth: $dob, clientStatus: ACTIVE,
					street: $street, city: $city, state: $state, zipCode: $zip) {
					id familyId primaryContactUid clients { id }
				}
			}',
			$vars
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$family    = isset( $data['addFamily'] ) ? $data['addFamily'] : array();
		$client_id = ! empty( $family['primaryContactUid'] ) ? $family['primaryContactUid'] : ( isset( $family['clients'][0]['id'] ) ? $family['clients'][0]['id'] : '' );
		return array(
			'id'           => (string) $client_id,
			'givenName'    => $c['given_name'],
			'familyName'   => $c['family_name'],
			'familyId'     => ! empty( $family['familyId'] ) ? (string) $family['familyId'] : ( isset( $family['id'] ) ? (string) $family['id'] : '' ),
			'phoneNumbers' => array( array( 'value' => $c['phone'] ) ),
			'patients'     => array(),
		);
	}

	public function create_booking_patient( array $client, array $args ) {
		if ( empty( $client['familyId'] ) ) {
			return new WP_Error( 'vsps_patient', 'The owner record has no family to add the pet to.' );
		}
		$p     = $args['patient'];
		$input = array(
			'status'    => 'ACTIVE',
			'firstName' => $p['name'],
			'species'   => $p['species'],
			'familyId'  => (string) $client['familyId'],
			'createdVia' => 'CLIENT_PORTAL',
		);
		if ( ! empty( $client['familyName'] ) ) {
			$input['lastName'] = (string) $client['familyName'];
		}
		if ( '' !== $p['breed'] ) {
			$input['breed'] = $p['breed'];
		}
		if ( '' !== $p['sex'] ) {
			$input['gender'] = $p['sex']; // MALE | FEMALE | UNKNOWN, same enum
		}
		if ( ! empty( $p['neutered'] ) ) {
			$input['spayNeuter'] = 'yes' === $p['neutered'];
		}
		if ( ! empty( $p['birth_date'] ) ) {
			$input['dateOfBirth'] = self::date_time( $p['birth_date'] );
		} elseif ( isset( $p['age'] ) && '' !== $p['age'] ) {
			// Nectar has no "estimated age" flag: today's date, N years back.
			$input['dateOfBirth'] = self::date_time( gmdate( 'Y-m-d', strtotime( '-' . (int) $p['age'] . ' years' ) ) );
		}
		if ( ! empty( $p['weight'] ) ) {
			$input[ 'KG' === $p['weight_unit'] ? 'weightKg' : 'weightLbs' ] = (float) $p['weight'];
		}
		foreach ( array( 'color' => 'color', 'microchip' => 'microchip' ) as $from => $to ) {
			if ( ! empty( $p[ $from ] ) ) {
				$input[ $to ] = $p[ $from ];
			}
		}
		$data = $this->request(
			'mutation ($input: PatientCreateSchema!) { addPatient(input: $input) { id firstName } }',
			array( 'input' => $input )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['addPatient']['id'] )
			? array( 'id' => (string) $data['addPatient']['id'], 'name' => (string) $data['addPatient']['firstName'] )
			: null;
	}

	/**
	 * Nectar online booking is a request: addOnlineAppointment holds the slot,
	 * scheduleAndRequestAppointment turns it into ONLINE_REQUESTED for the
	 * clinic to confirm.
	 */
	public function create_booking_appointment( array $booking ) {
		try {
			$start = new DateTimeImmutable( $booking['start'] );
		} catch ( Exception $e ) {
			return new WP_Error( 'vsps_datetime', 'Invalid date/time for booking.' );
		}
		$input = array(
			// The owner is the creator, as when they book in the Nectar client portal.
			'creatorUid'        => (string) $booking['client_id'],
			'startDatetime'     => $start->format( 'c' ),
			'endDatetime'       => $start->modify( '+' . max( 5, (int) $booking['duration'] ) . ' minutes' )->format( 'c' ),
			'patientId'         => (string) $booking['patient_id'],
			'clientId'          => (string) $booking['client_id'],
			'appointmentTypeId' => (string) $booking['appointment_type_id'],
			'isOnlineRequest'   => true,
			'staffNotes'        => (string) $booking['reason'],
		);
		if ( '' !== (string) $booking['provider_id'] ) {
			$input['assignedUid'] = (string) $booking['provider_id'];
		}
		if ( '' !== $this->meta_location ) {
			$input['metaLocation'] = $this->meta_location;
		}
		$data = $this->request(
			'mutation ($input: AppointmentCreateSchema!) { addOnlineAppointment(input: $input) { id appointmentId startDatetime status } }',
			array( 'input' => $input )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$created = isset( $data['addOnlineAppointment'] ) ? $data['addOnlineAppointment'] : array();
		$id      = ! empty( $created['appointmentId'] ) ? $created['appointmentId'] : ( isset( $created['id'] ) ? $created['id'] : '' );
		if ( '' === (string) $id ) {
			return null;
		}

		$request = array( 'appointmentId' => (string) $id );
		if ( '' !== $this->meta_location ) {
			$request['metaLocation'] = $this->meta_location;
		}
		$mutation  = 'mutation ($input: OnlineAppointmentScheduleSchema!) { scheduleAndRequestAppointment(input: $input) { id appointmentId status startDatetime } }';
		$requested = $this->request( $mutation, array( 'input' => $request ) );
		if ( is_wp_error( $requested ) ) {
			// The slot is already held: one retry beats the visitor booking again
			// (which would hold a second one).
			usleep( 400000 );
			$requested = $this->request( $mutation, array( 'input' => $request ) );
		}
		if ( is_wp_error( $requested ) ) {
			// The held slot expires on Nectar's side; log it so it can be traced.
			error_log( '[vetspire-scheduler] Nectar appointment ' . $id . ' was created but not requested: ' . $requested->get_error_message() );
			return $requested;
		}
		$final = isset( $requested['scheduleAndRequestAppointment'] ) ? $requested['scheduleAndRequestAppointment'] : array();
		return array(
			'id'    => (string) $id,
			'start' => ! empty( $final['startDatetime'] ) ? (string) $final['startDatetime'] : $booking['start'],
		);
	}

	public function get_referral_sources() {
		return array(); // Nectar has no "how did you hear about us" list
	}

	/* ---------- helpers ---------- */

	private static function digits( $phone ) {
		return preg_replace( '/\D/', '', (string) $phone );
	}

	/** A Y-m-d date as a Nectar datetime (noon UTC, so no timezone shifts it a day). */
	private static function date_time( $date ) {
		return $date . 'T12:00:00+00:00';
	}
}
