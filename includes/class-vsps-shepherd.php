<?php
/**
 * Shepherd (shepherd.vet) adapter. Server-side REST client for the Open API.
 *
 * Auth is two secret keys plus the clinic UUID, all sent as headers. They
 * never reach the browser. One saved clinic id is one location: Encore issues
 * a key per location, and this build has no key, so nothing here calls a live
 * clinic unless an admin has saved credentials and then uses the widget.
 *
 * Schemas: Shepherd Open API 2.246.0.33 (developer.shepherd.vet, Nov 2026).
 * Creates are not retried. Appointment overlap is not rejected by Shepherd,
 * so availability is read before every booking (VSPS_Booking already does that).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Shepherd implements VSPS_Pims {

	const PRODUCTION_BASE = 'https://open-api.shepherd.vet/pav2';
	const SANDBOX_BASE    = 'https://demo-open-api.shepherd.vet/pav2';
	const CLINIC_ID_RE    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/** Windows timezone names Shepherd's clinicSetting.timeZone is documented to use, to IANA. */
	const WINDOWS_ZONES = array(
		'Eastern Standard Time'  => 'America/New_York',
		'Central Standard Time'  => 'America/Chicago',
		'Mountain Standard Time' => 'America/Denver',
		'US Mountain Standard Time' => 'America/Phoenix',
		'Pacific Standard Time'  => 'America/Los_Angeles',
		'Alaskan Standard Time'  => 'America/Anchorage',
		'Hawaiian Standard Time' => 'Pacific/Honolulu',
	);

	private $public_key;
	private $private_key;
	private $clinic_id;
	private $base;
	private $fallback_timezone;

	/**
	 * @param string $environment 'sandbox' (demo host) or 'production'. Anything else is sandbox.
	 */
	public function __construct( $public_key, $private_key, $clinic_id, $environment = 'sandbox', $fallback_timezone = '' ) {
		$this->public_key         = (string) $public_key;
		$this->private_key        = (string) $private_key;
		$this->clinic_id          = trim( (string) $clinic_id );
		$this->base               = self::base_url( $environment );
		$this->fallback_timezone  = (string) $fallback_timezone;
	}

	public function platform() {
		return 'shepherd';
	}

	public static function base_url( $environment ) {
		return 'production' === $environment ? self::PRODUCTION_BASE : self::SANDBOX_BASE;
	}

	public static function is_clinic_id( $value ) {
		return is_string( $value ) && 1 === preg_match( self::CLINIC_ID_RE, trim( $value ) );
	}

	/** True when Settings has both keys and a clinic UUID. No network. */
	public static function configured( array $settings ) {
		return '' !== (string) ( isset( $settings['shepherd_public_key'] ) ? $settings['shepherd_public_key'] : '' )
			&& '' !== (string) ( isset( $settings['shepherd_private_key'] ) ? $settings['shepherd_private_key'] : '' )
			&& self::is_clinic_id( isset( $settings['shepherd_clinic_id'] ) ? $settings['shepherd_clinic_id'] : '' );
	}

	/* ---------- transport ---------- */

	/**
	 * POST a JSON object. Reads of 502/503 are tried once more. Writes are never
	 * retried: a Shepherd create is not idempotent.
	 *
	 * @return array|WP_Error Decoded JSON, or array() for an empty 2xx body.
	 */
	public function post( $path, array $body, $write = false ) {
		$result = $this->raw_post( $path, $body );
		if ( ! $write && is_wp_error( $result ) ) {
			$status = $result->get_error_data();
			$code   = is_array( $status ) && isset( $status['status'] ) ? (int) $status['status'] : 0;
			if ( 502 === $code || 503 === $code ) {
				$result = $this->raw_post( $path, $body );
			}
		}
		return $result;
	}

	private function raw_post( $path, array $body ) {
		if ( ! self::is_clinic_id( $this->clinic_id ) ) {
			return new WP_Error( 'vsps_config', 'Shepherd needs the clinic id from Encore (a UUID) before it can connect.' );
		}
		if ( '' === $this->public_key || '' === $this->private_key ) {
			return new WP_Error( 'vsps_config', 'Shepherd needs both API keys from Encore before it can connect.' );
		}
		$url = $this->base . '/' . ltrim( (string) $path, '/' );
		$version = defined( 'VSPS_VERSION' ) ? VSPS_VERSION : 'dev';
		$response = wp_remote_post( $url, array(
			'timeout'     => 30,
			'redirection' => 0, // never forward the private key to another host
			'headers'     => array(
				'Content-Type'              => 'application/json',
				'Accept'                    => 'application/json',
				'User-Agent'                => 'Vetcelerator-Scheduler/' . $version,
				'X-Integration-Public-Key'  => $this->public_key,
				'X-Integration-Private-Key' => $this->private_key,
				'X-Clinic-Id'               => $this->clinic_id,
			),
			'body'        => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 ) {
			if ( '' === trim( $raw ) ) {
				return array();
			}
			$json = json_decode( $raw, true );
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'vsps_http', 'Shepherd returned a response this plugin could not read (HTTP ' . $code . ').', array( 'status' => $code ) );
			}
			return $json;
		}
		return new WP_Error( 'vsps_http', $this->error_text( $code, $raw ), array( 'status' => $code ) );
	}

	private function error_text( $code, $raw ) {
		$parsed = json_decode( $raw, true );
		if ( is_string( $parsed ) && '' !== $parsed ) {
			$text = $parsed;
		} elseif ( is_array( $parsed ) && isset( $parsed['message'] ) && is_string( $parsed['message'] ) ) {
			$text = $parsed['message'];
		} else {
			$text = trim( $raw );
		}
		if ( '' === $text ) {
			$text = 'HTTP ' . (int) $code;
		}
		foreach ( array( $this->public_key, $this->private_key ) as $secret ) {
			if ( strlen( $secret ) >= 8 ) {
				$text = str_replace( $secret, '[redacted]', $text );
			}
		}
		$text = preg_replace( '/[^\s@]+@[^\s@]+/', '[email]', $text );
		$text = 'Shepherd: ' . $text . ' (HTTP ' . (int) $code . ')';
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 240, 'UTF-8' ) : substr( $text, 0, 240 );
	}

	/** One page of a collection, or a WP_Error. `item` is always a list. */
	private function collection( $path, array $body ) {
		if ( ! isset( $body['page'] ) ) {
			$body['page'] = 1;
		}
		if ( ! isset( $body['rpp'] ) ) {
			$body['rpp'] = 100;
		}
		$data = $this->post( $path, $body, false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$item = isset( $data['item'] ) ? $data['item'] : array();
		if ( is_array( $item ) && ! self::is_list( $item ) ) {
			$item = array( $item );
		}
		return is_array( $item ) ? $item : array();
	}

	/* ---------- clinic ---------- */

	private function clinic() {
		$data = VSPS_Cache::remember( array( 'shepherd-clinic', md5( $this->clinic_id . '|' . $this->base ) ), function () {
			return $this->post( 'open-api-clinics', array(
				'ids'   => $this->clinic_id,
				'rpp'   => 1,
				'page'  => 1,
				'embed' => 'clinicSetting,clinicAddress',
			) );
		}, 12 * HOUR_IN_SECONDS );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = isset( $data['item'] ) && is_array( $data['item'] ) ? $data['item'] : array();
		if ( self::is_list( $items ) ) {
			return isset( $items[0] ) ? $items[0] : null;
		}
		return $items ? $items : null;
	}

	private function timezone() {
		$clinic = $this->clinic();
		$raw    = ( ! is_wp_error( $clinic ) && isset( $clinic['clinicSetting']['timeZone'] ) ) ? (string) $clinic['clinicSetting']['timeZone'] : '';
		$iana   = self::to_iana( $raw );
		if ( '' === $iana ) {
			$iana = self::to_iana( $this->fallback_timezone );
		}
		return $iana;
	}

	/** An IANA zone, a known Windows zone name, or ''. */
	public static function to_iana( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( isset( self::WINDOWS_ZONES[ $value ] ) ) {
			$value = self::WINDOWS_ZONES[ $value ];
		}
		return in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
	}

	public function connection() {
		$clinic = $this->clinic();
		if ( is_wp_error( $clinic ) ) {
			return $clinic;
		}
		if ( empty( $clinic['id'] ) ) {
			return new WP_Error( 'vsps_config', 'Shepherd accepted the keys but did not return this clinic. Check the clinic id from Encore.' );
		}
		$name = ! empty( $clinic['name'] ) ? (string) $clinic['name'] : 'Shepherd clinic';
		return array(
			'org'       => $name,
			'locations' => array(
				array(
					'id'       => (string) $clinic['id'],
					'name'     => $name,
					'timezone' => $this->timezone(),
				),
			),
		);
	}

	public function get_location( $location_id ) {
		if ( ! self::is_clinic_id( (string) $location_id ) || strcasecmp( (string) $location_id, $this->clinic_id ) !== 0 ) {
			return null;
		}
		$conn = $this->connection();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		return isset( $conn['locations'][0] ) ? $conn['locations'][0] : null;
	}

	public function get_location_info( $location_id ) {
		$where = $this->get_location( $location_id );
		if ( is_wp_error( $where ) || null === $where ) {
			return $where;
		}
		$clinic  = $this->clinic();
		$address = '';
		$phone   = '';
		if ( ! is_wp_error( $clinic ) && is_array( $clinic ) ) {
			$addr    = isset( $clinic['clinicAddress'] ) && is_array( $clinic['clinicAddress'] ) ? $clinic['clinicAddress'] : array();
			$city    = trim( implode( ', ', array_filter( array(
				isset( $addr['city'] ) ? $addr['city'] : '',
				trim( ( isset( $addr['state'] ) ? $addr['state'] : '' ) . ' ' . ( isset( $addr['zipCode'] ) ? $addr['zipCode'] : '' ) ),
			) ) ) );
			$address = implode( ', ', array_filter( array( isset( $addr['address1'] ) ? $addr['address1'] : '', isset( $addr['address2'] ) ? $addr['address2'] : '', $city ) ) );
			$phone   = ! empty( $clinic['phone'] ) ? (string) $clinic['phone'] : '';
		}
		$name = $where['name'];
		return array(
			'id'            => $where['id'],
			'name'          => $name,
			'displayName'   => $name,
			'timezone'      => $where['timezone'],
			'addressString' => $address,
			'phoneNumber'   => $phone,
			'latitude'      => null,
			'longitude'     => null,
			'googleLink'    => '' !== $address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $name . ' ' . $address ) : '',
			'url'           => '',
			'locationHours' => null, // Shepherd's clinic record has no weekly hours
		);
	}

	/* ---------- types + availability ---------- */

	public function get_bookable_types( $location_id ) {
		if ( strcasecmp( (string) $location_id, $this->clinic_id ) !== 0 ) {
			return array();
		}
		$items = $this->collection( 'open-api-appointment-types', array( 'rpp' => 200, 'page' => 1 ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$types = array();
		foreach ( $items as $type ) {
			if ( ! is_array( $type ) || empty( $type['id'] ) || ! empty( $type['isDeleted'] ) || empty( $type['directBookingEnabled'] ) ) {
				continue;
			}
			$types[] = array(
				'id'       => (string) $type['id'],
				'name'     => isset( $type['name'] ) ? (string) $type['name'] : '',
				'duration' => self::slot_minutes( isset( $type['duration'] ) ? $type['duration'] : 0 ),
			);
		}
		usort( $types, function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return $types;
	}

	/** Shepherd start/end must land on a 5-minute boundary. 0 or missing becomes 30. */
	public static function slot_minutes( $duration ) {
		$n = (int) $duration;
		if ( $n < 5 ) {
			$n = 30;
		}
		$rem = $n % 5;
		return $rem ? $n + ( 5 - $rem ) : $n;
	}

	public function get_available_times( $location_id, $type_id, $date ) {
		if ( strcasecmp( (string) $location_id, $this->clinic_id ) !== 0 ) {
			return array();
		}
		$tz_name = $this->timezone();
		if ( '' === $tz_name ) {
			return new WP_Error( 'vsps_location', 'The clinic timezone is not set.' );
		}
		$duration = $this->type_duration( $type_id );
		if ( is_wp_error( $duration ) ) {
			return $duration;
		}
		$providers = $this->collection( 'open-api-providers', array(
			'isArchived'         => false,
			'appointmentTypeIds' => (string) $type_id,
			'rpp'                => 100,
			'page'               => 1,
		) );
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}
		$providers = array_values( array_filter( $providers, function ( $provider ) {
			return is_array( $provider ) && empty( $provider['isArchived'] ) && ! empty( $provider['id'] );
		} ) );
		if ( ! $providers ) {
			return array();
		}
		try {
			$tz    = new DateTimeZone( $tz_name );
			$start = new DateTimeImmutable( $date . ' 00:00:00', $tz );
			$end   = $start->modify( '+1 day' );
		} catch ( Exception $e ) {
			return new WP_Error( 'vsps_datetime', 'Invalid date.' );
		}
		$ids  = array();
		$byid = array();
		foreach ( array_slice( $providers, 0, 50 ) as $provider ) {
			$ids[]              = (string) $provider['id'];
			$byid[ strtolower( (string) $provider['id'] ) ] = array(
				'id'   => (string) $provider['id'],
				'name' => isset( $provider['name'] ) ? (string) $provider['name'] : '',
			);
		}
		$data = $this->post( 'open-api-provider-availabilities/timeslots', array(
			'startDate'                => $start->format( 'c' ),
			'endDate'                  => $end->format( 'c' ),
			'providerIds'              => implode( ',', $ids ),
			'filterActiveAppointments' => true,
		) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::timeslots_to_slots( $data, $byid, $tz, $date, $duration, new DateTimeImmutable( 'now', $tz ) );
	}

	/**
	 * ProviderTimeSlotAvailabilityRESTModel → the widget's slot list.
	 * `available` (and the blocking lists) are unpublished DateTimeRange values.
	 * A list of {start,end} / {startDate,endDate} / {min,max}, or one such object,
	 * is accepted. A value with no offset is clinic-local, which is how Shepherd
	 * says these times are computed.
	 *
	 * @param array $providers provider id (lower) => {id, name}
	 */
	public static function timeslots_to_slots( array $payload, array $providers, DateTimeZone $tz, $date, $duration, DateTimeImmutable $not_before ) {
		$step  = self::slot_minutes( $duration ) * 60;
		$items = isset( $payload['item'] ) ? $payload['item'] : array();
		if ( is_array( $items ) && ! self::is_list( $items ) ) {
			$items = array( $items );
		}
		$slots = array();
		$seen  = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pid      = isset( $item['providerId'] ) ? strtolower( (string) $item['providerId'] ) : '';
			$provider = isset( $providers[ $pid ] ) ? $providers[ $pid ] : array( 'id' => isset( $item['providerId'] ) ? (string) $item['providerId'] : '', 'name' => '' );
			$blocks   = array();
			foreach ( array( 'break', 'clinicBreak', 'unavailable', 'officeClosure' ) as $key ) {
				foreach ( self::ranges( isset( $item[ $key ] ) ? $item[ $key ] : array() ) as $range ) {
					$bounds = self::range_bounds( $range, $tz );
					if ( null !== $bounds ) {
						$blocks[] = $bounds;
					}
				}
			}
			foreach ( self::ranges( isset( $item['available'] ) ? $item['available'] : array() ) as $range ) {
				$bounds = self::range_bounds( $range, $tz );
				if ( null === $bounds ) {
					continue;
				}
				$at = self::ceil_five( $bounds[0] );
				for ( $i = 0; $i < 288 && $at < $bounds[1]; $i++ ) {
					$slot_end = $at->modify( '+' . $step . ' seconds' );
					if ( $slot_end > $bounds[1] ) {
						break;
					}
					if ( $at >= $not_before && $at->format( 'Y-m-d' ) === $date && ! self::overlaps( $at, $slot_end, $blocks ) ) {
						$time = $at->format( 'H:i' );
						$key  = $time . '|' . $provider['id'];
						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$slots[]      = array(
								'time'       => $time,
								'providerId' => $provider['id'],
								'scheduleId' => '',
								'provider'   => $provider,
							);
						}
					}
					$at = $at->modify( '+' . $step . ' seconds' );
				}
			}
		}
		return $slots;
	}

	private function type_duration( $type_id ) {
		$types = VSPS_Cache::remember( array( 'shepherd-types', md5( $this->clinic_id . '|' . $this->base ) ), function () {
			return $this->get_bookable_types( $this->clinic_id );
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

	public function find_client_by_email( $email ) {
		$email = trim( (string) $email );
		$items = $this->collection( 'open-api-clients', array(
			'searchQuery' => $email,
			'isDeleted'   => false,
			'embed'       => 'clientPhones',
			'rpp'         => 20,
			'page'        => 1,
		) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$matches = self::exact_email_clients( $items, $email );
		if ( ! $matches ) {
			return null;
		}
		$first    = $matches[0];
		$phones   = array();
		$patients = array();
		foreach ( array_slice( $matches, 0, 5 ) as $client ) {
			foreach ( isset( $client['clientPhones'] ) && is_array( $client['clientPhones'] ) ? $client['clientPhones'] : array() as $phone ) {
				if ( ! empty( $phone['phoneNumber'] ) ) {
					$phones[] = array( 'value' => (string) $phone['phoneNumber'] );
				}
			}
			$pets = $this->collection( 'open-api-patients', array(
				'clientIds'       => (string) $client['id'],
				'isDeleted'       => false,
				'excludeDeceased' => true,
				'rpp'             => 100,
				'page'            => 1,
			) );
			if ( is_wp_error( $pets ) ) {
				return $pets;
			}
			foreach ( $pets as $patient ) {
				if ( empty( $patient['id'] ) ) {
					continue;
				}
				$deceased   = ! empty( $patient['dateDeceased'] );
				$patients[] = array(
					'id'         => (string) $patient['id'],
					'name'       => isset( $patient['name'] ) ? (string) $patient['name'] : '',
					'isActive'   => ! $deceased && empty( $patient['deleted'] ),
					'isDeceased' => $deceased,
					'clientId'   => (string) $client['id'],
				);
			}
		}
		return array(
			'id'           => (string) $first['id'],
			'email'        => $email,
			'givenName'    => isset( $first['firstName'] ) ? (string) $first['firstName'] : '',
			'familyName'   => isset( $first['lastName'] ) ? (string) $first['lastName'] : '',
			'phoneNumbers' => $phones,
			'patients'     => $patients,
		);
	}

	/** searchQuery matches name and email as "contains". Keep exact emails only. */
	public static function exact_email_clients( array $items, $email ) {
		$wanted = strtolower( trim( $email ) );
		$out    = array();
		foreach ( $items as $client ) {
			if ( ! is_array( $client ) || ! empty( $client['deleted'] ) || empty( $client['id'] ) ) {
				continue;
			}
			$have = isset( $client['email'] ) ? strtolower( trim( (string) $client['email'] ) ) : '';
			if ( '' !== $wanted && $have === $wanted ) {
				$out[] = $client;
			}
		}
		return $out;
	}

	public function create_booking_client( array $args ) {
		$c     = $args['client'];
		$phone = self::lookup_row( $this->countries(), array( 'united states', 'usa', 'us' ) );
		$type  = self::lookup_row( $this->phone_types(), array( 'mobile', 'cell', 'cellular' ), true );
		if ( is_wp_error( $phone ) || is_wp_error( $type ) ) {
			return is_wp_error( $phone ) ? $phone : $type;
		}
		if ( null === $phone || null === $type ) {
			return new WP_Error( 'vsps_client', 'Shepherd did not return a country or phone type, so the client could not be created.' );
		}
		$body = self::client_body( $c, (string) $phone['id'], (string) $type['id'] );
		$data = $this->post( 'open-api-clients/write', $body, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'vsps_client', 'Shepherd did not return the new client.' );
		}
		return array(
			'id'           => (string) $data['id'],
			'givenName'    => $c['given_name'],
			'familyName'   => $c['family_name'],
			'phoneNumbers' => array( array( 'value' => $c['phone'] ) ),
			'patients'     => array(),
		);
	}

	/**
	 * ClientWriteModel. Phones are required as an array; country and phone type
	 * are lookup ids, not names.
	 */
	public static function client_body( array $client, $country_id, $phone_type_id ) {
		$body = array(
			'firstName'    => $client['given_name'],
			'lastName'     => $client['family_name'],
			'email'        => $client['email'],
			'clientPhones' => array(
				array(
					'countryId'   => (string) $country_id,
					'phoneNumber' => (string) $client['phone'],
					'phoneTypeId' => (string) $phone_type_id,
					'isPrimary'   => true,
				),
			),
		);
		if ( ! empty( $client['phone_alt'] ) ) {
			$body['clientPhones'][] = array(
				'countryId'       => (string) $country_id,
				'phoneNumber'     => (string) $client['phone_alt'],
				'phoneTypeId'     => (string) $phone_type_id,
				'isPrimary'       => false,
				'phoneNumberName' => 'Alternate',
			);
		}
		$a = isset( $client['address'] ) ? $client['address'] : array();
		if ( ! empty( $a['line1'] ) && ! empty( $a['city'] ) && ! empty( $a['state'] ) && ! empty( $a['postal'] ) ) {
			$body['clientInfo'] = array(
				'address' => $a['line1'],
				'address2' => isset( $a['line2'] ) ? $a['line2'] : '',
				'city'    => $a['city'],
				'state'   => $a['state'],
				'zipCode' => $a['postal'],
			);
		}
		if ( ! empty( $client['notes'] ) ) {
			$body['additionalText'] = self::clip( $client['notes'], 1000 );
		}
		if ( ! empty( $client['referral_id'] ) && self::is_clinic_id( $client['referral_id'] ) ) {
			$body['referralSourceIds'] = array( $client['referral_id'] );
		}
		return $body;
	}

	public function create_booking_patient( array $client, array $args ) {
		if ( empty( $client['id'] ) ) {
			return new WP_Error( 'vsps_patient', 'The owner record has no id to attach the pet to.' );
		}
		$p       = $args['patient'];
		$species = self::match_species_id( $this->must_rows( $this->species() ), $p['species'] );
		if ( is_wp_error( $species ) ) {
			return $species;
		}
		if ( '' === $species ) {
			return new WP_Error( 'vsps_patient', 'Shepherd has no species matching "' . $p['species'] . '".' );
		}
		$breeds = $this->collection( 'open-api-breeds', array(
			'speciesId'           => $species,
			'getBreedsByClinicId' => true,
			'isLegacy'            => false,
			'isDeleted'           => false,
			'searchQuery'         => '' !== $p['breed'] ? $p['breed'] : 'Mixed',
			'rpp'                 => 50,
			'page'                => 1,
		) );
		if ( is_wp_error( $breeds ) ) {
			return $breeds;
		}
		$breed = self::match_breed_id( $breeds, $p['breed'] );
		if ( '' === $breed && '' === $p['breed'] ) {
			$breeds = $this->collection( 'open-api-breeds', array(
				'speciesId'           => $species,
				'getBreedsByClinicId' => true,
				'isLegacy'            => false,
				'isDeleted'           => false,
				'rpp'                 => 200,
				'page'                => 1,
			) );
			if ( is_wp_error( $breeds ) ) {
				return $breeds;
			}
			$breed = self::match_breed_id( $breeds, '' );
		}
		if ( '' === $breed ) {
			return new WP_Error( 'vsps_patient', '' !== $p['breed']
				? 'Shepherd has no breed named "' . $p['breed'] . '" for this species.'
				: 'Shepherd needs a breed, and this clinic has no Mixed or Unknown breed for this species.' );
		}
		$sex = self::match_sex_id( $this->must_rows( $this->sexes() ), isset( $p['sex'] ) ? $p['sex'] : '', isset( $p['neutered'] ) ? $p['neutered'] : '' );
		if ( is_wp_error( $sex ) ) {
			return $sex;
		}
		if ( '' === $sex ) {
			return new WP_Error( 'vsps_patient', 'Shepherd did not return a patient sex this plugin could use.' );
		}
		$status = self::match_status_id( $this->must_rows( $this->statuses() ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		if ( '' === $status ) {
			return new WP_Error( 'vsps_patient', 'Shepherd did not return an Active patient status.' );
		}
		$dob = self::date_of_birth( $p );
		if ( is_wp_error( $dob ) ) {
			return $dob;
		}
		$body = array(
			'name'        => $p['name'],
			'clientId'    => (string) $client['id'],
			'breedId'     => $breed,
			'sexId'       => $sex,
			'statusId'    => $status,
			'dateOfBirth' => $dob,
		);
		if ( ! empty( $p['color'] ) ) {
			$body['color'] = self::clip( $p['color'], 60 );
		}
		if ( ! empty( $p['microchip'] ) ) {
			$body['microchipNumber'] = self::clip( $p['microchip'], 15 );
		}
		if ( ! empty( $p['notes'] ) ) {
			$body['additionalText'] = self::clip( $p['notes'], 1000 );
		}
		if ( ! empty( $p['weight'] ) ) {
			$unit = self::match_weight_unit_id( $this->must_rows( $this->weight_units() ), isset( $p['weight_unit'] ) ? $p['weight_unit'] : 'LB' );
			if ( is_wp_error( $unit ) ) {
				return $unit;
			}
			if ( '' !== $unit ) {
				$body['patientWeight'] = array(
					'weight'       => (float) $p['weight'],
					'weightUnitId' => $unit,
				);
			}
		}
		$data = $this->post( 'open-api-patients/write', $body, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return ! empty( $data['id'] )
			? array( 'id' => (string) $data['id'], 'name' => isset( $data['name'] ) ? (string) $data['name'] : $p['name'] )
			: new WP_Error( 'vsps_patient', 'Shepherd did not return the new patient.' );
	}

	/**
	 * PatientWriteModel.dateOfBirth is required and must not be in the future.
	 * An exact birth date wins. Age becomes UTC today minus that many years
	 * (Shepherd stores a date and ignores the time). Neither is an error:
	 * inventing a birthday would be wrong, and the clinic can require Age or
	 * Birth date on the form.
	 *
	 * @return string|WP_Error ISO date-time.
	 */
	public static function date_of_birth( array $patient ) {
		if ( ! empty( $patient['birth_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $patient['birth_date'] ) ) {
			return $patient['birth_date'] . 'T00:00:00Z';
		}
		if ( isset( $patient['age'] ) && '' !== $patient['age'] && is_numeric( $patient['age'] ) ) {
			$dob = ( new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) ) )->modify( '-' . (int) $patient['age'] . ' years' );
			return $dob->format( 'Y-m-d' ) . 'T00:00:00Z';
		}
		return new WP_Error( 'vsps_patient', 'Shepherd requires a date of birth. Ask for the pet\'s age or birth date on the booking form.' );
	}

	/** Widget species (Canine / Feline / Other) → a species lookup id, or ''. */
	public static function match_species_id( $rows, $species ) {
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$map = array(
			'canine' => array( 'canine', 'dog' ),
			'dog'    => array( 'canine', 'dog' ),
			'feline' => array( 'feline', 'cat' ),
			'cat'    => array( 'feline', 'cat' ),
			'other'  => array( 'other', 'unknown', 'exotic' ),
		);
		$key   = strtolower( trim( (string) $species ) );
		$names = isset( $map[ $key ] ) ? $map[ $key ] : array( $key );
		$row   = self::lookup_row( $rows, $names );
		return is_array( $row ) && ! empty( $row['id'] ) ? (string) $row['id'] : '';
	}

	/**
	 * A typed breed must match by name. An empty breed may use Mixed / Unknown / Other.
	 * A typed breed that is not on the list does not fall through to Mixed.
	 */
	public static function match_breed_id( $rows, $typed ) {
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$typed = trim( (string) $typed );
		if ( '' !== $typed ) {
			$row = self::lookup_row( $rows, array( strtolower( $typed ) ) );
			return is_array( $row ) && ! empty( $row['id'] ) ? (string) $row['id'] : '';
		}
		$row = self::lookup_row( $rows, array( 'mixed', 'mixed breed', 'unknown', 'other', 'mix' ) );
		return is_array( $row ) && ! empty( $row['id'] ) ? (string) $row['id'] : '';
	}

	/** MALE/FEMALE/UNKNOWN plus neutered yes/no → a patient-sex lookup id, or ''. */
	public static function match_sex_id( $rows, $sex, $neutered ) {
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$sex = strtoupper( (string) $sex );
		if ( 'MALE' === $sex && 'yes' === $neutered ) {
			$names = array( 'neutered male', 'male neutered', 'neutered' );
		} elseif ( 'FEMALE' === $sex && 'yes' === $neutered ) {
			$names = array( 'spayed female', 'female spayed', 'spayed' );
		} elseif ( 'MALE' === $sex ) {
			$names = array( 'male', 'intact male' );
		} elseif ( 'FEMALE' === $sex ) {
			$names = array( 'female', 'intact female' );
		} else {
			$names = array( 'unknown', 'unspecified', 'other' );
		}
		$row = self::lookup_row( $rows, $names );
		if ( ! is_array( $row ) && ( 'MALE' === $sex || 'FEMALE' === $sex ) ) {
			$row = self::lookup_row( $rows, array( strtolower( $sex ) ) );
		}
		return is_array( $row ) && ! empty( $row['id'] ) ? (string) $row['id'] : '';
	}

	public static function match_status_id( $rows ) {
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$row = self::lookup_row( $rows, array( 'active' ) );
		return is_array( $row ) && ! empty( $row['id'] ) ? (string) $row['id'] : '';
	}

	public static function match_weight_unit_id( $rows, $unit ) {
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$names = 'KG' === strtoupper( (string) $unit )
			? array( 'kg', 'kgs', 'kilogram', 'kilograms' )
			: array( 'lb', 'lbs', 'pound', 'pounds' );
		$row = self::lookup_row( $rows, $names );
		return is_array( $row ) && ! empty( $row['id'] ) ? (string) $row['id'] : '';
	}

	public function create_booking_appointment( array $booking ) {
		try {
			$start = new DateTimeImmutable( $booking['start'] );
		} catch ( Exception $e ) {
			return new WP_Error( 'vsps_datetime', 'Invalid date/time for booking.' );
		}
		$minutes = self::slot_minutes( $booking['duration'] );
		$end     = $start->modify( '+' . $minutes . ' minutes' );
		if ( ! self::on_five_minutes( $start ) || ! self::on_five_minutes( $end ) ) {
			return new WP_Error( 'vsps_datetime', 'Shepherd only books times on a 5-minute boundary.' );
		}
		if ( '' === (string) $booking['provider_id'] ) {
			return new WP_Error( 'vsps_appointment', 'Shepherd requires a provider on every appointment.' );
		}
		$body = array(
			'startDate'                 => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'endDate'                   => $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'providerId'                => (string) $booking['provider_id'],
			'appointmentTypeId'         => (string) $booking['appointment_type_id'],
			'clientId'                  => (string) $booking['client_id'],
			'patientIds'                => array( (string) $booking['patient_id'] ),
			'visitReason'               => self::clip( (string) $booking['reason'], 1000 ),
			'sendEmailNotification'     => false,
			'sendSmsNotification'       => false,
			'sendFormEmailNotification' => false,
		);
		$data = $this->post( 'open-api-appointments/write', $body, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'vsps_appointment', 'Shepherd did not return the new appointment.' );
		}
		return array(
			'id'    => (string) $data['id'],
			'start' => ! empty( $data['startDate'] ) ? (string) $data['startDate'] : $booking['start'],
		);
	}

	/**
	 * Used by VSPS_Booking when the slot has just disappeared: if this client
	 * already holds it, confirm that appointment instead of showing an error.
	 * Read only. Never creates.
	 */
	public function find_client_appointment_at( $location_id, $start, $email, $type_id, $patient_name ) {
		unset( $patient_name );
		if ( strcasecmp( (string) $location_id, $this->clinic_id ) !== 0 ) {
			return null;
		}
		$client = $this->find_client_by_email( $email );
		if ( is_wp_error( $client ) || empty( $client['id'] ) ) {
			return null;
		}
		try {
			$when = new DateTimeImmutable( $start );
		} catch ( Exception $e ) {
			return null;
		}
		$items = $this->collection( 'open-api-appointments', array(
			'dateFrom'  => $when->modify( '-2 hours' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'dateTo'    => $when->modify( '+2 hours' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'isDeleted' => false,
			'rpp'       => 50,
			'page'      => 1,
		) );
		if ( is_wp_error( $items ) ) {
			return null;
		}
		$client_ids = array( strtolower( (string) $client['id'] ) );
		foreach ( isset( $client['patients'] ) ? $client['patients'] : array() as $patient ) {
			if ( ! empty( $patient['clientId'] ) ) {
				$client_ids[] = strtolower( (string) $patient['clientId'] );
			}
		}
		foreach ( $items as $appointment ) {
			if ( empty( $appointment['id'] ) || ! empty( $appointment['isDeleted'] ) ) {
				continue;
			}
			if ( ! in_array( strtolower( (string) ( isset( $appointment['clientId'] ) ? $appointment['clientId'] : '' ) ), $client_ids, true ) ) {
				continue;
			}
			if ( (string) ( isset( $appointment['appointmentTypeId'] ) ? $appointment['appointmentTypeId'] : '' ) !== (string) $type_id ) {
				continue;
			}
			try {
				$have = new DateTimeImmutable( $appointment['startDate'] );
			} catch ( Exception $e ) {
				continue;
			}
			if ( abs( $have->getTimestamp() - $when->getTimestamp() ) < 60 ) {
				return (string) $appointment['id'];
			}
		}
		return null;
	}

	public function get_referral_sources() {
		$items = $this->collection( 'open-api-referral-source', array( 'rpp' => 100, 'page' => 1 ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $row ) {
			if ( empty( $row['id'] ) || ( isset( $row['isActive'] ) && ! $row['isActive'] ) ) {
				continue;
			}
			$out[] = array(
				'id'   => (string) $row['id'],
				'name' => isset( $row['name'] ) ? (string) $row['name'] : ( isset( $row['description'] ) ? (string) $row['description'] : '' ),
			);
		}
		return $out;
	}

	/* ---------- lookups ---------- */

	private function species() {
		return $this->cached_lookup( 'species', 'open-api-species', array( 'isDeleted' => false, 'isLegacy' => false, 'rpp' => 100 ) );
	}

	private function sexes() {
		return $this->cached_lookup( 'sexes', 'open-api-patient-sexes', array( 'rpp' => 50 ) );
	}

	private function statuses() {
		return $this->cached_lookup( 'statuses', 'open-api-patient-statuses', array( 'rpp' => 50 ) );
	}

	private function countries() {
		return $this->cached_lookup( 'countries', 'open-api-countries', array( 'rpp' => 300 ) );
	}

	private function phone_types() {
		return $this->cached_lookup( 'phones', 'open-api-phone-types', array( 'rpp' => 50 ) );
	}

	private function weight_units() {
		return $this->cached_lookup( 'weights', 'open-api-weight-units', array( 'rpp' => 20 ) );
	}

	private function cached_lookup( $name, $path, array $body ) {
		return VSPS_Cache::remember( array( 'shepherd-' . $name, md5( $this->base ) ), function () use ( $path, $body ) {
			return $this->collection( $path, $body );
		}, 12 * HOUR_IN_SECONDS );
	}

	private function must_rows( $rows ) {
		return is_wp_error( $rows ) ? $rows : $rows;
	}

	/**
	 * First row whose name or abrv equals one of $names, in that order.
	 * $names are already lower-case. Returns null when nothing matches.
	 * A WP_Error passed as $rows is returned unchanged.
	 */
	public static function lookup_row( $rows, array $names, $fallback_first = false ) {
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$by = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			foreach ( array( 'name', 'abrv' ) as $key ) {
				if ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
					$by[ strtolower( trim( (string) $row[ $key ] ) ) ] = $row;
				}
			}
		}
		foreach ( $names as $name ) {
			if ( isset( $by[ $name ] ) ) {
				return $by[ $name ];
			}
		}
		if ( $fallback_first ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && ! empty( $row['id'] ) ) {
					return $row;
				}
			}
		}
		return null;
	}

	/* ---------- small helpers ---------- */

	public static function on_five_minutes( DateTimeImmutable $at ) {
		return 0 === (int) $at->format( 's' ) && 0 === ( (int) $at->format( 'i' ) % 5 );
	}

	private static function ceil_five( DateTimeImmutable $at ) {
		$mod = ( (int) $at->format( 'i' ) % 5 ) * 60 + (int) $at->format( 's' );
		return 0 === $mod ? $at : $at->modify( '+' . ( 300 - $mod ) . ' seconds' );
	}

	private static function overlaps( DateTimeImmutable $start, DateTimeImmutable $end, array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( $start < $block[1] && $end > $block[0] ) {
				return true;
			}
		}
		return false;
	}

	/** @return array List of range arrays (possibly empty). */
	private static function ranges( $value ) {
		if ( ! is_array( $value ) || ! $value ) {
			return array();
		}
		if ( self::is_list( $value ) ) {
			return $value;
		}
		if ( null !== self::range_pair( $value ) ) {
			return array( $value );
		}
		foreach ( array( 'items', 'values', 'ranges' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
				return self::ranges( $value[ $key ] );
			}
		}
		return array();
	}

	/** @return array|null [DateTimeImmutable, DateTimeImmutable] */
	private static function range_bounds( $range, DateTimeZone $tz ) {
		$pair = self::range_pair( $range );
		if ( null === $pair ) {
			return null;
		}
		$start = self::parse_instant( $pair[0], $tz );
		$end   = self::parse_instant( $pair[1], $tz );
		if ( null === $start || null === $end || $end <= $start ) {
			return null;
		}
		return array( $start, $end );
	}

	private static function range_pair( $range ) {
		if ( ! is_array( $range ) ) {
			return null;
		}
		foreach ( array( array( 'start', 'end' ), array( 'startDate', 'endDate' ), array( 'min', 'max' ), array( 'from', 'to' ) ) as $keys ) {
			if ( isset( $range[ $keys[0] ], $range[ $keys[1] ] ) && is_scalar( $range[ $keys[0] ] ) && is_scalar( $range[ $keys[1] ] ) ) {
				return array( (string) $range[ $keys[0] ], (string) $range[ $keys[1] ] );
			}
		}
		return null;
	}

	private static function parse_instant( $value, DateTimeZone $tz ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			if ( preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
				return ( new DateTimeImmutable( $value ) )->setTimezone( $tz );
			}
			return new DateTimeImmutable( $value, $tz );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function is_list( array $value ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$i = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	private static function clip( $text, $max ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? mb_substr( $text, 0, $max, 'UTF-8' ) : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max ) : $text;
	}
}
