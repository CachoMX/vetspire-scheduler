<?php
/**
 * Delivery of the bookings log to the Vetcelerator hub.
 *
 * The local table (VSPS_Log) stays the safety net; every row is ALSO pushed
 * to POST {VSPS_HUB_URL}/api/wp/v1/scheduler/bookings and stamped with
 * hub_synced_at once the hub has answered for it (accepted OR rejected).
 *
 * Triggers (belt and braces — WP-Cron alone may never run on a multisite
 * subsite whose server runner uses DISABLE_WP_CRON):
 *  - right after a row is written (deferred to `shutdown`, after the visitor's
 *    response has been flushed, so a booking never waits on the hub);
 *  - on the widget's own REST calls, but only while the cheap "pending" flag
 *    (an autoloaded option) says rows may be waiting;
 *  - inline on the plugin's own admin screens (result shown as a notice);
 *  - an hourly WP-Cron event as the last fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Hub {

	const ENDPOINT_PATH  = '/api/wp/v1/scheduler/bookings';
	const BATCH_SIZE     = 200;
	const MAX_BATCHES    = 3;
	const TIMEOUT        = 8;
	const BACKOFF_SECS   = 300;
	const LOCK_TTL       = 60;
	const STATUS_OPTION  = 'vsps_hub_status';
	const PENDING_OPTION = 'vsps_hub_pending';
	const LOCK_OPTION    = 'vsps_hub_lock';
	const TZ_OPTION      = 'vsps_clinic_tz';
	const CRON_HOOK      = 'vsps_hub_push';
	const KEY_PATTERN    = '/^vss_[0-9a-f]{48}$/';
	// The hub's own JSON error codes for a bad key. Any other 401/403 (WAF, bot
	// challenge, proxy page, misdeploy) is treated as a transient failure.
	const KEY_ERRORS     = array( 'missing_site_key', 'invalid_site_key' );
	// HTTP requests one push may make while splitting refused (400/413) batches.
	const MAX_REQUESTS   = 16;

	/** Set once per request when a deferred push has been registered. */
	private static $scheduled = false;

	/** Result of the inline push done on an admin screen load (for the notice). */
	private static $admin_result = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_push' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_rest_dispatch' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_push' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* ---------- configuration ---------- */

	public static function hub_url() {
		return untrailingslashit( (string) VSPS_HUB_URL ) . self::ENDPOINT_PATH;
	}

	public static function key() {
		$settings = vsps_get_settings();
		$key      = isset( $settings['hub_key'] ) ? (string) $settings['hub_key'] : '';
		return preg_match( self::KEY_PATTERN, $key ) ? $key : '';
	}

	public static function status() {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	private static function save_status( array $changes ) {
		update_option( self::STATUS_OPTION, array_merge( self::status(), $changes ), true );
	}

	/** Called when a hub key is (re)entered or removed: forget the stop flag, backoff and old error. */
	public static function reset_after_key_change() {
		self::save_status( array(
			'stopped'       => 0,
			'backoff_until' => 0,
			'last_error'    => '',
			'last_code'     => 0,
			// A new key is a new connection: earlier success says nothing about it.
			'last_verified' => 0,
			'last_success'  => 0,
		) );
	}

	/** True when a push could do anything right now (key set, not stopped, not backing off). */
	public static function can_attempt() {
		if ( '' === self::key() ) {
			return false;
		}
		$status = self::status();
		if ( ! empty( $status['stopped'] ) ) {
			return false;
		}
		return empty( $status['backoff_until'] ) || (int) $status['backoff_until'] <= time();
	}

	/** Cheap flag (autoloaded option) meaning "some rows may still be waiting". */
	public static function mark_pending() {
		if ( '1' !== (string) get_option( self::PENDING_OPTION, '' ) ) {
			update_option( self::PENDING_OPTION, '1', true );
		}
	}

	/** Creates the flag as an autoloaded '0' when missing (never overwrites a '1'). */
	public static function ensure_pending_option() {
		if ( false === get_option( self::PENDING_OPTION, false ) ) {
			add_option( self::PENDING_OPTION, '0', '', true );
		}
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VSPS_Log::table() . ' WHERE hub_synced_at IS NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------- triggers ---------- */

	/** A row was just written: flag it and push after the response is sent. */
	public static function row_written() {
		try {
			self::mark_pending();
			self::schedule_push();
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] hub trigger failed: ' . $e->getMessage() );
		}
	}

	/** Registers (once per request) a shutdown push that runs after the visitor has their response. */
	public static function schedule_push() {
		if ( self::$scheduled || ! self::can_attempt() ) {
			return;
		}
		self::$scheduled = true;
		// After WP's own shutdown flush (wp_ob_end_flush_all runs at priority 1).
		add_action( 'shutdown', array( __CLASS__, 'run_deferred' ), 1000 );
	}

	/**
	 * Shutdown callback. Ends the visitor's response first when the SAPI allows
	 * it (PHP-FPM / LiteSpeed), then pushes in this process. Where it can't be
	 * ended early (e.g. plain CGI) pushing here would make the visitor wait for
	 * the hub, so a non-blocking signed loopback request does the push in its
	 * own process instead. CLI / WP-Cron have no visitor: push inline.
	 */
	public static function run_deferred() {
		try {
			$finished = ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron();
			if ( ! $finished && function_exists( 'fastcgi_finish_request' ) ) {
				$finished = false !== fastcgi_finish_request();
			} elseif ( ! $finished && function_exists( 'litespeed_finish_request' ) ) {
				$finished = false !== litespeed_finish_request();
			}
			if ( ! $finished ) {
				self::spawn_loopback();
				return;
			}
			ignore_user_abort( true );
			self::push_pending();
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] hub push failed: ' . $e->getMessage() );
		}
	}

	/* ---------- loopback (for servers that can't end a response early) ---------- */

	const LOOPBACK_ROUTE = '/hub-push';
	const LOOPBACK_TTL   = 120;

	private static function loopback_sig( $ts ) {
		return hash_hmac( 'sha256', 'vsps_hub_push|' . (int) $ts, wp_salt( 'auth' ) );
	}

	/** Fire-and-forget POST to our own REST route (same pattern as WP's spawn_cron()). */
	private static function spawn_loopback() {
		$ts = time();
		wp_remote_post( rest_url( VSPS_Rest::NS . self::LOOPBACK_ROUTE ), array(
			'timeout'   => 0.01,
			'blocking'  => false,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => array(
				'ts'  => $ts,
				'sig' => self::loopback_sig( $ts ),
			),
		) );
	}

	public static function register_routes() {
		register_rest_route( VSPS_Rest::NS, self::LOOPBACK_ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'loopback_push' ),
			'permission_callback' => '__return_true', // authenticated by the HMAC below
		) );
	}

	public static function loopback_push( WP_REST_Request $request ) {
		$ts  = (int) $request->get_param( 'ts' );
		$sig = (string) $request->get_param( 'sig' );
		if ( abs( time() - $ts ) > self::LOOPBACK_TTL || ! hash_equals( self::loopback_sig( $ts ), $sig ) ) {
			return new WP_Error( 'vsps_forbidden', 'Forbidden.', array( 'status' => 403 ) );
		}
		ignore_user_abort( true );
		try {
			$r = self::push_pending();
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] hub loopback push failed: ' . $e->getMessage() );
			$r = array();
		}
		return rest_ensure_response( array( 'ok' => true, 'delivered' => isset( $r['delivered'] ) ? (int) $r['delivered'] : 0 ) );
	}

	/** Widget REST calls (every page view): at most one option read unless rows are waiting. */
	public static function on_rest_dispatch( $result, $server, $request ) {
		try {
			if ( '1' === (string) get_option( self::PENDING_OPTION, '' )
				&& $request instanceof WP_REST_Request
				&& 0 === strpos( (string) $request->get_route(), '/' . VSPS_Rest::NS . '/' )
				&& '/' . VSPS_Rest::NS . self::LOOPBACK_ROUTE !== $request->get_route() ) {
				self::schedule_push();
			}
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] hub trigger failed: ' . $e->getMessage() );
		}
		return $result;
	}

	/** The plugin's own admin screens push inline (an admin can wait a few seconds). */
	public static function admin_push() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( 'vsps-appointments', 'vsps-settings' ), true ) ) {
			return;
		}
		try {
			// On Settings, also confirm the key when there's nothing to send, so a
			// wrong key shows up immediately instead of at the next booking.
			self::$admin_result = self::push_pending( self::BATCH_SIZE, true, 'vsps-settings' === $page );
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] hub push failed: ' . $e->getMessage() );
		}
	}

	public static function admin_notice() {
		$r = self::$admin_result;
		if ( ! is_array( $r ) || empty( $r['attempted'] ) ) {
			return;
		}
		if ( $r['delivered'] > 0 ) {
			$msg = sprintf(
				'Vetcelerator hub: sent %d booking %s.',
				(int) $r['delivered'],
				1 === (int) $r['delivered'] ? 'record' : 'records'
			);
			if ( $r['pending'] > 0 ) {
				$msg .= ' ' . (int) $r['pending'] . ' still waiting.';
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( '' !== $r['error'] ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( 'Vetcelerator hub: ' . $r['error'] ) . '</p></div>';
		}
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function cron_push() {
		try {
			self::push_pending( self::BATCH_SIZE, true );
		} catch ( Throwable $e ) {
			error_log( '[vetspire-scheduler] hub cron push failed: ' . $e->getMessage() );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------- the push ---------- */

	/**
	 * Sends waiting rows, up to MAX_BATCHES batches of $limit. Never throws for
	 * HTTP problems; returns a summary:
	 * attempted (bool), delivered (int), pending (int), error (string), skipped (string).
	 * $allow_api: the clinic timezone may be looked up via the (hourly-cached)
	 * Vetspire location call — only from admin/cron, never from a visitor request.
	 */
	public static function push_pending( $limit = self::BATCH_SIZE, $allow_api = false, $verify = false ) {
		$summary = array(
			'attempted' => false,
			'delivered' => 0,
			'pending'   => 0,
			'error'     => '',
			'skipped'   => '',
		);
		$key = self::key();
		if ( '' === $key ) {
			$summary['skipped'] = 'no_key';
			return $summary;
		}
		if ( ! self::can_attempt() ) {
			$summary['skipped'] = empty( self::status()['stopped'] ) ? 'backoff' : 'stopped';
			return $summary;
		}
		if ( ! self::acquire_lock() ) {
			$summary['skipped'] = 'locked';
			return $summary;
		}

		global $wpdb;
		$table = VSPS_Log::table();
		$limit = max( 1, min( self::BATCH_SIZE, (int) $limit ) );
		try {
			// Rows written by older code paths (or the v4 upgrade) get their
			// deterministic id before they can be sent.
			$wpdb->query( "UPDATE {$table} SET event_id = CONCAT('wp-', id) WHERE hub_synced_at IS NULL AND (event_id IS NULL OR event_id = '')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$site = array(
				'plugin_version' => VSPS_VERSION,
				'site_url'       => home_url(),
			);
			// Only a timezone from a real source; otherwise omit it (the hub keeps what it has).
			$tz = self::clinic_timezone( $allow_api );
			if ( '' !== $tz ) {
				$site['clinic_timezone'] = $tz;
			}
			$ctx = array(
				'requests' => 0,
				'ok'       => false,
				'suspects' => array(),
			);

			for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE hub_synced_at IS NULL ORDER BY id ASC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( empty( $rows ) ) {
					break;
				}
				$summary['attempted'] = true;
				$ctx['suspects']      = array();
				$result               = self::send_chunked( $key, $site, $rows, $ctx );
				if ( '' === $result['error'] && $ctx['suspects'] ) {
					$result = self::settle_suspects( $result, $ctx );
				}
				$summary['delivered'] += $result['delivered'];
				if ( '' !== $result['error'] ) {
					$summary['error'] = $result['error'];
					break;
				}
				// A short batch means the queue is drained; a batch the hub did not
				// fully answer for stops here instead of re-sending in a tight loop.
				if ( count( $rows ) < $limit || $result['delivered'] < count( $rows ) ) {
					break;
				}
			}
			if ( $verify && ! $summary['attempted'] ) {
				$summary['attempted'] = true;
				$summary['error']     = self::heartbeat( $key, $site );
			}
		} finally {
			$summary['pending'] = self::pending_count();
			if ( 0 === $summary['pending'] ) {
				// Kept as an autoloaded '0' (not deleted) so the per-page-view check
				// never costs a DB query for a missing option.
				update_option( self::PENDING_OPTION, '0', true );
				// A row inserted between the count and that write must not be
				// stranded behind a '0' flag: look again.
				$summary['pending'] = self::pending_count();
				if ( $summary['pending'] > 0 ) {
					self::mark_pending();
				}
			} else {
				self::mark_pending();
			}
			self::save_status( array( 'pending' => $summary['pending'] ) );
			self::release_lock();
		}
		return $summary;
	}

	/**
	 * Sends $rows; when the hub refuses the whole request (400/413) splits it in
	 * halves down to single rows so one unsendable row can't hold back the rest.
	 * A single row refused on its own becomes a "suspect", settled after the
	 * batch (see settle_suspects()). $ctx is shared across one push.
	 */
	private static function send_chunked( $key, array $site, array $rows, array &$ctx ) {
		if ( $ctx['requests'] >= self::MAX_REQUESTS ) {
			return self::fail( 0, 'The hub refused some records; retrying in 5 minutes.', true );
		}
		$ctx['requests']++;
		$r = self::send_batch( $key, $site, $rows );
		if ( empty( $r['retry_smaller'] ) ) {
			if ( '' === $r['error'] ) {
				$ctx['ok'] = true;
			}
			return $r;
		}
		if ( count( $rows ) > 1 ) {
			$half  = (int) ceil( count( $rows ) / 2 );
			$first = self::send_chunked( $key, $site, array_slice( $rows, 0, $half ), $ctx );
			if ( '' !== $first['error'] ) {
				return $first;
			}
			$second = self::send_chunked( $key, $site, array_slice( $rows, $half ), $ctx );
			return array(
				'delivered' => $first['delivered'] + $second['delivered'],
				'error'     => $second['error'],
			);
		}
		$ctx['suspects'][] = array( 'row' => $rows[0], 'code' => $r['code'], 'message' => $r['error'] );
		return array( 'delivered' => 0, 'error' => '' );
	}

	/**
	 * Single rows the hub refused on their own are dropped (marked as sent,
	 * event_id logged, no PII) only when another request in the same push
	 * succeeded: proof the request itself is fine and the row is the problem.
	 * Otherwise the refusal is treated as a hub-side problem: back off.
	 */
	private static function settle_suspects( array $result, array $ctx ) {
		$first = $ctx['suspects'][0];
		if ( ! $ctx['ok'] ) {
			$fail = self::fail( $first['code'], $first['message'], true );
			return array( 'delivered' => $result['delivered'], 'error' => $fail['error'] );
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $ctx['suspects'] as $suspect ) {
			$event_id = (string) $suspect['row']->event_id;
			$wpdb->update( VSPS_Log::table(), array( 'hub_synced_at' => $now ), array( 'id' => (int) $suspect['row']->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			error_log( '[vetspire-scheduler] hub refused record ' . self::clean_text( $event_id ) . ' on its own (HTTP ' . (int) $suspect['code'] . '); marked as sent so it no longer blocks the queue' );
			$result['delivered']++;
		}
		return $result;
	}

	/** True only for the hub's own "bad key" JSON answer. */
	private static function is_key_rejection( $code, $json ) {
		return ( 401 === $code || 403 === $code )
			&& is_array( $json )
			&& isset( $json['error'] )
			&& is_string( $json['error'] )
			&& in_array( $json['error'], self::KEY_ERRORS, true );
	}

	private static function stop_for_key( $code ) {
		$msg = 'The hub rejected this connection key (HTTP ' . $code . '). Sending is paused until a new key is saved.';
		self::save_status( array(
			'stopped'    => 1,
			'last_code'  => $code,
			'last_error' => $msg,
		) );
		error_log( '[vetspire-scheduler] hub rejected the connection key (HTTP ' . $code . '); paused until the key changes' );
		return $msg;
	}

	private static function blocked_message( $code ) {
		return 'The hub request was blocked (HTTP ' . $code . ', not the hub\'s own key check; possibly a firewall or proxy). Retrying in 5 minutes.';
	}

	/**
	 * One HTTP round-trip. Returns array( delivered => int, error => string ),
	 * plus retry_smaller/code when the hub refused the request as a whole (400/413).
	 */
	private static function send_batch( $key, array $site, array $rows ) {
		$records = array();
		$ids     = array();
		foreach ( $rows as $row ) {
			$records[] = self::record( $row );
			$ids[]     = (string) $row->event_id;
		}
		$body = wp_json_encode( array( 'site' => $site, 'records' => $records ), JSON_INVALID_UTF8_SUBSTITUTE );
		$now  = time();
		self::save_status( array( 'last_attempt' => $now ) );
		if ( false === $body ) {
			return self::fail( 0, 'Could not encode the booking records.', true );
		}

		$response = wp_remote_post( self::hub_url(), array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return self::fail( 0, 'Could not reach the hub (' . $response->get_error_message() . '). Retrying in 5 minutes.', true );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( self::is_key_rejection( $code, $json ) ) {
			return array( 'delivered' => 0, 'error' => self::stop_for_key( $code ) );
		}
		if ( 401 === $code || 403 === $code ) {
			return self::fail( $code, self::blocked_message( $code ), true );
		}
		if ( 400 === $code || 413 === $code ) {
			$detail = is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) ? ': ' . self::clean_text( $json['error'] ) : '';
			error_log( '[vetspire-scheduler] hub refused a batch of ' . count( $rows ) . ' (HTTP ' . $code . $detail . ')' );
			return array(
				'delivered'     => 0,
				'error'         => 'The hub refused the request (HTTP ' . $code . $detail . '). Retrying in 5 minutes.',
				'retry_smaller' => true,
				'code'          => $code,
			);
		}
		if ( 200 !== $code || ! is_array( $json ) ) {
			return self::fail( $code, 'The hub is unavailable (HTTP ' . $code . '). Retrying in 5 minutes.', true );
		}

		$done = array();
		foreach ( isset( $json['accepted'] ) && is_array( $json['accepted'] ) ? $json['accepted'] : array() as $event_id ) {
			if ( is_string( $event_id ) ) {
				$done[] = $event_id;
			}
		}
		foreach ( isset( $json['rejected'] ) && is_array( $json['rejected'] ) ? $json['rejected'] : array() as $rej ) {
			$event_id = is_array( $rej ) && isset( $rej['event_id'] ) && is_string( $rej['event_id'] ) ? $rej['event_id'] : null;
			$error    = is_array( $rej ) && isset( $rej['error'] ) && is_string( $rej['error'] ) ? self::clean_text( $rej['error'] ) : 'unknown';
			// event_id + the hub's reason only: no names/emails in the server log.
			error_log( '[vetspire-scheduler] hub rejected record ' . ( null === $event_id ? '(no id)' : self::clean_text( $event_id ) ) . ': ' . $error );
			if ( null !== $event_id ) {
				$done[] = $event_id;
			}
		}
		$done = array_values( array_unique( array_intersect( $done, $ids ) ) );

		$delivered = 0;
		if ( $done ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $done ), '%s' ) );
			$delivered    = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'UPDATE ' . VSPS_Log::table() . " SET hub_synced_at = %s WHERE event_id IN ({$placeholders})",
				array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $done )
			) );
		}
		if ( 0 === $delivered ) {
			// 200 but nothing we sent was acknowledged: treat as a hub fault, not a loop.
			return self::fail( $code, 'The hub did not acknowledge any record. Retrying in 5 minutes.', true );
		}
		self::save_status( array(
			'last_success' => $now,
			'last_sent'    => $delivered,
			'last_error'   => '',
			'last_code'    => $code,
		) );
		return array( 'delivered' => $delivered, 'error' => '' );
	}

	/**
	 * An empty send: the hub answers 200 for a valid key (and records the
	 * site's version/timezone) or 401/403 for a bad one. Returns '' or the error.
	 */
	private static function heartbeat( $key, array $site ) {
		$now = time();
		self::save_status( array( 'last_attempt' => $now ) );
		$response = wp_remote_post( self::hub_url(), array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( array( 'site' => $site, 'records' => array() ) ),
		) );
		if ( is_wp_error( $response ) ) {
			return self::fail( 0, 'Could not reach the hub (' . $response->get_error_message() . '). Retrying in 5 minutes.', true )['error'];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( self::is_key_rejection( $code, $json ) ) {
			return self::stop_for_key( $code );
		}
		if ( 401 === $code || 403 === $code ) {
			return self::fail( $code, self::blocked_message( $code ), true )['error'];
		}
		if ( 200 !== $code ) {
			return self::fail( $code, 'The hub is unavailable (HTTP ' . $code . '). Retrying in 5 minutes.', true )['error'];
		}
		self::save_status( array( 'last_verified' => $now, 'last_error' => '', 'last_code' => $code ) );
		return '';
	}

	private static function fail( $code, $message, $backoff ) {
		$changes = array(
			'last_code'  => (int) $code,
			'last_error' => $message,
		);
		if ( $backoff ) {
			$changes['backoff_until'] = time() + self::BACKOFF_SECS;
		}
		self::save_status( $changes );
		error_log( '[vetspire-scheduler] hub push failed: ' . $message );
		return array( 'delivered' => 0, 'error' => $message );
	}

	/** One local row → one contract record (client_id travels as vetspire_client_id). */
	private static function record( $row ) {
		$int_or_null = function ( $v ) {
			return null === $v ? null : (int) $v;
		};
		return array(
			'event_id'            => (string) $row->event_id,
			'created_at'          => $row->created_at,
			'outcome'             => (string) $row->outcome,
			'appointment_id'      => null === $row->appointment_id ? null : (string) $row->appointment_id,
			'location_id'         => (int) $row->location_id,
			'appointment_type_id' => (int) $row->appointment_type_id,
			'type_name'           => (string) $row->type_name,
			'vetspire_client_id'  => (string) $row->client_id,
			'client_name'         => (string) $row->client_name,
			'client_email'        => (string) $row->client_email,
			'client_type'         => (string) $row->client_type,
			'patient_id'          => (string) $row->patient_id,
			'patient_name'        => (string) $row->patient_name,
			'pet_is_new'          => (int) $row->pet_is_new,
			'slot_date'           => $row->slot_date,
			'slot_time'           => (string) $row->slot_time,
			'start_utc'           => $row->start_utc,
			'provider_name'       => (string) $row->provider_name,
			'status'              => (string) $row->status,
			'is_confirmed'        => (int) $row->is_confirmed,
			'is_deleted'          => (int) $row->is_deleted,
			'error_code'          => (string) $row->error_code,
			'error_message'       => (string) $row->error_message,
			'layout'              => (string) $row->layout,
			'variant'             => (string) $row->variant,
			'page_url'            => (string) $row->page_url,
			'after_hours'         => $int_or_null( $row->after_hours ),
			'synced_at'           => $row->synced_at,
			'edited_at'           => $row->edited_at,
		);
	}

	/** Strips tags/emails and caps length before a hub-supplied string is logged or shown. */
	private static function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/[^\s@]+@[^\s@]+/', '[email]', $text );
		return substr( $text, 0, 200 );
	}

	/* ---------- clinic timezone (no Vetspire call per push) ---------- */

	/**
	 * IANA timezone of the configured Vetspire location. Order: the value
	 * remembered in an option → the location data the plugin already caches
	 * (admin screen / booking path) → (admin/cron only) the hourly-cached
	 * location lookup. Returns '' when none of those is available: the WordPress
	 * site timezone is NOT a substitute (often left at UTC), so the caller omits
	 * the field and the hub keeps what it has.
	 */
	public static function clinic_timezone( $allow_api = false ) {
		$location_id = absint( vsps_get_settings()['default_location'] );
		if ( ! $location_id ) {
			return '';
		}
		$saved = get_option( self::TZ_OPTION, array() );
		if ( is_array( $saved ) && isset( $saved['loc'], $saved['tz'] ) && (int) $saved['loc'] === $location_id && self::valid_tz( $saved['tz'] ) ) {
			return $saved['tz'];
		}
		$tz = '';
		foreach ( array( array( 'admin-loc', $location_id ), array( 'locinfo', $location_id ) ) as $parts ) {
			$cached = get_transient( VSPS_Cache::PREFIX . md5( wp_json_encode( $parts ) ) );
			if ( is_array( $cached ) && ! empty( $cached['timezone'] ) ) {
				$tz = (string) $cached['timezone'];
				break;
			}
		}
		if ( '' === $tz && $allow_api ) {
			$api = vsps_api();
			if ( null !== $api ) {
				// Same cache key as the Bookings screen's own location lookup.
				$location = VSPS_Cache::remember( array( 'admin-loc', $location_id ), function () use ( $api, $location_id ) {
					return $api->get_location( $location_id );
				}, 3600 );
				if ( is_array( $location ) && ! empty( $location['timezone'] ) ) {
					$tz = (string) $location['timezone'];
				}
			}
		}
		if ( '' !== $tz && self::valid_tz( $tz ) ) {
			update_option( self::TZ_OPTION, array( 'loc' => $location_id, 'tz' => $tz ), false );
			return $tz;
		}
		return '';
	}

	private static function valid_tz( $tz ) {
		return is_string( $tz ) && in_array( $tz, timezone_identifiers_list(), true );
	}

	/* ---------- lock (atomic INSERT IGNORE; add_option() upserts so it can't be used) ---------- */

	private static function acquire_lock() {
		global $wpdb;
		$now   = time();
		$until = (string) ( $now + self::LOCK_TTL );
		$got   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $until ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === (int) $got ) {
			return true;
		}
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null === $held ) {
			return false; // released between the two queries; the next trigger will take it
		}
		if ( (int) $held > $now ) {
			return false;
		}
		// Stale lock (a push died mid-way): take it over only if nobody else did first.
		$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $until, self::LOCK_OPTION, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $took;
	}

	private static function release_lock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/* ---------- Settings status line ---------- */

	/** array( state => none|ok|error, text => string ) for the Settings screen. */
	public static function status_line() {
		if ( '' === self::key() ) {
			return array( 'state' => 'none', 'text' => 'Not connected (no key)' );
		}
		$s       = self::status();
		$pending = self::pending_count();
		$ago     = function ( $ts ) {
			return $ts ? human_time_diff( (int) $ts, time() ) . ' ago' : 'never';
		};
		if ( ! empty( $s['stopped'] ) || ! empty( $s['last_error'] ) ) {
			return array(
				'state' => 'error',
				'text'  => 'Error: ' . ( ! empty( $s['last_error'] ) ? $s['last_error'] : 'sending is paused.' )
					. ' (last attempt ' . $ago( isset( $s['last_attempt'] ) ? $s['last_attempt'] : 0 ) . ') · ' . $pending . ' waiting',
			);
		}
		if ( ! empty( $s['last_success'] ) ) {
			$sent = 'last sent ' . $ago( $s['last_success'] );
		} elseif ( ! empty( $s['last_verified'] ) ) {
			$sent = 'key verified ' . $ago( $s['last_verified'] ) . ', nothing sent yet';
		} else {
			$sent = 'not verified yet';
		}
		return array( 'state' => 'ok', 'text' => 'Connected — ' . $sent . ' · ' . $pending . ' waiting' );
	}
}
