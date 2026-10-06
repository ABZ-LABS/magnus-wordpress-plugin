<?php
/**
 * Integration tests, run inside a real WordPress by WordPress Playground:
 *
 *     bin/test.sh            # PHP 8.3 and 7.4, plus the JavaScript tests
 *
 * Magnus is never called: every outgoing request is answered by the
 * pre_http_request filter below, which also records what the plugin sent.
 *
 * @package IamagnusChat
 */

// phpcs:disable -- a test script, not plugin code.

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

$GLOBALS['t_pass']   = 0;
$GLOBALS['t_fail']   = 0;
$GLOBALS['t_issues'] = array();

// Any warning, notice or deprecation raised from a plugin file fails the run.
set_error_handler(
	function ( $no, $message, $file, $line ) {
		if ( false !== strpos( (string) $file, '/plugins/iamagnus-chat/' ) ) {
			$GLOBALS['t_issues'][] = $message . ' at ' . basename( $file ) . ':' . $line;
		}
		return false;
	}
);

function check( $name, $condition, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['t_pass']++;
		echo "  ok    $name\n";
	} else {
		$GLOBALS['t_fail']++;
		echo "  FAIL  $name" . ( '' !== $detail ? " :: $detail" : '' ) . "\n";
	}
}

function section( $title ) {
	echo "\n$title\n";
}

// --- the fake Magnus ---------------------------------------------------------

$GLOBALS['http'] = array(
	'calls' => array(),
	'next'  => null,
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['http']['calls'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		$next = $GLOBALS['http']['next'];
		if ( is_callable( $next ) ) {
			return $next( $url, $args );
		}
		return null !== $next ? $next : new WP_Error( 'test_no_answer', 'The test did not set a response.' );
	},
	10,
	3
);

