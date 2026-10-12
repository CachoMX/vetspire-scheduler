<?php
/**
 * Booking orchestration, the same for every platform (VSPS_Pims): validate the
 * type and slot against live data, reuse or create the client and the pet,
 * then create the appointment (a request on request-based platforms).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Booking {

	// Every widget booking is tagged with this in the appointment reason (not configurable).
	const SOURCE_LABEL = 'Vetcelerator';

	/**
	 * Books an appointment. $args is already-sanitized data:
	 *  location_id, appointment_type_id, date (Y-m-d), time (HH:MM),
	 *  provider_id, schedule_id,
	 *  client { given_name, family_name, email, phone, ... },
	 *  patient { name, species, breed, sex, ... }, notes.
	 *
	 * @return array|WP_Error { appointment_id, start, client_id, patient_id, existing_client, request, ... }
	 */
	public static function book( VSPS_Pims $api, $args ) {
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
		$slot        = null;
		if ( empty( $args['skip_slot_check'] ) ) {
			$slots = $api->get_available_times( $args['location_id'], $args['appointment_type_id'], $args['date'] );
			if ( is_wp_error( $slots ) ) {
				return $slots;
			}
			$slot = self::match_slot( $slots, $args['time'], $args['provider_id'] );
			if ( null === $slot ) {
				// The PIMS occasionally answers with a partial list (seen live on
				// Vetspire: a free slot rejected, then booked fine seconds later).
				// One short retry before telling the visitor the time is gone.
				usleep( 400000 );
				$again = $api->get_available_times( $args['location_id'], $args['appointment_type_id'], $args['date'] );
				if ( ! is_wp_error( $again ) ) {
					$slot = self::match_slot( $again, $args['time'], $args['provider_id'] );
				}
			}
			if ( null === $slot ) {
				// The slot can be "gone" because this same client already holds it:
				// a retry after a slow response, or a second tab. Confirm that
				// booking instead of showing an error to someone who is booked.
				$held = self::held_by_client( $api, $args, $location['timezone'] );
				if ( null !== $held ) {
					return $held;
				}
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

		// 1. Reuse the existing client when the email is already on file.
		$client          = $api->find_client_by_email( $args['client']['email'] );
		$existing_client = false;
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		// Returning-client flow: the client MUST already exist - never create one
		// from this path (the visitor only supplied an email).
		if ( ! empty( $args['client_type'] ) && 'existing' === $args['client_type'] && ! $client ) {
			return new WP_Error( 'vsps_client_missing', 'We could not find that email. Please book as a new client.' );
		}
		if ( $client ) {
			$existing_client = true;
		} else {
			$client = $api->create_booking_client( $args );
			if ( is_wp_error( $client ) ) {
				return $client;
			}
			if ( empty( $client['id'] ) ) {
				return new WP_Error( 'vsps_client', 'Could not create the client in the practice system.' );
			}
		}

		// 2. Reuse the client's patient when the name matches, otherwise create it.
		// Keep the PIMS's own casing/spelling for the log (visitor might type "fluffy"
		// for a pet on file as "Fluffy") so a later sync never mistakes that
		// difference for a real edit made in the PIMS.
		$matched_patient = self::match_patient( $client, $args['patient']['name'] );
		$patient_id      = $matched_patient ? $matched_patient['id'] : null;
		// A platform can keep one email on several owner records (Nectar): the
		// appointment then belongs to the owner the matched pet is on.
		$client_id = $matched_patient && ! empty( $matched_patient['clientId'] ) ? $matched_patient['clientId'] : $client['id'];
		if ( null === $patient_id && $existing_client ) {
			// The security decision keys off what the SERVER found ($existing_client),
			// never off the client-supplied client_type — otherwise the "new client"
			// path would silently dedupe onto the real account with no verification.
			if ( ! empty( $args['client_type'] ) && 'existing' === $args['client_type'] && empty( $args['pet_is_new'] ) ) {
				return new WP_Error( 'vsps_pet_missing', 'We could not find that pet on your account. Please pick again or add it as a new pet.' );
			}
			// Creating a pet on an account that already exists requires proving
			// ownership of the phone on file: the explicit last-4 field (returning
			// flow) or the last 4 of the full phone typed on the new-client form.
			// FAIL CLOSED: if the digits don't match — including when the account
			// has NO phone on file to verify against — we refuse and tell them to
			// call the clinic, so a stranger who only knows the email can never
			// inject a pet into someone's record.
			$claim = isset( $args['phone_last4'] ) && '' !== $args['phone_last4']
				? $args['phone_last4']
				: substr( preg_replace( '/\D/', '', $args['client']['phone'] ), -4 );
			if ( ! self::phone_last4_matches( $client, $claim ) ) {
				return new WP_Error( 'vsps_verify', 'We could not verify that this account is yours. Please use the phone number the clinic has on file, or call the clinic to add a new pet.' );
			}
		}
		if ( null === $patient_id ) {
			$patient = $api->create_booking_patient( $client, $args );
			if ( is_wp_error( $patient ) ) {
				return $patient;
			}
			if ( empty( $patient['id'] ) ) {
				return new WP_Error( 'vsps_patient', 'Could not create the patient in the practice system.' );
			}
			$patient_id = $patient['id'];
		}

		// 3. Create the appointment on the selected slot.
		$appointment = $api->create_booking_appointment( array(
			'location_id'         => (string) $args['location_id'],
			'appointment_type_id' => (string) $args['appointment_type_id'],
			'client_id'           => (string) $client_id,
			'patient_id'          => (string) $patient_id,
			'start'               => $start,
			'duration'            => $duration,
			'provider_id'         => $provider_id,
			'schedule_id'         => $schedule_id,
			'reason'              => self::SOURCE_LABEL . ' booking' . ( '' !== $args['notes'] ? ': ' . $args['notes'] : '' ),
		) );
		if ( is_wp_error( $appointment ) ) {
			return $appointment;
		}
		if ( empty( $appointment['id'] ) ) {
			return new WP_Error( 'vsps_appointment', 'Could not create the appointment in the practice system.' );
		}

		return array(
			'appointment_id'  => $appointment['id'],
			'start'           => $appointment['start'],
			'client_id'       => $client_id,
			'patient_id'      => $patient_id,
			// The PIMS's own name when an existing pet was matched; the visitor's
			// typed name is only canonical for a brand-new patient we just created.
			'patient_name'    => $matched_patient ? $matched_patient['name'] : $args['patient']['name'],
			'existing_client' => $existing_client,
			'type_name'       => isset( $type['name'] ) ? $type['name'] : '',
			'provider_name'   => isset( $slot['provider']['name'] ) ? $slot['provider']['name'] : '',
			// true = the clinic still has to confirm it (request-based platforms).
			'request'         => 'request' === VSPS_Pims_Registry::booking_mode( $api->platform() ),
		);
	}

	/** True when the client's phone(s) on file end with the given 4 digits. */
	private static function phone_last4_matches( $client, $last4 ) {
		if ( ! preg_match( '/^\d{4}$/', (string) $last4 ) || empty( $client['phoneNumbers'] ) || ! is_array( $client['phoneNumbers'] ) ) {
			return false;
		}
		foreach ( $client['phoneNumbers'] as $phone ) {
			$digits = preg_replace( '/\D/', '', isset( $phone['value'] ) ? $phone['value'] : '' );
			if ( strlen( $digits ) >= 4 && substr( $digits, -4 ) === $last4 ) {
				return true;
			}
		}
		return false;
	}

	/** Case-insensitive match of an active patient by name on an existing client. */
	/** Returns the matched {id, name, clientId} (the PIMS's own casing) or null. */
	private static function match_patient( $client, $patient_name ) {
		if ( empty( $client['patients'] ) || ! is_array( $client['patients'] ) ) {
			return null;
		}
		// trim() on both sides: a stray leading/trailing space in either the
		// visitor's submitted value or Vetspire's own stored patient name is
		// invisible on screen (confirmed live: Iowa Colony's "Reba " has a
		// trailing space nobody could see) but fails an exact strcasecmp,
		// wrongly telling a real returning client their real pet isn't on
		// their account.
		$needle = trim( (string) $patient_name );
		foreach ( $client['patients'] as $patient ) {
			$active   = ! isset( $patient['isActive'] ) || $patient['isActive'];
			$deceased = ! empty( $patient['isDeceased'] );
			if ( $active && ! $deceased && 0 === strcasecmp( trim( (string) $patient['name'] ), $needle ) ) {
				return array(
					'id'       => $patient['id'],
					'name'     => $patient['name'],
					'clientId' => isset( $patient['clientId'] ) ? $patient['clientId'] : '',
				);
			}
		}
		return null;
	}

	/**
	 * The result of a booking this client already holds at the requested start,
	 * or null. Only for platforms that can look it up (Vetspire today).
	 */
	private static function held_by_client( VSPS_Pims $api, $args, $timezone ) {
		if ( ! method_exists( $api, 'find_client_appointment_at' ) || empty( $args['client']['email'] ) || empty( $args['patient']['name'] ) ) {
			return null;
		}
		$start = self::to_iso8601( $args['date'], $args['time'], $timezone );
		if ( is_wp_error( $start ) ) {
			return null;
		}
		$id = $api->find_client_appointment_at(
			$args['location_id'],
			$start,
			$args['client']['email'],
			$args['appointment_type_id'],
			$args['patient']['name']
		);
		if ( null === $id ) {
			return null;
		}
		return array(
			'appointment_id' => $id,
			'start'          => $start,
			'already_booked' => true,
			'request'        => false,
		);
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
