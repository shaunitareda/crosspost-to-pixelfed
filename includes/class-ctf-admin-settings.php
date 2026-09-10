<?php
/**
 * Settings page and OAuth flow for Crosspost to Pixelfed.
 *
 * Redirect URI uses wp-admin/admin-post.php?action=ctf_oauth_callback
 * (single query param) to avoid & encoding issues with Pixelfed OAuth.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Settings page and handles the Pixelfed OAuth flow.
 */
class CTF_Admin_Settings {

	/**
	 * Cached plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * URL of the plugin settings page.
	 *
	 * @var string
	 */
	private $settings_url;

	/**
	 * OAuth redirect URI registered with Pixelfed.
	 *
	 * @var string
	 */
	private $oauth_redirect_uri;

	/**
	 * Constructor – wire up all hooks.
	 */
	public function __construct() {
		$this->settings           = get_option( CTF_OPTION_KEY, array() );
		$this->settings_url       = admin_url( 'options-general.php?page=crosspost-to-pixelfed' );
		$this->oauth_redirect_uri = admin_url( 'admin-post.php' ) . '?action=ctf_oauth_callback';

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_ctf_oauth_callback', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_init', array( $this, 'handle_form_save' ) );
		add_action( 'admin_init', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_init', array( $this, 'handle_register_app' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/* ── Menu ─────────────────────────────────────────────────────────── */

	/**
	 * Register the Settings sub-page under Settings.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Crosspost to Pixelfed', 'crosspost-to-pixelfed' ),
			__( 'Crosspost to Pixelfed', 'crosspost-to-pixelfed' ),
			'manage_options',
			'crosspost-to-pixelfed',
			array( $this, 'render_page' )
		);
	}

	/* ── Assets ───────────────────────────────────────────────────────── */

	/**
	 * Enqueue admin CSS and JS on plugin pages only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'settings_page_crosspost-to-pixelfed', 'tools_page_ctf-debug-log' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'ctf-admin', CTF_PLUGIN_URL . 'assets/admin.css', array(), CTF_VERSION );
		wp_enqueue_script( 'ctf-admin', CTF_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), CTF_VERSION, true );
	}

	/* ── Save settings ────────────────────────────────────────────────── */

	/**
	 * Process the main settings form submission.
	 *
	 * @return void
	 */
	public function handle_form_save() {
		if ( ! isset( $_POST['ctf_save_settings'], $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ctf_save_settings' ) ) {
			wp_die( 'Nonce check failed.' );
		}

		$old      = get_option( CTF_OPTION_KEY, array() );
		$instance = rtrim( esc_url_raw( wp_unslash( isset( $_POST['ctf_instance_url'] ) ? $_POST['ctf_instance_url'] : 'https://pixelfed.social' ) ), '/' );

		if ( isset( $old['instance_url'] ) && $old['instance_url'] !== $instance ) {
			$old['client_id']     = '';
			$old['client_secret'] = '';
			$old['access_token']  = '';
			$old['account_info']  = array();
			$old['redirect_uri']  = '';
			ctf_log( 'info', 'Instance URL changed – OAuth credentials cleared.' );
		}

		$new = array_merge(
			$old,
			array(
				'instance_url' => $instance,
				'auto_post'    => isset( $_POST['ctf_auto_post'] ) ? '1' : '0',
				'post_caption' => sanitize_text_field( wp_unslash( isset( $_POST['ctf_post_caption'] ) ? $_POST['ctf_post_caption'] : '{title} {url}' ) ),
				'post_types'   => isset( $_POST['ctf_post_types'] ) && is_array( $_POST['ctf_post_types'] )
					? array_map( 'sanitize_key', wp_unslash( $_POST['ctf_post_types'] ) )
					: array( 'post' ),
			)
		);

		$manual_token = sanitize_text_field( wp_unslash( isset( $_POST['ctf_access_token'] ) ? $_POST['ctf_access_token'] : '' ) );
		if ( $manual_token && ! preg_match( '/^[•\*]+$/', $manual_token ) ) {
			$new['access_token'] = $manual_token;
			$api = new CTF_Pixelfed_API( $new['instance_url'], $new['access_token'] );
			$acc = $api->verify_credentials();
			if ( is_wp_error( $acc ) ) {
				ctf_log( 'error', 'Manual token verify failed: ' . $acc->get_error_message() );
				add_settings_error(
					'ctf',
					'token_invalid',
					'Token saved but credentials could not be verified: ' . esc_html( $acc->get_error_message() ),
					'warning'
				);
			} else {
				$new['account_info'] = $this->extract_account_info( $acc );
				ctf_log( 'info', 'Manual token accepted. Account: @' . ( isset( $acc['username'] ) ? $acc['username'] : '?' ) );
			}
		}

		update_option( CTF_OPTION_KEY, $new );
		$this->settings = $new;
		add_settings_error( 'ctf', 'saved', 'Settings saved.', 'success' );
	}

	/* ── Register OAuth App ───────────────────────────────────────────── */

	/**
	 * Register a new OAuth app with Pixelfed and redirect the user to authorise it.
	 *
	 * @return void
	 */
	public function handle_register_app() {
		if ( ! isset( $_POST['ctf_register_app'], $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ctf_register_app' ) ) {
			wp_die( 'Nonce check failed.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$settings     = get_option( CTF_OPTION_KEY, array() );
		$instance_url = rtrim(
			esc_url_raw( wp_unslash( isset( $_POST['ctf_instance_url_reg'] ) ? $_POST['ctf_instance_url_reg'] : ( isset( $settings['instance_url'] ) ? $settings['instance_url'] : 'https://pixelfed.social' ) ) ),
			'/'
		);
		$redirect_uri = $this->oauth_redirect_uri;

		ctf_log( 'info', 'Registering app on ' . $instance_url . '. Redirect URI: ' . $redirect_uri );

		$api    = new CTF_Pixelfed_API( $instance_url );
		$result = $api->register_app( $redirect_uri );

		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			$raw = isset( $result->get_error_data()['body'] ) ? $result->get_error_data()['body'] : '';
			ctf_log( 'error', 'App registration failed: ' . $msg . ( $raw ? ' | ' . $raw : '' ) );
			set_transient(
				'ctf_settings_errors',
				array(
					array(
						'setting' => 'ctf',
						'code'    => 'reg_fail',
						'message' => 'App registration failed: ' . esc_html( $msg ),
						'type'    => 'error',
					),
				),
				60
			);
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		ctf_log( 'info', 'App registration response keys: ' . implode( ', ', array_keys( $result ) ) );

		$client_id     = isset( $result['client_id'] ) ? $result['client_id'] : '';
		$client_secret = isset( $result['client_secret'] ) ? $result['client_secret'] : '';

		if ( ! $client_id || ! $client_secret ) {
			ctf_log( 'error', 'Missing client_id/client_secret. Full response: ' . wp_json_encode( $result ) );
			set_transient(
				'ctf_settings_errors',
				array(
					array(
						'setting' => 'ctf',
						'code'    => 'reg_empty',
						'message' => 'Pixelfed returned an incomplete response (no client_id or client_secret). Check the Debug Log.',
						'type'    => 'error',
					),
				),
				60
			);
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		$oauth_state = wp_create_nonce( 'ctf_oauth_state' );
		set_transient( 'ctf_oauth_state_' . get_current_user_id(), $oauth_state, 10 * MINUTE_IN_SECONDS );

		$settings['instance_url']  = $instance_url;
		$settings['client_id']     = $client_id;
		$settings['client_secret'] = $client_secret;
		$settings['redirect_uri']  = $redirect_uri;
		$settings['access_token']  = '';
		$settings['account_info']  = array();
		update_option( CTF_OPTION_KEY, $settings );
		$this->settings = $settings;

		ctf_log( 'info', 'App registered. client_id=' . $client_id );

		$authorize_url = $api->get_authorize_url( $client_id, $redirect_uri, $oauth_state );
		ctf_log( 'info', 'Redirecting user to Pixelfed for authorisation.' );

		// External redirect to Pixelfed is intentional.
		wp_redirect( $authorize_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/* ── OAuth callback ───────────────────────────────────────────────── */

	/**
	 * Handle the OAuth callback from Pixelfed (admin-post.php?action=ctf_oauth_callback).
	 *
	 * @return void
	 */
	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You must be logged in as an admin to complete the Pixelfed connection.' );
		}

		$state          = sanitize_text_field( wp_unslash( isset( $_GET['state'] ) ? $_GET['state'] : '' ) );
		$stored_state   = get_transient( 'ctf_oauth_state_' . get_current_user_id() );
		$state_is_valid = $stored_state && wp_verify_nonce( $state, 'ctf_oauth_state' );
		delete_transient( 'ctf_oauth_state_' . get_current_user_id() );

		if ( ! $state_is_valid ) {
			ctf_log( 'error', 'OAuth callback failed state/nonce verification. Possible CSRF attempt.' );
			set_transient(
				'ctf_settings_errors',
				array(
					array(
						'setting' => 'ctf',
						'code'    => 'state_invalid',
						'message' => 'Security check failed (invalid state). Please try connecting again.',
						'type'    => 'error',
					),
				),
				60
			);
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		if ( isset( $_GET['error'] ) ) {
			$msg = sanitize_text_field( wp_unslash( isset( $_GET['error_description'] ) ? $_GET['error_description'] : $_GET['error'] ) );
			ctf_log( 'error', 'OAuth denied by user: ' . $msg );
			set_transient(
				'ctf_settings_errors',
				array(
					array(
						'setting' => 'ctf',
						'code'    => 'oauth_denied',
						'message' => 'Pixelfed authorisation was denied: ' . esc_html( $msg ),
						'type'    => 'error',
					),
				),
				60
			);
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		$code = sanitize_text_field( wp_unslash( isset( $_GET['code'] ) ? $_GET['code'] : '' ) );
		if ( ! $code ) {
			ctf_log( 'error', 'OAuth callback reached but no code present in URL.' );
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		$settings      = get_option( CTF_OPTION_KEY, array() );
		$instance_url  = isset( $settings['instance_url'] ) ? $settings['instance_url'] : 'https://pixelfed.social';
		$client_id     = isset( $settings['client_id'] ) ? $settings['client_id'] : '';
		$client_secret = isset( $settings['client_secret'] ) ? $settings['client_secret'] : '';
		$redirect_uri  = isset( $settings['redirect_uri'] ) ? $settings['redirect_uri'] : $this->oauth_redirect_uri;

		ctf_log( 'info', 'OAuth callback. code=' . substr( $code, 0, 8 ) . '… redirect_uri=' . $redirect_uri );

		$api        = new CTF_Pixelfed_API( $instance_url );
		$token_data = $api->get_access_token( $client_id, $client_secret, $redirect_uri, $code );

		if ( is_wp_error( $token_data ) ) {
			$msg = $token_data->get_error_message();
			$raw = isset( $token_data->get_error_data()['body'] ) ? $token_data->get_error_data()['body'] : '';
			ctf_log( 'error', 'Token exchange failed: ' . $msg . ( $raw ? ' | ' . $raw : '' ) );
			set_transient(
				'ctf_settings_errors',
				array(
					array(
						'setting' => 'ctf',
						'code'    => 'token_fail',
						'message' => 'Token exchange failed: ' . esc_html( $msg ) . ( $raw ? ' (detail: ' . esc_html( wp_strip_all_tags( $raw ) ) . ')' : '' ),
						'type'    => 'error',
					),
				),
				60
			);
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		$access_token = isset( $token_data['access_token'] ) ? $token_data['access_token'] : '';
		if ( ! $access_token ) {
			ctf_log( 'error', 'No access_token in response: ' . wp_json_encode( $token_data ) );
			set_transient(
				'ctf_settings_errors',
				array(
					array(
						'setting' => 'ctf',
						'code'    => 'token_empty',
						'message' => 'Pixelfed responded successfully but returned no access token.',
						'type'    => 'error',
					),
				),
				60
			);
			wp_safe_redirect( $this->settings_url );
			exit;
		}

		$settings['access_token'] = $access_token;

		$api2 = new CTF_Pixelfed_API( $instance_url, $access_token );
		$acc  = $api2->verify_credentials();
		if ( ! is_wp_error( $acc ) ) {
			$settings['account_info'] = $this->extract_account_info( $acc );
			ctf_log( 'success', '✅ Connected as @' . ( isset( $acc['username'] ) ? $acc['username'] : '?' ) );
		} else {
			ctf_log( 'warning', 'Token obtained but verify_credentials failed: ' . $acc->get_error_message() );
		}

		update_option( CTF_OPTION_KEY, $settings );

		set_transient(
			'ctf_settings_errors',
			array(
				array(
					'setting' => 'ctf',
					'code'    => 'connected',
					'message' => '✅ Successfully connected to Pixelfed!',
					'type'    => 'success',
				),
			),
			60
		);
		wp_safe_redirect( $this->settings_url );
		exit;
	}

	/* ── Disconnect ───────────────────────────────────────────────────── */

	/**
	 * Clear stored credentials when the user clicks Disconnect.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! isset( $_GET['ctf_disconnect'] ) ) {
			return;
		}
		check_admin_referer( 'ctf_disconnect' );

		$settings                  = get_option( CTF_OPTION_KEY, array() );
		$settings['access_token']  = '';
		$settings['client_id']     = '';
		$settings['client_secret'] = '';
		$settings['redirect_uri']  = '';
		$settings['account_info']  = array();
		update_option( CTF_OPTION_KEY, $settings );

		ctf_log( 'info', 'Account disconnected.' );
		wp_safe_redirect( $this->settings_url );
		exit;
	}

	/**
	 * Clear the Pixelfed feed cache when requested from the settings page.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		if ( ! isset( $_GET['ctf_clear_cache'] ) ) {
			return;
		}
		check_admin_referer( 'ctf_clear_cache' );

		CTF_Shortcode::bust_feed_cache();
		ctf_log( 'info', 'Feed cache manually cleared from settings page.' );

		set_transient( 'ctf_settings_errors', array(
			array(
				'setting' => 'ctf',
				'code'    => 'cache_cleared',
				'message' => '✅ Feed cache cleared. The shortcode will fetch fresh posts from Pixelfed on next page load.',
				'type'    => 'success',
			),
		), 60 );
		wp_safe_redirect( $this->settings_url );
		exit;
	}

	/* ── Render ───────────────────────────────────────────────────────── */

	/**
	 * Render the settings page HTML.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = get_option( CTF_OPTION_KEY, array() );
		$token     = isset( $settings['access_token'] ) ? $settings['access_token'] : '';
		$instance  = isset( $settings['instance_url'] ) ? $settings['instance_url'] : 'https://pixelfed.social';
		$acc       = isset( $settings['account_info'] ) ? $settings['account_info'] : array();
		$connected = ! empty( $token );
		$has_app   = ! empty( $settings['client_id'] );

		// Auto-clear stale registrations from old plugin versions.
		if ( $has_app && ! $connected && empty( $settings['redirect_uri'] ) ) {
			$settings['client_id']     = '';
			$settings['client_secret'] = '';
			update_option( CTF_OPTION_KEY, $settings );
			$has_app = false;
			ctf_log( 'info', 'Cleared stale OAuth registration (old URL format). Please reconnect.' );
			add_settings_error(
				'ctf',
				'stale_reg',
				'⚠️ A previous connection attempt was found and cleared (plugin was updated). Click <strong>Connect with Pixelfed</strong> to reconnect.',
				'warning'
			);
		}

		$transient = get_transient( 'ctf_settings_errors' );
		if ( $transient ) {
			foreach ( $transient as $e ) {
				add_settings_error( $e['setting'], $e['code'], $e['message'], $e['type'] );
			}
			delete_transient( 'ctf_settings_errors' );
		}
		?>
		<div class="wrap ctf-wrap">

			<div class="ctf-header">
				<div class="ctf-header-logo">
					<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="currentColor">
						<path d="M16 2C8.268 2 2 8.268 2 16s6.268 14 14 14 14-6.268 14-14S23.732 2 16 2zm6.5 9.5h-3v9h-3v-9h-3v-3h9v3z"/>
					</svg>
					<span><?php esc_html_e( 'Crosspost to Pixelfed', 'crosspost-to-pixelfed' ); ?></span>
				</div>
				<div class="ctf-header-links">
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=ctf-debug-log' ) ); ?>"><?php esc_html_e( 'Debug Log', 'crosspost-to-pixelfed' ); ?></a>
					<a href="https://pixelfed.social" target="_blank" rel="noopener"><?php esc_html_e( 'Pixelfed.social ↗', 'crosspost-to-pixelfed' ); ?></a>
				</div>
			</div>

			<?php settings_errors( 'ctf' ); ?>

			<div class="ctf-account-card <?php echo $connected ? 'is-connected' : 'is-disconnected'; ?>">
				<?php if ( $connected && $acc ) : ?>
					<div class="ctf-account-avatar">
						<?php if ( ! empty( $acc['avatar'] ) ) : ?>
							<img src="<?php echo esc_url( $acc['avatar'] ); ?>" alt="">
						<?php else : ?>
							<div class="ctf-avatar-placeholder"></div>
						<?php endif; ?>
					</div>
					<div class="ctf-account-info">
						<strong><?php echo esc_html( isset( $acc['display_name'] ) ? $acc['display_name'] : ( isset( $acc['username'] ) ? $acc['username'] : '' ) ); ?></strong>
						<span class="ctf-account-handle">
							@<?php echo esc_html( isset( $acc['username'] ) ? $acc['username'] : '' ); ?>@<?php echo esc_html( wp_parse_url( $instance, PHP_URL_HOST ) ); ?>
						</span>
						<?php if ( isset( $acc['followers_count'] ) ) : ?>
						<span class="ctf-account-meta">
							<?php echo esc_html( number_format( (int) $acc['followers_count'] ) ); ?> <?php esc_html_e( 'followers', 'crosspost-to-pixelfed' ); ?> ·
							<?php echo esc_html( number_format( (int) ( isset( $acc['statuses_count'] ) ? $acc['statuses_count'] : 0 ) ) ); ?> <?php esc_html_e( 'posts', 'crosspost-to-pixelfed' ); ?>
						</span>
						<?php endif; ?>
					</div>
					<div class="ctf-account-actions">
						<span class="ctf-status-badge connected">● <?php esc_html_e( 'Connected', 'crosspost-to-pixelfed' ); ?></span>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ctf_disconnect', '1', $this->settings_url ), 'ctf_disconnect' ) ); ?>"
						   class="button button-secondary ctf-disconnect-btn"
						   onclick="return confirm('<?php esc_attr_e( 'Disconnect this Pixelfed account?', 'crosspost-to-pixelfed' ); ?>');">
							<?php esc_html_e( 'Disconnect', 'crosspost-to-pixelfed' ); ?>
						</a>
					</div>
				<?php elseif ( $connected ) : ?>
					<div class="ctf-account-info">
						<strong><?php esc_html_e( 'Connected', 'crosspost-to-pixelfed' ); ?></strong>
						<span class="ctf-account-handle"><?php echo esc_html( $instance ); ?></span>
					</div>
					<div class="ctf-account-actions">
						<span class="ctf-status-badge connected">● <?php esc_html_e( 'Connected', 'crosspost-to-pixelfed' ); ?></span>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ctf_disconnect', '1', $this->settings_url ), 'ctf_disconnect' ) ); ?>"
						   class="button button-secondary ctf-disconnect-btn"
						   onclick="return confirm('<?php esc_attr_e( 'Disconnect this Pixelfed account?', 'crosspost-to-pixelfed' ); ?>');">
							<?php esc_html_e( 'Disconnect', 'crosspost-to-pixelfed' ); ?>
						</a>
					</div>
				<?php else : ?>
					<div class="ctf-account-info">
						<strong><?php esc_html_e( 'Not connected', 'crosspost-to-pixelfed' ); ?></strong>
						<span class="ctf-account-handle"><?php esc_html_e( 'Link your Pixelfed account below to get started.', 'crosspost-to-pixelfed' ); ?></span>
					</div>
					<span class="ctf-status-badge disconnected">● <?php esc_html_e( 'Disconnected', 'crosspost-to-pixelfed' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( ! $connected ) : ?>
			<div class="ctf-section">
				<h2><?php esc_html_e( 'Connect to Pixelfed', 'crosspost-to-pixelfed' ); ?></h2>
				<p><?php esc_html_e( 'Enter your Pixelfed instance URL and click Connect with Pixelfed. You\'ll be redirected to authorise this site.', 'crosspost-to-pixelfed' ); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field( 'ctf_register_app' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="ctf_instance_url_reg"><?php esc_html_e( 'Pixelfed Instance URL', 'crosspost-to-pixelfed' ); ?></label></th>
							<td>
								<input type="url" id="ctf_instance_url_reg" name="ctf_instance_url_reg"
								       value="<?php echo esc_attr( $instance ); ?>"
								       class="regular-text" placeholder="https://pixelfed.social">
								<p class="description">
									<?php esc_html_e( 'Use https://pixelfed.social or your own self-hosted instance URL.', 'crosspost-to-pixelfed' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" name="ctf_register_app" class="button button-primary ctf-connect-btn">
							<svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor" style="vertical-align:-3px;margin-right:6px"><path d="M16 2C8.268 2 2 8.268 2 16s6.268 14 14 14 14-6.268 14-14S23.732 2 16 2z"/></svg>
							<?php esc_html_e( 'Connect with Pixelfed', 'crosspost-to-pixelfed' ); ?>
						</button>
					</p>
				</form>

				<hr class="ctf-divider">

				<details class="ctf-manual-token">
					<summary><?php esc_html_e( '↳ Or paste an access token manually (advanced)', 'crosspost-to-pixelfed' ); ?></summary>
					<div class="ctf-manual-body">
						<p><?php esc_html_e( 'If you already have a Pixelfed access token, paste it here.', 'crosspost-to-pixelfed' ); ?></p>
						<form method="post" action="">
							<?php wp_nonce_field( 'ctf_save_settings' ); ?>
							<input type="hidden" name="ctf_instance_url" value="<?php echo esc_attr( $instance ); ?>">
							<table class="form-table">
								<tr>
									<th><label for="ctf_access_token_manual"><?php esc_html_e( 'Access Token', 'crosspost-to-pixelfed' ); ?></label></th>
									<td>
										<div class="ctf-token-wrap">
											<input type="password" id="ctf_access_token_manual" name="ctf_access_token"
											       value="" class="regular-text" placeholder="<?php esc_attr_e( 'Paste token here', 'crosspost-to-pixelfed' ); ?>" autocomplete="off">
											<button type="button" class="button ctf-toggle-token"
											        data-target="ctf_access_token_manual"
											        title="<?php esc_attr_e( 'Show / hide token', 'crosspost-to-pixelfed' ); ?>"
											        aria-label="<?php esc_attr_e( 'Toggle token visibility', 'crosspost-to-pixelfed' ); ?>">
												<span class="ctf-eye-icon">👁</span>
											</button>
										</div>
									</td>
								</tr>
							</table>
							<p class="submit">
								<input type="submit" name="ctf_save_settings" class="button button-secondary" value="<?php esc_attr_e( 'Save Token', 'crosspost-to-pixelfed' ); ?>">
							</p>
						</form>
					</div>
				</details>
			</div>
			<?php endif; ?>

			<div class="ctf-section">
				<h2><?php esc_html_e( 'Post Settings', 'crosspost-to-pixelfed' ); ?></h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'ctf_save_settings' ); ?>
					<input type="hidden" name="ctf_instance_url" value="<?php echo esc_attr( $instance ); ?>">
					<table class="form-table">
						<?php if ( $connected ) : ?>
						<tr>
							<th><label for="ctf_access_token"><?php esc_html_e( 'Access Token', 'crosspost-to-pixelfed' ); ?></label></th>
							<td>
								<div class="ctf-token-wrap">
									<input type="password" id="ctf_access_token" name="ctf_access_token"
									       value="<?php echo esc_attr( $token ); ?>"
									       class="regular-text" autocomplete="off">
									<button type="button" class="button ctf-toggle-token"
									        data-target="ctf_access_token"
									        title="<?php esc_attr_e( 'Show / hide token', 'crosspost-to-pixelfed' ); ?>"
									        aria-label="<?php esc_attr_e( 'Toggle token visibility', 'crosspost-to-pixelfed' ); ?>">
										<span class="ctf-eye-icon">👁</span>
									</button>
								</div>
								<p class="description"><?php esc_html_e( 'Your stored access token. Replace only if you want to use a different account.', 'crosspost-to-pixelfed' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><label for="ctf_auto_post"><?php esc_html_e( 'Auto-post on Publish', 'crosspost-to-pixelfed' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="ctf_auto_post" name="ctf_auto_post" value="1"
									       <?php checked( isset( $settings['auto_post'] ) ? $settings['auto_post'] : '1', '1' ); ?>>
									<?php esc_html_e( 'Automatically crosspost when a new image post is published.', 'crosspost-to-pixelfed' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Only posts that contain at least one image are crossposted.', 'crosspost-to-pixelfed' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Post Types to Monitor', 'crosspost-to-pixelfed' ); ?></th>
							<td>
								<?php
								$monitored    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post' );
								$post_types   = get_post_types( array( 'public' => true ), 'objects' );
								// Always offer 'post' even if for some reason it's not flagged public.
								if ( ! isset( $post_types['post'] ) ) {
									$post_types['post'] = get_post_type_object( 'post' );
								}
								?>
								<?php foreach ( $post_types as $pt ) : ?>
									<?php if ( ! $pt || 'attachment' === $pt->name ) { continue; } ?>
									<label style="display:inline-block;margin-right:16px;">
										<input type="checkbox" name="ctf_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
										       <?php checked( in_array( $pt->name, $monitored, true ) ); ?>>
										<?php echo esc_html( $pt->labels->singular_name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Select which post types should be checked for auto-crossposting. If you publish via a third-party app (e.g. Tusky through "Enable Mastodon Apps" or similar Mastodon-API plugins), tick its custom post type here — it is often not the default "Post" type.', 'crosspost-to-pixelfed' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><label for="ctf_post_caption"><?php esc_html_e( 'Caption Template', 'crosspost-to-pixelfed' ); ?></label></th>
							<td>
								<input type="text" id="ctf_post_caption" name="ctf_post_caption"
								       value="<?php echo esc_attr( isset( $settings['post_caption'] ) ? $settings['post_caption'] : '{title} {url}' ); ?>"
								       class="large-text">
								<p class="description">
									<?php esc_html_e( 'Placeholders: {title} post title · {url} permalink · {excerpt} excerpt · {tags} #hashtags', 'crosspost-to-pixelfed' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<input type="submit" name="ctf_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'crosspost-to-pixelfed' ); ?>">
						<?php if ( $connected ) : ?>
						&nbsp;
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ctf_disconnect', '1', $this->settings_url ), 'ctf_disconnect' ) ); ?>"
						   class="button button-secondary"
						   onclick="return confirm('<?php esc_attr_e( 'Disconnect your Pixelfed account?', 'crosspost-to-pixelfed' ); ?>');">
							<?php esc_html_e( 'Disconnect Account', 'crosspost-to-pixelfed' ); ?>
						</a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<?php if ( $connected ) : ?>
			<div class="ctf-section">
				<h2><?php esc_html_e( 'Test Crosspost', 'crosspost-to-pixelfed' ); ?></h2>
				<p><?php esc_html_e( 'Send an existing post to Pixelfed right now to verify everything is working.', 'crosspost-to-pixelfed' ); ?></p>
				<div id="ctf-test-area">
					<?php
					$monitored_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) && ! empty( $settings['post_types'] )
						? $settings['post_types']
						: array( 'post' );

					// Show recent posts from monitored types regardless of whether a
					// featured image is set, since images may also be embedded in
					// content or attached without becoming the featured image.
					$image_posts = get_posts(
						array(
							'post_type'      => $monitored_types,
							'post_status'    => 'publish',
							'posts_per_page' => 30,
						)
					);
					?>
					<?php if ( $image_posts ) : ?>
					<select id="ctf-test-post-id">
						<option value=""><?php esc_html_e( '— Select a post —', 'crosspost-to-pixelfed' ); ?></option>
						<?php foreach ( $image_posts as $p ) : ?>
						<option value="<?php echo esc_attr( $p->ID ); ?>">
							<?php echo esc_html( $p->post_title ? $p->post_title : wp_trim_words( wp_strip_all_tags( $p->post_content ), 8 ) ); ?>
							(<?php echo esc_html( $p->post_type ); ?>)
						</option>
						<?php endforeach; ?>
					</select>
					<button class="button" id="ctf-test-btn"
					        data-nonce="<?php echo esc_attr( wp_create_nonce( 'ctf_test_post' ) ); ?>">
						<?php esc_html_e( 'Send Test Post', 'crosspost-to-pixelfed' ); ?>
					</button>
					<span id="ctf-test-result"></span>
					<?php else : ?>
					<p><em><?php esc_html_e( 'No published posts with a featured image found.', 'crosspost-to-pixelfed' ); ?></em></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="ctf-section">
				<h2><?php esc_html_e( 'Feed Cache', 'crosspost-to-pixelfed' ); ?></h2>
				<p>
					<?php esc_html_e( 'The [pixelfed_feed] shortcode caches results to avoid repeated API calls. If new posts from your Pixelfed account are not appearing on your site, clear the cache to force a fresh fetch.', 'crosspost-to-pixelfed' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ctf_clear_cache', '1', $this->settings_url ), 'ctf_clear_cache' ) ); ?>"
					   class="button button-secondary">
						🔄 <?php esc_html_e( 'Clear Feed Cache', 'crosspost-to-pixelfed' ); ?>
					</a>
				</p>
			</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/* ── Helpers ──────────────────────────────────────────────────────── */

	/**
	 * Extract and normalise the account fields we need from the API response.
	 *
	 * @param array $acc Raw account array from verify_credentials.
	 * @return array Normalised account info array.
	 */
	private function extract_account_info( $acc ) {
		return array(
			'id'              => isset( $acc['id'] ) ? $acc['id'] : '',
			'username'        => isset( $acc['username'] ) ? $acc['username'] : '',
			'display_name'    => isset( $acc['display_name'] ) ? $acc['display_name'] : '',
			'avatar'          => isset( $acc['avatar'] ) ? $acc['avatar'] : ( isset( $acc['avatar_static'] ) ? $acc['avatar_static'] : '' ),
			'followers_count' => isset( $acc['followers_count'] ) ? $acc['followers_count'] : 0,
			'statuses_count'  => isset( $acc['statuses_count'] ) ? $acc['statuses_count'] : 0,
			'url'             => isset( $acc['url'] ) ? $acc['url'] : '',
		);
	}
}