function respond( $status, $body, $headers = array() ) {
	return array(
		'headers'  => $headers,
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

function magnus_reply( $text ) {
	return respond(
		200,
		array(
			'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $text ) ) ),
			'magnus'  => array( 'trace_id' => 'trace-1' ),
		)
	);
}

function last_call() {
	$calls = $GLOBALS['http']['calls'];
	return $calls ? $calls[ count( $calls ) - 1 ] : null;
}

function post_message( $params, $ip = '203.0.113.7' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$request                = new WP_REST_Request( 'POST', '/iamagnus-chat/v1/message' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}

function fresh_ip() {
	static $n = 0;
	++$n;
	return '198.51.100.' . $n;
}

// --- load the plugin ---------------------------------------------------------

section( 'Activation' );
$activated = activate_plugin( 'iamagnus-chat/iamagnus-chat.php' );
check( 'the plugin activates', ! is_wp_error( $activated ), is_wp_error( $activated ) ? $activated->get_error_message() : '' );
check( 'its classes load', class_exists( 'Iamagnus_Chat_Rest' ) && class_exists( 'Iamagnus_Chat_Client' ) );
$routes = rest_get_server()->get_routes();
check( 'the route is registered', isset( $routes['/iamagnus-chat/v1/message'] ) );
check( 'the setting is registered on init, so every write is sanitized', false !== has_action( 'init', array( 'Iamagnus_Chat_Settings', 'register' ) ) );
// This request ran init before the plugin was activated: do its part now.
Iamagnus_Chat_Settings::register();
echo '  (WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ")\n";

$key     = 'magnus_sys_test_secret_value';
$visitor = wp_generate_uuid4();

// --- the Magnus address --------------------------------------------------------

section( 'The Magnus address' );
$cases = array(
	'app.iamagnus.com'               => 'https://app.iamagnus.com',
	'https://app.iamagnus.com/'      => 'https://app.iamagnus.com',
	'https://app.iamagnus.com/v1'    => 'https://app.iamagnus.com',
	'https://app.iamagnus.com/v1/'   => 'https://app.iamagnus.com',
	'https://magnus.example.com/x?a' => 'https://magnus.example.com/x',
	'http://localhost:5001'          => 'http://localhost:5001',
	'http://127.0.0.1:8080/'         => 'http://127.0.0.1:8080',
	'http://app.iamagnus.com'        => null,
	'ftp://app.iamagnus.com'         => null,
	'javascript:alert(1)'            => null,
	''                               => null,
);
foreach ( $cases as $input => $expected ) {
	$got = Iamagnus_Chat_Settings::validate_base_url( $input );
	check( '"' . $input . '" -> ' . var_export( $expected, true ), $got === $expected, 'got ' . var_export( $got, true ) );
}

// --- saving the settings -------------------------------------------------------

section( 'Saving the settings' );
delete_option( Iamagnus_Chat_Settings::OPTION );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => "  $key  ", 'base_url' => 'https://magnus.test/v1', 'floating' => '1', 'color' => 'red' ) );
$s = Iamagnus_Chat_Settings::get();
check( 'the key is saved, trimmed', $key === $s['api_key'] );
check( 'a pasted /v1 is removed', 'https://magnus.test' === $s['base_url'], $s['base_url'] );
check( 'an invalid color falls back', Iamagnus_Chat_Settings::DEFAULT_TINT === $s['color'], $s['color'] );
check( 'empty texts use the defaults', '' !== $s['title'] && '' !== $s['welcome'] && '' !== $s['placeholder'] );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => '', 'base_url' => 'https://magnus.test', 'title' => 'Hola', 'floating' => '1' ) );
$s = Iamagnus_Chat_Settings::get();
check( 'an empty key field keeps the saved key', $key === $s['api_key'] );
check( 'a custom title is kept', 'Hola' === $s['title'] );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => 'x', 'clear_key' => '1', 'base_url' => 'https://magnus.test' ) );
check( 'the checkbox deletes the key', '' === Iamagnus_Chat_Settings::get()['api_key'] );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => $key, 'base_url' => 'http://evil.example', 'floating' => '1' ) );
check( 'an http address is refused and the previous one kept', 'https://magnus.test' === Iamagnus_Chat_Settings::get()['base_url'] );
$again = Iamagnus_Chat_Settings::sanitize( get_option( Iamagnus_Chat_Settings::OPTION ) );
check( 'sanitizing twice changes nothing', $again === get_option( Iamagnus_Chat_Settings::OPTION ) );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => $key, 'base_url' => 'https://magnus.test/' ) );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => '', 'base_url' => 'https://magnus.test/another/path' ) );
check( 'a new path on the same origin keeps the key', $key === Iamagnus_Chat_Settings::get()['api_key'] );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => '', 'base_url' => 'https://attacker.example' ) );
check( 'a new origin without a new key deletes the key', '' === Iamagnus_Chat_Settings::get()['api_key'] );
check( 'and says why', in_array( 'key_cleared', wp_list_pluck( get_settings_errors( Iamagnus_Chat_Settings::OPTION ), 'code' ), true ) );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => 'magnus_sys_other', 'base_url' => 'https://magnus.test' ) );
check( 'a new origin with a new key keeps the new key', 'magnus_sys_other' === Iamagnus_Chat_Settings::get()['api_key'] );

// --- not configured --------------------------------------------------------------

section( 'Without a key' );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => '', 'clear_key' => '1', 'base_url' => 'https://magnus.test', 'floating' => '1' ) );
$calls    = count( $GLOBALS['http']['calls'] );
$response = post_message( array( 'message' => 'Hola' ) );
check( 'the endpoint answers 503', 503 === $response->get_status(), (string) $response->get_status() );
check( 'with code not_configured', 'not_configured' === $response->get_data()['code'] );
check( 'and Magnus is not called', count( $GLOBALS['http']['calls'] ) === $calls );
wp_set_current_user( 0 );
check( 'the shortcode prints nothing for a visitor', '' === do_shortcode( '[magnus_chat]' ) );
ob_start();
Iamagnus_Chat_Widget::floating();
check( 'the corner button is not printed', '' === ob_get_clean() );

// --- a turn --------------------------------------------------------------------

