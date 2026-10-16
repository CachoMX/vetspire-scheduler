<?php
/**
 * Section 0 of tests/harness.php: funnel events, offline (no network, no WordPress).
 * Covers event validation/sanitising, the per-IP rate limit, the outbox
 * (multi-row insert, idempotency, prune) and the hub delivery rules
 * (ack/reject deletes, key stop, backoff, poison event) against an in-memory
 * SQLite stand-in for $wpdb and a mocked hub.
 *
 * Included by tests/harness.php (uses its check() and stubs).
 */

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'VSPS_HUB_URL' ) ) {
	define( 'VSPS_HUB_URL', 'https://hub.test' );
}
if ( ! defined( 'VSPS_VERSION' ) ) {
	define( 'VSPS_VERSION', 'test' );
}

function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
$GLOBALS['vsps_actions'] = array();
function add_action( $hook, $cb, $prio = 10 ) { $GLOBALS['vsps_actions'][] = $hook; return true; }

class WP_REST_Request {
	private $body;
	public function __construct( $body ) { $this->body = $body; }
	public function get_body() { return $this->body; }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}

/** Just enough of $wpdb for VSPS_Events / VSPS_Hub, on SQLite in memory. */
class VSPS_Test_WPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $queries    = array();
	private $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->pdo->exec( 'CREATE TABLE wp_vsps_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE,
			session_id TEXT, name TEXT, occurred_at TEXT, device TEXT, layout TEXT, variant TEXT, location_id TEXT,
			appointment_type_id TEXT, page_path TEXT, fields TEXT)' );
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$pdo = $this->pdo;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args, $pdo ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : $pdo->quote( (string) $v );
		}, $sql );
	}
	private function run( $sql ) {
		$this->queries[] = $sql;
		$st = $this->pdo->prepare( preg_replace( '/^INSERT IGNORE/i', 'INSERT OR IGNORE', $sql ) );
		if ( ! $st || ! $st->execute() ) {
			$this->last_error = implode( ' ', $st ? $st->errorInfo() : $this->pdo->errorInfo() );
			return false;
		}
		return $st;
	}
	public function query( $sql ) {
		$st = $this->run( $sql );
		return false === $st ? false : $st->rowCount();
	}
	public function get_results( $sql ) {
		$st = $this->run( $sql );
		return false === $st ? null : $st->fetchAll( PDO::FETCH_OBJ );
	}
	public function get_var( $sql ) {
		$st = $this->run( $sql );
		return false === $st ? null : $st->fetchColumn();
	}
	public function exec( $sql ) { return $this->pdo->exec( $sql ); }
}

require_once __DIR__ . '/../includes/class-vsps-fields.php';
require_once __DIR__ . '/../includes/class-vsps-events.php';
require_once __DIR__ . '/../includes/class-vsps-rest.php';
require_once __DIR__ . '/../includes/class-vsps-hub.php';

echo "== 0. Funnel events (offline) ==\n";

$now = '2026-10-16 15:00:00';
$ev  = function ( array $over = array() ) {
	return array_merge( array(
		'event_id'            => bin2hex( random_bytes( 16 ) ),
		'session_id'          => str_repeat( 'a1', 16 ),
		'name'                => 'widget_view',
		'device'              => 'mobile',
		'layout'              => 'bar',
		'variant'             => 'B',
		'location_id'         => 23539,
		'appointment_type_id' => '5541',
		'page_path'           => '/home-2/',
	), $over );
};
$body = function ( array $events ) {
	return json_encode( array( 'events' => $events ) );
};

// --- shape ---
check( 'events: not JSON → invalid batch', null === VSPS_Events::parse_body( 'nope', $now ) );
check( 'events: no events list → invalid batch', null === VSPS_Events::parse_body( '{"records":[]}', $now ) );
check( 'events: events as an object → invalid batch', null === VSPS_Events::parse_body( '{"events":{"a":1}}', $now ) );
$fifty = array();
for ( $i = 0; $i < 51; $i++ ) {
	$fifty[] = $ev();
}
check( 'events: 51 events → invalid batch', null === VSPS_Events::parse_body( $body( $fifty ), $now ) );
check( 'events: 50 events accepted', 50 === count( (array) VSPS_Events::parse_body( $body( array_slice( $fifty, 0, 50 ) ), $now ) ) );

