<?php
/**
 * Offline checks for the Nectar adapter's production wiring (v1.29.0): the
 * clinic address → API host, the clinics query and the cache keys.
 * No WordPress, no network.
 *
 * php tests/nectar-unit.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

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

// Records every outgoing request instead of sending it.
$GLOBALS['vsps_sent'] = array();
function wp_remote_post( $url, $args ) {
	$GLOBALS['vsps_sent'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ), 'headers' => $args['headers'] );
	if ( false !== strpos( $url, 'auth0.com' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => 'tok', 'expires_in' => 3600 ) ) );
	}
	$code = isset( $GLOBALS['vsps_reply_code'] ) ? $GLOBALS['vsps_reply_code'] : 200;
	return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $GLOBALS['vsps_reply'] ) );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
$GLOBALS['vsps_transients'] = array();
function get_transient( $key ) { return isset( $GLOBALS['vsps_transients'][ $key ] ) ? $GLOBALS['vsps_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['vsps_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['vsps_transients'][ $key ] ); return true; }
function vsps_get_settings() { return array( 'cache_ttl' => 60, 'pims' => 'nectar' ); }

require __DIR__ . '/../includes/class-vsps-pims.php';
require __DIR__ . '/../includes/class-vsps-cache.php';
require __DIR__ . '/../includes/class-vsps-nectar.php';

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}
function reset_net( $reply, $code = 200 ) {
	$GLOBALS['vsps_reply_code'] = $code;
	$GLOBALS['vsps_sent']       = array();
	$GLOBALS['vsps_transients'] = array();
	$GLOBALS['vsps_reply']      = $reply;
}
function graphql_calls() {
	return array_values( array_filter( $GLOBALS['vsps_sent'], function ( $c ) {
		return false === strpos( $c['url'], 'auth0.com' );
	} ) );
}

/* ---------- clinic address → code ---------- */

echo "== clinic address ==\n";
$ok_inputs = array(
	'esteroeasyvet'                                    => 'esteroeasyvet',
	'  EsteroEasyVet '                                 => 'esteroeasyvet',
	'https://esteroeasyvet.app.prod.nectar.vet/'       => 'esteroeasyvet',
	'esteroeasyvet.app.prod.nectar.vet'                => 'esteroeasyvet',
	'https://esteroeasyvet.app.prod.nectar.vet/calendar?x=1' => 'esteroeasyvet',
	'https://esteroeasyvet.api.prod.nectar.vet/graphql' => 'esteroeasyvet',
	'http://dr-tolley.app.prod.nectar.vet'             => 'dr-tolley',
);
foreach ( $ok_inputs as $in => $want ) {
	check( "'" . trim( $in ) . "' → $want", $want === VSPS_Nectar::clinic_code( $in ) );
}
check( "'' → '' (not set)", '' === VSPS_Nectar::clinic_code( '' ) );
$bad_inputs = array(
	'https://evil.example/graphql',
	'esteroeasyvet.app.prod.nectar.vet.evil.example',
	'https://esteroeasyvet.evil.example/.prod.nectar.vet',
	'https://user@esteroeasyvet.app.prod.nectar.vet.evil.example/',
	'dev-clinic.app.dev.nectar.vet',      // staging is not production
	'esteroeasyvet.www.prod.nectar.vet',  // only app / api hosts
	'a.b.app.prod.nectar.vet',
	'-estero',
	'estero-',
	'estero_easyvet',
	'estero easyvet',
	str_repeat( 'a', 64 ),
	'esteroeasyvet.app.prod.nectar.vet:8443',
	'https://evil.example\@esteroeasyvet.app.prod.nectar.vet/',
	'https://evil.example#@esteroeasyvet.app.prod.nectar.vet/',
	'https://evil.example?@esteroeasyvet.app.prod.nectar.vet/',
	'esteroeasyvet.app.prod.nectar.vet.',
	'esteroeasyvet%2eapp.prod.nectar.vet',
	"esteroeasyvet\n.app.prod.nectar.vet",
	"estero\0easyvet",
	'https://[::1]/graphql',
	'ｅｓｔｅｒｏ.app.prod.nectar.vet',
	'ftp://esteroeasyvet.app.prod.nectar.vet/',
	'javascript:alert(1)',
);
foreach ( $bad_inputs as $in ) {
	check( "refused: '" . addcslashes( substr( $in, 0, 60 ), "\0..\37" ) . "'", false === VSPS_Nectar::clinic_code( $in ) );
}
check( '63-char code accepted', str_repeat( 'a', 63 ) === VSPS_Nectar::clinic_code( str_repeat( 'a', 63 ) ) );

