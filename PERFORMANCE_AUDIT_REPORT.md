# WPCUSN Performance Audit: Unlinked Post Delays

**Date:** 09/04/2026  
**Plugin:** WPCUSN - WordPress ClickUp Sync-nator  
**Version Audited:** 1.5.4  
**Resolved In:** 1.5.5  
**Severity:** CRITICAL ✅ FIXED  

---

## Executive Summary

The `auto_link_task()` method is hooked to WordPress `save_post` and runs **synchronously on the admin thread**. For any post that has no matching ClickUp task, this method exhaustively paginates through every task in the ClickUp list AND then the entire ClickUp space — all while the user waits for the page to respond. This affects **every WordPress operation that fires `save_post`**: creating a new post, saving a draft, publishing, trashing, and restoring from trash.

Despite multiple performance-related fixes in versions 1.4.3 through 1.5.4, **this specific bottleneck was never addressed**. The Phase 2 async fix (v1.5.0) only fixed `transition_post_status` → `sync_to_clickup()`, not `save_post` → `auto_link_task()`.

---

## Root Cause

### The Bottleneck: `class-task-linker.php` Line 62

```php
add_action( 'save_post', array( $this, 'auto_link_task' ), 10, 2 );
```

This hook fires on **every** `save_post` event. The `auto_link_task()` method (line 221) does the following for unlinked posts:

1. Checks `_clickup_task_id` meta — if empty, proceeds to search
2. Calls `$api->search_tasks()` — which is a **synchronous**, **blocking** multi-page API crawl
3. `search_tasks()` first calls `search_tasks_in_list()` — paginates through every page of the configured list
4. If no match found, falls back to `search_tasks_in_team()` — paginates through every page in the entire space
5. Only after exhausting ALL pages does it finally return "no match found"

### Why It's Slow: The API Crawl Math

Each ClickUp API page returns 100 tasks maximum, with a **10-second timeout** per request.

| ClickUp Tasks in Workspace | List Pages | Team/Space Pages | Total API Calls | Worst Case Duration |
|---|---|---|---|---|
| 250 | 3 | 3 | **6** | **60s** (1 min) |
| 500 | 5 | 5 | **10** | **100s** (~1.5 min) |
| 1000 | 5 | 10 | **15** | **150s** (~2.5 min) |
| 2000 | 5 | 20 | **25** | **250s** (~4 min) |

When a keyword **does not exist** in ClickUp, there is no early-exit — every single page must be checked.

---

## All Affected WordPress Operations

The following operations all fire `save_post`, triggering the full synchronous ClickUp search:

| Operation | How `save_post` Fires | Blocked? |
|---|---|---|
| **Create new post & save** | `wp_insert_post()` fires `save_post` | ✅ YES — BLOCKED |
| **Save/update draft** | `wp_update_post()` fires `save_post` | ✅ YES — BLOCKED |
| **Publish post** | `wp_publish_post()` fires `save_post` | ✅ YES — BLOCKED |
| **Move to Trash** | `wp_trash_post()` → `wp_insert_post()` fires `save_post` | ✅ YES — BLOCKED |
| **Restore from Trash** | `wp_untrash_post()` → `wp_insert_post()` fires `save_post` | ✅ YES — BLOCKED |
| **Schedule post** | `wp_update_post()` fires `save_post` | ✅ YES — BLOCKED |
| **Bulk Edit** | Fires `save_post` per post | ⛔ Skipped (fixed in v1.5.3) |
| **Quick Edit** | Fires `save_post` via AJAX | ⛔ Skipped (fixed in v1.5.3) |

> **Key insight:** The plugin tries to auto-link a post to ClickUp even when the post is being **trashed** (deleted). This is completely unnecessary work — there is no reason to search for a ClickUp task match when the user is deleting the post.

---

## What Previous Fixes DID and DID NOT Address

### ❌ NEVER Fixed — The Core Issue

The `save_post` → `auto_link_task()` → synchronous ClickUp API exhaustive search path has **never been made asynchronous or guarded against futile repeated searches**.

### ✅ What WAS Fixed (Related but Different Issues)