// --- one event ---
$rows = VSPS_Events::parse_body( $body( array( $ev( array( 'occurred_at' => '1999-01-01 00:00:00' ) ) ) ), $now );
$r    = $rows[0];
check( 'events: occurred_at stamped by the server, browser clock ignored', $now === $r['occurred_at'] );
check( 'events: location id kept as a string', '23539' === $r['location_id'] && '5541' === $r['appointment_type_id'] );
check( 'events: variant lower-cased, device + layout kept', 'b' === $r['variant'] && 'mobile' === $r['device'] && 'bar' === $r['layout'] );
check( 'events: no fields on a non-abandon event', '[]' === $r['fields'] );

$bad = VSPS_Events::parse_body( $body( array(
	$ev( array( 'event_id' => 'short' ) ),
	$ev( array( 'event_id' => str_repeat( 'x', 65 ) ) ),
	$ev( array( 'event_id' => 'has spaces in it' ) ),
	$ev( array( 'session_id' => '' ) ),
	$ev( array( 'name' => 'page_view' ) ),
	$ev( array( 'name' => array( 'widget_view' ) ) ),
	'not an object',
) ), $now );
check( 'events: bad ids / unknown names dropped, batch still fine', is_array( $bad ) && 0 === count( $bad ), json_encode( $bad ) );

$dup  = $ev();
$rows = VSPS_Events::parse_body( $body( array( $dup, $dup ) ), $now );
check( 'events: duplicate event_id in one batch stored once', 1 === count( $rows ) );

$rows = VSPS_Events::parse_body( $body( array( $ev( array(
	'device'              => 'phone',
	'layout'              => '<script>',
	'variant'             => 'way-too-long-variant',
	'location_id'         => '23539; DROP TABLE',
	'appointment_type_id' => array( 1 ),
	'page_path'           => 'https://evil.test/book/now?email=a@b.c#x',
) ) ) ), $now );
$r = $rows[0];
check( 'events: unknown device/layout and bad variant become empty', '' === $r['device'] && '' === $r['layout'] && '' === $r['variant'] );
check( 'events: bad ids become empty', '' === $r['location_id'] && '' === $r['appointment_type_id'] );
check( 'events: page_path keeps only the path (no host, query, hash)', '/book/now' === $r['page_path'], $r['page_path'] );
check( 'events: page_path capped at 255', 255 === strlen( VSPS_Events::page_path( '/' . str_repeat( 'a', 400 ) ) ) );
check( 'events: page_path must be a path', '' === VSPS_Events::page_path( 'javascript:alert(1)' ) );

$rows   = VSPS_Events::parse_body( $body( array( $ev( array(
	'name'   => 'form_abandoned',
	'fields' => array( 'given_name', 'email', 'jane@example.com', 'Jane', 'email', 'address', 'phone_last4', array( 'pet_name' ), 'reason', str_repeat( 'k', 41 ) ),
) ) ) ), $now );
$fields = json_decode( $rows[0]['fields'], true );
check( 'events: form_abandoned keeps only registry keys, in order, once', array( 'given_name', 'email', 'address', 'reason' ) === $fields, json_encode( $fields ) );
$rows = VSPS_Events::parse_body( $body( array( $ev( array( 'name' => 'slot_selected', 'fields' => array( 'email' ) ) ) ) ), $now );
check( 'events: fields ignored on other events', '[]' === $rows[0]['fields'] );
$many = array_keys( VSPS_Fields::REGISTRY );
check( 'events: at most 40 field keys', count( VSPS_Events::field_keys( $many ) ) === min( 40, count( $many ) ) );

// --- ack rule ---
$acked = VSPS_Events::acked_ids( array(
	'accepted' => array( 'e1', 'e2', 42, 'not-sent' ),
	'rejected' => array( array( 'event_id' => 'e3', 'error' => 'bad' ), array( 'error' => 'no id' ), 'junk' ),
), array( 'e1', 'e2', 'e3', 'e4' ) );
sort( $acked );
check( 'events: accepted + rejected ids acknowledged, only ids that were sent', array( 'e1', 'e2', 'e3' ) === $acked, json_encode( $acked ) );
check( 'events: non-array hub answer acknowledges nothing', array() === VSPS_Events::acked_ids( 'oops', array( 'e1' ) ) );

