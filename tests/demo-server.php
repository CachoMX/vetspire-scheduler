<?php
/**
 * Local demo server for visual testing (NOT part of the plugin).
 * Serves the widget assets + thin REST proxy backed by the real plugin
 * classes against the live Vetspire API.
 *
 * Run: php -S 127.0.0.1:8737 tests/demo-server.php   (from plugin root)
 * Env: VSPS_DEMO_LOCATION / VSPS_DEMO_BOOK=1 to allow real bookings
 *      (defaults to TESTING LOCATION with booking enabled).
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_json_encode( $data ) { return json_encode( $data ); }

function wp_remote_post( $url, $args ) {
	$header_lines = '';
	foreach ( $args['headers'] as $k => $v ) { $header_lines .= "$k: $v\r\n"; }
	$ctx = stream_context_create( array( 'http' => array(
		'method' => 'POST', 'header' => $header_lines, 'content' => $args['body'],
		'timeout' => 20, 'ignore_errors' => true,
	) ) );
	$body = @file_get_contents( $url, false, $ctx );
	if ( false === $body ) { return new WP_Error( 'http_request_failed', 'stream request failed' ); }
	$code = 0;
	foreach ( ( isset( $http_response_header ) ? $http_response_header : array() ) as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) { $code = (int) $m[1]; }
	}
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

$GLOBALS['vsps_transients'] = array();
function get_transient( $key ) {
	$t = $GLOBALS['vsps_transients'];
	if ( isset( $t[ $key ] ) && $t[ $key ]['exp'] > time() ) { return $t[ $key ]['val']; }
	return false;
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['vsps_transients'][ $key ] = array( 'val' => $value, 'exp' => time() + $ttl );
	return true;
}
function delete_transient( $key ) { unset( $GLOBALS['vsps_transients'][ $key ] ); return true; }
function get_option( $key, $default = false ) { return $default; }
function apply_filters( $tag, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function vsps_get_settings() { return array( 'cache_ttl' => 60 ); }

require __DIR__ . '/../includes/class-vsps-api.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-booking.php';

$env      = parse_ini_file( __DIR__ . '/../.env' );
$api      = new VSPS_Api( $env['VETSPIRE_API_TOKEN'], 'https://api.vetspire.com/graphql' );
$location = getenv( 'VSPS_DEMO_LOCATION' ) ?: '23539'; // Iowa Colony for realistic slots.

$uri  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$q    = $_GET;

function respond_json( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

/* Static assets */
if ( '/favicon.ico' === $uri ) {
	http_response_code( 204 );
	exit;
}
if ( '/assets/js/scheduler.js' === $uri ) {
	header( 'Content-Type: application/javascript' );
	readfile( __DIR__ . '/../assets/js/scheduler.js' );
	exit;
}
if ( '/assets/css/scheduler.css' === $uri ) {
	header( 'Content-Type: text/css' );
	readfile( __DIR__ . '/../assets/css/scheduler.css' );
	exit;
}

