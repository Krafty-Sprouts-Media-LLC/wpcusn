# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.2] - 02/01/2026

### Fixed
- **Critical:** Increased max page limit from 10 to 50 (now handles 5000+ tasks instead of 1000)
- **Added fuzzy matching fallback:** If exact match fails but partial matches exist (task name contains search term or vice versa), the plugin will now use partial matches
- Enhanced debug logging shows sample task names from API and any partial matches found
- Added trimming to task name comparison to handle whitespace differences

## [1.2.1] - 02/01/2026

### Fixed
- **Critical:** Fixed auto-linking not finding tasks. Implemented the Get Filtered Team Tasks endpoint (`/team/{team_id}/task`) which properly searches across ALL lists in a space
- Added comprehensive API debug logging to Sync Logs for easier production debugging - now shows exactly what endpoint is called, how many tasks are found, and matching results
- Added helper methods: `search_tasks_in_team()`, `log_api_debug()`, `filter_tasks_by_name()`
- Added fallback search method for backwards compatibility

## [1.2.0] - 02/01/2026

### Added
- Auto-run auto-linking on post edit page load - automatically attempts to link posts when viewing existing drafts
- Comprehensive production logging - sync log now acts as a complete log book for production debugging (replaces debug logs)
- Webhook event logging - all webhook received events and errors are now logged in sync log
- Enhanced sync log display - improved formatting with event types, better error messages, and detailed information
- Manual linking options in post meta box - "Try Auto-Link Now" button and manual Task ID input for easier debugging

### Changed
- Updated all @since tags to 1.2.0 for new features and methods
- Sync log now shows all plugin activity including auto-linking attempts, webhook events, and API errors
- Improved sync log table with event type column and better formatting for different event types

### Fixed
- Case-insensitive task matching in ClickUp API search to align with slug-to-title conversion
- Auto-linking logging now includes detailed failure reasons and search parameters
- Sync failures now properly logged with reasons (missing task ID, status mapping, list ID, etc.)

## [1.1.9] - 02/01/2026

### Fixed
- Fixed space ID not saving when selecting from dropdown - manual input was overriding dropdown selection
- Fixed duplicate webhook success notices - removed manual transient storage that caused duplicates
- Fixed webhook success notice not displaying after creation - restored transient storage for custom form handlers
- Fixed spaces dropdown showing only IDs - now displays "Space Name (Team Name)" format
- Spaces now auto-load on page load - no need to click "Load Spaces" button every time
- Fixed nested forms issue - moved webhook forms outside main form to prevent HTML validation issues
- Improved space selection persistence - selected space now correctly saves and displays after page reload

## [1.1.8] - 02/01/2026

### Fixed
- Prevented webhook actions from clearing settings and ensured team ID is captured and sent for webhook creation
- Improved ClickUp webhook creation endpoint to use the required team path and added fallback to first team when not provided
- Hid "Disconnected" notice during settings saves; it only shows when explicitly requested
- Better API error logging with endpoint and response details

## [1.1.7] - 02/01/2026

### Fixed
- Fixed webhook creation/deletion clearing all settings - webhook actions now skip settings save to preserve existing configuration
- Settings are only saved when actually submitted in the form, not when webhook buttons are clicked

## [1.1.6] - 02/01/2026

### Changed
- Replaced WordPress Settings API form handler with custom form handler for full control over redirect behavior
- Form now submits to custom `admin-post.php` handler instead of `options.php`
- Settings are now saved manually with proper sanitization and nonce verification

### Fixed
- Fixed redirect to `options.php` after saving settings - now always redirects back to plugin settings page
- API Key authentication now properly recognized - Space dropdown and Load Spaces button work with API key
- Added debug logging (enabled when `WP_DEBUG` and `WP_DEBUG_LOG` are true) to help troubleshoot issues

## [1.1.5] - 2026-01-02

### Fixed
- Fixed settings save redirect issue: selecting a Space and clicking Save now properly stays on the plugin settings page with "Settings saved" message
- Replaced unreliable wp_redirect filter with pre_set_transient_settings_errors hook for more reliable redirect handling

## [1.1.4] - 2026-01-02

### Fixed
- Removed aggressive redirect hook that prevented options from being saved (Space selection now saves correctly)

## [1.1.3] - 2026-01-02

### Fixed
- Saving settings (including selecting a Space) now redirects back to the plugin settings page instead of options.php

## [1.1.2] - 2026-01-02

### Fixed
- Fixed redirect to options.php after saving settings - now properly redirects back to settings page with success message
- Removed confusing manual webhook setup instructions - webhook creation is now automatic only
- Cleaned up webhook configuration section for better UX

