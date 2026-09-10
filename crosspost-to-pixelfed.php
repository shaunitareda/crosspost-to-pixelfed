<?php
/**
 * Plugin Name:  Crosspost to Pixelfed
 * Plugin URI:   https://github.com/yourname/crosspost-to-pixelfed
 * Description:  Automatically crossposts image posts from your WordPress blog to any Pixelfed instance.
 * Version:      1.1.0
 * Author:       evecodes
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  crosspost-to-pixelfed
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CTF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CTF_VERSION', '1.1.0' );
define( 'CTF_OPTION_KEY', 'ctf_settings' );
define( 'CTF_LOG_OPTION', 'ctf_debug_log' );

require_once CTF_PLUGIN_DIR . 'includes/functions.php';
require_once CTF_PLUGIN_DIR . 'includes/class-ctf-admin-debug.php';
require_once CTF_PLUGIN_DIR . 'includes/class-ctf-pixelfed-api.php';
require_once CTF_PLUGIN_DIR . 'includes/class-ctf-admin-settings.php';
require_once CTF_PLUGIN_DIR . 'includes/class-ctf-crosspost.php';
require_once CTF_PLUGIN_DIR . 'includes/class-ctf-shortcode.php';

add_action( 'plugins_loaded', 'ctf_init' );

/**
 * Initialise plugin classes.
 *
 * @return void
 */
function ctf_init() {
	new CTF_Admin_Settings();
	new CTF_Admin_Debug();
	new CTF_Crosspost();
	new CTF_Shortcode();
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ctf_plugin_action_links' );

/**
 * Add Settings and Debug Log links on the Plugins page.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function ctf_plugin_action_links( $links ) {
	$new = array(
		'<a href="' . admin_url( 'options-general.php?page=crosspost-to-pixelfed' ) . '">' . esc_html__( 'Settings', 'crosspost-to-pixelfed' ) . '</a>',
		'<a href="' . admin_url( 'tools.php?page=ctf-debug-log' ) . '">' . esc_html__( 'Debug Log', 'crosspost-to-pixelfed' ) . '</a>',
	);
	return array_merge( $new, $links );
}

register_activation_hook( __FILE__, 'ctf_activate' );

/**
 * Plugin activation: seed default options.
 *
 * @return void
 */
function ctf_activate() {
	if ( false === get_option( CTF_OPTION_KEY ) ) {
		add_option(
			CTF_OPTION_KEY,
			array(
				'instance_url'  => 'https://pixelfed.social',
				'client_id'     => '',
				'client_secret' => '',
				'access_token'  => '',
				'account_info'  => array(),
				'auto_post'     => '1',
				'post_caption'  => '{title} {url}',
				'post_types'    => array( 'post' ),
			)
		);
	}
	if ( false === get_option( CTF_LOG_OPTION ) ) {
		add_option( CTF_LOG_OPTION, array() );
	}
}

register_deactivation_hook( __FILE__, 'ctf_deactivate' );

/**
 * Plugin deactivation hook (intentionally empty – settings kept until uninstall).
 *
 * @return void
 */
function ctf_deactivate() {
	// Settings are intentionally preserved on deactivation; cleaned on uninstall.
}
