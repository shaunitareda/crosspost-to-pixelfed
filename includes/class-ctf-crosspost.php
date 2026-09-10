<?php
/**
 * Handles crossposting WordPress image posts to Pixelfed.
 *
 * @package Crosspost_To_Pixelfed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for post publish events and crossposts image posts to Pixelfed.
 *
 * Hooks both transition_post_status (classic editor / programmatic) and
 * rest_after_insert_post (Gutenberg / block editor REST API publishes),
 * because the block editor publishes via REST and can miss the status
 * transition hook in some WordPress configurations.
 */
class CTF_Crosspost {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Classic editor and programmatic publishes.
		add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );

		// Block editor (Gutenberg) publishes via the REST API.
		add_action( 'rest_after_insert_post', array( $this, 'on_rest_insert_post' ), 10, 2 );

		// Catch-all: fires for any post saved via wp_insert_post(), regardless of
		// editor or API used. This covers third-party apps (e.g. Mastodon-API
		// clients like Tusky via "Enable Mastodon Apps") that publish posts of
		// a custom post type and may not reliably fire the hooks above.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
	}

	/**
	 * Return the list of post types this plugin should monitor for crossposting.
	 *
	 * Defaults to the standard 'post' type, but is configurable via Settings
	 * so installs using custom post types (e.g. Mastodon-API note plugins)
	 * can be included too.
	 *
	 * @return string[] Array of post type slugs.
	 */
	public static function get_monitored_post_types() {
		$settings = get_option( CTF_OPTION_KEY, array() );
		$types    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) && ! empty( $settings['post_types'] )
			? $settings['post_types']
			: array( 'post' );

		/**
		 * Filter the list of post types monitored for auto-crossposting.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return apply_filters( 'ctf_monitored_post_types', $types );
	}

	/* ── Hook: classic / programmatic publish ─────────────────────────── */

	/**
	 * Fire when a post transitions to 'publish' for the first time.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function on_post_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::get_monitored_post_types(), true ) ) {
			ctf_log( 'info', 'Post #' . $post->ID . ' skipped: post type "' . $post->post_type . '" is not monitored. Add it under Settings → Post Types to Monitor if this should be crossposted.' );
			return;
		}

		ctf_log( 'info', 'transition_post_status fired for post #' . $post->ID . ' (' . $old_status . ' → publish, type=' . $post->post_type . ').' );
		$this->maybe_crosspost( $post );
	}

	/* ── Hook: Gutenberg / REST API publish ───────────────────────────── */

	/**
	 * Fire after the block editor publishes or updates a post via REST.
	 *
	 * @param WP_Post         $post    Inserted/updated post object.
	 * @param WP_REST_Request $request The REST request object.
	 * @return void
	 */
	public function on_rest_insert_post( $post, $request ) {
		if ( ! in_array( $post->post_type, self::get_monitored_post_types(), true ) ) {
			return;
		}

		// Only act when the REST request is explicitly publishing the post.
		$params = $request->get_params();
		if ( ! isset( $params['status'] ) || 'publish' !== $params['status'] ) {
			return;
		}

		if ( get_post_meta( $post->ID, '_ctf_rest_crosspost_done', true ) ) {
			return;
		}
		update_post_meta( $post->ID, '_ctf_rest_crosspost_done', '1' );

		ctf_log( 'info', 'rest_after_insert_post fired for post #' . $post->ID . ' (Gutenberg/REST publish, type=' . $post->post_type . ').' );
		$this->maybe_crosspost( $post );
	}

	/* ── Hook: catch-all for any wp_insert_post() save ────────────────── */

	/**
	 * Catch-all hook covering any code path that calls wp_insert_post()
	 * or wp_update_post(), including third-party plugins (e.g. Mastodon-API
	 * apps like "Enable Mastodon Apps") that may not reliably trigger the
	 * transition_post_status or rest_after_insert_post hooks.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function on_save_post( $post_id, $post, $update ) {
		// Skip autosaves, revisions, and bulk/AJAX inline edits.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::get_monitored_post_types(), true ) ) {
			return;
		}
		if ( get_post_meta( $post_id, '_ctf_posted', true ) ) {
			return;
		}
		// Avoid duplicate firing if one of the other hooks already triggered for this save.
		if ( get_post_meta( $post_id, '_ctf_save_post_done', true ) ) {
			return;
		}
		update_post_meta( $post_id, '_ctf_save_post_done', '1' );

		ctf_log( 'info', 'save_post fired for post #' . $post_id . ' (type=' . $post->post_type . ', update=' . ( $update ? 'yes' : 'no' ) . ').' );
		$this->maybe_crosspost( $post );
	}

	/* ── Shared publish handler ───────────────────────────────────────── */

	/**
	 * Check conditions and trigger a crosspost if appropriate.
	 *
	 * @param WP_Post $post Post object to potentially crosspost.
	 * @return void
	 */
	private function maybe_crosspost( $post ) {
		$settings = get_option( CTF_OPTION_KEY, array() );

		if ( empty( $settings['auto_post'] ) || '1' !== $settings['auto_post'] ) {
			ctf_log( 'info', 'Auto-post disabled – skipping post #' . $post->ID . '.' );
			return;
		}

		if ( empty( $settings['access_token'] ) ) {
			ctf_log( 'warning', 'Post #' . $post->ID . ' published but no Pixelfed token configured – skipping.' );
			return;
		}

		if ( get_post_meta( $post->ID, '_ctf_posted', true ) ) {
			ctf_log( 'info', 'Post #' . $post->ID . ' already crossposted – skipping.' );
			return;
		}

		ctf_log( 'info', 'Auto-crosspost triggered for post #' . $post->ID . ' "' . $post->post_title . '".' );
		$this->crosspost_post( $post->ID );
	}

	/* ── Core crosspost method ────────────────────────────────────────── */

	/**
	 * Crosspost a WordPress post to Pixelfed.
	 *
	 * @param int  $post_id Post ID to crosspost.
	 * @param bool $force   If true, ignore the _ctf_posted meta guard.
	 * @return array|WP_Error Pixelfed status object on success, WP_Error on failure.
	 */
	public function crosspost_post( $post_id, $force = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'no_post', 'Post #' . $post_id . ' not found.' );
		}

		if ( ! $force && get_post_meta( $post_id, '_ctf_posted', true ) ) {
			ctf_log( 'info', 'Post #' . $post_id . ' was already crossposted – skipping.' );
			return new WP_Error( 'already_posted', 'Post was already crossposted.' );
		}

		$settings = get_option( CTF_OPTION_KEY, array() );

		if ( empty( $settings['access_token'] ) ) {
			ctf_log( 'error', 'Cannot crosspost post #' . $post_id . ': no access token configured.' );
			return new WP_Error( 'no_token', 'No Pixelfed access token configured.' );
		}

		$api = new CTF_Pixelfed_API(
			isset( $settings['instance_url'] ) ? $settings['instance_url'] : 'https://pixelfed.social',
			$settings['access_token']
		);

		// Gather images.
		$image_paths = $this->collect_images( $post );

		if ( empty( $image_paths ) ) {
			ctf_log( 'info', 'Post #' . $post_id . ' has no images – skipping crosspost.' );
			return new WP_Error( 'no_images', 'Post contains no images to crosspost.' );
		}

		// Upload images (Pixelfed supports up to 4 per post).
		$media_ids = array();
		$uploaded  = 0;

		foreach ( array_slice( $image_paths, 0, 4 ) as $image ) {
			$alt       = isset( $image['alt'] ) ? $image['alt'] : '';
			$path      = isset( $image['path'] ) ? $image['path'] : '';
			$temp_file = ! empty( $image['temp_file'] );

			if ( ! $path || ! file_exists( $path ) ) {
				ctf_log( 'warning', 'Image file not found on disk: ' . $path );
				continue;
			}

			ctf_log( 'info', 'Uploading image: ' . basename( $path ) . '.' );
			$media = $api->upload_media( $path, $alt );

			// Clean up downloaded temp files regardless of upload outcome.
			if ( $temp_file && file_exists( $path ) ) {
				wp_delete_file( $path );
			}

			if ( is_wp_error( $media ) ) {
				ctf_log( 'error', 'Media upload failed for post #' . $post_id . ': ' . $media->get_error_message() );
				return $media;
			}

			if ( ! empty( $media['id'] ) ) {
				$media_ids[] = $media['id'];
				++$uploaded;
			}
		}

		if ( empty( $media_ids ) ) {
			ctf_log( 'error', 'Post #' . $post_id . ': all media uploads failed.' );
			return new WP_Error( 'upload_failed', 'All media uploads failed.' );
		}

		$caption = $this->build_caption(
			$post,
			isset( $settings['post_caption'] ) ? $settings['post_caption'] : '{title} {url}'
		);

		ctf_log( 'info', 'Publishing status for post #' . $post_id . ' with ' . $uploaded . ' image(s).' );

		$status = $api->create_status( $caption, $media_ids );

		if ( is_wp_error( $status ) ) {
			ctf_log( 'error', 'Status creation failed for post #' . $post_id . ': ' . $status->get_error_message() );
			return $status;
		}

		update_post_meta( $post_id, '_ctf_posted', '1' );
		update_post_meta( $post_id, '_ctf_status_id', isset( $status['id'] ) ? $status['id'] : '' );
		update_post_meta( $post_id, '_ctf_status_url', isset( $status['url'] ) ? $status['url'] : '' );

		// Bust the feed cache and set a timestamp flag so live feeds auto-refresh.
		CTF_Shortcode::bust_feed_cache();
		update_option( 'ctf_last_crosspost_time', time() );
		ctf_log( 'success', 'Post #' . $post_id . ' crossposted ✓ → ' . ( isset( $status['url'] ) ? $status['url'] : '(no URL)' ) . '. Feed cache cleared.' );

		return $status;
	}

	/* ── Image collection ─────────────────────────────────────────────── */

	/**
	 * Return an array of image data for the post.
	 *
	 * Tries, in order: featured image, attachments with this post as parent,
	 * inline wp-image-* classed images, then any plain <img src="..."> tags
	 * resolved back to a local attachment ID. The last fallback exists
	 * because third-party publishing apps (e.g. Mastodon-API clients such
	 * as Tusky via "Enable Mastodon Apps") often insert plain <img> tags
	 * without WordPress's usual wp-image-NNN class.
	 *
	 * @param WP_Post $post Post object to collect images from.
	 * @return array Array of arrays with 'path' and 'alt' keys.
	 */
	private function collect_images( $post ) {
		$images   = array();
		$seen_ids = array();

		// 1. Featured image.
		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$path = $this->attachment_path( $thumb_id );
			if ( $path ) {
				$images[]   = array(
					'path' => $path,
					'alt'  => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
				);
				$seen_ids[] = $thumb_id;
			}
		}

		// 2. Attached images (attachments whose post_parent is this post).
		$attached = get_attached_media( 'image', $post->ID );
		foreach ( $attached as $att ) {
			if ( in_array( $att->ID, $seen_ids, true ) ) {
				continue;
			}
			$path = $this->attachment_path( $att->ID );
			if ( $path ) {
				$images[]   = array(
					'path' => $path,
					'alt'  => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				);
				$seen_ids[] = $att->ID;
			}
			if ( count( $images ) >= 4 ) {
				break;
			}
		}

		// 3. Inline wp-image-* classes from post content (standard block/classic editor markup).
		if ( count( $images ) < 4 ) {
			preg_match_all( '/wp-image-(\d+)/i', $post->post_content, $matches );
			foreach ( $matches[1] as $att_id ) {
				$att_id = (int) $att_id;
				if ( in_array( $att_id, $seen_ids, true ) ) {
					continue;
				}
				$path = $this->attachment_path( $att_id );
				if ( $path ) {
					$images[]   = array(
						'path' => $path,
						'alt'  => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
					);
					$seen_ids[] = $att_id;
				}
				if ( count( $images ) >= 4 ) {
					break;
				}
			}
		}

		// 4. Fallback: any plain <img src="..."> tag, resolved to a local attachment ID.
		// Covers third-party apps that insert images without wp-image-* classes.
		if ( count( $images ) < 4 ) {
			preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $img_matches );
			foreach ( $img_matches[1] as $src ) {
				$att_id = attachment_url_to_postid( $src );
				if ( ! $att_id || in_array( $att_id, $seen_ids, true ) ) {
					continue;
				}
				$path = $this->attachment_path( $att_id );
				if ( $path ) {
					$images[]   = array(
						'path' => $path,
						'alt'  => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
					);
					$seen_ids[] = $att_id;
					ctf_log( 'info', 'Post #' . $post->ID . ': found image via plain <img> tag fallback: ' . basename( $path ) . '.' );
				}
				if ( count( $images ) >= 4 ) {
					break;
				}
			}
		}

		// 5. Final fallback: any <img src="..."> not resolvable to a local attachment
		// (e.g. an externally-hosted image inserted by a third-party publishing app).
		// Download it to a temp file so it can still be uploaded to Pixelfed.
		if ( count( $images ) < 4 ) {
			preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $img_matches );
			foreach ( $img_matches[1] as $src ) {
				if ( count( $images ) >= 4 ) {
					break;
				}
				$att_id = attachment_url_to_postid( $src );
				if ( $att_id ) {
					continue; // Already handled in step 4, or not a local attachment.
				}

				$tmp_path = $this->download_remote_image( $src );
				if ( $tmp_path ) {
					$images[] = array(
						'path'      => $tmp_path,
						'alt'       => '',
						'temp_file' => true,
					);
					ctf_log( 'info', 'Post #' . $post->ID . ': downloaded external image for crosspost: ' . esc_url_raw( $src ) );
				}
			}
		}

		if ( empty( $images ) ) {
			ctf_log( 'info', 'Post #' . $post->ID . ': no images found via featured image, attachments, or content scan.' );
		}

		return $images;
	}

	/**
	 * Download a remote image to a temporary local file.
	 *
	 * @param string $url Remote image URL.
	 * @return string|null Local temp file path, or null on failure.
	 */
	private function download_remote_image( $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = download_url( esc_url_raw( $url ), 15 );

		if ( is_wp_error( $tmp ) ) {
			ctf_log( 'warning', 'Failed to download external image ' . esc_url_raw( $url ) . ': ' . $tmp->get_error_message() );
			return null;
		}

		return $tmp;
	}

	/**
	 * Return the absolute file path for an attachment, or null if not found.
	 *
	 * @param int $att_id Attachment post ID.
	 * @return string|null Absolute file path or null.
	 */
	private function attachment_path( $att_id ) {
		$file = get_attached_file( $att_id );
		if ( $file && file_exists( $file ) ) {
			return $file;
		}
		return null;
	}

	/* ── Caption builder ──────────────────────────────────────────────── */

	/**
	 * Build a caption string by replacing template placeholders.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $template Caption template with {placeholders}.
	 * @return string Rendered caption, truncated to 500 characters.
	 */
	private function build_caption( $post, $template ) {
		$tags_arr = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		$hashtags = implode(
			' ',
			array_map(
				function ( $t ) {
					return '#' . preg_replace( '/[^A-Za-z0-9_]/', '', str_replace( ' ', '_', $t ) );
				},
				$tags_arr
			)
		);

		$excerpt = wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : wp_trim_words( $post->post_content, 40 ) );
		if ( mb_strlen( $excerpt ) > 250 ) {
			$excerpt = mb_substr( $excerpt, 0, 247 ) . '…';
		}

		$caption = str_replace(
			array( '{title}', '{url}', '{excerpt}', '{tags}' ),
			array( $post->post_title, get_permalink( $post->ID ), $excerpt, $hashtags ),
			$template
		);

		if ( mb_strlen( $caption ) > 500 ) {
			$caption = mb_substr( $caption, 0, 497 ) . '…';
		}

		return $caption;
	}
}
