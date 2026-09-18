<?php
/**
 * Bookings log: every appointment (and failed attempt) made through the
 * website widget, stored locally so the admin can see WHAT the widget
 * produced, WHEN, and what happened to it afterwards — even after the
 * appointment is cancelled or deleted in Vetspire.
 *
 * Vetspire stays the source of truth for the appointment itself; this table
 * is the widget's own ledger. Statuses are refreshed from Vetspire on demand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Log {

	const DB_VERSION      = '3';
	const SYNC_STALE_SECS = 300;
	const SYNC_BATCH      = 40;
	// Vetspire's own AppointmentStatus enum (confirmed via GraphQL introspection)
	// spells this NOSHOW, no underscore -- unlike every other multi-word status
	// here, which do use one. Easy typo to make; get it wrong and a no-show
	// appointment never reads as terminal, so it keeps re-syncing forever and
	// its status badge falls through to the default "Pending" instead of
	// matching (it's still correct in the CSV, which prints the raw DB value).
	const TERMINAL        = array( 'CANCELLED', 'COMPLETED', 'NOSHOW', 'CHECKED_OUT' );

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
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY appointment_id (appointment_id),
			UNIQUE KEY appt_unique (appointment_id),
			KEY status (status),
			KEY outcome (outcome)
		) {$charset};";
		dbDelta( $sql );
		// Only remember the version when the table is really there; otherwise the
		// next request tries again instead of silently losing every booking.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			update_option( 'vsps_db_version', self::DB_VERSION, false );
		} else {
			error_log( '[vetspire-scheduler] could not create ' . $table . ': ' . $wpdb->last_error );
		}
	}

	private static function insert( array $row ) {
		global $wpdb;
		if ( false === $wpdb->insert( self::table(), $row ) ) {
			error_log( '[vetspire-scheduler] bookings log insert failed: ' . $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
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

	/* ---------- status sync with Vetspire ---------- */

	/**
	 * Refreshes status/confirmed/deleted from Vetspire for the given rows
	 * (only those not yet in a terminal state and not synced recently).
	 * Returns the number of rows refreshed.
	 */
	public static function sync_rows( VSPS_Api $api, array $rows, $force = false ) {
		global $wpdb;
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::SYNC_STALE_SECS );
		$todo  = array();
		foreach ( $rows as $row ) {
			if ( 'booked' !== $row->outcome || '' === (string) $row->appointment_id || $row->is_deleted ) {
				continue;
			}
			if ( in_array( $row->status, self::TERMINAL, true ) && $row->synced_at && ! $force ) {
				continue;
			}
			if ( $row->synced_at && $row->synced_at > $stale && ! $force ) {
				continue;
			}
			$todo[ (string) $row->appointment_id ] = $row;
			if ( count( $todo ) >= self::SYNC_BATCH ) {
				break;
			}
		}
		if ( ! $todo ) {
			return 0;
		}
		$states = $api->get_appointment_states( array_keys( $todo ) );
		if ( is_wp_error( $states ) ) {
			// Outage or auth problem: leave every row untouched rather than guess.
			error_log( '[vetspire-scheduler] status sync skipped: ' . $states->get_error_message() );
			return 0;
		}
		$now = current_time( 'mysql', true );
		$n   = 0;
		foreach ( $todo as $id => $row ) {
			$state  = isset( $states[ $id ] ) ? $states[ $id ] : null;
			$update = array( 'synced_at' => $now );
			if ( null === $state ) {
				// Vetspire no longer returns it at all → treat as deleted.
				$update['is_deleted'] = 1;
			} else {
				$update['status']       = substr( (string) $state['status'], 0, 20 );
				$update['is_confirmed'] = ! empty( $state['isConfirmed'] ) ? 1 : 0;
				$update['is_deleted']   = ! empty( $state['deleted'] ) ? 1 : 0;
				if ( ! empty( $state['provider']['name'] ) ) {
					$update['provider_name'] = substr( $state['provider']['name'], 0, 120 );
				}
				// Returning clients only gave the widget their email: take the name from Vetspire.
				if ( '' === (string) $row->client_name && ! empty( $state['patient']['client'] ) ) {
					$c    = $state['patient']['client'];
					$name = trim( ( isset( $c['givenName'] ) ? $c['givenName'] : '' ) . ' ' . ( isset( $c['familyName'] ) ? $c['familyName'] : '' ) );
					if ( '' !== $name ) {
						$update['client_name'] = substr( $name, 0, 160 );
					}
				}
				$new_start = null;
				if ( ! empty( $state['start'] ) ) {
					try {
						$new_start = ( new DateTimeImmutable( $state['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
					} catch ( Exception $e ) { /* keep stored start */ }
				}
				// A content change made directly in Vetspire (type, pet, or time moved after we
				// created it) must be reflected here — the exported/displayed data always has to
				// match Vetspire, and staff need to know it no longer matches what the visitor booked.
				$edited = false;
				if ( ! empty( $state['type']['id'] ) && (string) $state['type']['id'] !== (string) $row->appointment_type_id ) {
					$update['appointment_type_id'] = (int) $state['type']['id'];
					$update['type_name']           = isset( $state['type']['name'] ) ? substr( (string) $state['type']['name'], 0, 120 ) : $row->type_name;
					$edited                         = true;
				}
				if ( ! empty( $state['patient']['name'] ) && (string) $state['patient']['name'] !== (string) $row->patient_name ) {
					$update['patient_name'] = substr( (string) $state['patient']['name'], 0, 120 );
					$edited                  = true;
				}
				if ( null !== $new_start ) {
					if ( ! empty( $row->start_utc ) && $new_start !== $row->start_utc ) {
						$edited = true;
					}
					$update['start_utc'] = $new_start;
				}
				if ( $edited ) {
					$update['edited_at'] = $now;
				}
			}
			$wpdb->update( self::table(), $update, array( 'id' => (int) $row->id ) );
			$n++;
		}
		return $n;
	}

	/* ---------- backfill (once): online bookings that pre-date the log ---------- */

	public static function maybe_backfill( VSPS_Api $api, $location_id, DateTimeZone $tz ) {
		if ( get_option( 'vsps_log_backfill_done' ) ) {
			return 0;
		}
		// The widget stamps every appointment reason with "<source label> booking";
		// Vetspire's own online booking also sets bookedOnline, so the reason is
		// what tells the two apart.
		$label  = vsps_get_settings()['source_label'];
		$prefix = strtolower( trim( '' !== trim( (string) $label ) ? $label : 'Online' ) ) . ' booking';
		update_option( 'vsps_log_backfill_done', 1, false ); // never retried on failure: a partial backfill beats a loop of API calls
		// The appointments query returns at most 200 rows per call and a busy
		// clinic has more than that in 90 days, so walk the range one week at a time.
		$cursor = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-30 days' );
		$end    = ( new DateTimeImmutable( 'today', $tz ) )->modify( '+60 days' );
		$appts  = array();
		while ( $cursor < $end ) {
			$next = $cursor->modify( '+7 days' );
			$page = $api->get_appointments( $location_id, $cursor->format( 'c' ), min( $next, $end )->format( 'c' ) );
			if ( is_wp_error( $page ) ) {
				return 0;
			}
			$appts  = array_merge( $appts, $page );
			$cursor = $next;
		}
		global $wpdb;
		$n = 0;
		foreach ( $appts as $appt ) {
			if ( empty( $appt['bookedOnline'] ) || empty( $appt['id'] ) ) {
				continue;
			}
			if ( 0 !== strpos( strtolower( (string) $appt['reason'] ), $prefix ) ) {
				continue;
			}
			if ( self::find_by_appointment( $appt['id'] ) ) {
				continue;
			}
			$client = isset( $appt['patient']['client'] ) ? $appt['patient']['client'] : array();
			try {
				$start_dt  = new DateTimeImmutable( $appt['start'] );
				$start_utc = $start_dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$local     = $start_dt->setTimezone( $tz );
				$created   = ! empty( $appt['insertedAt'] )
					? ( new DateTimeImmutable( $appt['insertedAt'], new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' )
					: current_time( 'mysql', true );
			} catch ( Exception $e ) {
				continue;
			}
			$wpdb->insert( self::table(), array(
				'created_at'          => $created,
				'outcome'             => 'booked',
				'appointment_id'      => (string) $appt['id'],
				'location_id'         => (int) $location_id,
				'appointment_type_id' => isset( $appt['type']['id'] ) ? (int) $appt['type']['id'] : 0,
				'type_name'           => isset( $appt['type']['name'] ) ? substr( $appt['type']['name'], 0, 120 ) : '',
				'client_id'           => isset( $client['id'] ) ? (string) $client['id'] : '',
				'client_name'         => substr( trim( ( isset( $client['givenName'] ) ? $client['givenName'] : '' ) . ' ' . ( isset( $client['familyName'] ) ? $client['familyName'] : '' ) ), 0, 160 ),
				'client_email'        => isset( $client['email'] ) ? substr( (string) $client['email'], 0, 190 ) : '',
				'client_type'         => 'new',
				'patient_id'          => isset( $appt['patient']['id'] ) ? (string) $appt['patient']['id'] : '',
				'patient_name'        => isset( $appt['patient']['name'] ) ? substr( $appt['patient']['name'], 0, 120 ) : '',
				'slot_date'           => $local->format( 'Y-m-d' ),
				'slot_time'           => $local->format( 'H:i' ),
				'start_utc'           => $start_utc,
				'provider_name'       => isset( $appt['provider']['name'] ) ? substr( $appt['provider']['name'], 0, 120 ) : '',
				'status'              => substr( (string) $appt['status'], 0, 20 ),
				'is_confirmed'        => ! empty( $appt['isConfirmed'] ) ? 1 : 0,
				'layout'              => 'backfill',
				'synced_at'           => current_time( 'mysql', true ),
			) );
			$n++;
		}
		return $n;
	}

	/* ---------- queries ---------- */

	public static function find_by_appointment( $appointment_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE appointment_id = %s LIMIT 1', (string) $appointment_id ) );
	}

	/**
	 * Filtered, paginated list. $f keys: status (all|pending|confirmed|cancelled|
	 * deleted|completed|no_show|failed), from, to (Y-m-d, matched against created_at in
	 * the WordPress site's own timezone — display uses the clinic's timezone
	 * instead, see utc_to_local(), so the two can disagree by a few hours near
	 * midnight if the two timezones differ), failed (1 = include failed attempts).
	 */
	public static function query( array $f, $page = 1, $per_page = 25 ) {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();
		$status = isset( $f['status'] ) ? $f['status'] : 'all';
		if ( 'failed' === $status ) {
			$where[] = "outcome = 'failed'";
		} else {
			if ( empty( $f['failed'] ) ) {
				$where[] = "outcome = 'booked'";
			}
			if ( 'pending' === $status ) {
				$where[] = "outcome = 'booked' AND is_deleted = 0 AND is_confirmed = 0 AND status NOT IN ('CANCELLED','COMPLETED','NOSHOW','CHECKED_OUT')";
			} elseif ( 'confirmed' === $status ) {
				$where[] = "outcome = 'booked' AND is_deleted = 0 AND is_confirmed = 1 AND status <> 'CANCELLED'";
			} elseif ( 'cancelled' === $status ) {
				$where[] = "outcome = 'booked' AND is_deleted = 0 AND status = 'CANCELLED'";
			} elseif ( 'deleted' === $status ) {
				$where[] = "outcome = 'booked' AND is_deleted = 1";
			} elseif ( 'completed' === $status ) {
				$where[] = "outcome = 'booked' AND is_deleted = 0 AND status IN ('COMPLETED','CHECKED_OUT')";
			} elseif ( 'no_show' === $status ) {
				$where[] = "outcome = 'booked' AND is_deleted = 0 AND status = 'NOSHOW'";
			}
		}
		if ( 'yes' === ( isset( $f['after_hours'] ) ? $f['after_hours'] : '' ) ) {
			$where[] = 'after_hours = 1';
		} elseif ( 'no' === ( isset( $f['after_hours'] ) ? $f['after_hours'] : '' ) ) {
			$where[] = 'after_hours = 0';
		}
		if ( ! empty( $f['appointment_type_id'] ) ) {
			$where[] = 'appointment_type_id = %d';
			$vals[]  = (int) $f['appointment_type_id'];
		}
		if ( ! empty( $f['provider'] ) ) {
			$where[] = 'provider_name = %s';
			$vals[]  = (string) $f['provider'];
		}
		$tz = wp_timezone();
		if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = ( new DateTimeImmutable( $f['from'] . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = ( new DateTimeImmutable( $f['to'] . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $sql_where;
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$offset    = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$list_sql  = 'SELECT * FROM ' . self::table() . ' WHERE ' . $sql_where . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $vals, array( (int) $per_page, $offset ) ) ) );
		return array( 'rows' => $rows ? $rows : array(), 'total' => $total );
	}

	/** Appointment types seen in the log, for the Bookings filter dropdown. */
	public static function distinct_types() {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT DISTINCT appointment_type_id, type_name FROM ' . self::table()
			. " WHERE appointment_type_id > 0 AND type_name != '' ORDER BY type_name ASC"
		);
	}

	/** Providers seen in the log, for the Bookings filter dropdown. */
	public static function distinct_providers() {
		global $wpdb;
		return $wpdb->get_col(
			'SELECT DISTINCT provider_name FROM ' . self::table()
			. " WHERE provider_name != '' ORDER BY provider_name ASC"
		);
	}

	/** Unconfirmed, still-upcoming widget bookings (the menu bubble). */
	public static function pending_count() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE outcome = 'booked' AND is_deleted = 0 AND is_confirmed = 0"
			. " AND status NOT IN ('CANCELLED','COMPLETED','NOSHOW','CHECKED_OUT') AND (slot_date IS NULL OR slot_date >= %s)",
			$today
		) );
	}

	/** Bookings created today (WP timezone), newest first — dashboard widget. */
	public static function today( $limit = 8 ) {
		global $wpdb;
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'today', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE outcome = 'booked' AND created_at >= %s ORDER BY created_at DESC LIMIT %d",
			$start,
			(int) $limit
		) );
		return $rows ? $rows : array();
	}

	public static function count_today() {
		global $wpdb;
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'today', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE outcome = 'booked' AND created_at >= %s", $start ) );
	}

	/* ---------- presentation helpers ---------- */

	/** Human status: Pending / Confirmed / Cancelled / Completed / Deleted in Vetspire / Failed. */
	public static function status_label( $row ) {
		if ( 'failed' === $row->outcome ) {
			return array( 'Failed', '#b3261e', '#fdecec' );
		}
		if ( $row->is_deleted ) {
			return array( 'Deleted in Vetspire', '#555', '#eee' );
		}
		if ( 'CANCELLED' === $row->status ) {
			return array( 'Cancelled', '#8a4b00', '#fff1dc' );
		}
		if ( in_array( $row->status, array( 'COMPLETED', 'CHECKED_OUT' ), true ) ) {
			return array( 'Completed', '#1d4ed8', '#e5edff' );
		}
		if ( 'NOSHOW' === $row->status ) {
			// Deliberately not a red like "Failed" (#b3261e/#fdecec) -- a no-show
			// is a real appointment that happened, not a booking that never
			// went through, and the two shouldn't read as the same color.
			return array( 'No Show', '#6b21a8', '#f3e8ff' );
		}
		if ( $row->is_confirmed ) {
			return array( 'Confirmed', '#1a6b3a', '#e3f4ea' );
		}
		return array( 'Pending', '#7a5a00', '#fff7d6' );
	}

	public static function csv( array $rows, $show_client, DateTimeZone $clinic_tz ) {
		$out = fopen( 'php://temp', 'w+' );
		$head = array( 'Created (clinic time)', 'Outcome', 'Status', 'Confirmed', 'Deleted in Vetspire', 'Edited in Vetspire', 'Edited at (clinic time)', 'Appointment ID', 'Appointment (clinic time)', 'Provider', 'Type', 'Pet', 'Client type', 'New pet' );
		if ( $show_client ) {
			array_push( $head, 'Client', 'Email' );
		}
		array_push( $head, 'Layout', 'Variant', 'Page', 'After hours', 'Error code', 'Error message' );
		fputcsv( $out, $head );
		foreach ( $rows as $r ) {
			$line = array(
				self::utc_to_local( $r->created_at, $clinic_tz, 'Y-m-d H:i' ),
				$r->outcome,
				$r->status,
				$r->is_confirmed ? 'yes' : 'no',
				$r->is_deleted ? 'yes' : 'no',
				$r->edited_at ? 'yes' : 'no',
				$r->edited_at ? self::utc_to_local( $r->edited_at, $clinic_tz, 'Y-m-d H:i' ) : '',
				$r->appointment_id,
				self::appt_local( $r, $clinic_tz, 'Y-m-d H:i' ),
				$r->provider_name,
				$r->type_name,
				$r->patient_name,
				$r->client_type,
				$r->pet_is_new ? 'yes' : 'no',
			);
			if ( $show_client ) {
				array_push( $line, $r->client_name, $r->client_email );
			}
			array_push( $line, $r->layout, $r->variant, $r->page_url, null === $r->after_hours ? '' : ( $r->after_hours ? 'yes' : 'no' ), $r->error_code, $r->error_message );
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $line ) );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	/** Visitor-typed text must never become a spreadsheet formula (=, +, -, @, tab, CR). */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/** Appointment start in the clinic's timezone (falls back to the requested slot). */
	public static function appt_local( $row, DateTimeZone $tz, $format = 'D, M j · g:i A' ) {
		if ( ! empty( $row->start_utc ) ) {
			try {
				return ( new DateTimeImmutable( $row->start_utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( $format );
			} catch ( Exception $e ) { /* fall through */ }
		}
		if ( ! empty( $row->slot_date ) ) {
			try {
				return ( new DateTimeImmutable( $row->slot_date . ' ' . ( $row->slot_time ?: '00:00' ), $tz ) )->format( $format );
			} catch ( Exception $e ) { /* fall through */ }
		}
		return '—';
	}

	/**
	 * A stored UTC datetime (created_at, edited_at, ...) in the clinic's own
	 * timezone. Deliberately NOT get_date_from_gmt(), which converts to the
	 * WordPress site's General Settings timezone instead — a separate,
	 * easy-to-leave-misconfigured (often still the WP default of UTC) setting
	 * that has nothing to do with which clinic this install serves.
	 */
	public static function utc_to_local( $mysql_utc, DateTimeZone $tz, $format ) {
		if ( empty( $mysql_utc ) ) {
			return '';
		}
		try {
			return ( new DateTimeImmutable( $mysql_utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( $format );
		} catch ( Exception $e ) {
			return $mysql_utc;
		}
	}
}