## [1.1.1] - 2026-01-02

### Fixed
- List ID now automatically retrieved from task - no longer required in settings
- Sync works automatically across all lists in space without configuring each list ID
- Removed forced title case conversion - task name matching is now case-insensitive
- Task matching now works regardless of how the task name is capitalized in ClickUp

## [1.1.0] - 2026-01-02

### Fixed
- Fixed redirect to options.php after saving settings - now redirects back to settings page
- Settings page now shows "Settings saved" message after successful save

## [1.0.9] - 2026-01-02

### Fixed
- Fixed disconnect action being triggered when saving settings
- Disconnect button now uses separate admin-post handler to prevent conflicts with settings form
- Settings can now be saved without accidentally disconnecting from ClickUp

## [1.0.8] - 2026-01-02

### Fixed
- Fixed missing JavaScript code for "Load Spaces" button
- Load Spaces button now works correctly to fetch and display spaces from ClickUp API

## [1.0.7] - 2026-01-02

### Added
- Space dropdown selector - automatically fetch and display all spaces when connected
- "Load Spaces" button to fetch spaces from ClickUp API
- Manual Space ID entry option (toggle between dropdown and manual input)
- AJAX handler to get teams and spaces from ClickUp API

### Changed
- Space ID field now shows dropdown when connected to ClickUp
- Improved UX - users can select space from dropdown instead of manually entering ID

## [1.0.6] - 2026-01-02

### Added
- Automatic webhook creation via ClickUp API
- "Create Webhook Automatically" button in settings
- Webhook management (create/delete) directly from WordPress
- Webhook ID and secret storage for verification

### Changed
- Updated webhook setup instructions to reflect API-based creation (no longer available in UI)
- Webhook configuration now uses Space ID instead of List ID

## [1.0.5] - 2026-01-02

### Changed
- **BREAKING:** Changed from List ID to Space ID as primary configuration
- Plugin now searches across all lists in a space instead of a single list
- List ID is now optional and can be used to limit search to a specific list
- Improved task search to work at space level for better flexibility

### Fixed
- Fixed OAuth token exchange endpoint (changed from `app.clickup.com` to `api.clickup.com`)
- Improved OAuth error handling with better error messages and logging

## [1.0.4] - 2026-01-02

### Changed
- Updated minimum WordPress version requirement to 6.8+
- Updated minimum PHP version requirement to 8.0+

## [1.0.3] - 2026-01-02

### Fixed
- Fixed OAuth authorization URL endpoint (changed from `/api/v2/oauth` to `/api` per ClickUp documentation)
- Fixed OAuth token exchange endpoint (changed from `app.clickup.com` to `api.clickup.com`)
- Fixed double-encoding of redirect_uri parameter
- Improved OAuth error handling with better error messages and logging
- OAuth authorization URL now correctly uses `https://app.clickup.com/api?` endpoint

## [1.0.2] - 2026-01-02

### Fixed
- Always show OAuth redirect URI in settings with copy button
- Reduced update check period to 1 hour for faster update detection

## [1.0.1] - 2026-01-02

### Fixed
- Fixed plugin update checker initialization error (Puc_v4_Factory not found)
- Updated to use correct namespaced factory class for plugin-update-checker v5.6
- Fixed GitHub URI header format (changed from "GitHub Plugin URI" to "GitHub URI" for library compatibility)
- Always show OAuth redirect URI in settings (previously only shown when not connected)
- Added copy button for redirect URI to make ClickUp app setup easier
- Reduced update check period from 12 hours to 1 hour for faster update detection

## [1.0.0] - 2026-01-02

### Added
- Initial release of WPCUSN plugin
- OAuth2 authentication with ClickUp (with API key fallback)
- Auto-linking system: WordPress posts automatically link to ClickUp tasks by slug matching
- Two-way status synchronization:
  - WordPress → ClickUp: Syncs on post status change
  - ClickUp → WordPress: Receives webhooks from ClickUp
- Status mapping between WordPress and ClickUp statuses
- Admin settings page with:
  - OAuth2 connection interface
  - API key option
  - List ID configuration
  - Webhook URL display
  - Sync log viewer
- Post editor meta box with:
  - Linked task information
  - Force sync button
  - Unlink task button
- Sync logging system (stores last 50 events)
- Plugin update checker integration for GitHub releases

### Technical Details
- WordPress minimum version: 5.0
- PHP minimum version: 7.4
- Uses WordPress REST API for webhook endpoint
- Stores OAuth tokens securely in WordPress options
- Auto-refreshes OAuth tokens before expiry

