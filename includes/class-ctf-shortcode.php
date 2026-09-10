<?php
/**
 * Pixelfed feed shortcode for Crosspost to Pixelfed.
 *
 * Usage:
 *   [pixelfed_feed]                                    – your own feed (images only)
 *   [pixelfed_feed limit="12" columns="3"]             – own feed, custom grid
 *   [pixelfed_feed type="hashtag" hashtag="nature"]    – public hashtag feed
 *   [pixelfed_feed type="hashtag" hashtag="art" limit="6" columns="2"]
 *
 * Shortcode attributes:
 *   type         – "feed" (default) or "hashtag"
 *   hashtag      – hashtag to display when type="hashtag" (without #)
 *   limit        – number of posts to show, 1–40 (default 12)
 *   columns      – grid columns, 1–6 (default 3)
 *   show_caption – "yes" or "no" (default "no")
 *   cache        – cache lifetime in seconds, 0 to disable (default 3600)
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the [pixelfed_feed] shortcode.
 */
class CTF_Shortcode {

	/**
	 * Transient key prefix used for all feed caches.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'ctf_feed_';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_shortcode( 'pixelfed_feed', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ctf_load_more', array( $this, 'ajax_load_more' ) );
		add_action( 'wp_ajax_nopriv_ctf_load_more', array( $this, 'ajax_load_more' ) );
		add_action( 'wp_ajax_ctf_check_new_posts', array( $this, 'ajax_check_new_posts' ) );
		add_action( 'wp_ajax_nopriv_ctf_check_new_posts', array( $this, 'ajax_check_new_posts' ) );
	}

	/* ── Cache helpers (static so CTF_Crosspost can call them) ────────── */

	/**
	 * Delete all cached feed transients.
	 *
	 * Called after a successful crosspost so the shortcode immediately
	 * reflects the new post without waiting for the TTL to expire.
	 *
	 * @return void
	 */
	public static function bust_feed_cache() {
		global $wpdb;
		// Delete all transients whose option_name starts with our prefix.
		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}

	/* ── Assets ───────────────────────────────────────────────────────── */

	/**
	 * Register front-end styles and scripts (enqueued only when shortcode is used).
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_register_style(
			'ctf-feed',
			CTF_PLUGIN_URL . 'assets/feed.css',
			array(),
			CTF_VERSION
		);
		wp_register_script(
			'ctf-feed',
			CTF_PLUGIN_URL . 'assets/feed.js',
			array( 'jquery' ),
			CTF_VERSION,
			true
		);
		wp_localize_script(
			'ctf-feed',
			'ctfFeed',
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'ctf_load_more' ),
				'loading'       => esc_html__( 'Loading…', 'crosspost-to-pixelfed' ),
				'no_more'       => esc_html__( 'No more posts.', 'crosspost-to-pixelfed' ),
				'error'         => esc_html__( 'Could not load posts. Please try again.', 'crosspost-to-pixelfed' ),
				'new_post_msg'  => esc_html__( '✨ New post available', 'crosspost-to-pixelfed' ),
				'server_time'   => (int) get_option( 'ctf_last_crosspost_time', 0 ),
				'poll_interval' => 30000, // milliseconds between polls
			)
		);
	}

	/* ── Shortcode ────────────────────────────────────────────────────── */

