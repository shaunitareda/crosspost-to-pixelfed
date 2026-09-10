<?php
/**
 * Debug log admin page for Crosspost to Pixelfed.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Debug Log admin page under Tools.
 */
class CTF_Admin_Debug {

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_clear' ) );
		add_action( 'wp_ajax_ctf_test_post', array( $this, 'ajax_test_post' ) );
	}

	/**
	 * Register the Tools sub-page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_management_page(
			__( 'Pixelfed Debug Log', 'crosspost-to-pixelfed' ),
			__( 'Pixelfed Log', 'crosspost-to-pixelfed' ),
			'manage_options',
			'ctf-debug-log',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Clear the log when the nonce-protected clear action is triggered.
	 *
	 * @return void
	 */
	public function handle_clear() {
		if ( ! isset( $_GET['ctf_clear_log'] ) ) {
			return;
		}
		check_admin_referer( 'ctf_clear_log' );
		update_option( CTF_LOG_OPTION, array() );
		wp_safe_redirect( admin_url( 'tools.php?page=ctf-debug-log&cleared=1' ) );
		exit;
	}

	/**
	 * Handle AJAX test-crosspost requests.
	 *
	 * @return void
	 */
	public function ajax_test_post() {
		check_ajax_referer( 'ctf_test_post', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$post_id = absint( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
		if ( ! $post_id ) {
			wp_send_json_error( 'No post ID provided.' );
		}

		$crosspost = new CTF_Crosspost();
		$result    = $crosspost->crosspost_post( $post_id, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( 'Post sent to Pixelfed! Status URL: ' . ( isset( $result['url'] ) ? $result['url'] : '(unknown)' ) );
	}

	/**
	 * Render the debug log page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filter_nonce = wp_create_nonce( 'ctf_log_filter' );

		// Validate nonce on filtered requests.
		$has_filter = isset( $_GET['ctf_filter_nonce'] );
		if ( $has_filter ) {
			$nonce_ok = wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['ctf_filter_nonce'] ) ),
				'ctf_log_filter'
			);
			if ( ! $nonce_ok ) {
				wp_die( 'Security check failed.' );
			}
		}

		$log       = get_option( CTF_LOG_OPTION, array() );
		$clear_url = wp_nonce_url(
			admin_url( 'tools.php?page=ctf-debug-log&ctf_clear_log=1' ),
			'ctf_clear_log'
		);

		$level_filter = $has_filter ? sanitize_key( wp_unslash( isset( $_GET['level'] ) ? $_GET['level'] : '' ) ) : '';
		$search       = $has_filter ? sanitize_text_field( wp_unslash( isset( $_GET['search'] ) ? $_GET['search'] : '' ) ) : '';

		if ( $level_filter ) {
			$log = array_values(
				array_filter(
					$log,
					function ( $e ) use ( $level_filter ) {
						return isset( $e['level'] ) && $e['level'] === $level_filter;
					}
				)
			);
		}
		if ( $search ) {
			$log = array_values(
				array_filter(
					$log,
					function ( $e ) use ( $search ) {
						return isset( $e['message'] ) && false !== stripos( $e['message'], $search );
					}
				)
			);
		}

		$all_entries = get_option( CTF_LOG_OPTION, array() );
		$counts      = array_count_values( array_column( $all_entries, 'level' ) );
		?>
		<div class="wrap ctf-wrap">
			<div class="ctf-header">
				<div class="ctf-header-logo">
					<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor">
						<path d="M16 2C8.268 2 2 8.268 2 16s6.268 14 14 14 14-6.268 14-14S23.732 2 16 2zm6.5 9.5h-3v9h-3v-9h-3v-3h9v3z"/>
					</svg>
					<span><?php esc_html_e( 'Pixelfed Debug Log', 'crosspost-to-pixelfed' ); ?></span>
				</div>
				<div class="ctf-header-links">
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=crosspost-to-pixelfed' ) ); ?>">
						<?php esc_html_e( '← Settings', 'crosspost-to-pixelfed' ); ?>
					</a>
				</div>
			</div>

			<?php if ( isset( $_GET['cleared'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Debug log cleared.', 'crosspost-to-pixelfed' ); ?></p></div>
			<?php endif; ?>

			<?php
			// Surface the most recent connection-related error prominently, so
			// problems with the Pixelfed link are immediately visible without
			// needing to filter/search the full log.
			$recent_conn_error = null;
			foreach ( $all_entries as $entry ) {
				if ( isset( $entry['level'] ) && 'error' === $entry['level']
					&& isset( $entry['message'] )
					&& ( false !== stripos( $entry['message'], 'token' )
						|| false !== stripos( $entry['message'], 'oauth' )
						|| false !== stripos( $entry['message'], 'credential' )
						|| false !== stripos( $entry['message'], 'api error' )
						|| false !== stripos( $entry['message'], 'feed api' ) )
				) {
					$recent_conn_error = $entry;
					break; // Entries are newest-first.
				}
			}
			?>
			<?php if ( $recent_conn_error ) : ?>
			<div class="notice notice-error ctf-conn-error-banner">
				<p>
					<strong><?php esc_html_e( '⚠️ Recent Pixelfed connection error:', 'crosspost-to-pixelfed' ); ?></strong>
					<?php echo esc_html( $recent_conn_error['message'] ); ?>
					<span class="ctf-conn-error-time">(<?php echo esc_html( $recent_conn_error['time'] ); ?>)</span>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=crosspost-to-pixelfed' ) ); ?>">
						<?php esc_html_e( 'Go to Settings to check your connection →', 'crosspost-to-pixelfed' ); ?>
					</a>
				</p>
			</div>
			<?php endif; ?>

			<div class="ctf-log-toolbar">
				<div class="ctf-log-stats">
					<?php foreach ( array( 'success', 'info', 'warning', 'error' ) as $lvl ) : ?>
					<span class="ctf-badge ctf-badge--<?php echo esc_attr( $lvl ); ?>">
						<?php echo esc_html( ucfirst( $lvl ) ) . ': ' . esc_html( isset( $counts[ $lvl ] ) ? $counts[ $lvl ] : 0 ); ?>
					</span>
					<?php endforeach; ?>
					<span class="ctf-badge ctf-badge--total">
						<?php echo esc_html__( 'Total: ', 'crosspost-to-pixelfed' ) . esc_html( count( $all_entries ) ); ?>
					</span>
				</div>

				<form method="get" class="ctf-log-filters">
					<input type="hidden" name="page" value="ctf-debug-log">
					<input type="hidden" name="ctf_filter_nonce" value="<?php echo esc_attr( $filter_nonce ); ?>">
					<select name="level">
						<option value=""><?php esc_html_e( 'All levels', 'crosspost-to-pixelfed' ); ?></option>
						<?php foreach ( array( 'success', 'info', 'warning', 'error' ) as $lvl ) : ?>
						<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $level_filter, $lvl ); ?>>
							<?php echo esc_html( ucfirst( $lvl ) ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'crosspost-to-pixelfed' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'crosspost-to-pixelfed' ); ?></button>
					<?php if ( $level_filter || $search ) : ?>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=ctf-debug-log' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Clear filter', 'crosspost-to-pixelfed' ); ?>
					</a>
					<?php endif; ?>
					<span class="ctf-log-actions">
						<a href="<?php echo esc_url( $clear_url ); ?>"
						   class="button button-secondary ctf-clear-log"
						   onclick="return confirm('<?php esc_attr_e( 'Clear all log entries? This cannot be undone.', 'crosspost-to-pixelfed' ); ?>');">
							<?php esc_html_e( '🗑 Clear Log', 'crosspost-to-pixelfed' ); ?>
						</a>
						<button type="button" class="button" id="ctf-copy-log">
							<?php esc_html_e( '📋 Copy Log', 'crosspost-to-pixelfed' ); ?>
						</button>
					</span>
				</form>
			</div>

			<div class="ctf-log-table-wrap">
				<?php if ( empty( $log ) ) : ?>
				<div class="ctf-log-empty">
					<p>
					<?php
					if ( $level_filter || $search ) {
						esc_html_e( 'No log entries found for your filter.', 'crosspost-to-pixelfed' );
					} else {
						esc_html_e( 'No log entries found.', 'crosspost-to-pixelfed' );
					}
					?>
					</p>
				</div>
				<?php else : ?>
				<table class="widefat ctf-log-table" id="ctf-log-table">
					<thead>
						<tr>
							<th style="width:160px"><?php esc_html_e( 'Time', 'crosspost-to-pixelfed' ); ?></th>
							<th style="width:90px"><?php esc_html_e( 'Level', 'crosspost-to-pixelfed' ); ?></th>
							<th><?php esc_html_e( 'Message', 'crosspost-to-pixelfed' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
						<tr class="ctf-log-row ctf-log-row--<?php echo esc_attr( isset( $entry['level'] ) ? $entry['level'] : 'info' ); ?>">
							<td class="ctf-log-time"><?php echo esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ); ?></td>
							<td>
								<span class="ctf-badge ctf-badge--<?php echo esc_attr( isset( $entry['level'] ) ? $entry['level'] : 'info' ); ?>">
									<?php echo esc_html( strtoupper( isset( $entry['level'] ) ? $entry['level'] : 'INFO' ) ); ?>
								</span>
							</td>
							<td class="ctf-log-message">
								<?php echo esc_html( isset( $entry['message'] ) ? $entry['message'] : '' ); ?>
								<?php if ( ! empty( $entry['context'] ) ) : ?>
								<details class="ctf-context-details">
									<summary><?php esc_html_e( 'context', 'crosspost-to-pixelfed' ); ?></summary>
									<pre><?php echo esc_html( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT ) ); ?></pre>
								</details>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
