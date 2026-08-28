=== Superman Links ===
Contributors: supermanservices
Tags: seo, rankmath, api, crm, elementor
Requires at least: 5.3
Tested up to: 6.7
Stable tag: 2.3.8
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

= 2.3.7 =
* FIX: the review widget could fatal a page (white screen) if its stored data was not in the expected shape. `empty()` passes for a non-empty string, so a `reviews` value that was not an array reached count() and array_slice(), both a TypeError in PHP 8. Found by fuzzing the stored row rather than by reading the code.
* FIX: the widget called mb_strtoupper() directly. WordPress polyfills mb_substr() and mb_strlen() for hosts without the mbstring extension, but it does NOT polyfill mb_strtoupper() — so on such a host every review card fataled the page. It now falls back to strtoupper().
* FIX (follow-up to 2.3.6): colour validation still rejected several forms a WordPress theme legitimately supplies — hsl()/hsla(), 8-digit hex (#RRGGBBAA), modern space-separated rgb(), oklch()/lab()/color(), and plain keywords such as `transparent` and `currentColor`. Those were silently replaced by the plugin's built-in defaults. Validation is now safe by construction: a value is rejected if it contains any character that could end the declaration or open a rule, comment or at-rule, or if it calls url()/image()/element()/expression(); otherwise any recognised colour form is passed through untouched.
* Requires at least is now 5.3, not 5.0. The widget calls wp_unique_id(), which WordPress only added in 5.0.3, so the old floor was wrong.

= 2.3.6 =
* FIX (follow-up to 2.3.4): the review widget's colour validation only accepted hex, so a client whose brand colours came from their theme (e.g. var(--nv-primary-accent)) had them silently replaced by the plugin's built-in defaults. That is a real styling regression: the plugin's own /theme-colors endpoint deliberately returns non-hex values unchanged, and the CRM seeds the widget from it. Hex, var(--token) with an optional hex fallback, and rgb()/rgba() are now all accepted. Anything else still falls back to the default, and none of the accepted forms can carry the '{', '}' or ';' characters a CSS injection needs.

= 2.3.5 =
* FIX (follow-up to 2.3.4): 2.3.4's colour validation could itself raise a fatal error. WordPress's sanitize_hex_color() and esc_url() have no type guard and throw a TypeError in PHP 8 when handed an array rather than a string. The stored reviews row can contain any JSON type if it was last written by a plugin before 2.3.4, when the request body was saved unchanged — so on such a site the review widget could white-screen the page instead of rendering. Every value is now coerced to a string before it reaches those functions, on both the write and the render path. No site was observed in this state; the CRM only ever pushes strings.

= 2.3.4 =
* SECURITY: the review widget's colour values are now validated as hex colours before they reach the inline <style> block. They were escaped with esc_attr(), which does not escape '{', '}' or ';' in a CSS context, so a malformed colour could inject CSS rules. Validated on write AND on read, because a site that last received a push from an older plugin still holds an unvalidated value.
* SECURITY: POST /reviews now whitelist-sanitizes every field it stores. It previously wrote the request body to the options table unchanged.
* SECURITY: the Regenerate button draws the new API key from crypto.getRandomValues() instead of Math.random(). Math.random() is not a cryptographic source, so the old key was predictable. Same length and character set.
* SECURITY: the API key comparison in the main REST class now uses hash_equals(), matching the review-widget and theme-colors classes.
* FIX: a truncated review comment showed the literal text "&hellip;" instead of an ellipsis. The entity was built into the string before esc_html() ran over it.
* Housekeeping: escape-at-output on two widget data attributes, esc_js() on the regenerate confirmation text, and a missing text domain. No behaviour change.

= 2.3.3 =
* FIX (follow-up to 2.3.2): 2.3.2's fix for the over-eager "already linked" message went too far the other way — it compared against the raw HTML source instead of the rendered text, so any sentence containing an apostrophe, an &amp;, or a non-breaking space stopped being recognised and fell back to the vaguer "can't edit that widget" message. Both the matcher and the diagnostic now share one text-extraction and matching routine, so they can no longer disagree. Diagnostic only — no change to what gets written.

= 2.3.2 =
* FIX (follow-up to 2.3.0): the "that sentence is already linked" error could fire on sentences that are not linked at all. The check looked at every text widget on the page, so any unrelated link whose anchor text happened to be a substring of the sentence (e.g. a "spider" link vs. the sentence "...spider control...") triggered it. It is now scoped to the widget that actually contains the sentence. Nothing was ever written incorrectly — this only affected which of two 422 messages you were shown.

= 2.3.1 =
* FIX (follow-up to 2.3.0): re-inserting a link that had previously been placed as a "phantom" is no longer blocked. A phantom left its anchor in post_content, which the duplicate check counted as "link already exists" — so the retry that 2.3.0 exists to enable returned 409. On a page with an Elementor tree, post_content is no longer consulted for that check: it isn't rendered, so a URL sitting there is stale debris, not a link on the page.
* FIX: the duplicate check never actually worked on Elementor pages. _elementor_data is JSON, where wp_json_encode escapes forward slashes, so a stored link reads https:\/\/example.com\/ and a plain search for the raw URL never matched. (It appeared to work before 2.2.1 only because the unslashed writes stripped those escapes.) Both forms are now checked, so inserting the same target twice into a page is properly rejected.

= 2.3.0 =
* CRITICAL FIX: internal-link inserts can no longer create "phantom" links on Elementor pages — links the CRM records as live that never render. Elementor paints _elementor_data, not post_content, but three paths silently wrote post_content and returned success: a walk that found no text-editor widget, an unparseable _elementor_data, and the no-context "Related:" append when the page has no text widget (that one phantomed every time). All three now return 422 and write nothing. The rule is absolute: on an Elementor page with a valid, non-empty element tree, post_content is never written.
* Internal-link inserts and deletes now also work in heading, icon-box, and image-box widgets, not just text-editor. Widgets that carry their own whole-widget link (settings.link.url) are skipped so we never nest an <a> inside an <a>.
* Insert responses gain placed_in {element_id, widget_type, setting} so operators can see exactly where a link landed.
* Every _elementor_data write for internal links now goes through one helper that both wp_slash()es the JSON and refuses to write when wp_json_encode() fails — previously an encoding failure would have written an empty string and wiped the page's entire Elementor design.
* Better error messages: a sentence that is already linked now reports that specifically instead of "could not find that sentence".

= 2.2.3 =
* FIX: is_elementor_post() now returns false when the Elementor plugin isn't active. Previously a stale _elementor_edit_mode meta (left over from a past builder) routed internal-link inserts/deletes into a vestigial _elementor_data blob that never renders — a silent phantom insert (claritypest incident). Inserts on such pages now correctly take the post_content path.

= 2.2.2 =
* GET /elementor/:id now returns data_raw_b64 (base64 of the stored _elementor_data string) whenever the meta exists but fails json_decode, and accepts revision IDs — forensics support for repairing pages corrupted by the pre-v2.2.1 unslashed writes. Read-only, no behavior change on healthy pages.

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
