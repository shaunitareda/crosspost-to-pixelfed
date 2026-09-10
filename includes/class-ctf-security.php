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