section( 'A turn' );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => $key, 'base_url' => 'https://magnus.test', 'floating' => '1' ) );
$GLOBALS['http']['next'] = magnus_reply( 'Hola, ¿en qué te ayudo?' );
$turn                    = wp_generate_uuid4();
$response                = post_message( array( 'message' => '  Hola  ', 'visitor' => $visitor, 'turn' => $turn ) );
$data                    = $response->get_data();
check( 'the visitor gets 200', 200 === $response->get_status(), wp_json_encode( $data ) );
check( 'and the agent\'s reply', isset( $data['reply'] ) && 'Hola, ¿en qué te ayudo?' === $data['reply'] );
check( 'and keeps its visitor id', isset( $data['visitor'] ) && $visitor === $data['visitor'] );
check( 'the response never carries the key', false === strpos( wp_json_encode( $data ), $key ) );
$call = last_call();
check( 'the call goes to /v1/chat/completions', 'https://magnus.test/v1/chat/completions' === $call['url'], $call['url'] );
check( 'as a POST', 'POST' === $call['args']['method'] );
check( 'with the key as a bearer token', 'Bearer ' . $key === $call['args']['headers']['Authorization'] );
check( 'with a 64-character idempotency key', 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $call['args']['headers']['Idempotency-Key'] ) );
check( 'and a user agent that names the plugin', 0 === strpos( $call['args']['headers']['User-Agent'], 'iamagnus-chat/' . IAMAGNUS_CHAT_VERSION ) );
check( 'with a timeout long enough for a turn', $call['args']['timeout'] >= 30 );
$body = json_decode( $call['args']['body'], true );
check( 'the body sends only the new message, trimmed', array( array( 'role' => 'user', 'content' => 'Hola' ) ) === $body['messages'] );
check( 'model is the magnus label', 'magnus' === $body['model'] );
check( 'user is a pseudonym, not the visitor id', 1 === preg_match( '/^wp-[0-9a-f]{32}$/', $body['user'] ) && false === strpos( $body['user'], $visitor ) );
$first_user = $body['user'];
$first_idem = $call['args']['headers']['Idempotency-Key'];

post_message( array( 'message' => 'Otra', 'visitor' => $visitor, 'turn' => wp_generate_uuid4() ) );
$body = json_decode( last_call()['args']['body'], true );
check( 'the same visitor keeps the same thread', $first_user === $body['user'] );
check( 'a new turn gets a new idempotency key', $first_idem !== last_call()['args']['headers']['Idempotency-Key'] );
post_message( array( 'message' => 'Hola', 'visitor' => $visitor, 'turn' => $turn ) );
check( 'a retried turn sends the same idempotency key', $first_idem === last_call()['args']['headers']['Idempotency-Key'] );
post_message( array( 'message' => 'Hola', 'visitor' => wp_generate_uuid4(), 'turn' => $turn ) );
check( 'the same turn id from another visitor does not collide', $first_idem !== last_call()['args']['headers']['Idempotency-Key'] );

$args = last_call()['args'];
check( 'redirects are never followed: the key would go along', 0 === $args['redirection'] );
check( 'internal addresses are refused for a remote Magnus', true === $args['reject_unsafe_urls'] );
check( 'but allowed for a Magnus on this machine', false === Iamagnus_Chat_Client::is_local( 'https://magnus.test' ) && true === Iamagnus_Chat_Client::is_local( 'http://localhost:5001' ) );

