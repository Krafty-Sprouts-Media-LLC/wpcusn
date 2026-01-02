# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

