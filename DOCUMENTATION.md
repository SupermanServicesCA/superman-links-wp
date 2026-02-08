# Superman Links WordPress Plugin

**Version:** 1.2.0
**Requires:** WordPress 5.0+ | PHP 7.4+
**SEO Plugins:** RankMath (primary), Yoast SEO (fallback)
**Page Builders:** Elementor (optional)

## What It Does

The Superman Links plugin turns any WordPress site into a REST API bridge for the Superman Links CRM. It exposes page data, SEO metadata, and Elementor templates so the CRM can:

- Pull all pages with their focus keywords, SEO scores, word counts, and metadata
- Push focus keywords and titles back to WordPress
- Download and upload Elementor page templates
- Receive real-time updates via webhooks when posts are saved or deleted

## Installation

1. Upload the `superman-links` folder to `/wp-content/plugins/`
2. Activate the plugin in the WordPress Plugins menu
3. Go to **Settings > Superman Links** to view your auto-generated API key
4. In the CRM, go to a client's Pages tab and use "WordPress" to connect the site

## Authentication

All endpoints (except `/ping`) require the API key in the request header:

```
X-Superman-Links-Key: your-api-key-here
```

The key is auto-generated on plugin activation and can be regenerated from the settings page.

---

## API Endpoints

Base URL: `https://yoursite.com/wp-json/superman-links/v1`

### GET /ping

Test connection. No authentication required.

**Response:**
```json
{
  "status": "ok",
  "plugin": "Superman Links",
  "version": "1.2.0",
  "wordpress": "6.4",
  "site_name": "My Site",
  "site_url": "https://yoursite.com",
  "rankmath_active": true,
  "yoast_active": false,
  "elementor_active": true,
  "elementor_version": "3.18.0"
}
```

---

### GET /pages

Fetch all published pages and posts with SEO data.

**Query Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `post_type` | `post,page` | Comma-separated post types |
| `per_page` | `100` | Results per page (max 500) |
| `page` | `1` | Page number |
| `has_focus_keyword` | `false` | Only return posts with a focus keyword |

**Response:**
```json
{
  "pages": [
    {
      "id": 123,
      "url": "https://yoursite.com/my-page/",
      "slug": "my-page",
      "title": "My Page Title",
      "post_type": "page",
      "status": "publish",
      "published_at": "2024-01-15T10:30:00",
      "modified_at": "2024-01-20T14:22:00",
      "author": "Admin",
      "word_count": 1250,
      "seo": {
        "focus_keyword": "main keyword",
        "focus_keywords": ["main keyword", "secondary keyword"],
        "seo_score": 85,
        "is_pillar": false,
        "meta_title": "Custom SEO Title",
        "meta_description": "Custom meta description"
      },
      "featured_image": "https://yoursite.com/wp-content/uploads/image.jpg",
      "categories": ["Marketing"],
      "tags": ["seo", "guide"]
    }
  ],
  "total": 45,
  "total_pages": 1,
  "current_page": 1,
  "per_page": 100
}
```

**SEO data sources:**
- `focus_keyword` / `focus_keywords` — RankMath `rank_math_focus_keyword` or Yoast `_yoast_wpseo_focuskw`
- `seo_score` — RankMath `rank_math_seo_score`
- `is_pillar` — RankMath `rank_math_pillar_content`
- `meta_title` / `meta_description` — RankMath `rank_math_title` / `rank_math_description`

---

### GET /pages/{id}

Fetch a single page by WordPress post ID. Same response structure as a single item from `/pages`.

Returns 404 if the post doesn't exist or is not published.

---

### POST /pages/{id}/focus-keyword

Update the focus keyword for a single page.

**Body:**
```json
{
  "focus_keyword": "new keyword"
}
```

**Response:**
```json
{
  "success": true,
  "post_id": 123,
  "focus_keyword": "new keyword",
  "seo_plugin": "rankmath"
}
```

Writes to RankMath if active, falls back to Yoast. Returns 400 if neither is installed.

---

### POST /pages/bulk-focus-keyword

Update focus keywords for multiple pages by URL.