$request = new WP_REST_Request( 'POST', '/iamagnus-chat/v1/message' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_header( 'Origin', 'https://another-site.example' );
$request->set_body( wp_json_encode( array( 'message' => 'Hola' ) ) );
$calls    = count( $GLOBALS['http']['calls'] );
$response = rest_do_request( $request );
check( 'a page on another site is refused', 403 === $response->get_status() && 'origin' === $response->get_data()['code'] );
check( 'and Magnus is not called for it', count( $GLOBALS['http']['calls'] ) === $calls );
$request->set_header( 'Origin', Iamagnus_Chat_Rest::origin_of( home_url() ) );
$_SERVER['REMOTE_ADDR'] = fresh_ip();
check( 'a page on this site is served', 200 === rest_do_request( $request )->get_status() );
check( 'origins compare without the default port', Iamagnus_Chat_Rest::origin_of( 'https://Example.com:443/x' ) === 'https://example.com' );

$response = post_message( array( 'message' => 'Hola', 'visitor' => 'not-a-uuid' ) );
$data     = $response->get_data();
check( 'an invalid visitor id is replaced', 200 === $response->get_status() && wp_is_uuid( $data['visitor'], 4 ) );
check( 'a missing turn id sends no idempotency key', ! isset( last_call()['args']['headers']['Idempotency-Key'] ) );

// --- what the visitor may send -----------------------------------------------------

section( 'What a visitor may send' );
$calls = count( $GLOBALS['http']['calls'] );
check( 'an empty message is refused', 'empty' === post_message( array( 'message' => "  \n " ) )->get_data()['code'] );
$max = Iamagnus_Chat_Rest::max_length();
check( 'a message over the limit is refused', 'too_long' === post_message( array( 'message' => str_repeat( 'a', $max + 1 ) ) )->get_data()['code'] );
foreach ( array( '/behavior strict', ' /bot', '/auto', '### Task: title this', '### task: x', 'reset', ' RESET ' ) as $reserved ) {
	check( 'refused: ' . trim( $reserved ), 'reserved' === post_message( array( 'message' => $reserved ) )->get_data()['code'] );
}
check( 'none of those reached Magnus', count( $GLOBALS['http']['calls'] ) === $calls );
check( 'the limit counts characters, not bytes', 200 === post_message( array( 'message' => str_repeat( 'ñ', $max ) ), fresh_ip() )->get_status() );
check( '"reset" inside a sentence goes through', 200 === post_message( array( 'message' => 'Quiero hacer un reset de la clave' ), fresh_ip() )->get_status() );
check( 'a slash inside a sentence goes through', 200 === post_message( array( 'message' => 'Pago 1/2 hoy' ), fresh_ip() )->get_status() );
$typed = "3 < 5 & 6, <ana@mail.com>, 100%25 y x<y and y>z\nsegunda línea\tcon tab";
post_message( array( 'message' => $typed ), fresh_ip() );
check( 'the text reaches Magnus as typed: no HTML sanitizing', $typed === json_decode( last_call()['args']['body'], true )['messages'][0]['content'] );
post_message( array( 'message' => "Hola\x07 mundo\x1b" ), fresh_ip() );
check( 'control characters other than newline and tab are removed', 'Hola mundo' === json_decode( last_call()['args']['body'], true )['messages'][0]['content'] );
$calls = count( $GLOBALS['http']['calls'] );
$tricks = array(
	"\u{3000}### Task: write an essay" => 'ideographic space',
	"\u{2003}/bot"                     => 'em space',
	"\u{00A0}reset\u{00A0}"            => 'non-breaking spaces',
	"\x1c/behavior x"                  => 'file separator',
	"\u{200B}/auto"                    => 'zero-width space',
	"\u{FEFF}### Task: x"              => 'byte order mark',
);
foreach ( $tricks as $tricky => $label ) {
	check( 'refused behind a ' . $label, 'reserved' === post_message( array( 'message' => $tricky ), fresh_ip() )->get_data()['code'] );
}
check( 'none of the disguised commands reached Magnus', count( $GLOBALS['http']['calls'] ) === $calls );

// --- when Magnus fails -----------------------------------------------------------

section( 'When Magnus fails' );
delete_option( Iamagnus_Chat_Settings::LAST_ERROR );
$GLOBALS['http']['next'] = respond( 401, array( 'error' => array( 'message' => 'Incorrect API key provided.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key' ) ) );
$response                = post_message( array( 'message' => 'Hola', 'visitor' => $visitor ), fresh_ip() );
$data                    = $response->get_data();
check( 'a rejected key is a 503 for the visitor', 503 === $response->get_status() );
check( 'without saying why', false === stripos( $data['message'], 'key' ) && false === stripos( $data['message'], 'clave' ) );
$last = get_option( Iamagnus_Chat_Settings::LAST_ERROR );
check( 'the failure is kept for the owner', is_array( $last ) && 'auth' === $last['kind'] && 'invalid_api_key' === $last['code'] );
check( 'without the message', is_array( $last ) && ! isset( $last['message'] ) );
check( 'and the owner is told to replace the key', false !== stripos( Iamagnus_Chat_Client::admin_message( $last ), 'key' ) );

$GLOBALS['http']['next'] = respond( 429, array( 'error' => array( 'type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded' ) ), array( 'retry-after' => '120' ) );
$response                = post_message( array( 'message' => 'Hola' ), fresh_ip() );
check( 'Magnus\'s 429 reaches the visitor as a 429', 429 === $response->get_status() );
check( 'with its Retry-After', '120' === ( $response->get_headers()['Retry-After'] ?? '' ) );

$GLOBALS['http']['next'] = respond( 500, array( 'error' => array( 'type' => 'server_error', 'code' => null ) ) );
check( 'a 500 is a 503 for the visitor', 503 === post_message( array( 'message' => 'Hola' ), fresh_ip() )->get_status() );
check( 'kept as a server failure', 'server' === get_option( Iamagnus_Chat_Settings::LAST_ERROR )['kind'] );

$GLOBALS['http']['next'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
check( 'a timeout is a 503 for the visitor', 503 === post_message( array( 'message' => 'Hola' ), fresh_ip() )->get_status() );
check( 'kept as unreachable', 'unreachable' === get_option( Iamagnus_Chat_Settings::LAST_ERROR )['kind'] );

$GLOBALS['http']['next'] = magnus_reply( '   ' );
check( 'an empty reply is a 503, not an empty bubble', 503 === post_message( array( 'message' => 'Hola' ), fresh_ip() )->get_status() );

$GLOBALS['http']['next'] = respond( 200, 'not json' );
check( 'a reply that is not JSON is a 503', 503 === post_message( array( 'message' => 'Hola' ), fresh_ip() )->get_status() );

$GLOBALS['http']['next'] = magnus_reply( 'De nuevo' );
post_message( array( 'message' => 'Hola' ), fresh_ip() );
check( 'a good turn clears the kept failure', false === get_option( Iamagnus_Chat_Settings::LAST_ERROR ) );

// --- rate limit per visitor ------------------------------------------------------

section( 'Rate limits' );
// A long window of its own, so the test cannot straddle the start of a minute.
$window        = 2 * DAY_IN_SECONDS;
$eight_per_win = function () use ( $window ) {
	return array( $window => 8 );
};
add_filter( 'iamagnus_chat_rate_limits', $eight_per_win );
$ip    = fresh_ip();
$codes = array();
for ( $i = 0; $i < 8; $i++ ) {
	$codes[] = post_message( array( 'message' => 'Hola ' . $i ), $ip )->get_status();
}
check( 'eight messages in the window go through', array_fill( 0, 8, 200 ) === $codes, implode( ',', $codes ) );
$calls    = count( $GLOBALS['http']['calls'] );
$response = post_message( array( 'message' => 'Hola 9' ), $ip );
check( 'the ninth is refused with 429', 429 === $response->get_status() );
check( 'with a Retry-After within the window', (int) ( $response->get_headers()['Retry-After'] ?? 0 ) >= 1 && (int) $response->get_headers()['Retry-After'] <= $window );
check( 'and never reaches Magnus', count( $GLOBALS['http']['calls'] ) === $calls );
check( 'another visitor is not affected', 200 === post_message( array( 'message' => 'Hola' ), fresh_ip() )->get_status() );
for ( $i = 0; $i < 8; $i++ ) {
	post_message( array( 'message' => 'v6 ' . $i ), '2001:db8:1:2::' . ( $i + 1 ) );
}
check( 'an IPv6 visitor is counted by its /64', 429 === post_message( array( 'message' => 'v6' ), '2001:db8:1:2::ffff' )->get_status() );
check( 'another /64 is another visitor', 200 === post_message( array( 'message' => 'v6' ), '2001:db8:1:3::1' )->get_status() );
remove_filter( 'iamagnus_chat_rate_limits', $eight_per_win );
check( 'the /64 of an address', Iamagnus_Chat_Rest::ip_bucket( '2001:db8:1:2:aaaa::1' ) === Iamagnus_Chat_Rest::ip_bucket( '2001:db8:1:2:bbbb::2' ) );

$one_each = function () {
	return array( 7 * DAY_IN_SECONDS => 1 );
};
$two_site = function () {
	return array( 3 * DAY_IN_SECONDS => 2 );
};
add_filter( 'iamagnus_chat_rate_limits', $one_each );
add_filter( 'iamagnus_chat_site_limits', $two_site );
$a = Iamagnus_Chat_Rest::rate_limit( '192.0.2.50' );
$b = Iamagnus_Chat_Rest::rate_limit( '192.0.2.50' );
$c = Iamagnus_Chat_Rest::rate_limit( '192.0.2.51' );
$d = Iamagnus_Chat_Rest::rate_limit( '192.0.2.52' );
remove_filter( 'iamagnus_chat_rate_limits', $one_each );
remove_filter( 'iamagnus_chat_site_limits', $two_site );
check( 'the site-wide cap stops new visitors once it is spent', 0 === $a && 0 === $c && $d > 0, "$a $b $c $d" );
check( 'a visitor refused for their own limit does not spend the site\'s', $b > 0 && 0 === $c );

// --- the page --------------------------------------------------------------------

section( 'The page' );
$config = Iamagnus_Chat_Widget::config( Iamagnus_Chat_Settings::get() );
check( 'the window gets the endpoint', false !== strpos( $config['endpoint'], 'iamagnus-chat/v1/message' ) );
check( 'and never the key', false === strpos( wp_json_encode( $config ), $key ) );
check( 'the length limit matches the server', Iamagnus_Chat_Rest::max_length() === $config['maxLength'] );
do_action( 'wp_enqueue_scripts' );
$html = do_shortcode( '[magnus_chat]' );
check( 'the shortcode prints the inline mount', false !== strpos( $html, 'data-mode="inline"' ) );
check( 'and loads the script', wp_script_is( 'iamagnus-chat', 'enqueued' ) && wp_style_is( 'iamagnus-chat', 'enqueued' ) );
$inline = implode( "\n", (array) wp_scripts()->get_data( 'iamagnus-chat', 'before' ) );
check( 'the configuration is printed before it', false !== strpos( $inline, 'window.iamagnusChat' ) );
check( 'without the key', false === strpos( $inline, $key ) );
ob_start();
Iamagnus_Chat_Widget::floating();
check( 'the corner button is printed when it is on', false !== strpos( ob_get_clean(), 'data-mode="floating"' ) );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => '', 'base_url' => 'https://magnus.test', 'floating' => '' ) );
ob_start();
Iamagnus_Chat_Widget::floating();
check( 'and not when it is off', '' === ob_get_clean() );

