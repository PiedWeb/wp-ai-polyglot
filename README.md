# WP AI Polyglot

**Translate your WordPress + WooCommerce site into any number of languages — with one install, one database, zero duplication.**

WP AI Polyglot uses a Master/Shadow architecture: you manage content in your primary language, and the plugin creates linked shadow copies for each target locale. Each language gets its own domain (or subdomain), with full SEO support out of the box.

Designed for AI-powered translation workflows — but works just as well with human translators.

### Why WP AI Polyglot?

- **No content duplication** — shadows are linked copies, not clones. Update the master, shadows follow.
- **No separate database** — everything lives in a single WordPress install. No sync headaches.
- **No performance penalty** — stock, images, and reviews are virtualized at query time, not copied.
- **No plugin lock-in** — all data is stored as standard WordPress post meta. Remove the plugin, your content stays.
- **AI-ready** — WP-CLI commands expose master content as JSON, ready to pipe into any translation API or LLM.

### Features at a glance

- **Domain-based routing** — one domain per language, detected via `HTTP_HOST`. Each domain serves only its locale's content.
- **Virtualized stock** — shadow products read stock from the master in real time. Orders on any domain decrement the same inventory.
- **Virtualized reviews** — reviews submitted on any domain are stored on the master product. Star ratings are aggregated across all locales.
- **Virtualized images** — shadow products inherit the master's featured image and gallery without duplicating media files.
- **Hreflang SEO** — automatic `<link rel="alternate" hreflang="...">` on every page + sitemap annotations with `xhtml:link`.
- **Locale-filtered sitemaps & RSS** — each domain's sitemap and feed only expose its own content.
- **WooCommerce i18n** — shipping labels, checkout legal texts, cart product names, and permalink slugs are all translated per locale.
- **Human lock** — manually edited translations are protected from automated overwrite (unless you `--force`).
- **Language suggestion banner** — auto-detects the visitor's browser language and suggests switching locale, with dismiss + localStorage persistence. Disable via config.
- **Draft link handling** — internal links pointing to drafts (or any non-published target) are auto-hidden for public visitors and re-activate at render time once the target is published. Editors keep working links.
- **Language switcher** — drop-in function for your theme with WooCommerce cart session transfer across domains.
- **Admin UI** — locale filter dropdown, language badge column, shadow warning banner, and translations metabox on masters.
- **Flat-file export/import** — bulk export all translations to TSV + HTML files, edit offline, re-import. Concurrency lock prevents simultaneous runs.
- **WP-CLI powered** — list untranslated content, push translations, manage slugs — all from the command line.
- **Extensible** — filters for authority detection (`polyglot_current_authority`) and shipping label translations (`polyglot_shipping_labels`).

### Works without WooCommerce

All WooCommerce features (stock, reviews, cart, slugs) activate only when WooCommerce is present. On a standard WordPress site, the plugin handles routing, hreflang, admin UI, and translation management with no extra dependencies.

---

## Todo

- [ ] Marketing - compare features (table) to 10 Best WordPress Translation Plugins (write compared versions !) - WPML vs Polylang vs TranslatePress vs Loco ...

---

## Language Suggestion Banner

When a visitor's browser language (via `navigator.languages`) differs from the current page locale, a fixed banner appears at the top of the page suggesting they switch. For example, a French-speaking visitor on the English site sees:

> Cette page est disponible en français — **Voir en français**

**Behavior:**

- Rendered `hidden` by default — JavaScript reveals it only when a match is found
- The dismiss button (`×`) hides the banner and persists the choice in `localStorage` (`polyglot-dismissed-{hreflang}`) so it won't reappear
- WooCommerce cart session is transferred across domains via HMAC-signed token
- Banner messages are translated in all 8 supported languages
- The CTA button is hidden on mobile (< 600px) — the message text remains visible

**Disable the banner:**

```php
// wp-config.php
define('POLYGLOT_BAR', false);
```

The banner is enabled by default. Setting `POLYGLOT_BAR` to `false` prevents both the asset enqueue and the HTML rendering.

**Files:**

| File                              | Role                                                   |
| --------------------------------- | ------------------------------------------------------ |
| `templates/suggestion-banner.php` | HTML template                                          |
| `assets/polyglot-bar.css`         | Positioning, admin-bar offset, mobile breakpoint       |
| `assets/polyglot-bar.js`          | Browser language detection, localStorage, show/dismiss |

---

## Draft Link Handling

When a published article links to a page or product that is still in `draft` (or `pending`, `future`, `private`), the public version of the article would normally expose a broken link. WP AI Polyglot resolves this at render time:

- The `the_content` filter parses every internal `<a>` tag, resolves the target via locale-aware `custom_permalink` lookup, and checks the post status.
- If the target is **published** → link kept as-is.
- If the target is **not published** and the visitor is **not an editor** → the `<a>` is replaced with `<span data-draft="{post title}" data-status="{status}">{original text}</span>`. The text content is preserved; only the hyperlink disappears.
- If the visitor **can edit posts** (admin / editor) → the link is kept active so editors can navigate to the draft.
- When the draft is later published, the next render automatically restores the link — no content rewrite needed.

