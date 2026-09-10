# Crosspost to Pixelfed

A WordPress plugin that automatically crossposts image posts from your WordPress blog to any [Pixelfed](https://pixelfed.social) instance.

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple?logo=php)
![License](https://img.shields.io/badge/License-GPLv2%2B-green)
![Version](https://img.shields.io/badge/Version-1.1.0-orange)

---

## Features

- 🔗 **One-click OAuth 2.0** — connect your Pixelfed account directly from the WordPress admin, no manual token generation required
- 🌐 **Any Pixelfed instance** — works with [pixelfed.social](https://pixelfed.social) or any self-hosted Pixelfed server
- 🖼️ **Image-first** — only crossposts posts that contain at least one image (featured image, attached images, or inline content images)
- 📸 **Up to 4 images** per Pixelfed post (Pixelfed's limit)
- ✏️ **Caption templates** — customise captions with `{title}`, `{url}`, `{excerpt}`, and `{tags}` placeholders
- 👁️ **Show/hide token** — toggle visibility on all access token fields
- 🔑 **Manual token fallback** — paste an existing token for advanced users
- 📋 **Debug log** — every action is logged with level filtering, keyword search, and copy-to-clipboard
- 🔗 **Plugin page links** — Settings and Debug Log links appear directly on the Plugins admin page
- 🧪 **Test crosspost** — send any existing post to Pixelfed instantly to verify your setup

---

## Screenshots

| Settings page | Debug log |
|---|---|
| Connected account card with avatar, handle, and follower count | Full log with level badges, filtering, and search |

---

## Requirements

- WordPress 5.8 or higher
- PHP 8.0 or higher
- A Pixelfed account (pixelfed.social or self-hosted)

---

## Installation

### From ZIP

1. Download the latest release ZIP from the [Releases](../../releases) page.
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.

### Manual

1. Clone or download this repository.
2. Copy the `crosspost-to-pixelfed` folder to `/wp-content/plugins/`.
3. Activate from **Plugins → Installed Plugins**.

---

## Setup

1. Go to **Settings → Crosspost to Pixelfed**.
2. Enter your Pixelfed instance URL (defaults to `https://pixelfed.social`).
3. Click **Connect with Pixelfed** — you'll be redirected to Pixelfed to authorise the app.
4. Once connected, your account avatar, handle, and follower count appear in the account card.
5. Configure your caption template and auto-post preference, then click **Save Settings**.

---

## Caption Placeholders

| Placeholder | Replaced with |
|---|---|
| `{title}` | Post title |
| `{url}` | Post permalink |
| `{excerpt}` | Post excerpt (max 250 characters) |
| `{tags}` | WordPress tags as `#hashtags` |

**Default template:** `{title} {url}`

Captions are automatically truncated to 500 characters (Pixelfed's limit).

---

## OAuth Flow

```
WordPress                        Pixelfed Instance
    |                                   |
    |── POST /api/v1/apps ─────────────>|  Register app, get client_id/secret
    |<── client_id, client_secret ──────|
    |                                   |
    |── Redirect user to /oauth/authorize ──>|  User logs in & approves
    |<── Redirect back with ?code ──────|
    |                                   |
    |── POST /oauth/token ─────────────>|  Exchange code for token
    |<── access_token ──────────────────|
    |                                   |
    |── GET /api/v1/accounts/           |
    |   verify_credentials ────────────>|  Fetch account info
    |<── username, avatar, etc. ────────|
```

---

## Debug Log

Navigate to **Tools → Pixelfed Log** or click **Debug Log** on the Plugins page.

- Filter by level: Success, Info, Warning, Error
- Search messages by keyword
- Copy entire log to clipboard
- Clear log (automatically capped at 500 entries)

---

## Development

### Code Standards

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/). To check the code:

```bash
# Install dependencies
composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs --dev -W

# Run PHPCS
phpcs --standard=WordPress --extensions=php .

# Auto-fix
phpcbf --standard=WordPress --extensions=php .
```

### File Structure

```
crosspost-to-pixelfed/
├── crosspost-to-pixelfed.php          # Main plugin file
├── uninstall.php                      # Cleanup on uninstall
├── readme.txt                         # WordPress.org readme
├── phpcs.xml                          # PHPCS config
├── includes/
│   ├── functions.php                  # Global helper (ctf_log)
│   ├── class-ctf-pixelfed-api.php     # Pixelfed REST API wrapper
│   ├── class-ctf-admin-settings.php   # Settings page + OAuth flow
│   ├── class-ctf-admin-debug.php      # Debug log page
│   └── class-ctf-crosspost.php        # Crosspost logic
└── assets/
    ├── admin.css                      # Admin styles
    └── admin.js                       # Token toggle, test post, log copy
```

---

## FAQ

**Which Pixelfed instances are supported?**
Any Pixelfed instance — enter the full URL (e.g. `https://pixelfed.social`) in the settings.

**What posts get crossposted?**
Only posts with at least one image. The plugin checks featured images, attached images, and inline `<img>` tags in the post content.

**Will existing posts be crossposted when I activate the plugin?**
No — only new posts published after connecting will be crossposted automatically. Use the Test Crosspost section to manually send any existing post.

**Can I use a manually-generated token instead of OAuth?**
Yes — expand the "Or paste an access token manually" section on the settings page.

---

## Changelog

### 1.1.0
- Added `[pixelfed_feed]` shortcode (own feed or hashtag feed) with lightbox, pagination, and live auto-refresh
- Added configurable "Post Types to Monitor" — fixes auto-crosspost for posts from Mastodon-API apps (e.g. Tusky via "Enable Mastodon Apps")
- Added `save_post` catch-all hook for more reliable third-party publish detection
- Added fallback image detection for plain `<img>` tags and externally-hosted images
- Added connection-error banner on the Debug Log page

### 1.0.0
- Initial release

---

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