/* REST proxy */
if ( '/wp-json/vetspire/v1/types' === $uri ) {
	$types = $api->get_bookable_types( isset( $q['location_id'] ) ? $q['location_id'] : $location );
	if ( is_wp_error( $types ) ) { respond_json( array( 'message' => $types->get_error_message() ), 502 ); }
	respond_json( array( 'types' => $types ) );
}
if ( '/wp-json/vetspire/v1/availability' === $uri ) {
	$days   = min( 14, max( 1, isset( $q['days'] ) ? (int) $q['days'] : 7 ) );
	$loc    = isset( $q['location_id'] ) ? $q['location_id'] : $location;
	$type   = $q['appointment_type_id'];
	$result = array();
	$start  = new DateTimeImmutable( 'today' );
	for ( $i = 0; $i < $days; $i++ ) {
		$date  = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
		$slots = VSPS_Cache::remember( array( 'avail', $loc, $type, $date ), function () use ( $api, $loc, $type, $date ) {
			return $api->get_available_times( $loc, $type, $date );
		} );
		if ( is_wp_error( $slots ) ) { $slots = array(); }
		usort( $slots, function ( $a, $b ) { return strcmp( $a['time'], $b['time'] ); } );
		$result[] = array( 'date' => $date, 'slots' => $slots );
	}
	respond_json( array( 'days' => $result ) );
}
if ( '/wp-json/vetspire/v1/book' === $uri && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$payload = json_decode( file_get_contents( 'php://input' ), true );
	if ( ! $payload ) { respond_json( array( 'message' => 'Bad payload' ), 400 ); }
	// Demo guard: only ever book against TESTING LOCATION unless explicitly allowed.
	$allow_live = '1' === getenv( 'VSPS_DEMO_BOOK' );
	$loc        = $allow_live ? $payload['location_id'] : '23512';
	$type_id    = $allow_live ? $payload['appointment_type_id'] : '9403';
	$args = array(
		'location_id'         => $loc,
		'appointment_type_id' => $type_id,
		'date'                => $payload['date'],
		'time'                => $payload['time'],
		'provider_id'         => $allow_live ? (string) $payload['provider_id'] : '',
		'schedule_id'         => $allow_live ? (string) $payload['schedule_id'] : '',
		// TESTING LOCATION has no schedule → skip slot re-check for the demo guard path.
		'skip_slot_check'     => ! $allow_live,
		'notes'               => 'DEMO TEST — safe to delete. ' . (string) $payload['notes'],
		'client'              => array(
			'given_name'  => $payload['client']['given_name'],
			'family_name' => $payload['client']['family_name'],
			'email'       => $payload['client']['email'],
			'phone'       => $payload['client']['phone'],
		),
		'patient'             => array(
			'name'    => $payload['patient']['name'],
			'species' => $payload['patient']['species'],
			'breed'   => '',
			'sex'     => '',
		),
	);
	$result = VSPS_Booking::book( $api, $args );
	if ( is_wp_error( $result ) ) { respond_json( array( 'message' => $result->get_error_message() ), 502 ); }
	respond_json( array( 'success' => true, 'appointment_id' => $result['appointment_id'], 'start' => $result['start'] ) );
}

/* Demo page mimicking the shortcode output */
if ( '/' === $uri ) {
	$mode   = ( isset( $q['mode'] ) && 'link' === $q['mode'] ) ? 'link' : 'book';
	$types  = isset( $q['types'] ) ? array_map( 'intval', explode( ',', $q['types'] ) ) : array();
	$layout = isset( $q['layout'] ) && in_array( $q['layout'], array( 'full', 'bar', 'calendar', 'float' ), true ) ? $q['layout'] : 'full';
	$config = json_encode( array(
		'locationId' => (int) $location,
		'typeIds'    => $types,
		'days'       => 7,
		'mode'       => $mode,
		'linkUrl'    => 'link' === $mode ? 'https://example.com/external-booking' : '',
		'layout'     => $layout,
	) );
	header( 'Content-Type: text/html' );
	echo <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vetspire Scheduler Demo</title>
<link rel="stylesheet" href="/assets/css/scheduler.css">
<style>
/* Hostile theme simulation (Elementor-kit-like, specificity 0-1-1): the widget must survive this. */
body button { color: #fff; background: #fff; border: none; text-transform: uppercase; letter-spacing: 3px; box-shadow: 0 0 4px #000; }
body input, body select, body textarea { color: #fff; background: #333; }
</style>
</head><body style="font-family:system-ui,sans-serif;background:#f4f6f5;padding:40px;">
<script>window.vspsConfig={restUrl:'/wp-json/vetspire/v1',analytics:1};</script>
<div class="vsps-widget" style="--vsps-primary:#2f6f4f;margin:0 auto;" data-vsps-config='{$config}'>
	<h3 class="vsps-title">Book an Appointment</h3>
	<div class="vsps-body"><p class="vsps-loading">Loading available times…</p></div>
</div>
<script src="/assets/js/scheduler.js"></script>
</body></html>
HTML;
	exit;
}

http_response_code( 404 );
echo 'Not found';
