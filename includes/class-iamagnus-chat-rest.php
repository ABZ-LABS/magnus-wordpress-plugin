<?php
/**
 * The endpoint the chat window talks to.
 *
 * @package IamagnusChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/iamagnus-chat/v1/message
 *
 * The browser never talks to Magnus: it talks to this endpoint, and this
 * endpoint adds the key on the server. The route is public, like any chat on
 * a public site, so it carries its own limits: message length, reserved
 * inputs, and a per-visitor rate.
 */
final class Iamagnus_Chat_Rest {

	const NAMESPACE_V1 = 'iamagnus-chat/v1';
	const MAX_LENGTH   = 2000;

	/**
	 * Hooks the route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public static function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// A public chat: anyone visiting the site may write. Abuse is
				// bounded by the limits in handle(), not by a login.
				'permission_callback' => '__return_true',
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
					'visitor' => array(
						'type'     => 'string',
						'required' => false,
					),
					'turn'    => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * One message from a visitor, one answer from the agent.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$settings = Iamagnus_Chat_Settings::get();
		if ( '' === $settings['api_key'] ) {
			return self::refuse( 503, 'not_configured', __( 'The chat is not available right now.', 'iamagnus-chat' ) );
		}

		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( '' === $message ) {
			return self::refuse( 400, 'empty', __( 'Write a message first.', 'iamagnus-chat' ) );
		}
		$max = self::max_length();
		if ( self::length( $message ) > $max ) {
			return self::refuse(
				400,
				'too_long',
				/* translators: %d: maximum number of characters */
				sprintf( __( 'Messages can be up to %d characters long.', 'iamagnus-chat' ), $max )
			);
		}
		if ( self::is_reserved( $message ) ) {
			return self::refuse( 400, 'reserved', __( 'That message can’t be sent as it is. Please write it inside a sentence.', 'iamagnus-chat' ) );
		}

		$wait = self::rate_limit( self::client_ip() );
		if ( $wait > 0 ) {
			return self::refuse( 429, 'rate_limited', Iamagnus_Chat_Client::visitor_message( array( 'kind' => 'rate_limited' ) ), $wait );
		}

		$visitor = (string) $request->get_param( 'visitor' );
		if ( ! wp_is_uuid( $visitor, 4 ) ) {
			$visitor = wp_generate_uuid4();
		}
		$turn        = (string) $request->get_param( 'turn' );
		$idempotency = wp_is_uuid( $turn ) ? self::derive( 'turn', $visitor . '|' . $turn ) : '';

		$client = new Iamagnus_Chat_Client( $settings['base_url'], $settings['api_key'] );
		$result = $client->chat( $message, self::user_for( $visitor ), $idempotency );

		if ( $result['ok'] ) {
			if ( false !== get_option( Iamagnus_Chat_Settings::LAST_ERROR ) ) {
				delete_option( Iamagnus_Chat_Settings::LAST_ERROR );
			}
			return new WP_REST_Response(
				array(
					'reply'   => $result['reply'],
					'visitor' => $visitor,
				),
				200
			);
		}

		self::record_failure( $result );
		$status = 'rate_limited' === $result['kind'] ? 429 : 503;
		return self::refuse( $status, $result['kind'], Iamagnus_Chat_Client::visitor_message( $result ), $result['retry_after'], $visitor );
	}

	/**
	 * Inputs Magnus treats as commands rather than as a message for the agent.
	 *
	 * A message starting with "/" can be an operator or debug command
	 * (/bot, /auto, /behavior), "### Task:" skips the agent and spends the
	 * key on a plain model call, and a lone "reset" ends a human takeover.
	 * None of that is for an anonymous visitor.
	 *
	 * @param string $message The visitor's message.
	 * @return bool
	 */
	public static function is_reserved( $message ) {
		$m = ltrim( (string) $message );
		if ( '' === $m ) {
			return false;
		}
		if ( '/' === $m[0] ) {
			return true;
		}
		if ( 0 === stripos( $m, '### task:' ) ) {
			return true;
		}
		return 'reset' === strtolower( trim( $m ) );
	}

	/**
	 * The thread key sent to Magnus: stable for a browser, meaningless outside
	 * this site, and not the visitor's identity.
	 *
	 * @param string $visitor The random id the browser keeps.
	 * @return string
	 */
	public static function user_for( $visitor ) {
		return 'wp-' . substr( self::derive( 'visitor', $visitor ), 0, 32 );
	}

	/**
	 * A keyed hash, so the values sent to Magnus cannot be traced back.
	 *
	 * @param string $purpose What the hash is for, so two uses never collide.
	 * @param string $value   The value.
	 * @return string 64 hex characters.
	 */
	private static function derive( $purpose, $value ) {
		return hash_hmac( 'sha256', $purpose . '|' . $value, wp_salt( 'auth' ) );
	}

	/**
	 * Fixed-window counters per visitor IP, kept in transients.
	 *
	 * Approximate under concurrency (transients are not atomic), which is
	 * enough to keep one visitor from spending the site's key. Magnus also
	 * limits the key: 120 messages per hour by default.
	 *
	 * @param string $ip The visitor's IP address.
	 * @return int Seconds to wait, 0 when the message may go through.
	 */
	public static function rate_limit( $ip ) {
		/**
		 * Messages allowed per window, as seconds => messages.
		 *
		 * @param array $limits Default: 8 per minute and 60 per hour.
		 */
		$limits = apply_filters(
			'iamagnus_chat_rate_limits',
			array(
				MINUTE_IN_SECONDS => 8,
				HOUR_IN_SECONDS   => 60,
			)
		);
		$id      = substr( self::derive( 'ip', $ip ), 0, 24 );
		$now     = time();
		$entries = array();
		foreach ( (array) $limits as $window => $max ) {
			$window = (int) $window;
			$key    = 'iamagnus_chat_rl_' . $window . '_' . $id;
			$entry  = get_transient( $key );
			if ( ! is_array( $entry ) || ! isset( $entry['n'], $entry['until'] ) || $entry['until'] <= $now ) {
				$entry = array(
					'n'     => 0,
					'until' => $now + $window,
				);
			}
			if ( $entry['n'] >= (int) $max ) {
				return max( 1, $entry['until'] - $now );
			}
			$entries[ $key ] = $entry;
		}
		foreach ( $entries as $key => $entry ) {
			++$entry['n'];
			set_transient( $key, $entry, max( 1, $entry['until'] - $now ) );
		}
		return 0;
	}

	/**
	 * The visitor's IP as PHP sees it. Behind a proxy or a CDN every visitor
	 * may share one address: the filter lets the site supply the right one.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'iamagnus_chat_client_ip', $ip );
	}

	/**
	 * The longest message accepted, in characters.
	 *
	 * @return int
	 */
	public static function max_length() {
		return max( 1, (int) apply_filters( 'iamagnus_chat_max_message_length', self::MAX_LENGTH ) );
	}

	/**
	 * Length in characters, not bytes.
	 *
	 * @param string $text The text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Keeps the last failure, without the message, for the settings page.
	 *
	 * @param array $failure A failure from the client.
	 */
	private static function record_failure( $failure ) {
		update_option(
			Iamagnus_Chat_Settings::LAST_ERROR,
			array(
				'kind'   => $failure['kind'],
				'status' => $failure['status'],
				'code'   => $failure['code'],
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * A refusal the chat window can show as it is.
	 *
	 * @param int         $status      HTTP status.
	 * @param string      $code        Machine-readable reason.
	 * @param string      $message     What the visitor reads.
	 * @param int|null    $retry_after Seconds, when known.
	 * @param string|null $visitor     The visitor id, when one was settled.
	 * @return WP_REST_Response
	 */
	private static function refuse( $status, $code, $message, $retry_after = null, $visitor = null ) {
		$body = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $visitor ) {
			$body['visitor'] = $visitor;
		}
		$response = new WP_REST_Response( $body, $status );
		if ( $retry_after ) {
			$response->header( 'Retry-After', (string) (int) $retry_after );
		}
		return $response;
	}
}