	/**
	 * Render the [pixelfed_feed] shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'         => 'feed',
				'hashtag'      => '',
				'limit'        => 12,
				'columns'      => 3,
				'show_caption' => 'no',
				'cache'        => 3600,
			),
			$atts,
			'pixelfed_feed'
		);

		$type         = 'hashtag' === $atts['type'] ? 'hashtag' : 'feed';
		$hashtag      = sanitize_text_field( $atts['hashtag'] );
		$limit        = min( max( 1, absint( $atts['limit'] ) ), 40 );
		$columns      = min( max( 1, absint( $atts['columns'] ) ), 6 );
		$show_caption = 'yes' === $atts['show_caption'];
		$cache_ttl    = absint( $atts['cache'] );

		if ( 'hashtag' === $type && ! $hashtag ) {
			return '<p class="ctf-feed-error">' . esc_html__( 'Pixelfed Feed: please provide a hashtag attribute.', 'crosspost-to-pixelfed' ) . '</p>';
		}

		$settings = get_option( CTF_OPTION_KEY, array() );
		if ( empty( $settings['access_token'] ) ) {
			return '<p class="ctf-feed-error">' . esc_html__( 'Pixelfed Feed: no account connected. Please configure the plugin in Settings → Crosspost to Pixelfed.', 'crosspost-to-pixelfed' ) . '</p>';
		}

		$statuses = $this->fetch_statuses( $type, $hashtag, $limit, $cache_ttl, '' );

		if ( is_wp_error( $statuses ) ) {
			return '<p class="ctf-feed-error">' . esc_html__( 'Pixelfed Feed: could not load posts. Please check the Debug Log.', 'crosspost-to-pixelfed' ) . '</p>';
		}

		if ( empty( $statuses ) ) {
			return '<p class="ctf-feed-empty">' . esc_html__( 'No posts found.', 'crosspost-to-pixelfed' ) . '</p>';
		}

		wp_enqueue_style( 'ctf-feed' );
		wp_enqueue_script( 'ctf-feed' );

		$last_status = end( $statuses );
		$last_id     = is_array( $last_status ) && isset( $last_status['id'] ) ? $last_status['id'] : '';
		$unique_id   = 'ctf-feed-' . wp_unique_id();

		ob_start();
		?>
		<div class="ctf-feed-wrap"
		     id="<?php echo esc_attr( $unique_id ); ?>"
		     data-type="<?php echo esc_attr( $type ); ?>"
		     data-hashtag="<?php echo esc_attr( $hashtag ); ?>"
		     data-limit="<?php echo esc_attr( $limit ); ?>"
		     data-columns="<?php echo esc_attr( $columns ); ?>"
		     data-caption="<?php echo esc_attr( $show_caption ? 'yes' : 'no' ); ?>"
		     data-cache="<?php echo esc_attr( $cache_ttl ); ?>"
		     data-last-id="<?php echo esc_attr( $last_id ); ?>">

			<div class="ctf-feed-grid ctf-feed-cols-<?php echo esc_attr( $columns ); ?>">
				<?php foreach ( $statuses as $status ) : ?>
					<?php echo $this->render_status_card( $status, $show_caption ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>

			<?php if ( count( $statuses ) >= $limit ) : ?>
			<div class="ctf-feed-footer">
				<button class="ctf-load-more button"
				        data-feed="<?php echo esc_attr( $unique_id ); ?>">
					<?php esc_html_e( 'Load More', 'crosspost-to-pixelfed' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<div class="ctf-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Post preview', 'crosspost-to-pixelfed' ); ?>" hidden>
				<button class="ctf-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'crosspost-to-pixelfed' ); ?>">✕</button>
				<div class="ctf-lightbox-inner">
					<button class="ctf-lightbox-prev" aria-label="<?php esc_attr_e( 'Previous image', 'crosspost-to-pixelfed' ); ?>">&#8249;</button>
					<div class="ctf-lightbox-media"></div>
					<button class="ctf-lightbox-next" aria-label="<?php esc_attr_e( 'Next image', 'crosspost-to-pixelfed' ); ?>">&#8250;</button>
				</div>
				<div class="ctf-lightbox-caption"></div>
				<a class="ctf-lightbox-link" href="#" target="_blank" rel="noopener">
					<?php esc_html_e( 'View on Pixelfed ↗', 'crosspost-to-pixelfed' ); ?>
				</a>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/* ── AJAX load more ───────────────────────────────────────────────── */

	/**
	 * Handle AJAX load-more requests for pagination.
	 *
	 * @return void
	 */
	public function ajax_load_more() {
		check_ajax_referer( 'ctf_load_more', 'nonce' );

		$type         = isset( $_POST['type'] ) ? ( 'hashtag' === $_POST['type'] ? 'hashtag' : 'feed' ) : 'feed';
		$hashtag      = sanitize_text_field( wp_unslash( isset( $_POST['hashtag'] ) ? $_POST['hashtag'] : '' ) );
		$limit        = min( max( 1, absint( isset( $_POST['limit'] ) ? $_POST['limit'] : 12 ) ), 40 );
		$max_id       = sanitize_text_field( wp_unslash( isset( $_POST['max_id'] ) ? $_POST['max_id'] : '' ) );
		$show_caption = isset( $_POST['caption'] ) && 'yes' === $_POST['caption'];
		$cache_ttl    = absint( isset( $_POST['cache'] ) ? $_POST['cache'] : 3600 );

		$statuses = $this->fetch_statuses( $type, $hashtag, $limit, $cache_ttl, $max_id );

		if ( is_wp_error( $statuses ) || empty( $statuses ) ) {
			wp_send_json_success(
				array(
					'html'     => '',
					'last_id'  => '',
					'has_more' => false,
				)
			);
		}

		$html = '';
		foreach ( $statuses as $status ) {
			$html .= $this->render_status_card( $status, $show_caption );
		}

		$last_status = end( $statuses );
		$last_id     = is_array( $last_status ) && isset( $last_status['id'] ) ? $last_status['id'] : '';

		wp_send_json_success(
			array(
				'html'     => $html,
				'last_id'  => $last_id,
				'has_more' => count( $statuses ) >= $limit,
			)
		);
	}