/* ---------- production API host ---------- */

echo "== API host ==\n";
check( 'code → per-clinic host', 'https://esteroeasyvet.api.prod.nectar.vet/graphql' === VSPS_Nectar::production_api_url( 'esteroeasyvet' ) );
check( 'no code → no host', '' === VSPS_Nectar::production_api_url( '' ) );
check( 'invalid code → no host', '' === VSPS_Nectar::production_api_url( 'evil.example/x' ) );
check( 'stored code with a trailing newline → no host', '' === VSPS_Nectar::production_api_url( "estero\n" ) );
check( 'a trailing newline is not a code', false === VSPS_Nectar::clinic_code( "esteroeasyvet\n.x" ) );

reset_net( array( 'data' => array( 'appointmentTypes' => array() ) ) );
$prod = new VSPS_Nectar( 'id', 'secret', '', 'America/New_York', 'production', 'esteroeasyvet' );
$prod->request( '{ __typename }' );
$sent = $GLOBALS['vsps_sent'];
check( 'production token from prod Auth0', isset( $sent[0] ) && 'https://nectarvet.us.auth0.com/oauth/token' === $sent[0]['url'] );
check( 'production query to the clinic host', isset( $sent[1] ) && 'https://esteroeasyvet.api.prod.nectar.vet/graphql' === $sent[1]['url'] );

reset_net( array() );
$none = new VSPS_Nectar( 'id', 'secret', '', 'America/New_York', 'production', '' );
$err  = $none->request( '{ __typename }' );
check( 'production without a clinic address: error', is_wp_error( $err ) && 'vsps_config' === $err->get_error_code() );
check( 'production without a clinic address: the message says what to do', is_wp_error( $err ) && false !== stripos( $err->get_error_message(), 'clinic' ) );
check( 'production without a clinic address: nothing sent (secret stays home)', array() === $GLOBALS['vsps_sent'] );

reset_net( array( 'data' => array() ) );
$sandbox = new VSPS_Nectar( 'id', 'secret', '', 'America/New_York', 'sandbox', 'esteroeasyvet' );
$sandbox->request( '{ __typename }' );
$sent = $GLOBALS['vsps_sent'];
check( 'sandbox ignores the clinic address', isset( $sent[1] ) && 'https://dev-clinic.api.dev.nectar.vet/graphql' === $sent[1]['url'] );

/* ---------- clinics query + metaLocation ---------- */

echo "== clinics query ==\n";
$clinic = array( 'id' => 'c1', 'name' => 'EasyVet Estero', 'street' => '8006 Mediterranean Dr.', 'city' => 'Estero', 'state' => 'FL', 'zipCode' => '33928', 'phone' => '2393013728', 'email' => 'e@x', 'timezone' => 'America/Detroit' );
reset_net( array( 'data' => array( 'clinics' => array( $clinic ) ) ) );
$prod  = new VSPS_Nectar( 'id', 'secret', '', 'America/New_York', 'production', 'esteroeasyvet' );
$where = $prod->get_location( VSPS_Nectar::LOCATION_ID );
$calls = graphql_calls();
check( 'no location code: clinics query does not ask for metaLocation', isset( $calls[0] ) && false === strpos( $calls[0]['body']['query'], 'metaLocation' ) );
check( 'clinic name from Nectar', 'EasyVet Estero' === $where['name'] );
check( 'clinic timezone from Nectar', 'America/Detroit' === $where['timezone'] );

$two = array(
	array_merge( $clinic, array( 'name' => 'North', 'metaLocation' => 'north' ) ),
	array_merge( $clinic, array( 'name' => 'South', 'metaLocation' => 'south' ) ),
);
reset_net( array( 'data' => array( 'clinics' => $two ) ) );
$multi = new VSPS_Nectar( 'id', 'secret', 'south', 'America/New_York', 'production', 'esteroeasyvet' );
$where = $multi->get_location( VSPS_Nectar::LOCATION_ID );
$calls = graphql_calls();
check( 'with a location code: clinics query asks for metaLocation', isset( $calls[0] ) && false !== strpos( $calls[0]['body']['query'], 'metaLocation' ) );
check( 'with a location code: that clinic is used', 'South' === $where['name'] );

