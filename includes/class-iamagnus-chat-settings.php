<?php
/**
 * The settings page: the key, the address of Magnus, and how the chat looks.
 *
 * @package IamagnusChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the settings in one option and renders Settings → Magnus Chat.
 *
 * The API key is written here and read only on the server. Nothing in this
 * class prints it back: the field is always empty, and leaving it empty keeps
 * the saved key.
 */
final class Iamagnus_Chat_Settings {

	const OPTION       = 'iamagnus_chat_settings';
	const LAST_ERROR   = 'iamagnus_chat_last_error';
	const PAGE         = 'iamagnus-chat';
	const DEFAULT_BASE = 'https://app.iamagnus.com';
	const DEFAULT_TINT = '#4f46e5';

	/**
	 * Hooks the page, the setting and the connection test.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		// On init, not admin_init: the sanitizer must also run when the option
		// is written from WP-CLI or another plugin, not only from this page.
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
		add_action( 'admin_post_iamagnus_chat_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IAMAGNUS_CHAT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * What is stored when nothing was saved yet. The three texts stay empty:
	 * an empty text means "the default, in the visitor's language".
	 *
	 * @return array
	 */
	public static function stored_defaults() {
		return array(
			'api_key'     => '',
			'base_url'    => self::DEFAULT_BASE,
			'title'       => '',
			'welcome'     => '',
			'placeholder' => '',
			'floating'    => true,
			'position'    => 'right',
			'color'       => self::DEFAULT_TINT,
		);
	}

	/**
	 * The texts used when the owner left a field empty.
	 *
	 * @return array
	 */
	public static function default_texts() {
		return array(
			'title'       => __( 'Chat with us', 'iamagnus-chat' ),
			'welcome'     => __( 'Hi! How can I help you?', 'iamagnus-chat' ),
			'placeholder' => __( 'Type your message…', 'iamagnus-chat' ),
		);
	}

	/**
	 * The settings as the rest of the plugin uses them, with every text filled in.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$s     = array_merge( self::stored_defaults(), $saved );
		foreach ( self::default_texts() as $key => $text ) {
			if ( '' === trim( (string) $s[ $key ] ) ) {
				$s[ $key ] = $text;
			}
		}
		return $s;
	}

	/**
	 * Whether a key is saved. Without one the chat is not shown anywhere.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get()['api_key'];
	}

	/**
	 * Settings → Magnus Chat.
	 */
	public static function add_page() {
		add_options_page(
			__( 'Magnus Chat', 'iamagnus-chat' ),
			__( 'Magnus Chat', 'iamagnus-chat' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers the option with its sanitizer.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Cleans what the form sent. It also runs a second time on the already
	 * clean array when WordPress creates the option, so it must be idempotent.
	 *
	 * @param mixed $input The submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = get_option( self::OPTION, array() );
		$out     = array_merge( self::stored_defaults(), is_array( $current ) ? $current : array() );
		$input   = is_array( $input ) ? $input : array();

		// The key: an empty field keeps the saved one, the checkbox deletes it.
		$new_key = isset( $input['api_key'] ) ? sanitize_text_field( trim( (string) $input['api_key'] ) ) : '';
		if ( ! empty( $input['clear_key'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' !== $new_key ) {
			$out['api_key'] = $new_key;
		}

		$base  = isset( $input['base_url'] ) ? (string) $input['base_url'] : '';
		$valid = self::validate_base_url( '' === trim( $base ) ? self::DEFAULT_BASE : $base );
		if ( null === $valid ) {
			add_settings_error(
				self::OPTION,
				'base_url',
				__( 'The Magnus address must start with https:// (http:// only for localhost). The previous address was kept.', 'iamagnus-chat' )
			);
		} else {
			// A saved key only goes where it was saved for. Otherwise anyone
			// who may change this page could point it at their own server and
			// press "Test the connection" to receive a key they cannot read.
			if ( '' === $new_key && '' !== $out['api_key']
				&& Iamagnus_Chat_Rest::origin_of( $valid ) !== Iamagnus_Chat_Rest::origin_of( $out['base_url'] ) ) {
				$out['api_key'] = '';
				add_settings_error(
					self::OPTION,
					'key_cleared',
					__( 'The saved key was deleted because the Magnus address changed. Paste the key again for the new address.', 'iamagnus-chat' ),
					'warning'
				);
			}
			$out['base_url'] = $valid;
		}

		$out['title']       = isset( $input['title'] ) ? self::limit( sanitize_text_field( $input['title'] ), 80 ) : '';
		$out['placeholder'] = isset( $input['placeholder'] ) ? self::limit( sanitize_text_field( $input['placeholder'] ), 120 ) : '';
		$out['welcome']     = isset( $input['welcome'] ) ? self::limit( sanitize_textarea_field( $input['welcome'] ), 500 ) : '';
		$out['floating']    = ! empty( $input['floating'] );
		$out['position']    = ( isset( $input['position'] ) && 'left' === $input['position'] ) ? 'left' : 'right';
		$color              = isset( $input['color'] ) ? sanitize_hex_color( $input['color'] ) : '';
		$out['color']       = $color ? $color : self::DEFAULT_TINT;

		return $out;
	}

	/**
	 * The server root of Magnus, or null when it is not acceptable.
	 *
	 * The key travels in every request, so plain http is refused except on
	 * the same machine. A pasted `/v1` is removed: the client needs the root,
	 * because the health check lives outside `/v1`.
	 *
	 * @param string $url What the owner typed.
	 * @return string|null
	 */
	public static function validate_base_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$url   = esc_url_raw( $url, array( 'https', 'http' ) );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$local  = in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1' ), true );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local ) ) {
			return null;
		}
		$url = untrailingslashit( preg_replace( '#[?\#].*$#', '', $url ) );
		return untrailingslashit( preg_replace( '#/v1$#i', '', $url ) );
	}

	/**
	 * Cuts a text to a number of characters, not bytes.
	 *
	 * @param string $text The text.
	 * @param int    $max  Characters kept.
	 * @return string
	 */
	private static function limit( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * A "Settings" link next to "Deactivate" in the plugin list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'iamagnus-chat' ) . '</a>' );
		return $links;
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = __( 'When you use the chat on this site, what you write is sent to Magnus, the service that runs our virtual assistant, so that it can answer. Magnus keeps the conversation in order to continue it. Your name, email address and IP address are not sent with the messages: each browser is identified by a random code stored in it. Please do not write sensitive personal data in the chat.', 'iamagnus-chat' );
		wp_add_privacy_policy_content( __( 'Magnus Chat', 'iamagnus-chat' ), wp_kses_post( wpautop( $text ) ) );
	}