	/* ── AJAX: check for new posts ────────────────────────────────────── */

	/**
	 * Lightweight poll endpoint – returns the latest crosspost timestamp
	 * and, when it is newer than the client's known timestamp, the fresh
	 * first-page HTML so the feed can swap itself in-place.
	 *
	 * @return void
	 */
	public function ajax_check_new_posts() {
		check_ajax_referer( 'ctf_load_more', 'nonce' );

		$last_known   = absint( isset( $_POST['last_known'] ) ? $_POST['last_known'] : 0 );
		$server_time  = absint( get_option( 'ctf_last_crosspost_time', 0 ) );

		// Nothing new – tell the client to keep waiting.
		if ( ! $server_time || $server_time <= $last_known ) {
			wp_send_json_success(
				array(
					'new_post'   => false,
					'server_time' => $server_time,
				)
			);
		}

		// There is a new post – fetch the fresh first page and return it.
		$type         = isset( $_POST['type'] ) ? ( 'hashtag' === $_POST['type'] ? 'hashtag' : 'feed' ) : 'feed';
		$hashtag      = sanitize_text_field( wp_unslash( isset( $_POST['hashtag'] ) ? $_POST['hashtag'] : '' ) );
		$limit        = min( max( 1, absint( isset( $_POST['limit'] ) ? $_POST['limit'] : 12 ) ), 40 );
		$show_caption = isset( $_POST['caption'] ) && 'yes' === $_POST['caption'];

		// Fetch with cache disabled so we always get the freshest data.
		$statuses = $this->fetch_statuses( $type, $hashtag, $limit, 0, '' );

		if ( is_wp_error( $statuses ) || empty( $statuses ) ) {
			wp_send_json_success(
				array(
					'new_post'    => false,
					'server_time' => $server_time,
				)
			);
		}

		$html = '';
		foreach ( $statuses as $status ) {
			$html .= $this->render_status_card( $status, $show_caption );
		}

		$last_status = end( $statuses );
		$last_id     = is_array( $last_status ) && isset( $last_status['id'] ) ? $last_status['id'] : '';

		wp_send_json_success(
			array(
				'new_post'    => true,
				'server_time' => $server_time,
				'html'        => $html,
				'last_id'     => $last_id,
				'count'       => count( $statuses ),
			)
		);
	}

	/* ── Fetch statuses ───────────────────────────────────────────────── */

	/**
	 * Fetch statuses from the Pixelfed API with optional transient caching.
	 *
	 * Cache key includes the account ID so that changing the connected
	 * account never serves another user's cached data.
	 * Cache is bypassed when paginating (max_id is set).
	 *
	 * @param string $type      'feed' or 'hashtag'.
	 * @param string $hashtag   Hashtag to fetch (for type=hashtag).
	 * @param int    $limit     Number of posts to return.
	 * @param int    $cache_ttl Transient lifetime in seconds. 0 disables caching.
	 * @param string $max_id    Pagination cursor – return results older than this ID.
	 * @return array|WP_Error Array of status objects or WP_Error on failure.
	 */
	private function fetch_statuses( $type, $hashtag, $limit, $cache_ttl, $max_id ) {
		$settings = get_option( CTF_OPTION_KEY, array() );
		$token    = isset( $settings['access_token'] ) ? $settings['access_token'] : '';
		$instance = isset( $settings['instance_url'] ) ? $settings['instance_url'] : 'https://pixelfed.social';
		$acc      = isset( $settings['account_info'] ) ? $settings['account_info'] : array();
		$acc_id   = isset( $acc['id'] ) ? $acc['id'] : '';

		if ( ! $token ) {
			ctf_log( 'error', 'Feed fetch failed: no access token configured.' );
			return new WP_Error( 'no_token', 'No access token configured.' );
		}

		// Cache key includes account ID to prevent cross-account stale data.
		$cache_key = self::CACHE_PREFIX . md5( $type . $hashtag . $limit . $acc_id );

		// Serve from cache unless paginating or cache is disabled.
		if ( $cache_ttl > 0 && ! $max_id ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				ctf_log( 'info', 'Feed served from cache. type=' . $type . ( $hashtag ? ' hashtag=' . $hashtag : '' ) );
				return $cached;
			}
		}

		ctf_log( 'info', 'Fetching feed from Pixelfed. type=' . $type . ( $hashtag ? ' hashtag=' . $hashtag : '' ) . ( $max_id ? ' max_id=' . $max_id : '' ) );

