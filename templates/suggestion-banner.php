<?php
/**
 * Polyglot suggestion banner template.
 *
 * Rendered hidden; JS reveals it when the visitor's browser language
 * differs from the current page locale.
 *
 * @var string $current_hreflang Current page hreflang code
 * @var string $locales_json     JSON-encoded locale data for JS
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div id="polyglot-bar" role="banner" hidden
     data-current="<?php echo esc_attr($current_hreflang); ?>"
     data-locales="<?php echo esc_attr($locales_json); ?>">
    <span id="polyglot-bar-message"></span>
    <button id="polyglot-bar-cta" type="button" style="background:none;border:1px solid #888;color:#ccc;padding:4px 14px;border-radius:4px;font-size:13px;cursor:pointer;white-space:nowrap"></button>
    <button id="polyglot-bar-close" type="button" aria-label="Close">&times;</button>
</div>