**Body:**
```json
{
  "pages": [
    { "url": "https://yoursite.com/page-1/", "focus_keyword": "keyword 1" },
    { "url": "https://yoursite.com/page-2/", "focus_keyword": "keyword 2" }
  ]
}
```

**Response:**
```json
{
  "updated": 2,
  "failed": 0,
  "details": [
    { "url": "https://yoursite.com/page-1/", "success": true, "seo_plugin": "rankmath" },
    { "url": "https://yoursite.com/page-2/", "success": true, "seo_plugin": "rankmath" }
  ]
}
```

URL matching uses WordPress `url_to_postid()`. URLs must match the WordPress permalink structure exactly.

---

### POST /pages/bulk-title

Update post titles for multiple pages by URL.

**Body:**
```json
{
  "pages": [
    { "url": "https://yoursite.com/page-1/", "title": "New Title 1" },
    { "url": "https://yoursite.com/page-2/", "title": "New Title 2" }
  ]
}
```

**Response:** Same structure as bulk-focus-keyword (`updated`, `failed`, `details`).

---

### GET /elementor/pages

List all pages built with Elementor.

**Query Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `post_type` | `page` | Comma-separated post types |
| `per_page` | `100` | Results per page (max 500) |
| `page` | `1` | Page number |

**Response:**
```json
{
  "pages": [
    {
      "id": 123,
      "url": "https://yoursite.com/my-page/",
      "slug": "my-page",
      "title": "My Page Title",
      "post_type": "page",
      "status": "publish",
      "modified_at": "2024-01-20T14:22:00",
      "template_type": "page",
      "elementor_version": "3.18.0",
      "widget_count": 15,
      "featured_image": "https://yoursite.com/wp-content/uploads/thumb.jpg"
    }
  ],
  "total": 10,
  "total_pages": 1,
  "current_page": 1,
  "per_page": 100
}
```

Returns 400 if Elementor is not active.

---

### GET /elementor/{id}

Download the full Elementor template for a page.

**Response:**
```json
{
  "version": "1.0",
  "type": "superman-links-elementor-template",
  "source": {
    "site_url": "https://yoursite.com",
    "post_id": 123,
    "post_url": "https://yoursite.com/my-page/",
    "exported_at": "2024-01-20T14:22:00+00:00"
  },
  "page": {
    "title": "My Page Title",
    "slug": "my-page",
    "post_type": "page",
    "status": "publish",
    "template_type": "page"
  },
  "elementor": {
    "version": "3.18.0",
    "data": [ ... ],
    "page_settings": { ... },
    "css": { ... }
  },
  "seo": {
    "focus_keyword": "main keyword",
    "meta_title": "Custom SEO Title",
    "meta_description": "Custom meta description"
  }
}
```

Returns 400 if the page is not built with Elementor.

---

### POST /elementor/{id}

Update an existing page with Elementor template data.

**Body:**
```json
{
  "elementor": {
    "data": [ ... ],
    "page_settings": { ... }
  },
  "seo": {
    "focus_keyword": "keyword",
    "meta_title": "Title",
    "meta_description": "Description"
  }
}
```

**Response:**
```json
{
  "success": true,
  "post_id": 123,
  "url": "https://yoursite.com/my-page/",
  "title": "My Page Title",
  "status": "publish",
  "is_new": false,
  "elementor_version": "3.18.0",
  "message": "Page updated with template."
}
```

Automatically regenerates Elementor CSS after update.

---

### POST /elementor/import

Create a new page from an Elementor template.

**Body:**
```json
{
  "page": {
    "title": "New Page",
    "slug": "new-page",
    "post_type": "page",
    "status": "draft"
  },
  "elementor": {
    "data": [ ... ],
    "page_settings": { ... }
  },
  "seo": {
    "focus_keyword": "keyword"
  }
}
```

**Defaults:** `post_type` defaults to `page`, `status` defaults to `draft`. Invalid values are reset to defaults.

---

## Auto-Sync Webhooks

The plugin automatically sends webhooks to the CRM whenever a published post or page is saved or deleted.

### How It Works

