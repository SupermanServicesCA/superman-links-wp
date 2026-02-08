# Superman Links WordPress Plugin - Testing Checklist

## Prerequisites

- WordPress site (5.0+) with PHP 7.4+
- Superman Links plugin installed and activated
- Superman Links CRM account with a client set up
- RankMath or Yoast SEO installed (for keyword tests)
- Elementor installed (for template tests, optional)

---

## 1. Plugin Installation & Activation

- [ ] Upload `superman-links` folder to `/wp-content/plugins/`
- [ ] Activate plugin via Plugins menu
- [ ] Confirm API key is auto-generated on activation
- [ ] Confirm "Settings" link appears on the Plugins list page
- [ ] Navigate to Settings > Superman Links and verify the settings page loads
- [ ] Verify API key is displayed and readonly
- [ ] Verify endpoint table shows correct site URL
- [ ] Verify example cURL command uses correct URL and key

## 2. API Key Management

- [ ] Click "Regenerate" button - confirm prompt appears
- [ ] Accept regeneration - new key appears in field
- [ ] Click "Save Settings" - key is persisted
- [ ] Verify old key no longer authenticates (returns 401)
- [ ] Verify new key authenticates successfully

---

## 3. REST API Endpoints

### 3.1 Ping (No Auth)

```bash
curl "https://yoursite.com/wp-json/superman-links/v1/ping"
```

- [ ] Returns 200 with `status: "ok"`
- [ ] Shows correct `plugin`, `version`, `wordpress`, `site_name`, `site_url`
- [ ] `rankmath_active` / `yoast_active` correctly reflects installed SEO plugin
- [ ] `elementor_active` / `elementor_version` correctly reflects Elementor status
- [ ] Works without any authentication header

### 3.2 Authentication

```bash
# No key - should fail
curl "https://yoursite.com/wp-json/superman-links/v1/pages"

# Wrong key - should fail
curl -H "X-Superman-Links-Key: wrongkey" "https://yoursite.com/wp-json/superman-links/v1/pages"

# Correct key - should succeed
curl -H "X-Superman-Links-Key: YOUR_KEY" "https://yoursite.com/wp-json/superman-links/v1/pages"
```

- [ ] No key returns 401 `invalid_api_key`
- [ ] Wrong key returns 401 `invalid_api_key`
- [ ] Correct key returns 200 with page data

### 3.3 Get Pages

```bash
curl -H "X-Superman-Links-Key: YOUR_KEY" \
  "https://yoursite.com/wp-json/superman-links/v1/pages"
```

- [ ] Returns paginated response with `pages`, `total`, `total_pages`, `current_page`, `per_page`
- [ ] Each page has: `id`, `url`, `slug`, `title`, `post_type`, `status`, `published_at`, `modified_at`, `author`, `word_count`, `seo`, `featured_image`, `categories`, `tags`
- [ ] `seo` object has: `focus_keyword`, `focus_keywords`, `seo_score`, `is_pillar`, `meta_title`, `meta_description`
- [ ] Focus keyword pulled from RankMath (or Yoast fallback)
- [ ] Multiple RankMath keywords are comma-separated in `focus_keywords` array
- [ ] Test `?post_type=page` - only returns pages
- [ ] Test `?post_type=post` - only returns posts
- [ ] Test `?post_type=post,page` - returns both
- [ ] Test `?per_page=5` - returns max 5 results
- [ ] Test `?per_page=5&page=2` - returns second page of results
- [ ] Test `?has_focus_keyword=true` - only returns posts with a focus keyword
- [ ] Per page capped at 500 even if you pass higher value
- [ ] Only published posts are returned (drafts excluded)

### 3.4 Get Single Page

```bash
curl -H "X-Superman-Links-Key: YOUR_KEY" \
  "https://yoursite.com/wp-json/superman-links/v1/pages/123"
```

- [ ] Returns single page object (same structure as list)
- [ ] Returns 404 for non-existent ID
- [ ] Returns 404 for draft/unpublished posts

### 3.5 Update Focus Keyword

