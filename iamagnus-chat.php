<?php
/**
 * Plugin Name:       Magnus Chat
 * Plugin URI:        https://iamagnus.com
 * Description:       Puts a Magnus agent on your site: a chat window that answers your visitors through the Magnus API. The API key stays on your server.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Magnus
 * Author URI:        https://iamagnus.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       iamagnus-chat
 * Domain Path:       /languages
 *
 * @package IamagnusChat
 */

/*
 * Copyright 2026 Gonzalo Garategui
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'IAMAGNUS_CHAT_VERSION', '0.1.0' );
define( 'IAMAGNUS_CHAT_FILE', __FILE__ );
define( 'IAMAGNUS_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'IAMAGNUS_CHAT_URL', plugin_dir_url( __FILE__ ) );

require_once IAMAGNUS_CHAT_DIR . 'includes/class-iamagnus-chat-settings.php';
require_once IAMAGNUS_CHAT_DIR . 'includes/class-iamagnus-chat-client.php';
require_once IAMAGNUS_CHAT_DIR . 'includes/class-iamagnus-chat-rest.php';
require_once IAMAGNUS_CHAT_DIR . 'includes/class-iamagnus-chat-widget.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'iamagnus-chat', false, dirname( plugin_basename( IAMAGNUS_CHAT_FILE ) ) . '/languages' );
	}
);

Iamagnus_Chat_Settings::init();
Iamagnus_Chat_Rest::init();
Iamagnus_Chat_Widget::init();