External links, `mailto:`, `tel:`, and anchor-only (`#section`) URLs are never touched. Cross-domain shadow links (e.g. an EN article linking to an FR shadow draft) are resolved against the correct locale.

The `data-draft` / `data-status` attributes make it easy to debug missing links via the browser inspector. By default they are not styled — add CSS if you want to visually mark them in the editor view.

> ⚠️ Cache invalidation on publish is **not** automated. If you use a full-page cache (WP Super Cache, Varnish), pages referencing a freshly-published target will keep the hidden version until the cache expires or is purged.

## Configuration

The `POLYGLOT_LOCALES` constant **must** be defined before the plugin loads (typically in `wp-config.php`). The plugin will silently deactivate if the constant is missing.

```php
// wp-config.php
define('POLYGLOT_LOCALES', [
    'www.example.com'    => ['locale' => 'fr_FR', 'hreflang' => 'fr', 'label' => 'Français',  'currency' => 'EUR', 'master' => true],
    'en.example.com'     => ['locale' => 'en_GB', 'hreflang' => 'en', 'label' => 'English',   'currency' => 'GBP'],
    'es.example.com'     => ['locale' => 'es_ES', 'hreflang' => 'es', 'label' => 'Español',   'currency' => 'EUR'],
]);
```

Each entry maps a domain (or `host:port` for local dev) to:

| Key        | Description                                      |
| ---------- | ------------------------------------------------ |
| `locale`   | WordPress locale code (`fr_FR`, `en_GB`, etc.)   |
| `hreflang` | ISO 639-1 code for `<link rel="alternate">` tags |
| `label`    | Human-readable language name                     |
| `currency` | ISO 4217 currency code (for WooCommerce)         |
| `master`   | `true` on exactly one entry — the master locale  |

### Domain setup

WP AI Polyglot uses **one domain (or subdomain) per language**. All domains must point to the **same WordPress installation**. Subdirectory-based routing (`example.com/en/`) is not supported.

**1. DNS** — Create A or CNAME records for each locale domain, all pointing to your server:

```
www.example.com    → A    → 1.2.3.4
en.example.com     → CNAME → www.example.com
es.example.com     → CNAME → www.example.com
```

Separate TLDs work too (`example.com` + `example.co.uk` + `example.es`), as long as they all resolve to the same server.

**2. Web server** — Configure your virtual host (Apache or Nginx) to serve the same WordPress document root for all domains. With Apache:

```apache
ServerName www.example.com
ServerAlias en.example.com es.example.com
DocumentRoot /var/www/wordpress
```

**3. SSL** — All domains need valid HTTPS certificates. Use a wildcard certificate (`*.example.com`) for subdomains, or individual certs / multi-SAN.

**4. WordPress** — Set `WP_HOME` and `WP_SITEURL` dynamically in `wp-config.php` so WordPress responds correctly on each domain:

```php
$host = $_SERVER['HTTP_HOST'] ?? 'www.example.com';
define('WP_HOME', 'https://' . $host);
define('WP_SITEURL', 'https://' . $host);
```

**Local development** — Use one port per language instead of separate domains:

```php
$port = $_SERVER['SERVER_PORT'] ?? '8080';
define('WP_HOME', 'http://127.0.0.1:' . $port);
define('WP_SITEURL', 'http://127.0.0.1:' . $port);

define('POLYGLOT_LOCALES', [
    '127.0.0.1:8080' => ['locale' => 'fr_FR', 'hreflang' => 'fr', 'label' => 'Français', 'currency' => 'EUR', 'master' => true],
    '127.0.0.1:8081' => ['locale' => 'en_GB', 'hreflang' => 'en', 'label' => 'English',  'currency' => 'GBP'],
]);
```

## Data Model

### Posts / Products

| Meta key            | Master      | Shadow                  |
| ------------------- | ----------- | ----------------------- |
| `_locale`           | _(not set)_ | `en_GB`, `es_ES`, etc.  |
| `_master_id`        | _(not set)_ | Master post ID          |
| `_translation_mode` | _(not set)_ | `manual` if hand-edited |

### Terms (categories, tags)

| Meta key          | Master      | Shadow         |
| ----------------- | ----------- | -------------- |
| `_locale`         | _(not set)_ | Target locale  |
| `_master_term_id` | _(not set)_ | Master term ID |

### Reviews (comments)

| Meta key             | Original review | Translated review   |
| -------------------- | --------------- | ------------------- |
| `_source_locale`     | Source locale   | _(not set)_         |
| `_master_comment_id` | _(not set)_     | Original comment ID |
| `_locale`            | _(not set)_     | Target locale       |
| `_translation_mode`  | _(not set)_     | `manual` if edited  |

### Users

