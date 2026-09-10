=== Crosspost to Pixelfed ===
Contributors: evecodes
Tags: pixelfed, fediverse, crosspost, social media, images
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically crosspost image posts from your WordPress blog to any Pixelfed instance.

== Description ==

Crosspost to Pixelfed connects your WordPress blog to any Pixelfed instance and automatically shares your image posts to the Fediverse.

**Features:**

* One-click OAuth 2.0 connection — no manual token generation required
* Works with pixelfed.social or any self-hosted Pixelfed instance
* Crossposts up to 4 images per post (Pixelfed's limit)
* Customisable caption templates with `{title}`, `{url}`, `{excerpt}`, `{tags}` placeholders
* Show/hide toggle on all token fields
* Manual access token entry for advanced users
* Built-in debug log with level filtering and search
* Settings and Debug Log links directly on the Plugins page
* Test crosspost button — verify your setup without publishing

Only posts containing at least one image (featured image, attached images, or inline content images) will be crossposted.

== Installation ==

1. Upload the `crosspost-to-pixelfed` folder to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Go to **Settings → Crosspost to Pixelfed**.
4. Enter your Pixelfed instance URL (defaults to `https://pixelfed.social`).
5. Click **Connect with Pixelfed** and follow the OAuth prompts.
6. Configure your caption template and save.

== Frequently Asked Questions ==

= Which Pixelfed instances are supported? =

Any Pixelfed instance, including pixelfed.social and self-hosted instances. Enter the full URL (e.g. `https://pixelfed.social`) in the settings.

= What types of posts get crossposted? =

Only posts with at least one image. The plugin checks for featured images, attached images, and inline images in post content.

= Can I use a manually-generated access token instead of OAuth? =

Yes. On the settings page, expand the "Or paste an access token manually" section and enter your token there.

= Where can I see what the plugin has been doing? =

Go to **Tools → Pixelfed Log** or click the **Debug Log** link on the Plugins page.

= Will existing posts be crossposted when I activate the plugin? =

No. Only new posts published after the plugin is connected will be crossposted automatically. You can manually crosspost any existing post using the Test Crosspost section.

== Changelog ==

= 1.1.0 =
* Added [pixelfed_feed] shortcode to display your Pixelfed feed or a hashtag feed on any page/post.
* Added live auto-refresh for the feed shortcode when a new crosspost is published.
* Added configurable "Post Types to Monitor" setting so custom post types (e.g. from Mastodon-API apps like Tusky via "Enable Mastodon Apps") can be auto-crossposted, not just the default Post type.
* Added a save_post catch-all hook for more reliable detection of third-party publishing apps.
* Added fallback image detection for plain <img> tags and externally-hosted images.
* Added a connection-error banner to the Debug Log page for faster troubleshooting.
* Added a manual "Clear Feed Cache" button to Settings.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds the [pixelfed_feed] shortcode, configurable post type monitoring (fixes auto-crosspost for Mastodon-API apps like Tusky), and improved debug logging.

= 1.0.0 =
Initial release.
