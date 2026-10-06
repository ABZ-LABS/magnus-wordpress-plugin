<?php
/**
 * The calls to Magnus, through the WordPress HTTP API.
 *
 * @package IamagnusChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * A small client for the three Magnus endpoints the plugin uses.
 *
 * It uses wp_remote_*() rather than a PHP SDK on purpose: plugins that bundle
 * HTTP libraries collide with each other, and the WordPress API already
 * honours the host's proxy and certificate settings.
 *
 * Every method returns an array instead of throwing. A failure carries a
 * `kind` the rest of the plugin decides on:
 * unreachable, auth, rate_limited, request, server, empty or unexpected.
 */
final class Iamagnus_Chat_Client {

	/**
	 * Seconds to wait for a turn. An agent turn can take several seconds and
	 * may call tools; a visitor waits less than this before giving up.
	 */
	const TURN_TIMEOUT = 45;

	/**
	 * The server root, without a trailing slash and without `/v1`.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * The System API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * @param string $base_url The server root, e.g. https://app.iamagnus.com.
	 * @param string $api_key  The System API key.
	 */
	public function __construct( $base_url, $api_key ) {
		$this->base = untrailingslashit( (string) $base_url );
		$this->key  = (string) $api_key;
	}

	/**
	 * Whether the address answers. Needs no key, so a wrong address and a
	 * wrong key do not look alike.
	 *
	 * @return array {ok: bool, kind?: string, status?: int, detail?: string}
	 */
	public function health() {
		$response = wp_remote_get(
			$this->base . '/api/health/simple',
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => self::user_agent() ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::failure( 'unreachable', 0, null, null, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return 200 === $status ? array( 'ok' => true ) : self::failure( 'unreachable', $status, null );
	}

	/**
	 * The agents the key can reach. A key bound to an agent lists exactly one.
	 *
	 * @return array {ok: true, agents: string[]} or a failure.
	 */
	public function models() {
		$response = wp_remote_get(
			$this->base . '/v1/models',
			array(
				'timeout' => 15,
				'headers' => $this->headers(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::failure( 'unreachable', 0, null, null, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status ) {
			return self::error_from_status( $status, $body, $response );
		}
		$agents = array();
		if ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) {
			foreach ( $body['data'] as $model ) {
				if ( is_array( $model ) && isset( $model['id'] ) && is_string( $model['id'] ) ) {
					$agents[] = $model['id'];
				}
			}
		}
		return array(
			'ok'     => true,
			'agents' => $agents,
		);
	}

	/**
	 * One turn of the conversation.
	 *
	 * Only the new message is sent: Magnus keeps the conversation on its side,
	 * one thread per (key, user, agent), and reads only the last user message.
	 *
	 * @param string $message         What the visitor wrote.
	 * @param string $user            The pseudonymous visitor id: the thread key.
	 * @param string $idempotency_key Unique per turn, so a retry replays instead of running twice.
	 * @return array {ok: true, reply: string, trace_id: ?string} or a failure.
	 */
	public function chat( $message, $user, $idempotency_key = '' ) {
		$headers                 = $this->headers();
		$headers['Content-Type'] = 'application/json';
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$payload = array(
			// A key bound to an agent ignores this label; an org-wide key runs its default agent.
			'model'    => 'magnus',
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => (string) $message,
				),
			),
			'user'     => (string) $user,
		);
		$response = wp_remote_post(
			$this->base . '/v1/chat/completions',
			array(
				'timeout'     => self::TURN_TIMEOUT,
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);
		return self::parse_chat( $response );
	}

	/**
	 * Reads a chat response. Public so the tests can feed it canned responses.
	 *
	 * @param array|WP_Error $response What wp_remote_post() returned.
	 * @return array
	 */
	public static function parse_chat( $response ) {
		if ( is_wp_error( $response ) ) {
			return self::failure( 'unreachable', 0, null, null, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status ) {
			return self::error_from_status( $status, $body, $response );
		}
		$text = '';
		if ( is_array( $body ) && isset( $body['choices'][0]['message']['content'] ) && is_string( $body['choices'][0]['message']['content'] ) ) {
			$text = trim( $body['choices'][0]['message']['content'] );
		}
		if ( '' === $text ) {
			return self::failure( 'empty', 200, null );
		}
		$trace = null;
		if ( is_array( $body ) && isset( $body['magnus']['trace_id'] ) && is_string( $body['magnus']['trace_id'] ) ) {
			$trace = $body['magnus']['trace_id'];
		}
		return array(
			'ok'       => true,
			'reply'    => $text,
			'trace_id' => $trace,
		);
	}

	/**
	 * Maps an HTTP error to a failure kind, keeping Magnus's error code.
	 *
	 * @param int            $status   HTTP status.
	 * @param mixed          $body     Decoded JSON body, if any.
	 * @param array|WP_Error $response The raw response, for headers.
	 * @return array
	 */
	private static function error_from_status( $status, $body, $response ) {
		$code = null;
		if ( is_array( $body ) && isset( $body['error']['code'] ) && is_string( $body['error']['code'] ) ) {
			$code = $body['error']['code'];
		}
		if ( 401 === $status || 403 === $status ) {
			return self::failure( 'auth', $status, $code );
		}
		if ( 429 === $status ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return self::failure( 'rate_limited', $status, $code, $retry > 0 ? $retry : null );
		}
		if ( $status >= 500 ) {
			return self::failure( 'server', $status, $code );
		}
		if ( $status >= 400 ) {
			return self::failure( 'request', $status, $code );
		}
		return self::failure( 'unexpected', $status, $code );
	}

	/**
	 * @param string      $kind        What went wrong.
	 * @param int         $status      HTTP status, 0 when there was none.
	 * @param string|null $code        Magnus's error code.
	 * @param int|null    $retry_after Seconds, when Magnus said.
	 * @param string|null $detail      Transport error text, for the admin only.
	 * @return array
	 */
	private static function failure( $kind, $status, $code, $retry_after = null, $detail = null ) {
		return array(
			'ok'          => false,
			'kind'        => $kind,
			'status'      => (int) $status,
			'code'        => $code,
			'retry_after' => $retry_after,
			'detail'      => $detail,
		);
	}

	/**
	 * What a visitor reads when a turn fails. Never the cause: a visitor
	 * cannot fix a key, and saying it is broken helps nobody.
	 *
	 * @param array $failure A failure.
	 * @return string
	 */
	public static function visitor_message( $failure ) {
		if ( isset( $failure['kind'] ) && 'rate_limited' === $failure['kind'] ) {
			return __( 'There are many questions right now. Please try again in a minute.', 'iamagnus-chat' );
		}
		return __( 'I can’t answer right now. Please try again in a few minutes.', 'iamagnus-chat' );
	}

	/**
	 * What the site owner reads: the cause and what to do about it.
	 *
	 * @param array $failure A failure.
	 * @return string
	 */
	public static function admin_message( $failure ) {
		$kind   = isset( $failure['kind'] ) ? $failure['kind'] : 'unexpected';
		$status = isset( $failure['status'] ) ? (int) $failure['status'] : 0;
		$code   = isset( $failure['code'] ) && is_string( $failure['code'] ) ? $failure['code'] : '';

		switch ( $kind ) {
			case 'unreachable':
				return __( 'Magnus could not be reached. Check the address and that this server can make outgoing HTTPS requests.', 'iamagnus-chat' );
			case 'auth':
				if ( 'organization_deactivated' === $code ) {
					return __( 'Magnus accepted the key, but its organization is deactivated.', 'iamagnus-chat' );
				}
				if ( 'no_organization' === $code ) {
					return __( 'The key does not belong to any organization. Create a System API key in the Magnus dashboard.', 'iamagnus-chat' );
				}
				return __( 'Magnus rejected the API key. Create a new one in the Magnus dashboard and paste it here.', 'iamagnus-chat' );
			case 'rate_limited':
				return __( 'Magnus is limiting this key: too many messages in the last hour. Ask Magnus for a higher limit if your site needs it.', 'iamagnus-chat' );
			case 'server':
				/* translators: %d: HTTP status code */
				return sprintf( __( 'Magnus failed (HTTP %d). It usually passes; if it does not, contact Magnus.', 'iamagnus-chat' ), $status );
			case 'empty':
				return __( 'Magnus answered with an empty message.', 'iamagnus-chat' );
			default:
				return sprintf(
					/* translators: 1: HTTP status code, 2: Magnus error code */
					__( 'Magnus refused the request (HTTP %1$d %2$s).', 'iamagnus-chat' ),
					$status,
					$code
				);
		}
	}

	/**
	 * Headers every authenticated call carries.
	 *
	 * @return array
	 */
	private function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->key,
			'Accept'        => 'application/json',
			'User-Agent'    => self::user_agent(),
		);
	}

	/**
	 * Identifies the plugin and its version in Magnus's logs.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return 'iamagnus-chat/' . IAMAGNUS_CHAT_VERSION . ' WordPress/' . get_bloginfo( 'version' );
	}
}
