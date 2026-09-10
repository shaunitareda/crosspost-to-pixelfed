/**
 * Crosspost to Pixelfed – Front-end Feed JS
 *
 * Features:
 *  - Lightbox with multi-image slide navigation + keyboard support
 *  - Load More / pagination
 *  - Auto-refresh: polls every 30 s; when a new crosspost is detected the
 *    grid updates in place with a smooth fade, no full page reload needed
 */
( function ( $ ) {
	'use strict';

	/* ── Lightbox state ─────────────────────────────────────────────── */
	var $lightbox    = null;
	var $lbMedia     = null;
	var $lbCaption   = null;
	var $lbLink      = null;
	var $lbPrev      = null;
	var $lbNext      = null;
	var currentMedia = [];
	var currentIndex = 0;

	/* ── Poll state ─────────────────────────────────────────────────── */
	// knownTime starts as the server_time value baked in at page load.
	// After each successful poll it advances to the latest server_time so we
	// don't keep re-fetching for the same post.
	var knownTime    = parseInt( ctfFeed.server_time, 10 ) || 0;
	var pollTimer    = null;

	/* ── Init ───────────────────────────────────────────────────────── */
	$( document ).ready( function () {

		// Card click → open lightbox.
		$( document ).on( 'click', '.ctf-card-btn', function () {
			var $card   = $( this ).closest( '.ctf-feed-card' );
			var media   = [];
			var caption = $card.data( 'caption' ) || '';
			var postUrl = $card.data( 'url' ) || '#';
			try {
				media = JSON.parse( $card.attr( 'data-media' ) || '[]' );
			} catch ( e ) {
				media = [];
			}
			if ( media.length ) {
				openLightbox( media, 0, caption, postUrl );
			}
		} );

		// Load More button.
		$( document ).on( 'click', '.ctf-load-more', function () {
			var $btn  = $( this );
			var feedId = $btn.data( 'feed' );
			loadMore( $( '#' + feedId ), $btn );
		} );

		// New-post banner "Refresh" click.
		$( document ).on( 'click', '.ctf-new-post-banner', function () {
			var $wrap = $( this ).closest( '.ctf-feed-wrap' );
			$( this ).remove();
			refreshGrid( $wrap );
		} );

		// Start polling all feeds on the page.
		startPolling();
	} );

	/* ── Auto-refresh polling ───────────────────────────────────────── */

	/**
	 * Begin polling for new posts on a set interval.
	 */
	function startPolling() {
		var interval = parseInt( ctfFeed.poll_interval, 10 ) || 30000;

		// Only poll if there is at least one feed on the page.
		if ( ! $( '.ctf-feed-wrap' ).length ) {
			return;
		}

		pollTimer = setInterval( pollAllFeeds, interval );
	}

	/**
	 * Poll every feed on the page for new posts.
	 */
	function pollAllFeeds() {
		$( '.ctf-feed-wrap' ).each( function () {
			pollFeed( $( this ) );
		} );
	}

	/**
	 * Ask the server whether a crosspost happened since knownTime.
	 * If yes, either auto-refresh or show a "new post" banner.
	 *
	 * @param {jQuery} $wrap Feed wrapper element.
	 */
	function pollFeed( $wrap ) {
		var type    = $wrap.data( 'type' ) || 'feed';
		var hashtag = $wrap.data( 'hashtag' ) || '';
		var limit   = $wrap.data( 'limit' ) || 12;
		var caption = $wrap.data( 'caption' ) || 'no';

		$.post( ctfFeed.ajaxurl, {
			action:     'ctf_check_new_posts',
			nonce:      ctfFeed.nonce,
			last_known: knownTime,
			type:       type,
			hashtag:    hashtag,
			limit:      limit,
			caption:    caption,
		} )
		.done( function ( res ) {
			if ( ! res.success ) {
				return;
			}

			// Advance our clock regardless of whether there's new content.
			if ( res.data.server_time ) {
				knownTime = parseInt( res.data.server_time, 10 );
			}

			if ( ! res.data.new_post || ! res.data.html ) {
				return;
			}

			// Show a dismissible banner so the user can choose when to refresh.
			showNewPostBanner( $wrap );
		} );
	}

	/**
	 * Show a "new post" banner above the feed grid.
	 * Clicking it replaces the grid with fresh content.
	 *
	 * @param {jQuery} $wrap Feed wrapper element.
	 */
	function showNewPostBanner( $wrap ) {
		// Don't stack banners.
		if ( $wrap.find( '.ctf-new-post-banner' ).length ) {
			return;
		}

		var $banner = $( '<button class="ctf-new-post-banner" type="button"></button>' )
			.text( ctfFeed.new_post_msg );

		$wrap.prepend( $banner );

		// Auto-dismiss and auto-refresh after 8 seconds if the user doesn't click.
		setTimeout( function () {
			if ( $banner.is( ':visible' ) ) {
				$banner.remove();
				refreshGrid( $wrap );
			}
		}, 8000 );
	}

	/**
	 * Replace the grid contents with a fresh first-page fetch.
	 *
	 * @param {jQuery} $wrap Feed wrapper element.
	 */
	function refreshGrid( $wrap ) {
		var type    = $wrap.data( 'type' ) || 'feed';
		var hashtag = $wrap.data( 'hashtag' ) || '';
		var limit   = $wrap.data( 'limit' ) || 12;
		var caption = $wrap.data( 'caption' ) || 'no';
		var $grid   = $wrap.find( '.ctf-feed-grid' );

		// Fetch fresh content (cache=0 forces a live API call).
		$.post( ctfFeed.ajaxurl, {
			action:  'ctf_load_more',
			nonce:   ctfFeed.nonce,
			type:    type,
			hashtag: hashtag,
			limit:   limit,
			max_id:  '',
			caption: caption,
			cache:   0,
		} )
		.done( function ( res ) {
			if ( ! res.success || ! res.data.html ) {
				return;
			}

			// Fade out → swap → fade in.
			$grid.fadeTo( 200, 0, function () {
				$grid.html( res.data.html );
				$wrap.data( 'last-id', res.data.last_id );

				// Show / hide Load More based on whether there are more posts.
				if ( res.data.has_more ) {
					$wrap.find( '.ctf-feed-footer' ).show();
				} else {
					$wrap.find( '.ctf-feed-footer' ).hide();
				}

				$grid.fadeTo( 300, 1 );
			} );
		} );
	}

	/* ── Load More ──────────────────────────────────────────────────── */

	/**
	 * Fetch the next page of posts and append them to the grid.
	 *
	 * @param {jQuery} $wrap Feed wrapper element.
	 * @param {jQuery} $btn  Load More button element.
	 */
	function loadMore( $wrap, $btn ) {
		var lastId  = $wrap.data( 'last-id' ) || '';
		var type    = $wrap.data( 'type' ) || 'feed';
		var hashtag = $wrap.data( 'hashtag' ) || '';
		var limit   = $wrap.data( 'limit' ) || 12;
		var caption = $wrap.data( 'caption' ) || 'no';
		var cache   = $wrap.data( 'cache' ) || 3600;

		$btn.addClass( 'is-loading' ).text( ctfFeed.loading );

		$.post( ctfFeed.ajaxurl, {
			action:  'ctf_load_more',
			nonce:   ctfFeed.nonce,
			type:    type,
			hashtag: hashtag,
			limit:   limit,
			max_id:  lastId,
			caption: caption,
			cache:   cache,
		} )
		.done( function ( res ) {
			if ( ! res.success || ! res.data.html ) {
				$btn.closest( '.ctf-feed-footer' ).html( '<p>' + ctfFeed.no_more + '</p>' );
				return;
			}

			$wrap.find( '.ctf-feed-grid' ).append( res.data.html );
			$wrap.data( 'last-id', res.data.last_id );

			if ( ! res.data.has_more ) {
				$btn.closest( '.ctf-feed-footer' ).html( '<p>' + ctfFeed.no_more + '</p>' );
			} else {
				$btn.removeClass( 'is-loading' ).text( 'Load More' );
			}
		} )
		.fail( function () {
			$btn.removeClass( 'is-loading' ).text( 'Load More' );
			alert( ctfFeed.error );
		} );
	}

	/* ── Lightbox ───────────────────────────────────────────────────── */

	/**
	 * Open the lightbox for the given media array.
	 *
	 * @param {Array}  media    Array of { url, preview, alt, type } objects.
	 * @param {number} index    Slide index to show first.
	 * @param {string} caption  Post caption text.
	 * @param {string} postUrl  URL to the Pixelfed post.
	 */
	function openLightbox( media, index, caption, postUrl ) {
		ensureLightbox();

		currentMedia = media;
		currentIndex = index;

		showSlide( index );
		$lbCaption.text( caption );
		$lbLink.attr( 'href', postUrl );
		$lightbox.removeAttr( 'hidden' );
		$lightbox.focus();

		$( document ).on( 'keydown.ctfLightbox', handleKeydown );
	}

	/**
	 * Close the lightbox.
	 */
	function closeLightbox() {
		$lightbox.attr( 'hidden', '' );
		$( document ).off( 'keydown.ctfLightbox' );
		currentMedia = [];
	}

	/**
	 * Display the slide at the given index.
	 *
	 * @param {number} index Slide index.
	 */
	function showSlide( index ) {
		if ( ! currentMedia.length ) {
			return;
		}
		var item = currentMedia[ index ];
		$lbMedia.empty().append(
			$( '<img>' ).attr( { src: item.url || item.preview || '', alt: item.alt || '' } )
		);
		$lbPrev[ index <= 0 ? 'attr' : 'removeAttr' ]( 'hidden', '' );
		$lbNext[ index >= currentMedia.length - 1 ? 'attr' : 'removeAttr' ]( 'hidden', '' );
		currentIndex = index;
	}

	/**
	 * Handle keyboard navigation inside the lightbox.
	 *
	 * @param {Event} e Keydown event.
	 */
	function handleKeydown( e ) {
		if ( 'Escape' === e.key ) { closeLightbox(); }
		if ( 'ArrowLeft' === e.key && currentIndex > 0 ) { showSlide( currentIndex - 1 ); }
		if ( 'ArrowRight' === e.key && currentIndex < currentMedia.length - 1 ) { showSlide( currentIndex + 1 ); }
	}

	/**
	 * Lazily build the lightbox DOM once per page load.
	 */
	function ensureLightbox() {
		if ( $lightbox && $lightbox.length ) { return; }

		$lightbox  = $( '.ctf-lightbox' ).first();
		$lbMedia   = $lightbox.find( '.ctf-lightbox-media' );
		$lbCaption = $lightbox.find( '.ctf-lightbox-caption' );
		$lbLink    = $lightbox.find( '.ctf-lightbox-link' );
		$lbPrev    = $lightbox.find( '.ctf-lightbox-prev' );
		$lbNext    = $lightbox.find( '.ctf-lightbox-next' );

		$lightbox.on( 'click', function ( e ) {
			if ( $( e.target ).is( '.ctf-lightbox' ) ) { closeLightbox(); }
		} );
		$lightbox.find( '.ctf-lightbox-close' ).on( 'click', closeLightbox );
		$lbPrev.on( 'click', function () {
			if ( currentIndex > 0 ) { showSlide( currentIndex - 1 ); }
		} );
		$lbNext.on( 'click', function () {
			if ( currentIndex < currentMedia.length - 1 ) { showSlide( currentIndex + 1 ); }
		} );
	}

}( jQuery ) );