// --- rate limit (fixed window, VSPS_Rest::rate_limit_ok) ---
$rl = new ReflectionMethod( 'VSPS_Rest', 'rate_limit_ok' );
$rl->setAccessible( true );
$allowed = 0;
for ( $i = 0; $i < VSPS_Events::RATE_LIMIT + 5; $i++ ) {
	if ( $rl->invoke( null, 'ev_test', VSPS_Events::RATE_LIMIT, VSPS_Events::RATE_TTL ) ) {
		$allowed++;
	}
}
check( 'events: rate limit allows 120 calls per window, then refuses', VSPS_Events::RATE_LIMIT === $allowed, "allowed $allowed" );
$GLOBALS['vsps_transients']['vsps_rl_ev_test']['expires'] = time() - 1;
check( 'events: a new window opens once the first one expires', $rl->invoke( null, 'ev_test', VSPS_Events::RATE_LIMIT, VSPS_Events::RATE_TTL ) );

// --- outbox ---
$GLOBALS['wpdb'] = new VSPS_Test_WPDB();
$wpdb            = $GLOBALS['wpdb'];
$batch           = VSPS_Events::parse_body( $body( array( $ev(), $ev(), $ev() ) ), $now );
$before          = count( $wpdb->queries );
check( 'events: store() inserts 3 rows', 3 === VSPS_Events::store( $batch ) );
check( 'events: ...in ONE query', 1 === count( $wpdb->queries ) - $before );
check( 'events: re-sent events are ignored (idempotent)', 0 === VSPS_Events::store( $batch ) && 3 === VSPS_Events::pending_count() );
$old = VSPS_Events::parse_body( $body( array( $ev() ) ), gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ) );
VSPS_Events::store( $old );
check( 'events: prune drops rows older than 14 days only', 1 === VSPS_Events::prune() && 3 === VSPS_Events::pending_count() );
$pending = VSPS_Events::pending( 10 );
check( 'events: pending() oldest first', $pending[0]->event_id === $batch[0]['event_id'] );
$rec = VSPS_Events::record( $pending[0] );
check( 'events: hub record has every contract field', array( 'event_id', 'session_id', 'name', 'occurred_at', 'device', 'layout', 'variant', 'location_id', 'appointment_type_id', 'page_path', 'fields' ) === array_keys( $rec ) && array() === $rec['fields'] );

// --- browser endpoint ---
$GLOBALS['vsps_test_settings'] = array( 'hub_key' => '' );
$res = VSPS_Events::rest_ingest( new WP_REST_Request( $body( array( $ev() ) ) ), function () { return true; } );
check( 'events: no hub key → 204 and nothing stored', 204 === $res->status && 3 === VSPS_Events::pending_count() );
$GLOBALS['vsps_test_settings'] = array( 'hub_key' => 'vss_' . str_repeat( 'ab', 24 ) );
$res = VSPS_Events::rest_ingest( new WP_REST_Request( 'garbage' ), function () { return true; } );
check( 'events: invalid body → 400', 400 === $res->status );
$res = VSPS_Events::rest_ingest( new WP_REST_Request( $body( array( $ev() ) ) ), function () { return false; } );
check( 'events: over the rate limit → 429, nothing stored', 429 === $res->status && 3 === VSPS_Events::pending_count() );
$GLOBALS['vsps_actions'] = array();
$res = VSPS_Events::rest_ingest( new WP_REST_Request( $body( array( $ev(), $ev() ) ) ), function () { return true; } );
check( 'events: valid batch → 204, stored', 204 === $res->status && 5 === VSPS_Events::pending_count() );
check( 'events: ...and a deferred hub push is asked for', in_array( 'shutdown', $GLOBALS['vsps_actions'], true ) );
$res = VSPS_Events::rest_ingest( new WP_REST_Request( $body( array( $ev() ) ) ), function () { throw new Exception( 'boom' ); } );
check( 'events: an internal error is swallowed (still 204)', 204 === $res->status );