```bash
curl -X POST \
  -H "X-Superman-Links-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"focus_keyword": "test keyword"}' \
  "https://yoursite.com/wp-json/superman-links/v1/pages/123/focus-keyword"
```

- [ ] Returns `success: true` with `seo_plugin` indicating which plugin was used
- [ ] Keyword is visible in RankMath/Yoast in WordPress editor
- [ ] Returns 404 for non-existent post ID
- [ ] Returns 400 if no SEO plugin is active

### 3.6 Bulk Update Focus Keywords

```bash
curl -X POST \
  -H "X-Superman-Links-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"pages": [{"url": "https://yoursite.com/page-1/", "focus_keyword": "keyword 1"}, {"url": "https://yoursite.com/page-2/", "focus_keyword": "keyword 2"}]}' \
  "https://yoursite.com/wp-json/superman-links/v1/pages/bulk-focus-keyword"
```

- [ ] Returns `updated`, `failed`, and `details` array
- [ ] Successfully matched pages show `success: true`
- [ ] Non-existent URLs show `success: false` with "Page not found"
- [ ] Missing URL or keyword shows appropriate error
- [ ] Keywords actually updated in WordPress for matched pages

### 3.7 Bulk Update Titles

```bash
curl -X POST \
  -H "X-Superman-Links-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"pages": [{"url": "https://yoursite.com/page-1/", "title": "New Title"}]}' \
  "https://yoursite.com/wp-json/superman-links/v1/pages/bulk-title"
```

- [ ] Returns `updated`, `failed`, and `details` array
- [ ] Titles actually changed in WordPress
- [ ] Non-existent URLs fail gracefully

---

## 4. Elementor Endpoints

### 4.1 List Elementor Pages

```bash
curl -H "X-Superman-Links-Key: YOUR_KEY" \
  "https://yoursite.com/wp-json/superman-links/v1/elementor/pages"
```

- [ ] Returns only pages built with Elementor
- [ ] Each page has: `id`, `url`, `slug`, `title`, `post_type`, `status`, `modified_at`, `template_type`, `elementor_version`, `widget_count`, `featured_image`
- [ ] `widget_count` is accurate
- [ ] Returns 400 if Elementor is not active
- [ ] Pagination works (`?per_page=5&page=1`)

### 4.2 Download Elementor Template

```bash
curl -H "X-Superman-Links-Key: YOUR_KEY" \
  "https://yoursite.com/wp-json/superman-links/v1/elementor/123"
```

- [ ] Returns full template with `version`, `type`, `source`, `page`, `elementor`, `seo`
- [ ] `elementor.data` contains the full widget/section tree
- [ ] `elementor.page_settings` included if set
- [ ] `elementor.css` included
- [ ] `seo` data included (focus keyword, meta title, meta description)
- [ ] Returns 400 if page is not an Elementor page
- [ ] Returns 404 for non-existent page

### 4.3 Update Elementor Page

```bash
curl -X POST \
  -H "X-Superman-Links-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"elementor": {"data": [...]}, "seo": {"focus_keyword": "test"}}' \
  "https://yoursite.com/wp-json/superman-links/v1/elementor/123"
```

- [ ] Returns `success: true` with page details
- [ ] Elementor data is saved and page renders correctly in Elementor editor
- [ ] CSS is regenerated (page looks correct on frontend)
- [ ] SEO data is applied if provided
- [ ] Returns 400 if `elementor.data` is missing

### 4.4 Import Elementor Template (Create New Page)

```bash
curl -X POST \
  -H "X-Superman-Links-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"page": {"title": "New Page", "slug": "new-page", "status": "draft"}, "elementor": {"data": [...]}}' \
  "https://yoursite.com/wp-json/superman-links/v1/elementor/import"
```

- [ ] Creates a new page with `is_new: true` in response
- [ ] Page appears in WordPress with correct title and slug
- [ ] Elementor content is intact and editable
- [ ] Default status is "draft" if not specified
- [ ] Invalid post types default to "page"
- [ ] Invalid statuses default to "draft"

---