| Version | Fix | What It Actually Fixed |
|---|---|---|
| **v1.5.4** | Hyphen normalization in matching | Improved matching accuracy, but search still runs synchronously |
| **v1.5.3** | Skip bulk edit / quick edit | Only skips those two specific code paths; normal save is untouched |
| **v1.5.0** | `sync_to_clickup()` moved to WP-Cron (Phase 2) | This fixed `transition_post_status` hook only — NOT `save_post` hook |
| **v1.4.4** | Removed auto-AJAX on post-edit page load | Fixed the page-load AJAX trigger, but `save_post` hook is unrelated |
| **v1.4.4** | API timeout reduced from 30s to 10s | Reduced worst-case per-call from 30s to 10s, but total is still 60-250s |
| **v1.4.4** | Cached `status_exists_in_list()` | This is in `sync_to_clickup()`, not `auto_link_task()` |
| **v1.4.3** | Memory optimization for large task lists | Reduced memory usage, but execution still blocks the admin thread |
| **v1.4.2** | Removed 50-page search cap | Actually made the problem WORSE by allowing unlimited pages to scan |

---

## Three Missing Guards in `auto_link_task()`

### 1. No Exclusion for Non-Linkable Statuses

The method does not check the post status. It fires for `trash`, `auto-draft`, and other statuses where auto-linking makes no sense.

```php
// MISSING from auto_link_task():
$skip_statuses = array( 'trash', 'auto-draft', 'inherit' );
if ( in_array( $post->post_status, $skip_statuses, true ) ) {
    return;
}
```

### 2. No "Not Found" Cooldown / Cache

Every single save re-triggers the full exhaustive search for the same slug. If you save a draft 5 times, it runs 5 identical full search cycles (potentially 5 × 2-3 minutes = 10-15 minutes of wasted API calls).

```php
// MISSING: A transient to skip recently-failed lookups
$cooldown_key = 'wpcusn_no_match_' . md5( $slug );
if ( get_transient( $cooldown_key ) ) {
    return; // Already searched recently, no point retrying
}
// ... after search fails:
set_transient( $cooldown_key, true, 6 * HOUR_IN_SECONDS );
```

### 3. Runs Synchronously on the Admin Thread

Unlike `sync_to_clickup()` which was correctly moved to WP-Cron in Phase 2 (v1.5.0), `auto_link_task()` still runs inline — blocking the entire page response while it makes 5-25+ API calls.

```php
// CURRENT (blocking):
add_action( 'save_post', array( $this, 'auto_link_task' ), 10, 2 );

// SHOULD BE (non-blocking, like sync_to_clickup):
// Schedule the search for WP-Cron, return to browser immediately
```

---

## Execution Flow: What Happens Today (Unlinked Post)

```
User clicks "Save Draft" on a new post
    ↓
WordPress fires save_post hook
    ↓
auto_link_task() runs (class-task-linker.php:221)
    ↓
Checks: post_type=post? ✓
Checks: not autosave? ✓
Checks: not bulk/quick edit? ✓
Checks: has _clickup_task_id? ✗ (not linked)
Checks: has slug? ✓
Checks: has space_id/list_id? ✓
    ↓
Calls search_tasks() — SYNCHRONOUS, BLOCKING
    ↓
search_tasks_in_list():
  → API call: /list/{id}/task?page=0 ... waits up to 10s
  → API call: /list/{id}/task?page=1 ... waits up to 10s
  → API call: /list/{id}/task?page=2 ... waits up to 10s
  → (continues until no more pages)
  → No match found in list
    ↓
Fallback: search_tasks_in_team():
  → API call: /team/{id}/task?page=0 ... waits up to 10s
  → API call: /team/{id}/task?page=1 ... waits up to 10s
  → API call: /team/{id}/task?page=N ... waits up to 10s
  → (continues until ALL pages exhausted)
  → No match found anywhere
    ↓
Logs "No matching task found"
    ↓
Returns to WordPress (finally)
    ↓
User sees the page respond (30s-250s later)
```

---

## Recommended Fix

| Priority | Fix | Impact | File |
|---|---|---|---|
| **P0** | Skip `trash`, `auto-draft`, `inherit` statuses | Eliminates 100% of trash/auto-draft delays | `class-task-linker.php` |
| **P0** | Add "not found" cooldown transient per slug | Prevents repeated futile searches on consecutive saves | `class-task-linker.php` |
| **P1** | Move `auto_link_task()` to WP-Cron (async) | Zero admin-thread blocking, same pattern as Phase 2 `sync_to_clickup()` | `class-task-linker.php` |

### P0 fixes are simple guard clauses that can be added in minutes.
### P1 mirrors the existing Phase 2 architecture already proven in `class-status-mapper.php`.

---

## Files to Modify

- `includes/class-task-linker.php` — `auto_link_task()` method (lines 221-325)
- `wpcusn.php` — Register new cron action hook (if going async)
- `CHANGELOG.md` — Document the fix
- `readme.txt` — Update stable tag and changelog

---

*Report prepared for code review.*
