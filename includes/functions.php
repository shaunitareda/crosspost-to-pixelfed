<?php
/**
 * Global helper functions for Crosspost to Pixelfed.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ctf_log' ) ) {

	/**
	 * Append an entry to the debug log (stored in wp_options, max 500 entries).
	 *
	 * @param string $level   Severity: 'info', 'warning', 'error', or 'success'.
	 * @param string $message Human-readable message.
	 * @param array  $context Optional extra data to store alongside the message.
	 * @return void
	 */
	function ctf_log( $level, $message, $context = array() ) {
		$log = get_option( CTF_LOG_OPTION, array() );

		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => $message,
		);

		if ( ! empty( $context ) ) {
			$entry['context'] = $context;
		}

		array_unshift( $log, $entry );

		if ( count( $log ) > 500 ) {
			$log = array_slice( $log, 0, 500 );
		}

		update_option( CTF_LOG_OPTION, $log );
	}
}