// --- outbox cap (MAX_ROWS, count cached COUNT_TTL seconds) ---
check( 'events: per-IP limit is 600/hour (storage is bounded by the cap)', 600 === VSPS_Events::RATE_LIMIT );
$rows_now = VSPS_Events::pending_count();
set_transient( VSPS_Events::COUNT_CACHE, VSPS_Events::MAX_ROWS + 1, 60 );
$res = VSPS_Events::rest_ingest( new WP_REST_Request( $body( array( $ev(), $ev() ) ) ), function () { return true; } );
check( 'events: outbox over 20,000 rows → nothing stored, still a silent 204', 204 === $res->status && $rows_now === VSPS_Events::pending_count() );
set_transient( VSPS_Events::COUNT_CACHE, VSPS_Events::MAX_ROWS, 60 );
VSPS_Events::rest_ingest( new WP_REST_Request( $body( array( $ev() ) ) ), function () { return true; } );
check( 'events: at exactly the cap, still stored', $rows_now + 1 === VSPS_Events::pending_count() );
delete_transient( VSPS_Events::COUNT_CACHE );
check( 'events: count computed from the table when not cached', false === VSPS_Events::outbox_full() && $rows_now + 1 === (int) get_transient( VSPS_Events::COUNT_CACHE ) );
$before = count( $wpdb->queries );
VSPS_Events::outbox_full();
VSPS_Events::outbox_full();
$counted = count( array_filter( array_slice( $wpdb->queries, $before ), function ( $q ) { return false !== stripos( $q, 'COUNT(' ); } ) );
check( 'events: cached count means no COUNT(*) per request', 0 === $counted, $counted );

// --- hub delivery (mocked hub) ---
$push  = new ReflectionMethod( 'VSPS_Hub', 'push_events' );
$push->setAccessible( true );
$started = new ReflectionProperty( 'VSPS_Hub', 'push_started' );
$started->setAccessible( true );
$key   = 'vss_' . str_repeat( 'ab', 24 );
$reset = function () use ( $wpdb, $started ) {
	$wpdb->exec( 'DELETE FROM wp_vsps_events' );
	$GLOBALS['vsps_options'] = array();
	$started->setValue( null, microtime( true ) ); // a fresh push
};
$seed  = function ( $n ) use ( $ev, $body, $now ) {
	$events = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$events[] = $ev();
	}
	foreach ( array_chunk( $events, VSPS_Events::MAX_PER_CALL ) as $chunk ) {
		VSPS_Events::store( VSPS_Events::parse_body( $body( $chunk ), $now ) );
	}
	return array_column( $events, 'event_id' );
};
$http = function ( $code, $json ) {
	return array( 'response' => array( 'code' => $code ), 'body' => is_string( $json ) ? $json : json_encode( $json ) );
};
$calls = array();

$reset();
$ids = $seed( 4 );
$GLOBALS['vsps_mock_http'] = function ( $url, $args ) use ( &$calls, $http ) {
	$sent    = json_decode( $args['body'], true );
	$calls[] = array( 'url' => $url, 'n' => count( $sent['events'] ), 'auth' => $args['headers']['Authorization'], 'keys' => array_keys( $sent ) );
	$ids     = array_column( $sent['events'], 'event_id' );
	return $http( 200, array( 'accepted' => array_slice( $ids, 0, 2 ), 'rejected' => array( array( 'event_id' => $ids[2], 'error' => 'bad' ) ) ) );
};
$n = $push->invoke( null, $key );
check( 'hub events: POST to /api/wp/v1/scheduler/events with the site key', 'https://hub.test/api/wp/v1/scheduler/events' === $calls[0]['url'] && 'Bearer ' . $key === $calls[0]['auth'] );
check( 'hub events: body is { events: [...] }', array( 'events' ) === $calls[0]['keys'] && 4 === $calls[0]['n'] );
check( 'hub events: accepted + rejected rows deleted, unanswered row kept', 3 === $n && 1 === VSPS_Events::pending_count() && $ids[3] === VSPS_Events::pending( 1 )[0]->event_id );

$reset();
$seed( 3 );
$GLOBALS['vsps_mock_http'] = function () use ( $http ) {
	return $http( 403, array( 'error' => 'invalid_site_key' ) );
};
$push->invoke( null, $key );
check( 'hub events: rejected key stops delivery like bookings, rows kept', ! empty( VSPS_Hub::status()['stopped'] ) && 3 === VSPS_Events::pending_count() );

$reset();
$seed( 3 );
$calls = array();
$GLOBALS['vsps_mock_http'] = function () use ( &$calls, $http ) {
	$calls[] = 1;
	return $http( 503, 'down' );
};
$push->invoke( null, $key );
$status = VSPS_Hub::status();
check( 'hub events: hub down → events back off, bookings untouched, rows kept',
	! empty( $status['events_backoff_until'] ) && empty( $status['backoff_until'] ) && empty( $status['stopped'] ) && 3 === VSPS_Events::pending_count() );
$push->invoke( null, $key );
check( 'hub events: no request while backing off', 1 === count( $calls ) );

