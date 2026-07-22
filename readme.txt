=== Superman Links ===
Contributors: supermanservices
Tags: seo, rankmath, api, crm, elementor
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 2.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your bridge to Superman Links, courtesy of Superman SEO.

== Description ==

Superman Links plugin creates REST API endpoints that allow your Superman Links CRM to pull page data including:

* Focus keywords (from RankMath or Yoast SEO)
* SEO scores
* Page URLs and titles
* Word count
* Categories and tags
* Publish/modified dates
* Elementor templates

**Features:**

* Simple API key authentication
* Support for RankMath and Yoast SEO
* Elementor template download and upload
* Pagination support for large sites
* Filter by post type
* Filter to only pages with focus keywords
* Auto-sync webhooks for real-time updates
* Auto-updates from GitHub releases

== Installation ==

1. Download the latest release from https://github.com/SupermanServicesCA/superman-links-wp/releases
2. Upload the zip file via Plugins > Add New > Upload Plugin
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings > Superman Links to view your API key
5. Copy the API key to your Superman Links CRM

Updates will appear automatically in your WordPress dashboard when new releases are published.

== Changelog ==

= 2.2.1 =
* CRITICAL FIX: LinkFinder internal-link insert/delete on Elementor pages could corrupt _elementor_data. update_post_meta() unslashes its input, so the raw wp_json_encode() writes lost the escape backslashes inside widget HTML (same root cause as the v1.8.1 page-builder import fix). All three write sites now wrap with wp_slash(). If a page's Elementor editor shows blank/broken after a recent LinkFinder insert, restore the previous revision from page History.

= 2.2.0 =
* RankMath redirect push: the plugin now pushes its active RankMath redirects to the CRM automatically (outbound webhook), so Content Silos stays in sync in real time even on hosts whose firewall/anti-bot blocks the CRM from pulling. Hourly cron + a push whenever a page save creates a redirect; hash-gated so it only sends on change. No effect if RankMath isn't installed.

= 2.1.0 =
* New GET /redirects endpoint exposes active RankMath redirects (exact-match sources) so the CRM's Content Silos can track + auto-follow slug-change redirects. Returns gracefully on sites without RankMath. No effect on existing behavior.

= 2.0.0 =
* Rebrand: plugin description is now "Your bridge to Superman Links, courtesy of Superman SEO." Removed the chatty admin helper paragraphs from the settings page (section blurb, API-key helper text, and the LinkFinder Sync description) for a cleaner admin UI. No functional changes.

= 1.18.0 =
* Internal-link insert: clearer 422 errors. When the in-place wrap can't be done because the sentence already contains a link (the plugin never nests links), it now returns a distinct "anchor already linked" message instead of the misleading "LinkFinder index may be stale — re-sync." The genuinely-missing case is reworded to point at builder/shortcode storage or content drift, so operators stop chasing phantom re-syncs.

= 1.17.0 =
* On-page/schema capture: LinkFinder pushes now capture the page the way Google sees it — the rendered <head> JSON-LD schema (LocalBusiness/Organization/WebSite/BreadcrumbList + page schema, not just body FAQ), the resolved meta description (including templated descriptions Rank Math generates, which post-meta returns empty), and on-page booleans (tap-to-call, structural NAP, map embed). Hybrid capture: an in-process Rank Math floor (cache-immune) overlaid with the live rendered head via an internal loopback fetch.
* The internal page-capture fetch now actually bypasses the page cache — the X-Superman-Internal marker is honored (DONOTCACHEPAGE + no-cache headers) and a cache-buster query param forces a fresh render, instead of capturing a FlyingPress/SiteGround cached or challenge copy.
* Bulk push batch lowered 30 -> 10 per tick to keep each run bounded now that capture does a per-post loopback fetch (avoids shared-host PHP/WP-Cron timeouts).

= 1.16.1 =
* Blog publishing fix: re-publishing a draft now updates the existing post in place — a draft→live re-publish correctly FLIPS the post to published (and refreshes the body/title) instead of returning the draft unchanged. The slug/permalink is preserved on re-publish so the tracked outbound link is never orphaned. The go-live date is stamped when a draft is first promoted to publish (correct freshness/sitemap ordering), and an already-published post is never silently demoted back to draft.

= 1.16.0 =
* Blog publishing: new POST /superman-links/v1/posts endpoint creates a native Gutenberg/HTML post from a Content Writer draft (wp_kses_post sanitized, idempotent via _superman_draft_id, focus keyword to Rank Math/Yoast, inline LinkFinder push on live publish).
* Sync key drift-immunity: a fresh API key minted on activation is now derived deterministically from the site's wp-config salts instead of random, so a re-mint after a host wipe reproduces the same key (no silent fork). Existing keys are untouched.

= 1.15.0 =
* Notify the CRM immediately when a fresh API key is minted on activation (e.g. after a host migration/restore reset the key), so sync key drift surfaces instantly instead of silently 401ing. The CRM stages the key for human-verified adoption.

= 1.9.0 =
* Added Review Widget feature - Google Reviews carousel via shortcode
* New REST endpoint: POST /reviews for pushing review data from CRM
* Shortcode [superman_reviews] with configurable max reviews
* Responsive carousel with CSS scroll-snap, touch swipe, and arrow navigation
* Server-side rendering with graceful fallback when no data

= 1.2.0 =
* Added Elementor template support
* Added auto-updates via GitHub releases
* New endpoints: /elementor/pages, /elementor/{id}, /elementor/import

= 1.1.0 =
* Added auto-sync webhook functionality
* Automatically push title and focus keyword changes to CRM

= 1.0.0 =
* Initial release
* RankMath and Yoast SEO support
* REST API endpoints for pages
* API key authentication
