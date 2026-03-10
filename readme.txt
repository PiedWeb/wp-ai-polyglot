=== WP AI Polyglot ===
Contributors: piedweb
Tags: multilingual, i18n, translation, woocommerce, multisite
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Master/Shadow i18n architecture for WordPress + WooCommerce.

== Description ==

WP AI Polyglot translates your WordPress + WooCommerce site into any number of languages with one install, one database, zero content duplication.

For full documentation, see the [README on GitHub](https://github.com/PiedWeb/wp-ai-polyglot).

== Installation ==

1. Upload the plugin to `wp-content/plugins/wp-ai-polyglot/`
2. Define the `POLYGLOT_LOCALES` constant in `wp-config.php` (see README)
3. Activate the plugin

== Changelog ==

= 2.0.0 =
* Initial public release
* Master/Shadow architecture with domain-based routing
* WooCommerce support: virtualized stock, reviews, images, translated slugs
* WP-CLI commands for AI-powered translation workflows
* Flat-file export/import (TSV + HTML)
* Full hreflang SEO with sitemap annotations