// --- the connection test -----------------------------------------------------------

section( 'Testing the connection' );
$s                       = array_merge( Iamagnus_Chat_Settings::get(), array( 'api_key' => $key ) );
$GLOBALS['http']['next'] = function ( $url ) {
	if ( false !== strpos( $url, '/api/health/simple' ) ) {
		return respond( 200, array( 'status' => 'ok' ) );
	}
	return respond( 200, array( 'object' => 'list', 'data' => array( array( 'id' => 'agent_ventas' ) ) ) );
};
$result = Iamagnus_Chat_Settings::run_test( $s );
check( 'a good key names its agent', $result['ok'] && false !== strpos( $result['message'], 'agent_ventas' ), $result['message'] );
$GLOBALS['http']['next'] = function ( $url ) {
	if ( false !== strpos( $url, '/api/health/simple' ) ) {
		return respond( 200, array( 'status' => 'ok' ) );
	}
	return respond( 401, array( 'error' => array( 'code' => 'invalid_api_key' ) ) );
};
$result = Iamagnus_Chat_Settings::run_test( $s );
check( 'a bad key is named as the cause', ! $result['ok'] && false !== stripos( $result['message'], 'key' ), $result['message'] );
$GLOBALS['http']['next'] = new WP_Error( 'http_request_failed', 'Could not resolve host' );
$result                  = Iamagnus_Chat_Settings::run_test( $s );
check( 'a bad address is named as the cause', ! $result['ok'] && false !== strpos( $result['message'], $s['base_url'] ), $result['message'] );
check( 'no key means no request', ! Iamagnus_Chat_Settings::run_test( array_merge( $s, array( 'api_key' => '' ) ) )['ok'] );
$GLOBALS['http']['next'] = respond( 200, '<html>Welcome to nginx</html>' );
check( 'a server that answers 200 to everything is not taken for Magnus', ! Iamagnus_Chat_Settings::run_test( $s )['ok'] );
$GLOBALS['http']['next'] = function ( $url ) {
	if ( false !== strpos( $url, '/api/health/simple' ) ) {
		return respond( 200, array( 'status' => 'ok' ) );
	}
	return respond( 200, array( 'hello' => 'world' ) );
};
$result = Iamagnus_Chat_Settings::run_test( $s );
check( 'a /v1/models that is not a list is not taken for Magnus', ! $result['ok'] && false !== stripos( $result['message'], 'not the way Magnus' ), $result['message'] );