## 5. CORS

- [ ] OPTIONS preflight to `/wp-json/superman-links/v1/pages` returns 200
- [ ] Response includes `Access-Control-Allow-Origin: *`
- [ ] Response includes `Access-Control-Allow-Headers: X-Superman-Links-Key, Content-Type, Authorization`
- [ ] CRM frontend can make cross-origin requests successfully

---

## 6. Auto-Sync Webhooks

### 6.1 Post Save Triggers

- [ ] Edit a published page and save - check WordPress `error_log` for webhook log
- [ ] Webhook payload includes: `action`, `site_url`, `api_key`, `post` (id, url, title, focus_keyword, modified_at)
- [ ] Focus keyword is pulled from RankMath (or Yoast fallback)
- [ ] RankMath multi-keyword (comma-separated) sends only first keyword
- [ ] Verify CRM receives the webhook and updates the page

### 6.2 Filtered Events (Should NOT Trigger)

- [ ] Autosaves do NOT trigger webhook
- [ ] Revisions do NOT trigger webhook
- [ ] Auto-drafts do NOT trigger webhook
- [ ] Non-published posts (drafts, pending) do NOT trigger webhook
- [ ] Custom post types (not post/page) do NOT trigger webhook

### 6.3 Post Delete/Trash Triggers

- [ ] Trash a published post - webhook fires with `action: post_deleted`
- [ ] CRM clears `wordpress_title` and `wordpress_focus_keyword` but does NOT delete the page

### 6.4 Webhook Edge Function (CRM Side)

- [ ] Webhook validates `site_url` + `api_key` against `wordpress_sites` table
- [ ] Invalid site_url or api_key returns 401
- [ ] `post_updated` with existing CRM page updates `wordpress_title` and `wordpress_focus_keyword`
- [ ] `post_updated` with new URL creates a new page in CRM
- [ ] `post_deleted` clears WordPress fields on matching CRM page
- [ ] `last_webhook_at` timestamp is updated on the wordpress_sites record
- [ ] `last_sync_at` timestamp is updated on the wordpress_sites record

---

## 7. CRM Frontend Integration

### 7.1 Connect Dialog

- [ ] Opens from Pages list page
- [ ] Site URL field accepts full URL
- [ ] API Key field is password-masked
- [ ] "Test Connection" validates both fields are filled
- [ ] Successful test shows site name and detected SEO plugin
- [ ] Failed test shows error message
- [ ] "Connect" button disabled until test passes
- [ ] Duplicate site URL shows appropriate error
- [ ] After connecting, site appears in sync dialog

### 7.2 Sync Dialog

- [ ] Shows list of connected WordPress sites
- [ ] Each site shows: name, URL (with external link), auto-sync status, last synced
- [ ] Auto-sync shows "Active" (green) if `last_webhook_at` exists, "Waiting" otherwise
- [ ] Tooltip shows when last webhook was received
- [ ] "Sync" button shows progress (fetched / total)
- [ ] After sync: toast shows count of new + updated pages
- [ ] Pages table refreshes with new data
- [ ] `wordpress_focus_keyword` and `wordpress_title` columns populated
- [ ] "Disconnect" shows confirmation dialog
- [ ] Disconnect removes site but keeps CRM pages intact

### 7.3 Bulk Push (CRM to WordPress)

- [ ] Push focus keywords from CRM back to WordPress
- [ ] Push titles from CRM back to WordPress
- [ ] Batching works correctly (50 per batch with 500ms delays)
- [ ] Progress feedback during bulk operations
- [ ] Failed pushes reported per-page

---

## 8. Edge Cases

- [ ] Site with trailing slash in URL matches site without trailing slash
- [ ] Very large site (500+ pages) paginates correctly during sync
- [ ] Concurrent syncs don't create duplicate pages
- [ ] Unicode characters in titles and keywords handled correctly
- [ ] Pages with no focus keyword sync with `null` keyword
- [ ] WordPress site goes offline after connecting - sync shows clear error
- [ ] API key regenerated in WordPress - CRM connection fails with 401
