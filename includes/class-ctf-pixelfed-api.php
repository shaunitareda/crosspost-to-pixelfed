<?php
/**
 * Pixelfed / Mastodon-compatible REST API wrapper.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the Pixelfed (Mastodon-compatible) REST API.
 *
 * Endpoints used:
 *   POST /api/v1/apps                      – register OAuth application
 *   GET  /oauth/authorize                  – user authorises the app
 *   POST /oauth/token                      – exchange code for access token
 *   GET  /api/v1/accounts/verify_credentials – fetch account info
 *   POST /api/v1/media                     – upload media attachment
 *   POST /api/v1/statuses                  – publish a status
 */
class CTF_Pixelfed_API {

	/**
	 * Base URL of the Pixelfed instance (no trailing slash).
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Bearer access token.
	 *
	 * @var string|null
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string      $base_url Pixelfed instance URL.
	 * @param string|null $token    Optional OAuth access token.
	 */
	public function __construct( $base_url, $token = null ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->token    = $token;
	}

	/* ── App Registration ──────────────────────────────────────────────── */

	/**
	 * Register a new OAuth application with the instance.
	 *
	 * @param string $redirect_uri Callback URL registered with the app.
	 * @return array|WP_Error Array with client_id and client_secret on success.
	 */
	public function register_app( $redirect_uri ) {
		return $this->request(
			'POST',
			'/api/v1/apps',
			array(
				'client_name'   => 'WP Crosspost to Pixelfed',
				'redirect_uris' => $redirect_uri,
				'scopes'        => 'read write',
				'website'       => home_url(),
			),
			'form'
		);
	}

	/* ── OAuth helpers ─────────────────────────────────────────────────── */

