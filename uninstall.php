<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all plugin options and post meta.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'ctf_settings' );
delete_option( 'ctf_debug_log' );
delete_transient( 'ctf_settings_errors' );

// Remove per-post meta from every post using the WP meta API.
$meta_keys = array( '_ctf_posted', '_ctf_status_id', '_ctf_status_url' );
foreach ( $meta_keys as $key ) {
	delete_metadata( 'post', 0, $key, '', true );
}