/* ---------- cache keys per API host ---------- */

echo "== cache per clinic ==\n";
reset_net( array( 'data' => array( 'clinics' => array( array_merge( $clinic, array( 'name' => 'Clinic A' ) ) ) ) ) );
$a = new VSPS_Nectar( 'same-id', 'secret', '', 'America/New_York', 'production', 'clinica' );
$a->get_location( VSPS_Nectar::LOCATION_ID );
$GLOBALS['vsps_reply'] = array( 'data' => array( 'clinics' => array( array_merge( $clinic, array( 'name' => 'Clinic B' ) ) ) ) );
$b     = new VSPS_Nectar( 'same-id', 'secret', '', 'America/New_York', 'production', 'clinicb' );
$where = $b->get_location( VSPS_Nectar::LOCATION_ID );
check( 'same client id, other clinic: its own clinic profile', 'Clinic B' === $where['name'] );

/* ---------- production through the Vetcelerator hub ---------- */

echo "== through the hub ==\n";
$site_key = 'vss_' . str_repeat( 'ab', 24 );
reset_net( array( 'data' => array( 'appointmentTypes' => array( array( 'id' => 't1' ) ) ) ) );
$hub  = VSPS_Nectar::via_hub( 'https://hub.test/', $site_key, '', 'America/New_York', '1.29.0' );
$data = $hub->request( '{ appointmentTypes { id } }' );
$sent = $GLOBALS['vsps_sent'];
check( 'hub mode: one request, to the hub proxy', 1 === count( $sent ) && 'https://hub.test/api/wp/v1/scheduler/nectar/graphql' === $sent[0]['url'] );
check( 'hub mode: no Nectar login from WordPress', 0 === count( array_filter( $sent, function ( $c ) { return false !== strpos( $c['url'], 'auth0' ); } ) ) );
check( 'hub mode: authenticated with the site key', 'Bearer ' . $site_key === $sent[0]['headers']['Authorization'] );
check( 'hub mode: sends the plugin version', '1.29.0' === $sent[0]['headers']['X-VSPS-Version'] );
check( 'hub mode: data comes back as from Nectar', array( array( 'id' => 't1' ) ) === $data['appointmentTypes'] );
check( 'hub mode: platform is still nectar', 'nectar' === $hub->platform() );

reset_net( array( 'error' => 'nectar_clinic_not_linked' ), 409 );
$err = VSPS_Nectar::via_hub( 'https://hub.test', $site_key, '', 'America/New_York', '1.29.0' )->connection();
check( 'site not linked: config error that says where to link it', is_wp_error( $err ) && 'vsps_config' === $err->get_error_code() && false !== stripos( $err->get_error_message(), 'Manage sites' ) );

reset_net( array( 'error' => 'invalid_site_key' ), 403 );
$err = VSPS_Nectar::via_hub( 'https://hub.test', $site_key, '', 'America/New_York', '1.29.0' )->connection();
check( 'refused site key: auth error', is_wp_error( $err ) && 'vsps_auth' === $err->get_error_code() );

reset_net( array( 'detail' => 'Clinic is not enabled or not registered' ), 403 );
$err = VSPS_Nectar::via_hub( 'https://hub.test', $site_key, '', 'America/New_York', '1.29.0' )->connection();
check( "Nectar's own refusal is passed on", is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Clinic is not enabled' ) );

reset_net( array( 'detail' => 'expired' ), 401 );
$err = VSPS_Nectar::via_hub( 'https://hub.test', $site_key, '', 'America/New_York', '1.29.0' )->request( '{ appointmentTypes { id } }' );
check( 'a 401 is not retried with a Nectar login in hub mode', is_wp_error( $err ) && 1 === count( $GLOBALS['vsps_sent'] ) );

reset_net( array() );
$err = VSPS_Nectar::via_hub( 'https://hub.test', '', '', 'America/New_York', '1.29.0' )->request( '{ appointmentTypes { id } }' );
check( 'no hub key: config error, nothing sent', is_wp_error( $err ) && 'vsps_config' === $err->get_error_code() && array() === $GLOBALS['vsps_sent'] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
