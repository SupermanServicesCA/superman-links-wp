=== Superman Links ===
Contributors: supermanservices
Tags: seo, rankmath, api, crm, elementor
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST API bridge for Superman Links CRM - exposes page data, SEO metadata, and Elementor templates.

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