	/**
	 * Checks the address and the key when the owner asks for it.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iamagnus-chat' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'iamagnus_chat_test' );
		set_transient( 'iamagnus_chat_test_' . get_current_user_id(), self::run_test( self::get() ), 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * The address first and the key second, so each failure names itself.
	 *
	 * @param array $s Settings.
	 * @return array {ok: bool, message: string}
	 */
	public static function run_test( $s ) {
		if ( '' === $s['api_key'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no API key yet. Paste it above and save.', 'iamagnus-chat' ),
			);
		}
		$client = new Iamagnus_Chat_Client( $s['base_url'], $s['api_key'] );
		$health = $client->health();
		if ( ! $health['ok'] ) {
			return array(
				'ok'      => false,
				/* translators: %s: the Magnus address, such as https://app.iamagnus.com */
				'message' => sprintf( __( 'Magnus does not answer at %s. Check the address.', 'iamagnus-chat' ), $s['base_url'] ),
			);
		}
		$models = $client->models();
		if ( ! $models['ok'] ) {
			return array(
				'ok'      => false,
				'message' => Iamagnus_Chat_Client::admin_message( $models ),
			);
		}
		delete_option( self::LAST_ERROR );
		if ( empty( $models['agents'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The key works, but its organization has no agent to answer.', 'iamagnus-chat' ),
			);
		}
		return array(
			'ok'      => true,
			/* translators: %s: the agent (or agents) the key answers as */
			'message' => sprintf( __( 'Connected. This key answers as: %s.', 'iamagnus-chat' ), implode( ', ', $models['agents'] ) ),
		);
	}

	/**
	 * Settings → Magnus Chat.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$saved   = get_option( self::OPTION, array() );
		$saved   = array_merge( self::stored_defaults(), is_array( $saved ) ? $saved : array() );
		$texts   = self::default_texts();
		$has_key = '' !== $saved['api_key'];
		$name    = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Magnus Chat', 'iamagnus-chat' ); ?></h1>
			<?php self::render_notices( $has_key ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<h2><?php esc_html_e( 'Connection', 'iamagnus-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="iamagnus-chat-key"><?php esc_html_e( 'API key', 'iamagnus-chat' ); ?></label></th>
						<td>
							<input type="password" id="iamagnus-chat-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" spellcheck="false"
								placeholder="<?php echo esc_attr( $has_key ? __( 'Saved. Leave empty to keep it.', 'iamagnus-chat' ) : 'magnus_sys_…' ); ?>" />
							<p class="description"><?php esc_html_e( 'Create it in the Magnus dashboard, under API keys, for the agent that should answer on this site. It stays on this server: visitors never see it.', 'iamagnus-chat' ); ?></p>
							<?php if ( $has_key ) : ?>
								<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1" /> <?php esc_html_e( 'Delete the saved key', 'iamagnus-chat' ); ?></label></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="iamagnus-chat-base"><?php esc_html_e( 'Magnus address', 'iamagnus-chat' ); ?></label></th>
						<td>
							<input type="url" id="iamagnus-chat-base" name="<?php echo esc_attr( $name ); ?>[base_url]" value="<?php echo esc_attr( $saved['base_url'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Leave https://app.iamagnus.com unless Magnus gave you another address.', 'iamagnus-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Chat window', 'iamagnus-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="iamagnus-chat-title"><?php esc_html_e( 'Title', 'iamagnus-chat' ); ?></label></th>
						<td><input type="text" id="iamagnus-chat-title" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $saved['title'] ); ?>" placeholder="<?php echo esc_attr( $texts['title'] ); ?>" class="regular-text" maxlength="80" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="iamagnus-chat-welcome"><?php esc_html_e( 'Welcome message', 'iamagnus-chat' ); ?></label></th>
						<td>
							<textarea id="iamagnus-chat-welcome" name="<?php echo esc_attr( $name ); ?>[welcome]" rows="3" class="large-text" maxlength="500" placeholder="<?php echo esc_attr( $texts['welcome'] ); ?>"><?php echo esc_textarea( $saved['welcome'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown before the visitor writes. Empty fields use the text in gray, in the language of the site.', 'iamagnus-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="iamagnus-chat-placeholder"><?php esc_html_e( 'Text in the message box', 'iamagnus-chat' ); ?></label></th>
						<td><input type="text" id="iamagnus-chat-placeholder" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $saved['placeholder'] ); ?>" placeholder="<?php echo esc_attr( $texts['placeholder'] ); ?>" class="regular-text" maxlength="120" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Chat button', 'iamagnus-chat' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[floating]" value="1" <?php checked( ! empty( $saved['floating'] ) ); ?> /> <?php esc_html_e( 'Show a chat button in a corner of every page', 'iamagnus-chat' ); ?></label>
							<p>
								<label for="iamagnus-chat-position"><?php esc_html_e( 'Corner', 'iamagnus-chat' ); ?></label>
								<select id="iamagnus-chat-position" name="<?php echo esc_attr( $name ); ?>[position]">
									<option value="right" <?php selected( $saved['position'], 'right' ); ?>><?php esc_html_e( 'Bottom right', 'iamagnus-chat' ); ?></option>
									<option value="left" <?php selected( $saved['position'], 'left' ); ?>><?php esc_html_e( 'Bottom left', 'iamagnus-chat' ); ?></option>
								</select>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="iamagnus-chat-color"><?php esc_html_e( 'Color', 'iamagnus-chat' ); ?></label></th>
						<td><input type="color" id="iamagnus-chat-color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $saved['color'] ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Check the connection', 'iamagnus-chat' ); ?></h2>
			<p><?php esc_html_e( 'Asks Magnus whether the address answers and whether the saved key is accepted. It does not send any message to the agent.', 'iamagnus-chat' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="iamagnus_chat_test" />
				<?php wp_nonce_field( 'iamagnus_chat_test' ); ?>
				<?php submit_button( __( 'Test the connection', 'iamagnus-chat' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'The chat inside a page', 'iamagnus-chat' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: the shortcode */
					esc_html__( 'Add %s to a page or post to show the chat inside it, instead of in a corner. On that page the corner button is not shown.', 'iamagnus-chat' ),
					'<code>[magnus_chat]</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The result of the last test and the last failure a visitor ran into.
	 *
	 * @param bool $has_key Whether a key is saved.
	 */
	private static function render_notices( $has_key ) {
		$key  = 'iamagnus_chat_test_' . get_current_user_id();
		$test = get_transient( $key );
		if ( is_array( $test ) && isset( $test['message'] ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( ! empty( $test['ok'] ) ? 'success' : 'error' ),
				esc_html( $test['message'] )
			);
		}

		if ( ! $has_key ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Paste the API key to turn the chat on. Until then nothing is shown to visitors.', 'iamagnus-chat' ) . '</p></div>';
			return;
		}

		// Behind a proxy or a CDN, REMOTE_ADDR is the proxy: every visitor would
		// share one address and one set of limits.
		$ip = Iamagnus_Chat_Rest::client_ip();
		if ( '' !== $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: an IP address */
						__( 'This site sees your request as coming from %s, a private address: it is probably behind a proxy or a CDN. Then every visitor shares one address and one set of chat limits. Use the iamagnus_chat_client_ip filter to read the visitor’s real address.', 'iamagnus-chat' ),
						$ip
					)
				)
			);
		}

		$last = get_option( self::LAST_ERROR );
		if ( is_array( $last ) && isset( $last['time'] ) ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: date and time, 2: what failed */
						__( 'The last time a visitor wrote (%1$s), the chat could not answer: %2$s', 'iamagnus-chat' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ),
						Iamagnus_Chat_Client::admin_message( $last )
					)
				)
			);
		}
	}
}
