<?php
/**
 * Outbox for the Vetcelerator hub: every appointment (and failed attempt)
 * made through the website widget is written here, sent to the hub by
 * VSPS_Hub, and deleted as soon as the hub acknowledges it.
 *
 * The booking data lives in the hub, not in WordPress (it must not travel
 * with a site export). A row only stays here while the hub has not received
 * it (no key configured, hub unreachable); it is never deleted before that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Log {

	// v5 (1.22.0): the table became an outbox; the upgrade deletes the
	// history the hub already holds.
	const DB_VERSION = '5';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vsps_bookings';
	}

	/** Creates / upgrades the table once per DB_VERSION (works for auto-updates, no activation hook needed). */
	public static function maybe_install() {
		if ( get_option( 'vsps_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			outcome varchar(10) NOT NULL DEFAULT 'booked',
			appointment_id varchar(32) DEFAULT NULL,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			appointment_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type_name varchar(120) NOT NULL DEFAULT '',
			client_id varchar(32) NOT NULL DEFAULT '',
			client_name varchar(160) NOT NULL DEFAULT '',
			client_email varchar(190) NOT NULL DEFAULT '',
			client_type varchar(10) NOT NULL DEFAULT 'new',
			patient_id varchar(32) NOT NULL DEFAULT '',
			patient_name varchar(120) NOT NULL DEFAULT '',
			pet_is_new tinyint(1) NOT NULL DEFAULT 0,
			slot_date date DEFAULT NULL,
			slot_time varchar(5) NOT NULL DEFAULT '',
			start_utc datetime DEFAULT NULL,
			provider_name varchar(120) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			is_confirmed tinyint(1) NOT NULL DEFAULT 0,
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			error_code varchar(40) NOT NULL DEFAULT '',
			error_message varchar(255) NOT NULL DEFAULT '',
			layout varchar(20) NOT NULL DEFAULT '',
			variant varchar(4) NOT NULL DEFAULT '',
			page_url varchar(255) NOT NULL DEFAULT '',
			after_hours tinyint(1) DEFAULT NULL,
			synced_at datetime DEFAULT NULL,
			edited_at datetime DEFAULT NULL,
			event_id varchar(64) DEFAULT NULL,
			hub_synced_at datetime DEFAULT NULL,
			hub_refused_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY appointment_id (appointment_id),
			UNIQUE KEY appt_unique (appointment_id),
			KEY status (status),
			KEY outcome (outcome),
			KEY event_id (event_id),
			KEY hub_synced_at (hub_synced_at)
		) {$charset};";
		dbDelta( $sql );
		// Only remember the version when the table is really there; otherwise the
		// next request tries again instead of silently losing every booking.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			// dbDelta can fail to add a column (permissions, lock timeout) without
			// saying so: confirm the hub columns before anything relies on them.
			$missing = array();
			foreach ( array( 'event_id', 'hub_synced_at', 'hub_refused_at' ) as $column ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$missing[] = $column;
				}
			}
			if ( $missing ) {
				error_log( '[vetspire-scheduler] could not add column(s) ' . implode( ', ', $missing ) . ' to ' . $table . ': ' . $wpdb->last_error );
				return; // version not stored: the next request retries the upgrade
			}
			// Rows that pre-date event_id get a deterministic one (safe to re-run),
			// and every not-yet-delivered row is queued for the Vetcelerator hub.
			$wpdb->query( "UPDATE {$table} SET event_id = CONCAT('wp-', id) WHERE event_id IS NULL OR event_id = ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// Rows the hub already acknowledged are no longer kept on the site.
			self::purge_delivered();
			// Leftovers of the removed Bookings screen.
			delete_option( 'vsps_log_backfill_done' );
			delete_transient( 'vsps_pending_online' );
			update_option( 'vsps_db_version', self::DB_VERSION, false );
			if ( class_exists( 'VSPS_Hub' ) ) {
				if ( $wpdb->get_var( "SELECT 1 FROM {$table} WHERE hub_synced_at IS NULL LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					VSPS_Hub::mark_pending();
				} else {
					VSPS_Hub::ensure_pending_option();
				}
			}
		} else {
			error_log( '[vetspire-scheduler] could not create ' . $table . ': ' . $wpdb->last_error );
		}
	}

	/** Deletes rows the hub has acknowledged (only ever those). Returns the number deleted. */
	public static function purge_delivered() {
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE hub_synced_at IS NOT NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Deletes acknowledged rows by event_id. Returns the number deleted, or false on a database error. */
	public static function delete_events( array $event_ids ) {
		global $wpdb;
		$event_ids = array_values( array_unique( array_map( 'strval', $event_ids ) ) );
		if ( ! $event_ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%s' ) );
		$deleted = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'DELETE FROM ' . self::table() . " WHERE event_id IN ({$placeholders})",
			$event_ids
		) );
		return false === $deleted ? false : (int) $deleted;
	}

	private static function insert( array $row ) {
		global $wpdb;
		$row['event_id'] = wp_generate_uuid4();
		if ( false === $wpdb->insert( self::table(), $row ) ) {
			error_log( '[vetspire-scheduler] bookings log insert failed: ' . $wpdb->last_error );
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		// Deliver to the Vetcelerator hub after the visitor's response is sent.
		if ( class_exists( 'VSPS_Hub' ) ) {
			VSPS_Hub::row_written();
		}
		return $id;
	}

	/* ---------- recording ---------- */

	/** A successful widget booking. $args = validated request, $result = VSPS_Booking::book() result. */
	public static function record_booking( array $args, array $result, array $extra = array() ) {
		$start_utc = null;
		if ( ! empty( $result['start'] ) ) {
			try {
				$start_utc = ( new DateTimeImmutable( $result['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				$start_utc = null;
			}
		}
		$row = array_merge( self::common_fields( $args, $extra ), array(
			'outcome'        => 'booked',
			'appointment_id' => (string) $result['appointment_id'],
			'client_id'      => isset( $result['client_id'] ) ? (string) $result['client_id'] : '',
			'patient_id'     => isset( $result['patient_id'] ) ? (string) $result['patient_id'] : '',
			'client_type'    => ! empty( $result['existing_client'] ) ? 'existing' : 'new',
			// Vetspire's own name for the pet when an existing one was matched (the
			// visitor's typed spelling/casing would otherwise look "edited" on the
			// very first sync even though nobody touched Vetspire).
			'patient_name'   => isset( $result['patient_name'] ) && '' !== $result['patient_name']
				? substr( (string) $result['patient_name'], 0, 120 )
				: substr( isset( $args['patient']['name'] ) ? (string) $args['patient']['name'] : '', 0, 120 ),
			'type_name'      => isset( $result['type_name'] ) ? substr( (string) $result['type_name'], 0, 120 ) : '',
			'provider_name'  => isset( $result['provider_name'] ) ? substr( (string) $result['provider_name'], 0, 120 ) : '',
			'start_utc'      => $start_utc,
			'status'         => 'PLANNED',
			'after_hours'    => isset( $extra['after_hours'] ) && null !== $extra['after_hours'] ? (int) (bool) $extra['after_hours'] : null,
		) );
		return self::insert( $row );
	}

	/** A booking attempt Vetspire (or our own guards) refused. Honeypot/rate-limit noise is not logged. */
	public static function record_failure( array $args, $code, $message, array $extra = array() ) {
		$row = array_merge( self::common_fields( $args, $extra ), array(
			'outcome'       => 'failed',
			'status'        => 'FAILED',
			'error_code'    => substr( (string) $code, 0, 40 ),
			'error_message' => substr( (string) $message, 0, 255 ),
		) );
		return self::insert( $row );
	}

	private static function common_fields( array $args, array $extra ) {
		$client = isset( $args['client'] ) ? $args['client'] : array();
		$name   = trim( ( isset( $client['given_name'] ) ? $client['given_name'] : '' ) . ' ' . ( isset( $client['family_name'] ) ? $client['family_name'] : '' ) );
		return array(
			'created_at'          => current_time( 'mysql', true ),
			'location_id'         => isset( $args['location_id'] ) ? (int) $args['location_id'] : 0,
			'appointment_type_id' => isset( $args['appointment_type_id'] ) ? (int) $args['appointment_type_id'] : 0,
			'client_name'         => substr( $name, 0, 160 ),
			'client_email'        => substr( isset( $client['email'] ) ? (string) $client['email'] : '', 0, 190 ),
			'client_type'         => isset( $args['client_type'] ) && 'existing' === $args['client_type'] ? 'existing' : 'new',
			'patient_name'        => substr( isset( $args['patient']['name'] ) ? (string) $args['patient']['name'] : '', 0, 120 ),
			'pet_is_new'          => ! empty( $args['pet_is_new'] ) ? 1 : 0,
			'slot_date'           => isset( $args['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['date'] ) ? $args['date'] : null,
			'slot_time'           => isset( $args['time'] ) ? substr( (string) $args['time'], 0, 5 ) : '',
			'layout'              => substr( isset( $extra['layout'] ) ? (string) $extra['layout'] : '', 0, 20 ),
			'variant'             => substr( isset( $extra['variant'] ) ? (string) $extra['variant'] : '', 0, 4 ),
			'page_url'            => substr( isset( $extra['page_url'] ) ? (string) $extra['page_url'] : '', 0, 255 ),
		);
	}
}