| Meta key               | Value                       |
| ---------------------- | --------------------------- |
| `_registration_locale` | Locale at registration time |

### WooCommerce Orders

| Meta key        | Value                          |
| --------------- | ------------------------------ |
| `_order_locale` | Locale at order placement time |

## WP-CLI Commands

```bash
# List configured locales
wp polyglot locales

# List untranslated content
wp polyglot untranslated --type=product
wp polyglot untranslated --type=product --target=en_GB

# Get master data (for AI translation)
wp polyglot translate 100 --target=en_GB

# Insert translation
wp polyglot translate 100 --target=en_GB --payload='{"translated_title":"..."}'

# Force overwrite a manual translation
wp polyglot translate 100 --target=en_GB --payload='...' --force

# Translate a taxonomy term
wp polyglot translate-term 5 --taxonomy=product_cat --target=en_GB --name="Shoes"

# Translate a review
wp polyglot translate-comment 42 --target=en_GB --payload='{"translated_content":"..."}'

# Manage WooCommerce slug translations
wp polyglot translate_slugs --status
wp polyglot translate_slugs --target=en_GB --payload='{"product_base":"product","category_base":"product-category","tag_base":"product-tag"}'

# Check internal links for mislocalized slugs, trailing slashes, localhost URLs
wp polyglot check-links                        # scan post_content (all locales)
wp polyglot check-links --locale=en_GB --fix   # auto-fix one locale

# Audit rendered HTML (templates, menus, sitemaps) for trailing slashes
wp polyglot check-links --rendered             # report-only, no --fix

# Export/import flat files (mutually exclusive — concurrent runs are blocked)
wp polyglot export
wp polyglot import --dry-run
wp polyglot import
```

## Tests

Tests use the [WordPress PHPUnit test suite](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/). WooCommerce is **not** required — the test bootstrap defines its own `POLYGLOT_LOCALES` and stubs WP-CLI.

### Setup (once)

```bash
# Install PHP dependencies
composer install

# Install the WordPress test suite (requires MySQL/MariaDB)
./bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]

# Example:
./bin/install-wp-tests.sh wp_tests root root localhost latest
```

This downloads WordPress core + test library to `/tmp/wordpress*` and creates a test database.

### Run

```bash
composer test

# Filter by test class or method
composer test:filter HelpersTest
composer test:filter test_is_master_on_master_domain
```

### Coverage

| Test file                | Area                                                          |
| ------------------------ | ------------------------------------------------------------- |
| `HelpersTest`            | Authority detection, locale resolution, URL scheme            |
| `RoutingTest`            | `pre_get_posts` locale filtering, master/shadow query scoping |
| `HreflangTest`           | `<link rel="alternate">` tag injection                        |
| `SitemapTest`            | Sitemap locale filtering + `xhtml:link` annotations           |
| `FeedTest`               | RSS feed locale filtering                                     |
| `AdminUITest`            | Admin column, locale filter dropdown, metabox                 |
| `HumanLockTest`          | `_translation_mode = manual` protection                       |
| `VirtualReviewsTest`     | Review virtualization + rating aggregation                    |
| `OrderLocaleTest`        | `_order_locale` meta on checkout                              |
| `RegistrationLocaleTest` | `_registration_locale` user meta                              |
| `ExportImportTest`       | TSV + HTML flat-file export/import round-trip                 |
| `SyncLockTest`           | MySQL advisory lock preventing concurrent import/export       |
| `BlocksBridgeTest`       | Gutenberg block product ID rewriting                          |
| `CheckLinksTest`         | Link scanning: wrong slugs, trailing slashes, localhost, fix  |
| `CheckLinksRenderedTest` | Rendered HTML scanning: dedup, sitemaps, hreflang, fragments  |
| `DraftLinkHandlerTest`   | Draft link resolution + `the_content` span replacement        |

Inventory bridge (stock virtualization, stock reduction) is tested via WP-CLI integration since it requires WooCommerce at runtime.

## Build & submit

```
cd /tmp && rm -rf piedweb-ai-polyglot && \
rsync -a \
  --exclude='.git' --exclude='.github' --exclude='.wordpress-org' \
  --exclude='bin' --exclude='node_modules' --exclude='tests' --exclude='vendor' \
  --exclude='.distignore' --exclude='.gitignore' --exclude='.php-cs-fixer*' \
  --exclude='.phpunit.result.cache' --exclude='composer.json' --exclude='composer.lock' \
  --exclude='phpunit.xml*' --exclude='README.md' \
  /home/robin/localhost/woodrock/wp-content/plugins/wp-ai-polyglot/ /tmp/piedweb-ai-polyglot/ && \
cd /tmp && zip -r piedweb-ai-polyglot.zip piedweb-ai-polyglot
```

---

Brought to you by Robin ([Pied Web](https://en.piedweb.com)), inspired by [Pushword CMS](https://pushword.piedweb.com), sponsored by [Woodrock Climbing](https://woodrockclimbing.com).
