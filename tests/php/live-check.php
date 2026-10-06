<?php
/**
 * Against a real Magnus, inside a real WordPress:
 *
 *     bin/test.sh live                        # a made-up key: checks the path and the refusals
 *     MAGNUS_API_KEY=... bin/test.sh live     # a real key: one real turn, from the REST route
 *
 * With a real key it runs ONE turn: it spends tokens and shows up in that
 * organization's conversations as a visitor called wp-<hash>.
 *
 * @package IamagnusChat
 */

// phpcs:disable -- a test script, not plugin code.

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$base = defined( 'MAGNUS_BASE_URL' ) ? MAGNUS_BASE_URL : 'https://app.iamagnus.com';
$key  = defined( 'MAGNUS_API_KEY' ) && '' !== MAGNUS_API_KEY ? MAGNUS_API_KEY : '';
$real = '' !== $key;
$fail = 0;

function line( $ok, $text ) {
	global $fail;
	if ( ! $ok ) {
		++$fail;
	}
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $text . "\n";
}

activate_plugin( 'iamagnus-chat/iamagnus-chat.php' );
Iamagnus_Chat_Settings::register();
update_option(
	Iamagnus_Chat_Settings::OPTION,
	array(
		'api_key'  => $real ? $key : 'magnus_sys_made_up_for_the_live_check',
		'base_url' => $base,
		'floating' => '1',
	)
);
echo 'target ' . $base . ' with ' . ( $real ? 'a real key' : 'a made-up key' ) . "\n";

$client = new Iamagnus_Chat_Client( $base, 'unused' );
line( $client->health()['ok'], 'the address answers the health check' );

$test = Iamagnus_Chat_Settings::run_test( Iamagnus_Chat_Settings::get() );
echo '        connection test says: ' . $test['message'] . "\n";
line( $real ? $test['ok'] : ( ! $test['ok'] && false !== stripos( $test['message'], 'rejected' ) ), $real ? 'the key is accepted' : 'the made-up key is rejected, and named as the cause' );

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$request                = new WP_REST_Request( 'POST', '/iamagnus-chat/v1/message' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body(
	wp_json_encode(
		array(
			'message' => 'Hola, ¿qué puedes hacer?',
			'visitor' => wp_generate_uuid4(),
			'turn'    => wp_generate_uuid4(),
		)
	)
);
$start    = microtime( true );
$response = rest_do_request( $request );
$data     = $response->get_data();
$seconds  = round( microtime( true ) - $start, 1 );

if ( $real ) {
	line( 200 === $response->get_status(), 'a real turn answers 200 (' . $seconds . ' s)' );
	echo '        reply: ' . ( isset( $data['reply'] ) ? mb_substr( $data['reply'], 0, 200 ) : wp_json_encode( $data ) ) . "\n";
} else {
	line( 503 === $response->get_status(), 'a turn with a rejected key is a 503 for the visitor' );
	$last = get_option( Iamagnus_Chat_Settings::LAST_ERROR );
	line( is_array( $last ) && 'auth' === $last['kind'] && 'invalid_api_key' === $last['code'], 'and the owner sees why: ' . wp_json_encode( $last ) );
}

printf( "\nRESULT %s\n", $fail ? "$fail failed" : 'everything passed' );
exit( $fail ? 1 : 0 );
