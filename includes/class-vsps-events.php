<?php
/**
 * Widget funnel events for the Vetcelerator hub (widget_view → booking_completed,
 * plus form_abandoned with the KEYS of the fields a visitor had filled).
 *
 * The browser batches events and posts them to `vetspire/v1/events`; they are
 * stored in `wp_vsps_events`, an outbox like the bookings table: VSPS_Hub sends
 * them to the hub and deletes every row the hub acknowledges. Events are
 * analytics, not records, so rows the hub never acknowledged are pruned after
 * RETENTION_DAYS. Nothing here may ever slow down or break a booking.
 *
 * Privacy: events carry ids, the page path and field keys only. No names,
 * emails, phones or typed values are accepted (field keys are checked against
 * the field registry; anything else is dropped).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Events {

	const NAMES          = array( 'widget_view', 'slot_selected', 'form_started', 'booking_submitted', 'booking_completed', 'booking_failed', 'form_abandoned' );
	const DEVICES        = array( 'mobile', 'tablet', 'desktop' );
	const LAYOUTS        = array( 'full', 'bar', 'calendar', 'float' );
	const MAX_PER_CALL   = 50;   // events accepted in one browser request
	const MAX_FIELDS     = 40;   // field keys on one form_abandoned
	const RATE_LIMIT     = 120;  // browser requests per IP...
	const RATE_TTL       = 3600; // ...per hour (fixed window)
	const RETENTION_DAYS = 14;   // unacknowledged rows older than this are dropped
	const ID_PATTERN     = '/^[A-Za-z0-9_-]{8,64}$/';
	// A request that delivers events also asks for a hub push, at most this often
	// (the hourly cron catches everything else).
	const PUSH_EVERY     = 300;
	const PUSH_OPTION    = 'vsps_events_push_after';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vsps_events';
	}

	/** CREATE TABLE statement for dbDelta (run by VSPS_Log::install with the bookings table). */
	public static function create_sql( $charset ) {
		$table = self::table();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(64) NOT NULL,
			session_id varchar(64) NOT NULL DEFAULT '',
			name varchar(24) NOT NULL DEFAULT '',
			occurred_at datetime NOT NULL,
			device varchar(10) NOT NULL DEFAULT '',
			layout varchar(20) NOT NULL DEFAULT '',
			variant varchar(10) NOT NULL DEFAULT '',
			location_id varchar(64) NOT NULL DEFAULT '',
			appointment_type_id varchar(64) NOT NULL DEFAULT '',
			page_path varchar(255) NOT NULL DEFAULT '',
			fields text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY occurred_at (occurred_at)
		) {$charset};";
	}

	/* ---------- validation (pure: no WordPress, unit-tested in tests/harness.php) ---------- */

	/**
	 * Decodes a browser request body (JSON text, sent as text/plain by
	 * sendBeacon) into stored rows. Returns null when the body itself is not a
	 * valid batch (not JSON, no `events` list, more than MAX_PER_CALL events);
	 * single bad events are just dropped.
	 */
	public static function parse_body( $body, $occurred_at ) {
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > 200000 ) {
			return null;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return null;
		}
		$events = $data['events'];
		if ( array_values( $events ) !== $events || count( $events ) > self::MAX_PER_CALL ) {
			return null;
		}
		$rows = array();
		$seen = array();
		foreach ( $events as $event ) {
			$row = self::sanitize_event( $event, $occurred_at );
			if ( null !== $row && ! isset( $seen[ $row['event_id'] ] ) ) {
				$seen[ $row['event_id'] ] = true;
				$rows[]                   = $row;
			}
		}
		return $rows;
	}

	/** One browser event → one row, or null when its ids / name are not acceptable. */
	public static function sanitize_event( $event, $occurred_at ) {
		if ( ! is_array( $event ) ) {
			return null;
		}
		$event_id   = self::id( isset( $event['event_id'] ) ? $event['event_id'] : '' );
		$session_id = self::id( isset( $event['session_id'] ) ? $event['session_id'] : '' );
		$name       = isset( $event['name'] ) && is_string( $event['name'] ) ? $event['name'] : '';
		if ( '' === $event_id || '' === $session_id || ! in_array( $name, self::NAMES, true ) ) {
			return null;
		}
		$fields = 'form_abandoned' === $name && isset( $event['fields'] ) ? self::field_keys( $event['fields'] ) : array();
		return array(
			'event_id'            => $event_id,
			'session_id'          => $session_id,
			'name'                => $name,
			'occurred_at'         => (string) $occurred_at,
			'device'              => self::pick( isset( $event['device'] ) ? $event['device'] : '', self::DEVICES ),
			'layout'              => self::pick( isset( $event['layout'] ) ? $event['layout'] : '', self::LAYOUTS ),
			'variant'             => self::variant( isset( $event['variant'] ) ? $event['variant'] : '' ),
			'location_id'         => VSPS_Pims_Registry::sanitize_id( isset( $event['location_id'] ) ? $event['location_id'] : '' ),
			'appointment_type_id' => VSPS_Pims_Registry::sanitize_id( isset( $event['appointment_type_id'] ) ? $event['appointment_type_id'] : '' ),
			'page_path'           => self::page_path( isset( $event['page_path'] ) ? $event['page_path'] : '' ),
			'fields'              => json_encode( $fields ),
		);
	}

	private static function id( $value ) {
		return is_string( $value ) && preg_match( self::ID_PATTERN, $value ) ? $value : '';
	}

	private static function pick( $value, array $allowed ) {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : '';
	}

	private static function variant( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( $value );
		return preg_match( '/^[a-z0-9_-]{1,10}$/', $value ) ? $value : '';
	}

	/** Path only (no host, query or hash), printable ASCII, ≤255 chars, always starting with "/". */
	public static function page_path( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$value = preg_split( '/[?#]/', $value, 2 )[0];
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://[^/]*(.*)$#i', $value, $m ) ) {
			$value = $m[1];
		}
		$value = preg_replace( '/[^\x21-\x7E]/', '', $value );
		if ( '' === $value || '/' !== $value[0] ) {
			return '';
		}
		return substr( $value, 0, 255 );
	}

	/**
	 * The field KEYS of a form_abandoned, in the order sent (the form's DOM
	 * order). Only keys of the field registry survive, so a value or a name
	 * can never be stored here by mistake or by a forged request.
	 */
	public static function field_keys( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$out = array();
		foreach ( $fields as $key ) {
			if ( is_string( $key ) && strlen( $key ) <= 40 && isset( VSPS_Fields::REGISTRY[ $key ] ) && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
				if ( count( $out ) >= self::MAX_FIELDS ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The event ids the hub answered for (accepted, or rejected for good),
	 * limited to the ids that were actually sent. Same rule as bookings.
	 */
	public static function acked_ids( $json, array $sent_ids ) {
		if ( ! is_array( $json ) ) {
			return array();
		}
		$done = array();
		foreach ( isset( $json['accepted'] ) && is_array( $json['accepted'] ) ? $json['accepted'] : array() as $event_id ) {
			if ( is_string( $event_id ) ) {
				$done[] = $event_id;
			}
		}
		foreach ( isset( $json['rejected'] ) && is_array( $json['rejected'] ) ? $json['rejected'] : array() as $rej ) {
			if ( is_array( $rej ) && isset( $rej['event_id'] ) && is_string( $rej['event_id'] ) ) {
				$done[] = $rej['event_id'];
			}
		}
		return array_values( array_unique( array_intersect( $done, array_map( 'strval', $sent_ids ) ) ) );
	}

	/** One stored row → one hub Event. */
	public static function record( $row ) {
		$fields = json_decode( isset( $row->fields ) ? (string) $row->fields : '', true );
		return array(
			'event_id'            => (string) $row->event_id,
			'session_id'          => (string) $row->session_id,
			'name'                => (string) $row->name,
			'occurred_at'         => (string) $row->occurred_at,
			'device'              => (string) $row->device,
			'layout'              => (string) $row->layout,
			'variant'             => (string) $row->variant,
			'location_id'         => (string) $row->location_id,
			'appointment_type_id' => (string) $row->appointment_type_id,
			'page_path'           => (string) $row->page_path,
			'fields'              => 'form_abandoned' === (string) $row->name && is_array( $fields ) ? array_values( $fields ) : array(),
		);
	}

	/* ---------- storage ---------- */

	/** One multi-row INSERT IGNORE (a re-sent event_id is skipped). Returns rows inserted, or false. */
	public static function store( array $rows ) {
		if ( ! $rows ) {
			return 0;
		}
		global $wpdb;
		$columns      = array_keys( $rows[0] );
		$placeholder  = '(' . implode( ',', array_fill( 0, count( $columns ), '%s' ) ) . ')';
		$placeholders = array();
		$values       = array();
		foreach ( $rows as $row ) {
			$placeholders[] = $placeholder;
			foreach ( $columns as $column ) {
				$values[] = (string) $row[ $column ];
			}
		}
		$sql = 'INSERT IGNORE INTO ' . self::table() . ' (' . implode( ',', $columns ) . ') VALUES ' . implode( ',', $placeholders );
		$ok  = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		if ( false === $ok ) {
			error_log( '[vetspire-scheduler] events insert failed: ' . $wpdb->last_error );
			return false;
		}
		return (int) $ok;
	}

	/** Oldest waiting rows first. */
	public static function pending( $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id ASC LIMIT %d', max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Deletes acknowledged rows. Returns the number deleted, or false on a database error. */
	public static function delete_ids( array $event_ids ) {
		global $wpdb;
		$event_ids = array_values( array_unique( array_map( 'strval', $event_ids ) ) );
		if ( ! $event_ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%s' ) );
		$deleted      = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'DELETE FROM ' . self::table() . " WHERE event_id IN ({$placeholders})",
			$event_ids
		) );
		return false === $deleted ? false : (int) $deleted;
	}

	/** Drops rows the hub never acknowledged within RETENTION_DAYS (analytics, not records). */
	public static function prune() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE occurred_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------- the browser endpoint ---------- */

	/**
	 * POST vetspire/v1/events. Always answers quickly; every failure is
	 * swallowed (the widget never waits for or reads this answer).
	 */
	public static function rest_ingest( WP_REST_Request $request, callable $rate_limit_ok ) {
		try {
			// No hub connection key: there is nowhere to deliver events, so none are kept.
			if ( '' === VSPS_Hub::key() ) {
				return new WP_REST_Response( null, 204 );
			}
			$rows = self::parse_body( $request->get_body(), current_time( 'mysql', true ) );
			if ( null === $rows ) {
				return new WP_REST_Response( array( 'code' => 'vsps_invalid' ), 400 );
			}
			if ( ! $rows ) {
				return new WP_REST_Response( null, 204 );
			}
			if ( ! $rate_limit_ok() ) {
				return new WP_REST_Response( array( 'code' => 'vsps_rate' ), 429 );
			}
			if ( self::store( $rows ) ) {
				self::maybe_push();
			}
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] events ingest failed: ' . $e->getMessage() );
		}
		return new WP_REST_Response( null, 204 );
	}

	/** Asks VSPS_Hub for a deferred push (after this response), at most every PUSH_EVERY seconds. */
	private static function maybe_push() {
		$now = time();
		if ( (int) get_option( self::PUSH_OPTION, 0 ) > $now ) {
			return;
		}
		update_option( self::PUSH_OPTION, $now + self::PUSH_EVERY, false );
		VSPS_Hub::schedule_push();
	}
}
