<?php
/**
 * Removes what the plugin stored when it is deleted (not when it is
 * deactivated). The rate-limit counters are transients and expire on their own.
 *
 * @package IamagnusChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'iamagnus_chat_settings' );
delete_option( 'iamagnus_chat_last_error' );