		$api = new CTF_Pixelfed_API( $instance, $token );

		if ( 'hashtag' === $type ) {
			$statuses = $api->get_hashtag_timeline( $hashtag, $limit, true, $max_id );
		} else {
			// Ensure we have an account ID before fetching the timeline.
			if ( ! $acc_id ) {
				ctf_log( 'info', 'Account ID not cached – calling verify_credentials.' );
				$creds = $api->verify_credentials();
				if ( is_wp_error( $creds ) ) {
					ctf_log( 'error', 'verify_credentials failed: ' . $creds->get_error_message() );
					return $creds;
				}
				$acc_id                   = isset( $creds['id'] ) ? $creds['id'] : '';
				$settings['account_info'] = array_merge( $acc, array( 'id' => $acc_id ) );
				update_option( CTF_OPTION_KEY, $settings );
				ctf_log( 'info', 'Account ID retrieved and cached: ' . $acc_id );
			}

			if ( ! $acc_id ) {
				ctf_log( 'error', 'Feed fetch failed: could not determine account ID.' );
				return new WP_Error( 'no_account_id', 'Could not determine Pixelfed account ID.' );
			}

			$statuses = $api->get_account_statuses( $acc_id, $limit, true, $max_id );
		}

		// Log any API errors.
		if ( is_wp_error( $statuses ) ) {
			ctf_log(
				'error',
				'Feed API error (' . $type . '): ' . $statuses->get_error_message(),
				array(
					'instance' => $instance,
					'type'     => $type,
					'hashtag'  => $hashtag,
					'acc_id'   => $acc_id,
				)
			);
			return $statuses;
		}

		$count = is_array( $statuses ) ? count( $statuses ) : 0;
		ctf_log( 'success', 'Feed fetched successfully. ' . $count . ' post(s) returned.' );

		// Store in cache (only for the first page, not paginated results).
		if ( $cache_ttl > 0 && ! $max_id ) {
			set_transient( $cache_key, $statuses, $cache_ttl );
		}

		return $statuses;
	}

	/* ── Render a single status card ──────────────────────────────────── */

	/**
	 * Render a single Pixelfed status as an image card.
	 *
	 * @param array $status       Pixelfed status object.
	 * @param bool  $show_caption Whether to show the post caption below the image.
	 * @return string HTML for the card, or empty string if no media.
	 */
	private function render_status_card( $status, $show_caption ) {
		$attachments = isset( $status['media_attachments'] ) ? $status['media_attachments'] : array();
		if ( empty( $attachments ) ) {
			return '';
		}

		$first       = $attachments[0];
		$preview_url = isset( $first['preview_url'] ) ? $first['preview_url'] : ( isset( $first['url'] ) ? $first['url'] : '' );
		$alt         = isset( $first['description'] ) ? $first['description'] : '';
		$post_url    = isset( $status['url'] ) ? $status['url'] : '#';
		$caption     = isset( $status['content'] ) ? wp_strip_all_tags( $status['content'] ) : '';
		$multi       = count( $attachments ) > 1;

		$media_json = wp_json_encode(
			array_map(
				function ( $att ) {
					return array(
						'url'     => isset( $att['url'] ) ? $att['url'] : '',
						'preview' => isset( $att['preview_url'] ) ? $att['preview_url'] : '',
						'alt'     => isset( $att['description'] ) ? $att['description'] : '',
						'type'    => isset( $att['type'] ) ? $att['type'] : 'image',
					);
				},
				$attachments
			)
		);

		ob_start();
		?>
		<div class="ctf-feed-card"
		     data-url="<?php echo esc_url( $post_url ); ?>"
		     data-caption="<?php echo esc_attr( $caption ); ?>"
		     data-media="<?php echo esc_attr( $media_json ); ?>">
			<button class="ctf-card-btn" aria-label="<?php esc_attr_e( 'View post', 'crosspost-to-pixelfed' ); ?>">
				<img src="<?php echo esc_url( $preview_url ); ?>"
				     alt="<?php echo esc_attr( $alt ); ?>"
				     loading="lazy">
				<?php if ( $multi ) : ?>
				<span class="ctf-multi-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
						<path d="M2 6h2V4H2v2zm0 4h2V8H2v2zm0 4h2v-2H2v2zm4 4V6H4v12h2zm2-8h12V6H8v4zm0 8h12v-4H8v4zm0-4h12v-4H8v4z"/>
					</svg>
				</span>
				<?php endif; ?>
			</button>
			<?php if ( $show_caption && $caption ) : ?>
			<p class="ctf-card-caption"><?php echo esc_html( wp_trim_words( $caption, 15 ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
