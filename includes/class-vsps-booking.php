<?php
/**
 * Booking orchestration: dedupe client by email, create client/patient
 * when needed, then create the appointment in Vetspire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Booking {

	/**
	 * Books an appointment. $args is already-sanitized data:
	 *  location_id, appointment_type_id, date (Y-m-d), time (HH:MM),
	 *  provider_id, schedule_id, duration,
	 *  client { given_name, family_name, email, phone },
	 *  patient { name, species, breed, sex }, notes.
	 *
	 * @return array|WP_Error { appointment_id, start, client_id, patient_id, existing_client }
	 */
	public static function book( VSPS_Api $api, $args ) {
		$location = $api->get_location( $args['location_id'] );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		if ( empty( $location['timezone'] ) ) {
			return new WP_Error( 'vsps_location', 'Unknown location or missing timezone.' );
		}

		// Never trust client-supplied type/duration: the type must be online-bookable
		// at this location, and duration always comes from the type definition.
		$types = $api->get_bookable_types( $args['location_id'] );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		$type = null;
		foreach ( $types as $candidate ) {
			if ( (string) $candidate['id'] === (string) $args['appointment_type_id'] ) {
				$type = $candidate;
				break;
			}
		}
		if ( null === $type ) {
			return new WP_Error( 'vsps_type', 'This appointment type cannot be booked online.' );
		}
		$duration = (int) $type['duration'];

		// Re-validate the slot against live availability (uncached) so a forged or
		// stale request cannot book outside real open times. Provider/schedule are
		// taken from the matched slot, never from the request.
		$provider_id = '';
		$schedule_id = '';
		if ( empty( $args['skip_slot_check'] ) ) {
			$slots = $api->get_available_times( $args['location_id'], $args['appointment_type_id'], $args['date'] );
			if ( is_wp_error( $slots ) ) {
				return $slots;
			}
			$slot = self::match_slot( $slots, $args['time'], $args['provider_id'] );
			if ( null === $slot ) {
				return new WP_Error( 'vsps_slot', 'That time is no longer available. Please pick another slot.' );
			}
			$provider_id = isset( $slot['providerId'] ) ? (string) $slot['providerId'] : '';
			$schedule_id = isset( $slot['scheduleId'] ) ? (string) $slot['scheduleId'] : '';
		} else {
			$provider_id = (string) $args['provider_id'];
			$schedule_id = (string) $args['schedule_id'];
		}

		$start = self::to_iso8601( $args['date'], $args['time'], $location['timezone'] );
		if ( is_wp_error( $start ) ) {
			return $start;
		}

		// 1. Reuse existing client when the email already exists in Vetspire.
		$client          = $api->find_client_by_email( $args['client']['email'] );
		$existing_client = false;
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( $client ) {
			$existing_client = true;
		} else {
			$client = $api->create_client( array(
				'givenName'         => $args['client']['given_name'],
				'familyName'        => $args['client']['family_name'],
				'email'             => $args['client']['email'],
				'phoneNumbers'      => array( array( 'value' => $args['client']['phone'] ) ),
				'primaryLocationId' => (string) $args['location_id'],
				'notes'             => 'Created via website online scheduler.',
			) );
			if ( is_wp_error( $client ) ) {
				return $client;
			}
			if ( empty( $client['id'] ) ) {
				return new WP_Error( 'vsps_client', 'Could not create client in Vetspire.' );
			}
		}

		// 2. Reuse the client's patient when the name matches, otherwise create it.
		$patient_id = self::match_patient( $client, $args['patient']['name'] );
		if ( null === $patient_id ) {
			$patient_input = array(
				'name'    => $args['patient']['name'],
				'species' => $args['patient']['species'],
			);
			if ( '' !== $args['patient']['breed'] ) {
				$patient_input['breed'] = $args['patient']['breed'];
			}
			if ( '' !== $args['patient']['sex'] ) {
				$patient_input['sex'] = $args['patient']['sex'];
			}
			if ( ! empty( $args['patient']['age'] ) ) {
				$patient_input['birthYear']      = (int) gmdate( 'Y' ) - (int) $args['patient']['age'];
				$patient_input['isEstimatedAge'] = true;
			}
			if ( ! empty( $args['patient']['neutered'] ) ) {
				$patient_input['neutered'] = 'yes' === $args['patient']['neutered'];
			}
			$patient = $api->create_patient( $client['id'], $patient_input );
			if ( is_wp_error( $patient ) ) {
				return $patient;
			}
			if ( empty( $patient['id'] ) ) {
				return new WP_Error( 'vsps_patient', 'Could not create patient in Vetspire.' );
			}
			$patient_id = $patient['id'];
		}

		// 3. Create the appointment on the selected slot.
		$input = array(
			'locationId'        => (string) $args['location_id'],
			'appointmentTypeId' => (string) $args['appointment_type_id'],
			'patientId'         => (string) $patient_id,
			'start'             => $start,
			'duration'          => $duration,
			'bookedOnline'      => true,
			'sendConfirmation'  => true,
			'reason'            => ( isset( $args['source_label'] ) && '' !== $args['source_label'] ? $args['source_label'] : 'Online' )
				. ' booking' . ( '' !== $args['notes'] ? ': ' . $args['notes'] : '' ),
		);
		if ( '' !== $provider_id ) {
			$input['providerId'] = $provider_id;
		}
		if ( '' !== $schedule_id ) {
			$input['scheduleId'] = $schedule_id;
		}

		$appointment = $api->create_appointment( $input );
		if ( is_wp_error( $appointment ) ) {
			return $appointment;
		}
		if ( empty( $appointment['id'] ) ) {
			return new WP_Error( 'vsps_appointment', 'Could not create appointment in Vetspire.' );
		}

		return array(
			'appointment_id'  => $appointment['id'],
			'start'           => $appointment['start'],
			'client_id'       => $client['id'],
			'patient_id'      => $patient_id,
			'existing_client' => $existing_client,
		);
	}

	/** Case-insensitive match of an active patient by name on an existing client. */
	private static function match_patient( $client, $patient_name ) {
		if ( empty( $client['patients'] ) || ! is_array( $client['patients'] ) ) {
			return null;
		}
		foreach ( $client['patients'] as $patient ) {
			$active   = ! isset( $patient['isActive'] ) || $patient['isActive'];
			$deceased = ! empty( $patient['isDeceased'] );
			if ( $active && ! $deceased && 0 === strcasecmp( $patient['name'], $patient_name ) ) {
				return $patient['id'];
			}
		}
		return null;
	}

	/** Picks the slot matching the requested time, preferring the requested provider. */
	private static function match_slot( $slots, $time, $preferred_provider ) {
		$match = null;
		foreach ( $slots as $slot ) {
			if ( ! isset( $slot['time'] ) || $slot['time'] !== $time ) {
				continue;
			}
			if ( '' !== $preferred_provider && isset( $slot['providerId'] ) && (string) $slot['providerId'] === (string) $preferred_provider ) {
				return $slot;
			}
			if ( null === $match ) {
				$match = $slot;
			}
		}
		return $match;
	}

	/**
	 * Builds an ISO8601 datetime from local clinic date/time + timezone.
	 * Rejects local times that don't exist (DST spring-forward gap), where PHP
	 * would otherwise silently shift the clock time.
	 */
	private static function to_iso8601( $date, $time, $timezone ) {
		try {
			$tz = new DateTimeZone( $timezone );
			$dt = new DateTimeImmutable( $date . ' ' . $time, $tz );
		} catch ( Exception $e ) {
			return new WP_Error( 'vsps_datetime', 'Invalid date/time for booking.' );
		}
		if ( $dt->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return new WP_Error( 'vsps_datetime', 'That time does not exist on the selected date. Please pick another slot.' );
		}
		return $dt->format( 'c' );
	}
}
