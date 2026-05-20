<?php
/**
 * Footer language switcher template.
 *
 * @var array  $items           Locale items: active, label, short_label, flag_url, url
 * @var string $active_label    Current locale label
 * @var string $active_flag_url Current locale flag URL
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="polyglot-footer-bar" id="polyglot-footer-bar">
  <div class="polyglot-fb-select" id="polyglot-fb-select">
    <button class="polyglot-fb-toggle" type="button" aria-expanded="false" aria-haspopup="listbox">
      <span class="polyglot-fb-flag" style="background-image:url(<?php echo esc_url($active_flag_url); ?>)"></span>
      <?php echo esc_html($active_label); ?>
      <span class="polyglot-fb-chevron">&#9662;</span>
    </button>
    <div class="polyglot-fb-dropdown" role="listbox">
      <?php foreach ($items as $item): ?>
        <?php if (! $item['active']): ?>
          <a href="<?php echo esc_url($item['url']); ?>" role="option" hreflang="<?php echo esc_attr($item['hreflang']); ?>">
            <span class="polyglot-fb-flag" style="background-image:url(<?php echo esc_url($item['flag_url']); ?>)"></span>
            <?php echo esc_html($item['label']); ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (defined('POLYGLOT_FOOTER_CREDIT') && POLYGLOT_FOOTER_CREDIT) : ?>
        <a class="polyglot-fb-powered" href="https://wap.piedweb.com" target="_blank" rel="noopener" title="best WordPress translation plugin">⚡ PiedWeb AI Polyglot</a>
      <?php endif; ?>
    </div>
  </div>
</div>
