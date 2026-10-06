<?php
/**
 * Removes what the plugin stored when it is deleted (not when it is
 * deactivated), on every site of a network: each site keeps its own API key.
 * The rate-limit counters are transients and expire on their own.
 *
 * @package IamagnusChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options on the current site.
 */
function iamagnus_chat_uninstall_site() {
	delete_option( 'iamagnus_chat_settings' );
	delete_option( 'iamagnus_chat_last_error' );
}

if ( is_multisite() ) {
	$iamagnus_chat_offset = 0;
	do {
		$iamagnus_chat_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $iamagnus_chat_offset,
			)
		);
		foreach ( $iamagnus_chat_sites as $iamagnus_chat_site ) {
			switch_to_blog( $iamagnus_chat_site );
			iamagnus_chat_uninstall_site();
			restore_current_blog();
		}
		$iamagnus_chat_offset += 100;
	} while ( count( $iamagnus_chat_sites ) === 100 );
} else {
	iamagnus_chat_uninstall_site();
}
