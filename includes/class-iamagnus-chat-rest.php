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
 * a public site, so it carries its own limits: where the request comes from,
 * message length, reserved inputs, a per-visitor rate and a site-wide rate.
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

		// WordPress answers CORS for any origin, so without this check another
		// site could put this chat, and this site's key, behind its own page.
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && ! self::origin_allowed( $origin ) ) {
			return self::refuse( 403, 'origin', __( 'The chat is not available here.', 'iamagnus-chat' ) );
		}

		$message = self::normalize_message( $request->get_param( 'message' ) );
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
	 * The message as it goes to Magnus.
	 *
	 * The body is JSON and the text is shown with textContent, never as HTML,
	 * so nothing here is an HTML sanitizer: "3 < 5 & 6" must reach the agent as
	 * typed. What goes: invalid UTF-8, control characters other than newline and
	 * tab, and every kind of Unicode space or invisible character at the ends,
	 * which Magnus also strips before it looks for its reserved inputs.
	 *
	 * @param mixed $raw What the browser sent.
	 * @return string
	 */
	public static function normalize_message( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$text = wp_check_invalid_utf8( $raw, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text );
		if ( null === $text ) {
			return '';
		}
		$text = preg_replace( '/^[\p{Z}\p{C}\s]+|[\p{Z}\p{C}\s]+$/u', '', $text );
		return null === $text ? '' : $text;
	}

	/**
	 * Inputs Magnus treats as commands rather than as a message for the agent.
	 *
	 * A message starting with "/" can be an operator or debug command
	 * (/bot, /auto, /behavior), "### Task:" skips the agent and spends the
	 * key on a plain model call, and a lone "reset" ends a human takeover.
	 * None of that is for an anonymous visitor. Expects a normalized message.
	 *
	 * @param string $message The visitor's message, after normalize_message().
	 * @return bool
	 */
	public static function is_reserved( $message ) {
		$m = (string) $message;
		if ( '' === $m ) {
			return false;
		}
		if ( '/' === $m[0] ) {
			return true;
		}
		if ( 0 === stripos( $m, '### task:' ) ) {
			return true;
		}
		return 'reset' === strtolower( $m );
	}

	/**
	 * Whether a browser on this origin may use the chat: this site's own
	 * address, or one added with the iamagnus_chat_allowed_origins filter.
	 *
	 * @param string $origin The Origin header.
	 * @return bool
	 */
	public static function origin_allowed( $origin ) {
		$allowed = array( self::origin_of( home_url() ), self::origin_of( site_url() ) );
		/**
		 * Origins (scheme://host[:port]) whose pages may use the chat.
		 *
		 * @param string[] $allowed Default: the site's home and WordPress addresses.
		 */
		$allowed = array_map( array( __CLASS__, 'origin_of' ), (array) apply_filters( 'iamagnus_chat_allowed_origins', $allowed ) );
		return in_array( self::origin_of( $origin ), $allowed, true );
	}

	/**
	 * scheme://host[:port], lowercase, without the default port.
	 *
	 * @param string $url A URL or an origin.
	 * @return string
	 */
	public static function origin_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$plain  = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
		return $scheme . '://' . strtolower( $parts['host'] ) . ( $port && ! $plain ? ':' . $port : '' );
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
	 * Fixed windows per visitor and for the whole site.
	 *
	 * Per visitor, so one person cannot spend the key; for the whole site, so
	 * many people together cannot either, even after Magnus raises the key's
	 * own limit (120 messages per hour by default). With a persistent object
	 * cache the counters are atomic; with transients they are approximate
	 * under bursts, which is enough for a chat.
	 *
	 * @param string $ip The visitor's IP address.
	 * @return int Seconds to wait, 0 when the message may go through.
	 */
	public static function rate_limit( $ip ) {
		/**
		 * Messages allowed per visitor, as seconds => messages.
		 *
		 * @param array $limits Default: 8 per minute and 60 per hour.
		 */
		$per_visitor = apply_filters(
			'iamagnus_chat_rate_limits',
			array(
				MINUTE_IN_SECONDS => 8,
				HOUR_IN_SECONDS   => 60,
			)
		);
		$wait = self::spend( 'ip|' . self::ip_bucket( $ip ), (array) $per_visitor );
		if ( $wait > 0 ) {
			// Refused per visitor: the site-wide budget is not touched, so one
			// visitor hammering the chat does not use it up for everyone.
			return $wait;
		}
		/**
		 * Messages allowed for the whole site, as seconds => messages.
		 *
		 * @param array $limits Default: 100 per hour, below the key's 120.
		 */
		$site = apply_filters( 'iamagnus_chat_site_limits', array( HOUR_IN_SECONDS => 100 ) );
		return self::spend( 'site', (array) $site );
	}

	/**
	 * Counts one message in each window of a bucket.
	 *
	 * @param string $name   The bucket: a visitor or the whole site.
	 * @param array  $limits seconds => messages.
	 * @return int Seconds until the fullest window reopens, 0 when all have room.
	 */
	private static function spend( $name, $limits ) {
		$now  = time();
		$wait = 0;
		foreach ( $limits as $window => $max ) {
			$window = (int) $window;
			if ( $window < 1 ) {
				continue;
			}
			$slot = (int) floor( $now / $window );
			$key  = 'iamagnus_chat_rl_' . substr( md5( $name . '|' . $window . '|' . $slot ), 0, 20 );
			if ( self::count( $key, $window ) > (int) $max ) {
				$wait = max( $wait, ( $slot + 1 ) * $window - $now );
			}
		}
		return $wait > 0 ? max( 1, $wait ) : 0;
	}

	/**
	 * Adds one to a window's counter and returns the new value.
	 *
	 * @param string $key    Counter key.
	 * @param int    $window Seconds the counter lives.
	 * @return int
	 */
	private static function count( $key, $window ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'iamagnus_chat', $window );
			$n = wp_cache_incr( $key, 1, 'iamagnus_chat' );
			if ( false !== $n ) {
				return (int) $n;
			}
		}
		$n = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, $window );
		return $n;
	}

	/**
	 * The address a limit is counted on. An IPv6 visitor usually controls a
	 * whole /64, so the /64 is the visitor; counting each address would hand
	 * them billions of fresh buckets.
	 *
	 * @param string $ip An IP address.
	 * @return string
	 */
	public static function ip_bucket( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
			}
		}
		return (string) $ip;
	}

	/**
	 * The visitor's IP as PHP sees it. Behind a proxy or a CDN every visitor
	 * may share one address: the filter lets the site supply the right one.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The visitor's IP address, for the per-visitor limits.
		 *
		 * @param string $ip Default: REMOTE_ADDR.
		 */
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