1. User saves/deletes a post in WordPress
2. Plugin fires a POST request to the Supabase edge function (`wordpress-webhook`)
3. Edge function validates the `site_url` + `api_key` against the `wordpress_sites` table
4. For saves: creates or updates the page in the CRM `pages` table
5. For deletes: clears `wordpress_title` and `wordpress_focus_keyword` (does not delete the CRM page)
6. Updates `last_webhook_at` on the `wordpress_sites` record (powers the auto-sync status indicator in the CRM)

### Webhook Payload

```json
{
  "action": "post_updated",
  "site_url": "https://yoursite.com",
  "api_key": "the-stored-api-key",
  "post": {
    "id": 123,
    "url": "https://yoursite.com/my-page/",
    "title": "My Page Title",
    "focus_keyword": "main keyword",
    "modified_at": "2024-01-20 14:22:00"
  }
}
```

### Filtered Events

Webhooks are **not** sent for:
- Autosaves
- Revisions
- Auto-drafts
- Non-published posts (drafts, pending, private)
- Post types other than `post` and `page` (customizable via `superman_links_webhook_post_types` filter)

### Debugging

All webhook requests are logged to WordPress `error_log`:
```
Superman Links Webhook: Sending to https://...
Superman Links Webhook: Payload - {...}
Superman Links Webhook Response: 200 - {...}
```

Check `wp-content/debug.log` (if `WP_DEBUG_LOG` is enabled) to troubleshoot webhook issues.

---

## CRM Integration

### Connecting a Site

1. In the CRM, navigate to a client's **Pages** tab
2. Click "WordPress" to open the sync dialog
3. Click "Connect Site"
4. Enter the WordPress site URL and API key (from Settings > Superman Links)
5. Click "Test Connection" to verify
6. Click "Connect" to save

### Syncing Pages

- **Manual sync:** Click "Sync" in the WordPress sync dialog. Fetches all pages with pagination and creates/updates CRM pages.
- **Auto-sync:** Once the plugin is active (v1.1.0+), any post save in WordPress automatically pushes updates to the CRM. The sync dialog shows auto-sync status.

### Pushing Data Back to WordPress

The CRM can push focus keywords and titles back to WordPress using the bulk endpoints. This is batched (50 pages per request with 500ms delays between batches).

---

## File Structure

```
superman-links/
  superman-links.php          # Main plugin file, initialization, activation hook
  includes/
    class-api.php             # REST API endpoints (pages, keywords, Elementor)
    class-settings.php        # WordPress admin settings page
    class-webhook.php         # Auto-sync webhook handler
  readme.txt                  # WordPress.org plugin description
  DOCUMENTATION.md            # This file
  TESTING.md                  # Testing checklist
```

### CRM-Side Files

```
src/lib/api/wordpress-sites.ts              # API layer (DB + WordPress HTTP calls)
src/hooks/useWordPressSites.ts              # React Query hooks
src/features/pages/components/
  wordpress-connect-dialog.tsx              # Connect site UI
  wordpress-sync-dialog.tsx                 # Sync/manage sites UI
supabase/functions/wordpress-webhook/       # Edge function for receiving webhooks
supabase/migrations/
  20250125120000_wordpress_sites.sql        # wordpress_sites table
  20260126_add_wordpress_focus_keyword.sql  # focus keyword column on pages
  20260127_add_wordpress_title.sql          # title column on pages
  20260129_add_wordpress_last_webhook.sql   # last_webhook_at column
```

---

## Hooks & Filters

| Filter | Description | Default |
|--------|-------------|---------|
| `superman_links_webhook_post_types` | Post types that trigger webhooks | `['post', 'page']` |

---

## Changelog

### 1.2.0
- Elementor template support (list, download, update, import)
- Automatic CSS regeneration on template import
- SEO data included in template export/import
- Ping endpoint shows Elementor status

### 1.1.0
- Auto-sync webhook functionality
- Real-time push of title and keyword changes to CRM
- Auto-sync status indicator in CRM

### 1.0.0
- Initial release
- RankMath and Yoast SEO support
- REST API endpoints for pages
- API key authentication
