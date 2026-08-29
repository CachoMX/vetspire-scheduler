<?php
/**
 * Server-side GraphQL client for the Vetspire API.
 * The API token is admin-level: it must NEVER reach the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Api {

	private $token;
	private $endpoint;

	public function __construct( $token, $endpoint ) {
		$this->token    = $token;
		$this->endpoint = $endpoint;
	}

	/**
	 * Executes a GraphQL request.
	 *
	 * @return array|WP_Error Decoded `data` array on success.
	 */
	public function request( $query, $variables = array() ) {
		$body = array( 'query' => $query );
		if ( ! empty( $variables ) ) {
			$body['variables'] = $variables;
		}

		$response = wp_remote_post( $this->endpoint, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => $this->token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || null === $json ) {
			return new WP_Error( 'vsps_http', 'Vetspire API returned HTTP ' . $code );
		}
		if ( ! empty( $json['errors'] ) ) {
			$msg = isset( $json['errors'][0]['message'] ) ? $json['errors'][0]['message'] : 'GraphQL error';
			return new WP_Error( 'vsps_graphql', $msg, $json['errors'] );
		}
		return isset( $json['data'] ) ? $json['data'] : array();
	}

	/** Basic connectivity check; returns org + locations. */
	public function get_org_and_locations() {
		return $this->request( '{ org { id name } locations { id name timezone } }' );
	}

	public function get_location( $location_id ) {
		$data = $this->request(
			'query ($id: ID!) { location(id: $id) { id name timezone } }',
			array( 'id' => (string) $location_id )
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['location'] ) ? $data['location'] : null );
	}

	/** Public clinic profile for the location drawer (address, phone, hours…). */
	public function get_location_info( $location_id ) {
		$data = $this->request(
			'query ($id: ID!) {
				location(id: $id) {
					id name displayName timezone addressString phoneNumber
					latitude longitude googleLink url
					locationHours {
						mondayRanges tuesdayRanges wednesdayRanges thursdayRanges
						fridayRanges saturdayRanges sundayRanges
					}
				}
			}',
			array( 'id' => (string) $location_id )
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['location'] ) ? $data['location'] : null );
	}

	/** Appointment types enabled for a location, online-bookable only. */
	public function get_bookable_types( $location_id ) {
		$data = $this->request(
			'query ($loc: ID!) { locationAppointmentTypes(locationId: $loc) { id name duration canBookOnline } }',
			array( 'loc' => (string) $location_id )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$types = isset( $data['locationAppointmentTypes'] ) ? $data['locationAppointmentTypes'] : array();
		return array_values( array_filter( $types, function ( $t ) {
			return ! empty( $t['canBookOnline'] );
		} ) );
	}

	/** Available slots for one type on one date. */
	public function get_available_times( $location_id, $type_id, $date ) {
		$data = $this->request(
			'query ($loc: ID!, $type: ID!, $date: Date!) {
				availableTimes(locationId: $loc, appointmentTypeId: $type, date: $date) {
					time providerId scheduleId provider { id name }
				}
			}',
			array(
				'loc'  => (string) $location_id,
				'type' => (string) $type_id,
				'date' => $date,
			)
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['availableTimes'] ) ? $data['availableTimes'] : array() );
	}

	/** Finds an existing client by exact email. Returns first match or null. */
	public function find_client_by_email( $email ) {
		$data = $this->request(
			'query ($email: String) {
				clients(filters: { email: $email }, limit: 5) {
					id email givenName familyName
					phoneNumbers { value }
					patients { id name isActive isDeceased }
				}
			}',
			array( 'email' => $email )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$clients = isset( $data['clients'] ) ? $data['clients'] : array();
		foreach ( $clients as $client ) {
			if ( isset( $client['email'] ) && 0 === strcasecmp( $client['email'], $email ) ) {
				return $client;
			}
		}
		return null;
	}

	public function create_client( $input ) {
		$data = $this->request(
			'mutation ($input: ClientInput) { createClient(input: $input) { id email givenName familyName } }',
			array( 'input' => $input )
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['createClient'] ) ? $data['createClient'] : null );
	}

	public function create_patient( $client_id, $input ) {
		$data = $this->request(
			'mutation ($clientId: ID!, $input: PatientInput) { createPatient(clientId: $clientId, input: $input) { id name } }',
			array(
				'clientId' => (string) $client_id,
				'input'    => $input,
			)
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['createPatient'] ) ? $data['createPatient'] : null );
	}

	/** Appointments for a location within [start, end) — for the admin schedule. */
	public function get_appointments( $location_id, $start_iso, $end_iso ) {
		$data = $this->request(
			'query ($loc: ID!, $start: DateTime, $end: DateTime) {
				appointments(locationId: $loc, start: $start, end: $end, limit: 200, includeCompleted: true) {
					id start duration status isConfirmed bookedOnline reason
					type { id name }
					provider { id name }
					patient { id name client { id givenName familyName email phoneNumbers { value } } }
				}
			}',
			array(
				'loc'   => (string) $location_id,
				'start' => $start_iso,
				'end'   => $end_iso,
			)
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['appointments'] ) ? $data['appointments'] : array() );
	}

	public function update_appointment( $id, $input ) {
		$data = $this->request(
			'mutation ($id: ID!, $input: AppointmentInput!) {
				updateAppointment(id: $id, input: $input) { id start status isConfirmed }
			}',
			array(
				'id'    => (string) $id,
				'input' => $input,
			)
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['updateAppointment'] ) ? $data['updateAppointment'] : null );
	}

	public function create_appointment( $input ) {
		$data = $this->request(
			'mutation ($input: AppointmentInput!) {
				createAppointment(input: $input) { id start status patient { id name } }
			}',
			array( 'input' => $input )
		);
		return is_wp_error( $data ) ? $data : ( isset( $data['createAppointment'] ) ? $data['createAppointment'] : null );
	}
}
