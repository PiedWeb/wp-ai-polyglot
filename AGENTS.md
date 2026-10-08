# wp-ai-polyglot

- Work on `main`; no task branch unless asked.
- Commits: Conventional Commits, `type(scope): subject`, in French or English.
- Before committing: `composer cs`, `composer phpstan` and `composer test` (WordPress test suite installed by `bin/install-wp-tests.sh`, see README › Tests); add `composer test:wc` when touching the WooCommerce bridge.
- `composer.lock` is versioned and resolved for PHP 8.0 (`config.platform.php`), the lowest PHP of the CI matrix: keep that platform when updating.
- Release: push a `vX.Y.Z` tag, only when asked; it deploys to WordPress.org (README › Release to WordPress.org).
- Never run `wp plugin update` on a dev checkout or a symlink to it; use `--exclude=wp-ai-polyglot`.
- Dev-only files go in `.distignore` so they don't ship to WordPress.org.