$reset();
$seed( 2 );
$GLOBALS['vsps_mock_http'] = function () use ( $http ) {
	return $http( 404, '<html>Not found</html>' );
};
$push->invoke( null, $key );
check( 'hub events: no events endpoint (404) → retried in an hour', VSPS_Hub::status()['events_backoff_until'] >= time() + 3500 );

$reset();
$ids    = $seed( 5 );
$poison = $ids[2];
$calls  = array();
$GLOBALS['vsps_mock_http'] = function ( $url, $args ) use ( &$calls, $http, $poison ) {
	$sent    = array_column( json_decode( $args['body'], true )['events'], 'event_id' );
	$calls[] = count( $sent );
	if ( in_array( $poison, $sent, true ) ) {
		return $http( 400, array( 'error' => 'invalid_body' ) );
	}
	return $http( 200, array( 'accepted' => $sent, 'rejected' => array() ) );
};
$push->invoke( null, $key );
check( 'hub events: a batch refused as a whole is split; the one bad event is dropped, the rest delivered',
	0 === VSPS_Events::pending_count() && count( $calls ) > 1, json_encode( $calls ) );

$reset();
$seed( 4 );
$GLOBALS['vsps_mock_http'] = function () use ( $http ) {
	return $http( 400, array( 'error' => 'invalid_body' ) );
};
$push->invoke( null, $key );
check( 'hub events: hub refuses everything → back off, nothing deleted',
	4 === VSPS_Events::pending_count() && ! empty( VSPS_Hub::status()['events_backoff_until'] ) );

$reset();
$seed( 2 );
$GLOBALS['vsps_mock_http'] = function () use ( $http ) {
	return $http( 200, array( 'accepted' => array(), 'rejected' => array() ) );
};
$push->invoke( null, $key );
check( 'hub events: 200 that acknowledges nothing → back off, rows kept',
	2 === VSPS_Events::pending_count() && ! empty( VSPS_Hub::status()['events_backoff_until'] ) );

// --- events time budget (LOCK_TTL 60 s): batches stop after ~40 s of push ---
$reset();
$seed( 3 );
$calls = array();
$GLOBALS['vsps_mock_http'] = function () use ( &$calls, $http ) {
	$calls[] = 1;
	return $http( 200, array( 'accepted' => array(), 'rejected' => array() ) );
};
$started->setValue( null, microtime( true ) - VSPS_Hub::EVENTS_TIME_BUDGET - 1 ); // bookings used it all
$push->invoke( null, $key );
check( 'hub events: no event request once the push has used 40 s, no backoff, rows kept',
	0 === count( $calls ) && 3 === VSPS_Events::pending_count() && empty( VSPS_Hub::status()['events_backoff_until'] ) );

$reset();
$seed( VSPS_Hub::EVENTS_BATCH + 100 );
$calls = array();
$GLOBALS['vsps_mock_http'] = function ( $url, $args ) use ( &$calls, $http, $started ) {
	$sent    = array_column( json_decode( $args['body'], true )['events'], 'event_id' );
	$calls[] = count( $sent );
	// The first batch "took" the rest of the budget.
	$started->setValue( null, microtime( true ) - VSPS_Hub::EVENTS_TIME_BUDGET );
	return $http( 200, array( 'accepted' => $sent, 'rejected' => array() ) );
};
$push->invoke( null, $key );
check( 'hub events: budget used up mid-push → the next batch waits for the next push',
	array( VSPS_Hub::EVENTS_BATCH ) === $calls && 100 === VSPS_Events::pending_count() && empty( VSPS_Hub::status()['events_backoff_until'] ), json_encode( $calls ) );
$started->setValue( null, microtime( true ) );
$push->invoke( null, $key );
check( 'hub events: ...which sends the rest', 0 === VSPS_Events::pending_count() );

$src = file_get_contents( __DIR__ . '/../includes/class-vsps-hub.php' );
check( 'hub: events start only after the bookings loop and heartbeat',
	strpos( $src, 'self::heartbeat( $key, $site )' ) < strpos( $src, "\$summary['events_delivered'] = self::push_events( \$key )" ) );

// Back to the real network for the rest of the harness.
unset( $GLOBALS['vsps_mock_http'], $GLOBALS['wpdb'] );
$GLOBALS['vsps_options']       = array();
$GLOBALS['vsps_test_settings'] = array();