	/**
	 * Build the /oauth/authorize URL to redirect the user to.
	 *
	 * @param string $client_id    OAuth client ID.
	 * @param string $redirect_uri Callback URL.
	 * @param string $state        CSRF state nonce.
	 * @return string Full authorisation URL.
	 */
	public function get_authorize_url( $client_id, $redirect_uri, $state = '' ) {
		return add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => 'read write',
				'state'         => $state,
			),
			$this->base_url . '/oauth/authorize'
		);
	}

	/**
	 * Exchange an authorisation code for an access token.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $redirect_uri  Callback URL (must match registration).
	 * @param string $code          Authorisation code from Pixelfed.
	 * @return array|WP_Error Array with access_token on success.
	 */
	public function get_access_token( $client_id, $client_secret, $redirect_uri, $code ) {
		// /oauth/token — standard OAuth 2.0, must be form-encoded.
		// Do NOT include 'scope'; Pixelfed rejects mismatches silently.
		return $this->request(
			'POST',
			'/oauth/token',
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
				'code'          => $code,
			),
			'form'
		);
	}

	/* ── Account ───────────────────────────────────────────────────────── */

	/**
	 * Fetch the authenticated user's account info.
	 *
	 * @return array|WP_Error Account data array on success.
	 */
	public function verify_credentials() {
		return $this->request( 'GET', '/api/v1/accounts/verify_credentials' );
	}

	/* ── Media & Statuses ──────────────────────────────────────────────── */

	/**
	 * Upload a local file as a media attachment.
	 *
	 * @param string $file_path   Absolute server path to the image file.
	 * @param string $description Alt text / image description.
	 * @return array|WP_Error Media object with id on success.
	 */
	public function upload_media( $file_path, $description = '' ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'File not found: ' . $file_path );
		}

		$mime     = wp_check_filetype( $file_path );
		$mime     = ! empty( $mime['type'] ) ? $mime['type'] : 'image/jpeg';
		$boundary = wp_generate_password( 24, false );

		// Read the file using WP_Filesystem.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$file_contents = $wp_filesystem->get_contents( $file_path );
		if ( false === $file_contents ) {
			return new WP_Error( 'file_read_error', 'Could not read file: ' . $file_path );
		}

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body .= $file_contents . "\r\n";

		if ( $description ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= "Content-Disposition: form-data; name=\"description\"\r\n\r\n";
			$body .= $description . "\r\n";
		}

		$body .= '--' . $boundary . "--\r\n";

		$headers                 = $this->auth_headers();
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		$response = wp_remote_post(
			$this->base_url . '/api/v1/media',
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 60,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Publish a status.
	 *
	 * @param string $status    Caption / text content.
	 * @param array  $media_ids Array of media attachment IDs to attach.
	 * @return array|WP_Error Status object on success.
	 */
	public function create_status( $status, $media_ids = array() ) {
		$body = array( 'status' => $status );
		foreach ( $media_ids as $id ) {
			$body['media_ids'][] = $id;
		}
		return $this->request( 'POST', '/api/v1/statuses', $body, 'json' );
	}

	/* ── Feed / Timeline ──────────────────────────────────────────────── */

	/**
	 * Fetch an account's public statuses, optionally filtered to images only.
	 *
	 * @param string $account_id Pixelfed account ID.
	 * @param int    $limit      Number of statuses to return (max 40).
	 * @param bool   $only_media Return only statuses with media attachments.
	 * @param string $max_id     Pagination cursor – return results older than this ID.
	 * @return array|WP_Error Array of status objects on success.
	 */
	public function get_account_statuses( $account_id, $limit = 12, $only_media = true, $max_id = '' ) {
		$params = array(
			'limit'      => min( absint( $limit ), 40 ),
			'only_media' => $only_media ? 'true' : 'false',
		);
		if ( $max_id ) {
			$params['max_id'] = $max_id;
		}
		return $this->request( 'GET', '/api/v1/accounts/' . rawurlencode( $account_id ) . '/statuses', $params );
	}

	/**
	 * Fetch the public timeline for a hashtag, filtered to image posts.
	 *
	 * @param string $hashtag Hashtag to search (without the # symbol).
	 * @param int    $limit   Number of statuses to return (max 40).
	 * @param bool   $only_media Return only statuses with media attachments.
	 * @param string $max_id  Pagination cursor – return results older than this ID.
	 * @return array|WP_Error Array of status objects on success.
	 */
	public function get_hashtag_timeline( $hashtag, $limit = 12, $only_media = true, $max_id = '' ) {
		$params = array(
			'limit'      => min( absint( $limit ), 40 ),
			'only_media' => $only_media ? 'true' : 'false',
		);
		if ( $max_id ) {
			$params['max_id'] = $max_id;
		}
		return $this->request( 'GET', '/api/v1/timelines/tag/' . rawurlencode( $hashtag ), $params );
	}

	/* ── HTTP helpers ──────────────────────────────────────────────────── */

	/**
	 * Build base request headers, including Bearer token if available.
	 *
	 * @return array HTTP headers array.
	 */
	private function auth_headers() {
		$headers = array( 'Accept' => 'application/json' );
		if ( $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}

	/**
	 * Make an HTTP request to the Pixelfed API.
	 *
	 * @param string $method       HTTP method: 'GET' or 'POST'.
	 * @param string $path         API path, e.g. '/api/v1/apps'.
	 * @param array  $params       Request parameters.
	 * @param string $content_type Body encoding: 'json' (default) or 'form'.
	 * @return array|WP_Error Decoded response array or WP_Error on failure.
	 */
	private function request( $method, $path, $params = array(), $content_type = 'json' ) {
		$url     = $this->base_url . $path;
		$headers = $this->auth_headers();
		$args    = array(
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( 'POST' === $method ) {
			if ( 'form' === $content_type ) {
				$headers['Content-Type'] = 'application/x-www-form-urlencoded';
				$args['body']            = http_build_query( $params );
			} else {
				$headers['Content-Type'] = 'application/json';
				$args['body']            = wp_json_encode( $params );
			}
			$args['headers'] = $headers;
			$response        = wp_remote_post( $url, $args );
		} else {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}
			$response = wp_remote_get( $url, $args );
		}

		return $this->parse_response( $response );
	}

	/**
	 * Parse a wp_remote_* response into an array or WP_Error.
	 *
	 * @param array|WP_Error $response Raw response from wp_remote_*.
	 * @return array|WP_Error Decoded body array or WP_Error.
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error'] ) ? $data['error'] : ( isset( $data['error_description'] ) ? $data['error_description'] : 'HTTP ' . $code );
			return new WP_Error(
				'api_error',
				$message,
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}

		return ! empty( $data ) ? $data : array();
	}
}
