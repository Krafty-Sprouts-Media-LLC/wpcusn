=== WPCUSN - WordPress ClickUp Sync-nator ===
Contributors: kraftysprouts
Tags: clickup, sync, wordpress, tasks, status
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-way status synchronization between WordPress posts and ClickUp tasks using slug-based matching.

== Description ==

WPCUSN (WordPress ClickUp Sync-nator) enables seamless two-way synchronization of post statuses between WordPress and ClickUp tasks. Posts are automatically linked to ClickUp tasks by matching post slugs to task names.

== Features ==

* **Auto-Linking**: Automatically links WordPress posts to ClickUp tasks when slug matches task name
* **Two-Way Sync**: Syncs status changes in both directions (WordPress ↔ ClickUp)
* **OAuth2 Support**: Secure OAuth2 authentication with ClickUp (API key fallback available)
* **Status Mapping**: Configurable status mappings between WordPress and ClickUp
* **Webhook Support**: Receives status updates from ClickUp via webhooks
* **Manual Controls**: Force sync and unlink tasks from post editor
* **Sync Logging**: Tracks all sync events for monitoring

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wpcusn` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → ClickUp Sync to configure the plugin.
4. Set up OAuth2 credentials or use an API key.
5. Configure your ClickUp List ID.
6. Set up webhook in ClickUp (optional, for ClickUp → WordPress sync).

== Configuration ==

**OAuth2 Setup (Recommended):**
1. Register an app at https://app.clickup.com/settings/apps
2. Set redirect URI to: `https://yourdomain.com/wp-admin/options-general.php?page=wpcusn&action=oauth_callback`
3. Enter Client ID and Client Secret in plugin settings
4. Click "Connect to ClickUp"

**API Key Setup (Alternative):**
1. Get your API key from ClickUp Settings → Apps → API Token
2. Enter the API key in plugin settings

**List ID:**
1. Open your ClickUp list
2. Copy the List ID from the URL: `app.clickup.com/.../{list_id}`
3. Enter in plugin settings

**Webhook Setup:**
1. In ClickUp: Space Settings → Integrations → Webhooks
2. Add webhook with URL: `https://yourdomain.com/wp-json/clickup/v1/webhook`
3. Select event: "Task Status Updated"
4. Filter by your list

== Status Mappings ==

**WordPress → ClickUp:**
* draft → IN PROGRESS
* pending → PENDING (Review)
* ready → READY
* schedulable → SCHEDULED
* scheduled → SCHEDULED
* publish → PUBLISHED

**ClickUp → WordPress:**
* TO DO → draft
* IN PROGRESS → draft
* PENDING (Review) → pending
* READY → ready
* SCHEDULED → schedulable
* PUBLISHED → publish

== Frequently Asked Questions ==

= How does auto-linking work? =

When you save a post with a slug (e.g., "best-cat-breeds-for-seniors"), the plugin searches ClickUp for a task with the matching name ("Best Cat Breeds for Seniors"). If found, it automatically links them.

= Can I manually link a task? =

Yes, you can manually link tasks by entering the task ID in the post meta box, or the plugin will auto-link on save if the slug matches.

= What happens if a task isn't found? =

The post will not be linked, but you can manually link it later or rename the ClickUp task to match the post slug.

= Does this work with custom post types? =

Currently, the plugin only works with the default "post" post type. Support for custom post types may be added in future versions.

== Changelog ==

= 1.5.3 =
* Fixed: Auto-link no longer runs during Bulk Edit or Quick Edit on the posts list (avoids many ClickUp API calls in one request). Full editor save, manual “Try Auto-Link Now”, and the scheduled cron job still perform linking as before.

= 1.5.2 =
* Fixed: ClickUp API debug logging now uses the same database log as all other sync events (no more legacy wp_options writes); entries show in the settings log panel.
* Fixed: Log viewer row formatting initializes message text per entry correctly.

= 1.5.1 =
* Fixed: Settings sync log panel reads from the database table introduced in 1.5.0 (was still reading the old option).

= 1.5.0 =
* Added: Dedicated sync log database table; replaces large serialized log in wp_options.
* Changed: WordPress to ClickUp status sync runs via WP-Cron after save so the editor does not block on ClickUp API calls.

= 1.4.4 =
* Fixed: Removed auto AJAX ClickUp calls on every post edit load; reduced API timeout; cached list status validation; sync log option no longer autoloads.

= 1.4.0 =
* Fixed: Auto-link now paginates through all pages when searching a specific List ID (previously only fetched first 100 tasks, causing misses on large lists)
* Fixed: List-based search now respects the "Include Closed Tasks" setting

= 1.0.0 =
* Initial release
* OAuth2 authentication support
* Auto-linking by slug
* Two-way status synchronization
* Webhook support
* Admin settings page
* Post meta box with manual controls
* Sync logging

== Upgrade Notice ==

= 1.0.0 =
Initial release of WPCUSN. Configure OAuth2 or API key in settings to get started.