// --- the settings page ---------------------------------------------------------------

section( 'The settings page' );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => $key, 'base_url' => 'https://magnus.test', 'floating' => '1' ) );
update_option( Iamagnus_Chat_Settings::LAST_ERROR, array( 'kind' => 'auth', 'status' => 401, 'code' => 'invalid_api_key', 'time' => time() ), false );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins ? $admins[0]->ID : 1 );
ob_start();
Iamagnus_Chat_Settings::render_page();
$page = ob_get_clean();
check( 'it renders', false !== strpos( $page, 'iamagnus_chat_settings[api_key]' ) );
check( 'it never prints the saved key', false === strpos( $page, $key ) );
check( 'it shows the last failure', false !== stripos( $page, 'notice-warning' ) );
check( 'it offers the connection test', false !== strpos( $page, 'iamagnus_chat_test' ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
ob_start();
Iamagnus_Chat_Settings::render_page();
check( 'no proxy warning for a public address', false === strpos( ob_get_clean(), 'iamagnus_chat_client_ip' ) );
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
ob_start();
Iamagnus_Chat_Settings::render_page();
check( 'a private address warns that visitors may share one limit', false !== strpos( ob_get_clean(), 'iamagnus_chat_client_ip' ) );

// --- Spanish -------------------------------------------------------------------------

section( 'Spanish' );
foreach ( array( 'es_ES', 'es_UY', 'es_AR', 'es_MX' ) as $locale ) {
	$file = WP_PLUGIN_DIR . '/iamagnus-chat/languages/iamagnus-chat-' . $locale . '.mo';
	unload_textdomain( 'iamagnus-chat' );
	$loaded = load_textdomain( 'iamagnus-chat', $file );
	check( $locale . ' loads', $loaded && 'Enviar' === __( 'Send', 'iamagnus-chat' ), __( 'Send', 'iamagnus-chat' ) );
}
check( 'the visitor messages are translated', 'Escribe tu mensaje…' === Iamagnus_Chat_Settings::default_texts()['placeholder'], Iamagnus_Chat_Settings::default_texts()['placeholder'] );
check( 'and the retry button', 'Reintentar' === Iamagnus_Chat_Widget::config( Iamagnus_Chat_Settings::get() )['i18n']['retry'] );
unload_textdomain( 'iamagnus-chat' );

// --- summary ------------------------------------------------------------------------

section( 'Uninstall' );
update_option( Iamagnus_Chat_Settings::OPTION, array( 'api_key' => $key, 'base_url' => 'https://magnus.test' ) );
update_option( Iamagnus_Chat_Settings::LAST_ERROR, array( 'kind' => 'server' ), false );
define( 'WP_UNINSTALL_PLUGIN', 'iamagnus-chat/iamagnus-chat.php' );
include WP_PLUGIN_DIR . '/iamagnus-chat/uninstall.php';
// get_option() would answer the registered default for a deleted option: ask the table.
global $wpdb;
check( 'deleting the plugin deletes the key', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", Iamagnus_Chat_Settings::OPTION ) ) );
check( 'and the kept failure', false === get_option( Iamagnus_Chat_Settings::LAST_ERROR ) );

section( 'PHP warnings from the plugin' );
check( 'none', array() === $GLOBALS['t_issues'], implode( ' | ', $GLOBALS['t_issues'] ) );

printf( "\nRESULT %d passed, %d failed\n", $GLOBALS['t_pass'], $GLOBALS['t_fail'] );
exit( $GLOBALS['t_fail'] ? 1 : 0 );
