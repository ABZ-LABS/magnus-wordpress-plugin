<?php
/**
 * Where the chat appears: a button in a corner, or inside a page.
 *
 * @package IamagnusChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the mount points and the configuration the chat window reads.
 *
 * The configuration carries the endpoint and the texts, never the key.
 */
final class Iamagnus_Chat_Widget {

	const HANDLE    = 'iamagnus-chat';
	const SHORTCODE = 'magnus_chat';

	/**
	 * Hooks the assets, the shortcode and the corner button.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating' ) );
	}

	/**
	 * Registers the script and the style; they load only where the chat shows.
	 */
	public static function register_assets() {
		wp_register_style( self::HANDLE, IAMAGNUS_CHAT_URL . 'assets/chat.css', array(), IAMAGNUS_CHAT_VERSION );
		wp_register_script(
			self::HANDLE,
			IAMAGNUS_CHAT_URL . 'assets/chat.js',
			array(),
			IAMAGNUS_CHAT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * What the chat window needs to know. Public so the tests can check that
	 * the key is never part of it.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function config( $s ) {
		return array(
			'endpoint'    => esc_url_raw( rest_url( Iamagnus_Chat_Rest::NAMESPACE_V1 . '/message' ) ),
			'title'       => $s['title'],
			'welcome'     => $s['welcome'],
			'placeholder' => $s['placeholder'],
			'color'       => $s['color'],
			'position'    => $s['position'],
			'maxLength'   => Iamagnus_Chat_Rest::max_length(),
			'i18n'        => array(
				'open'            => __( 'Open the chat', 'iamagnus-chat' ),
				'close'           => __( 'Close the chat', 'iamagnus-chat' ),
				'send'            => __( 'Send', 'iamagnus-chat' ),
				'newConversation' => __( 'New conversation', 'iamagnus-chat' ),
				'typing'          => __( 'Writing…', 'iamagnus-chat' ),
				'error'           => __( 'I can’t answer right now. Please try again in a few minutes.', 'iamagnus-chat' ),
				'offline'         => __( 'No connection. Check your internet and try again.', 'iamagnus-chat' ),
				/* translators: %d: maximum number of characters */
				'tooLong'         => __( 'Messages can be up to %d characters long.', 'iamagnus-chat' ),
				'you'             => __( 'You', 'iamagnus-chat' ),
				'assistant'       => __( 'Assistant', 'iamagnus-chat' ),
			),
		);
	}

	/**
	 * Loads the assets once, with the configuration in front of the script.
	 */
	private static function enqueue() {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script(
			self::HANDLE,
			'window.iamagnusChat = ' . wp_json_encode( self::config( Iamagnus_Chat_Settings::get() ) ) . ';',
			'before'
		);
	}

	/**
	 * [magnus_chat]: the chat inside the page.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! Iamagnus_Chat_Settings::is_configured() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="iamagnus-chat-notice">' . esc_html__( 'Magnus Chat: paste the API key in Settings → Magnus Chat to show the chat here. Visitors see nothing until then.', 'iamagnus-chat' ) . '</p>';
			}
			return '';
		}
		self::enqueue();
		return '<div class="iamagnus-chat-mount" data-mode="inline"></div>';
	}

	/**
	 * The button in a corner of every page, when it is turned on.
	 */
	public static function floating() {
		$s = Iamagnus_Chat_Settings::get();
		if ( empty( $s['floating'] ) || '' === $s['api_key'] ) {
			return;
		}
		/**
		 * Whether to show the corner button on this page.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'iamagnus_chat_show_floating', true ) ) {
			return;
		}
		self::enqueue();
		echo '<div class="iamagnus-chat-mount" data-mode="floating"></div>';
	}
}
