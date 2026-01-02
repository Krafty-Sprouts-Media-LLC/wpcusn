# WPCUSN - WordPress ClickUp Sync-nator - Development Plan

## Plugin Overview
**Name:** WPCUSN - WordPress ClickUp Sync-nator  
**Slug:** wpcusn  
**Purpose:** Two-way status synchronization between WordPress posts and ClickUp tasks using slug-based matching  
**No AI Required:** Direct slug → task name matching  
**Auto-Updates:** YahnisElsts Plugin Update Checker (GitHub releases)

---

## Core Features

### 1. Auto-Linking System
- When post saved with slug, search ClickUp for matching task name
- Convert slug to task name: `best-cat-breeds-for-seniors` → `Best Cat Breeds for Seniors`
- Store task ID in post meta: `_clickup_task_id`
- One-time linking per post

### 2. Status Mapping

**WordPress → ClickUp**
- `draft` → `IN PROGRESS`
- `ready` → `READY`
- `schedulable` → `PENDING`
- `scheduled` → `PENDING`
- `publish` → `PUBLISHED`

**ClickUp → WordPress**
- `TO DO` → `draft`
- `IN PROGRESS` → `draft`
- `READY` → `ready`
- `PENDING` → `schedulable`
- `PUBLISHED` → `publish`

### 3. Two-Way Sync
- **WP → ClickUp:** Hook `transition_post_status`, update ClickUp via API
- **ClickUp → WP:** Webhook endpoint receives ClickUp status changes, updates WordPress

---

## File Structure

```
wpcusn/
├── wpcusn.php (main plugin file)
├── vendor/
│   └── plugin-update-checker/ (YahnisElsts library)
├── includes/
│   ├── class-clickup-api.php (API wrapper)
│   ├── class-clickup-oauth.php (OAuth2 handler)
│   ├── class-status-mapper.php (status conversions)
│   ├── class-task-linker.php (slug matching logic)
│   └── class-webhook-handler.php (ClickUp webhook receiver)
├── admin/
│   ├── class-settings-page.php (admin settings UI)
│   └── views/
│       ├── settings-page.php (HTML template)
│       └── oauth-callback.php (OAuth2 callback handler)
└── readme.txt
```

---

## Development Phases

### Phase 1: Core Setup (Day 1)
**Files:** Main plugin file + OAuth2 + API wrapper

- [ ] Plugin header with GitHub URI for auto-updates
- [ ] Include YahnisElsts Plugin Update Checker library
- [ ] OAuth2 flow implementation
  - [ ] Register ClickUp app (get client_id, client_secret)
  - [ ] "Connect to ClickUp" button in settings
  - [ ] OAuth callback handler
  - [ ] Token storage (access + refresh tokens)
  - [ ] Auto token refresh on expiry
- [ ] Fallback: Manual API key option
- [ ] Settings page skeleton
- [ ] ClickUp API authentication test
- [ ] Store: OAuth tokens OR API Key, List ID, Status Names

**Plugin Header:**
```php
/**
 * Plugin Name: WPCUSN - WordPress ClickUp Sync-nator
 * Plugin URI: https://github.com/kraftysprouts/wpcusn
 * Description: Two-way status synchronization between WordPress and ClickUp
 * Version: 1.0.0
 * Author: Krafty Sprouts Media, LLC
 * Author URI: https://animalofthings.com
 * GitHub Plugin URI: kraftysprouts/wpcusn
 * Text Domain: wpcusn
 */
```

**Deliverable:** OAuth2 connection works OR API key authentication successful

---

### Phase 2: Auto-Linking (Day 2)
**Files:** `class-task-linker.php`

- [ ] Hook into `save_post`
- [ ] Check if `_clickup_task_id` already exists
- [ ] Convert `post_name` (slug) to title case
- [ ] Search ClickUp list for matching task name
- [ ] Store task ID in post meta
- [ ] Add admin notice on successful link

**Testing:**
- Create post with slug "best-cat-breeds-for-seniors"
- Verify finds ClickUp task "Best Cat Breeds for Seniors"
- Check post meta has task ID stored

---

### Phase 3: WP → ClickUp Sync (Day 3)
**Files:** `class-status-mapper.php`

- [ ] Hook `transition_post_status`
- [ ] Get task ID from post meta
- [ ] Map WordPress status to ClickUp status
- [ ] Update task via ClickUp API
- [ ] Log sync events (success/failure)
- [ ] Add manual "Sync to ClickUp" button in post editor

**Testing:**
- Change post from draft → ready → schedulable → publish
- Verify ClickUp task moves through: IN PROGRESS → READY → PENDING → PUBLISHED

---

### Phase 4: ClickUp → WP Sync (Day 4)
**Files:** `class-webhook-handler.php`

- [ ] Register REST API endpoint: `/wp-json/clickup/v1/webhook`
- [ ] Receive ClickUp webhook payload
- [ ] Extract task name and new status
- [ ] Find WordPress post by slug (from task name)
- [ ] Map ClickUp status to WordPress status
- [ ] Update post status
- [ ] Return success/error response

**ClickUp Webhook Setup:**
- Event: Task Status Updated
- URL: `https://animalofthings.com/wp-json/clickup/v1/webhook`
- List: Your keywords list

**Testing:**
- Move task in ClickUp: READY → PENDING
- Verify WordPress post changes: ready → schedulable

