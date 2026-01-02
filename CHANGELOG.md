# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

