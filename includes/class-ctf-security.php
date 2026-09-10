<?php
/**
 * Security helpers for Crosspost to Pixelfed.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redact sensitive values from debug-log messages before they are stored.
 *
 * @param mixed $new_value New option value.
 * @return mixed Redacted option value.
 */
function ctf_redact_debug_log_option( $new_value ) {
	if ( ! is_array( $new_value ) ) {
		return $new_value;
	}

	foreach ( $new_value as &$entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['message'] ) ) {
			continue;
		}

		$message = (string) $entry['message'];

		// JSON-style and query/form-style secrets.
		$message = preg_replace(
			'/(["\']?(?:client_secret|access_token|refresh_token|token|authorization)["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i',
			'$1[REDACTED]',
			$message
		);

		// Bearer credentials that may appear inside raw error responses.
		$message = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $message );

		$entry['message'] = $message;
	}
	unset( $entry );

	return $new_value;
}
add_filter( 'pre_update_option_' . CTF_LOG_OPTION, 'ctf_redact_debug_log_option', 10, 1 );

/**
 * Defense in depth: only administrators may submit the plugin settings form.
 * The settings page itself already requires manage_options, but admin_init runs
 * for every authenticated admin request.
 */
function ctf_secure_settings_save_capability() {
	if ( ! isset( $_POST['ctf_save_settings'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'crosspost-to-pixelfed' ) );
	}
}
add_action( 'admin_init', 'ctf_secure_settings_save_capability', 1 );

/**
 * Start output buffering only on the Pixelfed settings screen. This lets us
 * remove the stored access token from the rendered password field before the
 * response leaves WordPress, without changing the existing OAuth/save logic.
 */
function ctf_start_secure_settings_buffer() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'crosspost-to-pixelfed' !== $page ) {
		return;
	}
	ob_start( 'ctf_secure_settings_output' );
}
add_action( 'admin_init', 'ctf_start_secure_settings_buffer', 2 );

/**
 * Blank only the connected-account token field. The separate manual-token field
 * is already rendered blank by the plugin and remains untouched.
 *
 * @param string $html Buffered admin HTML.
 * @return string Sanitized admin HTML.
 */
function ctf_secure_settings_output( $html ) {
	return preg_replace_callback(
		'/<input\b(?=[^>]*\bid=["\']ctf_access_token["\'])[^>]*>/i',
		function ( $match ) {
			$field = $match[0];
			$field = preg_replace( '/\svalue=("[^"\n]*"|\'[^\'\n]*\')/i', ' value=""', $field, 1 );
			if ( false === stripos( $field, 'placeholder=' ) ) {
				$field = rtrim( $field, '>' ) . ' placeholder="' . esc_attr__( 'Leave blank to keep existing token', 'crosspost-to-pixelfed' ) . '">';
			}
			return $field;
		},
		$html
	);
}
