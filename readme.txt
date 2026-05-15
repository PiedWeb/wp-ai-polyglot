=== PiedWeb AI Polyglot ===
Contributors: piedweb
Tags: multilingual, translation, woocommerce, hreflang, i18n
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Master/Shadow i18n for WordPress + WooCommerce. One install, one database, zero content duplication. AI-ready via WP-CLI.

== Description ==

**PiedWeb AI Polyglot** translates your WordPress + WooCommerce site into any number of languages — with one install, one database, and zero content duplication.

It uses a **Master/Shadow architecture**: you manage content in your primary language, and the plugin creates linked shadow copies for each target locale. Each language gets its own domain (or subdomain), with full SEO support out of the box.

Designed for AI-powered translation workflows — but works just as well with human translators.

= Why PiedWeb AI Polyglot? =

* **No content duplication** — shadows are linked copies, not clones. Update the master, shadows follow.
* **No separate database** — everything lives in a single WordPress install. No sync headaches.
* **No performance penalty** — stock, images, and reviews are virtualized at query time, not copied.
* **No plugin lock-in** — all data is stored as standard WordPress post meta. Remove the plugin, your content stays.
* **AI-ready** — WP-CLI commands expose master content as JSON, ready to pipe into any translation API or LLM.

= Features =

* **Domain-based routing** — one domain per language, detected via `HTTP_HOST`. Each domain serves only its locale's content.
* **Virtualized stock** — shadow products read stock from the master in real time. Orders on any domain decrement the same inventory.
* **Virtualized reviews** — reviews submitted on any domain are stored on the master product. Star ratings are aggregated across all locales.
* **Virtualized images** — shadow products inherit the master's featured image and gallery without duplicating media files.
* **Hreflang SEO** — automatic `<link rel="alternate" hreflang="...">` tags plus sitemap annotations with `xhtml:link`.
* **Locale-filtered sitemaps and RSS** — each domain's sitemap and feed only expose its own content.
* **WooCommerce i18n** — shipping labels, checkout legal texts, cart product names, and permalink slugs are all translated per locale.
* **Human lock** — manually edited translations are protected from automated overwrite (unless you `--force`).
* **Language suggestion banner** — auto-detects the visitor's browser language and suggests switching locale, with dismiss + localStorage persistence.
* **Draft link handling** — internal links pointing to drafts are auto-hidden for public visitors and re-activate once the target is published. Editors keep working links.
* **Language switcher** — drop-in function for your theme with WooCommerce cart session transfer across domains.
* **Admin UI** — locale filter dropdown, language badge column, shadow warning banner, and translations metabox on masters.
* **Flat-file export/import** — bulk export all translations to TSV + HTML files, edit offline, re-import.
* **WP-CLI powered** — list untranslated content, push translations, manage slugs — all from the command line.

= Works without WooCommerce =

All WooCommerce features (stock, reviews, cart, slugs) activate only when WooCommerce is present. On a standard WordPress site, the plugin handles routing, hreflang, admin UI, and translation management with no extra dependencies.

= Full documentation =

For the complete documentation (data model, WP-CLI command reference, hooks, draft link handler, configuration examples), see the [README on GitHub](https://github.com/PiedWeb/wp-ai-polyglot).

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/wp-ai-polyglot/`, or install it through the WordPress plugin screen.
2. **Before activating**, define the `POLYGLOT_LOCALES` constant in `wp-config.php`:

`define('POLYGLOT_LOCALES', [
    'www.example.com' => ['locale' => 'fr_FR', 'hreflang' => 'fr', 'label' => 'Français', 'currency' => 'EUR', 'master' => true],
    'en.example.com'  => ['locale' => 'en_GB', 'hreflang' => 'en', 'label' => 'English',  'currency' => 'GBP'],
    'es.example.com'  => ['locale' => 'es_ES', 'hreflang' => 'es', 'label' => 'Español',  'currency' => 'EUR'],
]);`

3. Configure your web server (Apache or Nginx) to serve the same WordPress document root for every language domain.
4. Set `WP_HOME` and `WP_SITEURL` dynamically in `wp-config.php` based on `$_SERVER['HTTP_HOST']`.
5. Activate the plugin through the **Plugins** screen.

See the [GitHub README](https://github.com/PiedWeb/wp-ai-polyglot) for DNS, SSL, and local-development setup.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. WooCommerce features (stock virtualization, review aggregation, slug translation) activate only when WooCommerce is present. On a standard WordPress site, the plugin handles routing, hreflang, admin UI, and translation management.

= Can I use subdirectories like example.com/en/ instead of subdomains? =

No. PiedWeb AI Polyglot uses one domain (or subdomain) per language. Subdirectory routing is not supported by design — it keeps the routing logic fast and the SEO model clean.

= Is this compatible with WordPress Multisite? =

No. The plugin is single-site only and will display an admin notice on Multisite installs.

= How do I disable the language suggestion banner? =

Add `define('POLYGLOT_BAR', false);` to your `wp-config.php`. Both the asset enqueue and the HTML rendering are skipped.

= What happens to my content if I deactivate the plugin? =

All translation data is stored as standard WordPress post meta. Your shadow posts remain in the database and can be managed manually. Domain routing stops, so each domain will serve the same content until you remove the alias.

= How does the AI translation workflow work? =

The WP-CLI command `wp polyglot untranslated --type=product` lists posts needing translation. `wp polyglot translate <id> --target=<locale>` returns the master content as JSON — pipe that into any LLM or translation API, then push the result back with `--payload='{...}'`. The plugin does **not** call any third-party API itself; you control the translation provider.

= Are manual translations safe from being overwritten? =

Yes. Any translation edited through wp-admin (or marked with `_translation_mode = manual`) is protected from automated re-translation. Pass `--force` to override.

== Screenshots ==

1. Locale filter dropdown and language badge column on the posts admin screen.
2. Translations metabox on a master post showing all shadow copies.
3. Front-end language suggestion banner detecting browser locale.
4. WP-CLI output for `wp polyglot untranslated` and `wp polyglot translate`.
5. Flat-file export folder structure for offline editing.

== Credits ==

Country flag SVGs in `assets/flags/` are from [flag-icons](https://github.com/lipis/flag-icons) by Panayiotis Lipiridis, licensed under the MIT License.

== Changelog ==

= 2.0.0 =
* Initial public release on WordPress.org
* Master/Shadow architecture with domain-based routing
* WooCommerce support: virtualized stock, reviews, images, translated slugs
* WP-CLI commands for AI-powered translation workflows
* Flat-file export/import (TSV + HTML) with concurrency lock
* Hreflang SEO with sitemap and RSS annotations
* Draft link handler — hide links to unpublished targets, restore on publish
* Native permalink resolver (no Custom Permalinks plugin required)
* `check-links` WP-CLI command for detecting mislocalized internal links

== Upgrade Notice ==

= 2.0.0 =
First public release.