---

### Phase 5: Admin Interface (Day 5)
**Files:** `class-settings-page.php`, `settings-page.php`

**Settings Page Fields:**
- **Authentication Section:**
  - OAuth2: "Connect to ClickUp" button (recommended)
  - Manual API Key field (advanced users)
  - Connection status indicator
  - Disconnect/Re-authorize button
- **Configuration:**
  - List ID (text field with "Browse Lists" dropdown if OAuth)
  - Status mapping table (verify mappings)
- **Webhook Section:**
  - Webhook URL display (copy button)
  - Webhook setup instructions
- **Monitoring:**
  - Sync log viewer (last 50 events)
  - Sync statistics (success rate, last sync time)

**Post Edit Screen:**
- Meta box showing linked ClickUp task
- Task name, current status, last sync time
- "Unlink Task" button
- "Force Sync Now" button

---

### Phase 6: Error Handling & Logging (Day 6)

- [ ] API rate limit handling (ClickUp: 100 req/min)
- [ ] Retry logic for failed API calls
- [ ] Database table for sync logs
- [ ] Email admin on repeated failures
- [ ] Bulk sync tool (sync all posts at once)

**Log Table Schema:**
```sql
CREATE TABLE wp_clickup_sync_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT,
    task_id VARCHAR(50),
    direction ENUM('wp_to_clickup', 'clickup_to_wp'),
    old_status VARCHAR(50),
    new_status VARCHAR(50),
    success BOOLEAN,
    error_message TEXT,
    synced_at DATETIME
);
```

---

## Settings Configuration Required

### ClickUp OAuth2 App Setup (Recommended)
1. **Register App:** https://app.clickup.com/settings/apps
2. **Create New App:**
   - App Name: WPCUSN
   - Redirect URL: `https://yourdomain.com/wp-admin/options-general.php?page=wpcusn&action=oauth_callback`
3. **Copy Credentials:**
   - Client ID
   - Client Secret
4. **Required Scopes:**
   - `task:read` - Read tasks
   - `task:write` - Update task status  
   - `list:read` - Browse lists

### Alternative: Manual API Key
1. **Get API Key:** ClickUp → Settings → Apps → API Token
2. **Get List ID:** 
   - Open your keywords list in ClickUp
   - URL format: `https://app.clickup.com/{team_id}/{space_id}/{folder_id}/{list_id}`
   - Copy the last segment

### Auto-Updates Setup
1. **Install Plugin Update Checker:**
   - Download: https://github.com/YahnisElists/plugin-update-checker
   - Place in `vendor/plugin-update-checker/`
2. **Create GitHub Releases:**
   - Tag format: `v1.0.0`, `v1.0.1`, etc.
   - WordPress checks for new releases
   - Users see updates in dashboard

### WordPress Custom Statuses
Register custom post statuses if not already done:
```php
register_post_status('ready', [...]);
register_post_status('schedulable', [...]);
```

### Webhook Setup (ClickUp Side)
1. ClickUp → Space Settings → Integrations → Webhooks
2. Add webhook:
   - **Events:** Task Status Updated
   - **URL:** `https://animalofthings.com/wp-json/clickup/v1/webhook`
   - **List Filter:** Select your keywords list

---

## Key Functions Summary

### Auto-Link on Save
```
save_post hook
→ Get slug
→ Convert to title case
→ Search ClickUp tasks
→ Store task ID
```

### WP → ClickUp Sync
```
transition_post_status hook
→ Get task ID
→ Map WP status → ClickUp status
→ PUT request to ClickUp API
→ Log result
```

### ClickUp → WP Sync
```
Webhook receives POST
→ Extract task name
→ Convert to slug
→ Find post by slug
→ Map ClickUp status → WP status
→ Update post
→ Return response
```

---

## Testing Checklist

- [ ] Link new post with exact slug match
- [ ] Handle posts with no matching ClickUp task
- [ ] Handle ClickUp tasks with no matching post
- [ ] Status sync: draft → ready → schedulable → publish
- [ ] Reverse sync from ClickUp works
- [ ] Webhook receives and processes correctly
- [ ] API errors logged properly
- [ ] Rate limiting doesn't break sync
- [ ] Bulk sync 100+ posts works
- [ ] Unlink and re-link task works

---

## Future Enhancements (Optional)

- Sync custom fields (author, category, etc.)
- Sync ClickUp due dates to WordPress publish dates
- Support multiple ClickUp lists
- Sync post content to ClickUp task description
- Dashboard widget showing sync statistics
- WP-CLI commands for bulk operations

---

## Estimated Timeline
- **Phase 1:** 2 days (OAuth2 + core setup)
- **Phase 2-3:** 2 days (auto-link + WP→ClickUp sync)
- **Phase 4-5:** 2 days (two-way sync + UI)
- **Phase 6:** 1 day (polish + error handling)

**Total:** ~1 week for full-featured plugin with OAuth2

---

## Notes
- No AI needed - direct string matching
- OAuth2 provides better UX for public plugin
- Manual API key available as fallback
- YahnisElsts Plugin Update Checker handles GitHub updates
- ClickUp API is RESTful, well-documented
- WordPress REST API handles webhook receiving
- Consider rate limits: batch updates if syncing 100+ posts
- Store webhook secret for security (optional)
- OAuth tokens auto-refresh before expiry