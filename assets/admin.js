/* Crosspost to Pixelfed – Admin JS */
(function ($) {
	'use strict';

	/* ── Token show / hide ──────────────────────────────────────────────── */
	$(document).on('click', '.ctf-toggle-token', function () {
		var targetId = $(this).data('target');
		var $input   = $('#' + targetId);

		if ($input.attr('type') === 'password') {
			$input.attr('type', 'text');
			$(this).find('.ctf-eye-icon').text('🙈');
			$(this).attr('title', 'Hide token');
		} else {
			$input.attr('type', 'password');
			$(this).find('.ctf-eye-icon').text('👁');
			$(this).attr('title', 'Show token');
		}
	});

	/* ── Test crosspost ─────────────────────────────────────────────────── */
	$('#ctf-test-btn').on('click', function () {
		var postId  = $('#ctf-test-post-id').val();
		var $btn    = $(this);
		var $result = $('#ctf-test-result');

		if (!postId) {
			$result.text('Please select a post first.').removeClass('success').addClass('error');
			return;
		}

		$btn.prop('disabled', true).text('Sending…');
		$result.text('').removeClass('success error');

		$.post(ajaxurl, {
			action:  'ctf_test_post',
			post_id: postId,
			nonce:   $btn.data('nonce')
		})
		.done(function (res) {
			if (res.success) {
				$result.text('✅ ' + res.data).addClass('success');
			} else {
				$result.text('❌ ' + res.data).addClass('error');
			}
		})
		.fail(function () {
			$result.text('❌ Request failed. Check the debug log.').addClass('error');
		})
		.always(function () {
			$btn.prop('disabled', false).text('Send Test Post');
		});
	});

	/* ── Copy debug log to clipboard ─────────────────────────────────────── */
	$('#ctf-copy-log').on('click', function () {
		var rows = [];
		$('#ctf-log-table tbody tr').each(function () {
			var time  = $(this).find('.ctf-log-time').text().trim();
			var level = $(this).find('.ctf-badge').text().trim();
			var msg   = $(this).find('.ctf-log-message').clone()
				.find('details').remove().end().text().trim();
			rows.push('[' + time + '] ' + level + ' – ' + msg);
		});

		if (!rows.length) {
			alert('No log entries to copy.');
			return;
		}

		var text = rows.join('\n');

		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () {
				alert('Log copied to clipboard (' + rows.length + ' entries).');
			});
		} else {
			// Fallback
			var $ta = $('<textarea>').val(text).css({ position: 'fixed', top: -9999 });
			$('body').append($ta);
			$ta[0].select();
			document.execCommand('copy');
			$ta.remove();
			alert('Log copied to clipboard (' + rows.length + ' entries).');
		}
	});

	/* ── Auto-dismiss success notices after 4 s ─────────────────────────── */
	setTimeout(function () {
		$('.notice-success.is-dismissible').fadeOut(600);
	}, 4000);

}(jQuery));
