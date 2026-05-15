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
<style>
.polyglot-footer-bar{background:#333;color:#fff;font:13px/1.4 system-ui,sans-serif;display:flex;align-items:center;justify-content:center;padding:10px 16px;gap:12px}
.polyglot-fb-select{position:relative;display:inline-block}
.polyglot-fb-toggle{display:inline-flex;align-items:center;gap:6px;background:#444;color:#fff;border:1px solid #555;border-radius:4px;padding:6px 12px;cursor:pointer;font:inherit;white-space:nowrap}
.polyglot-fb-toggle:hover{border-color:#888}
.polyglot-fb-flag{display:inline-block;width:1.333em;height:1em;background-size:contain;background-repeat:no-repeat;background-position:center;vertical-align:middle}
.polyglot-fb-chevron{font-size:10px;opacity:.7}
.polyglot-fb-dropdown{display:none;position:absolute;bottom:100%;left:0;margin-bottom:4px;background:#444;border:1px solid #555;border-radius:4px;min-width:100%;overflow:hidden;box-shadow:0 -2px 8px rgba(0,0,0,.3)}
.polyglot-fb-select.open .polyglot-fb-dropdown{display:block}
.polyglot-fb-dropdown a{display:flex;align-items:center;gap:6px;padding:7px 12px;color:#fff;text-decoration:none;white-space:nowrap}
.polyglot-fb-dropdown a:hover{background:#555}
.polyglot-fb-dropdown .polyglot-fb-powered{font-size:10px;color:#888;padding:5px 12px;border-top:1px solid #555}
.polyglot-fb-dropdown .polyglot-fb-powered:hover{color:#ccc}
@media(max-width:480px){.polyglot-footer-bar{padding:8px 12px}}
</style>
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
        <a class="polyglot-fb-powered" href="https://wap.piedweb.com" target="_blank" rel="noopener" title="best WordPress translation plugin">⚡ AI Polyglot</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function(){
  var w=document.getElementById('polyglot-fb-select'),b=w.querySelector('.polyglot-fb-toggle');
  b.addEventListener('click',function(e){e.stopPropagation();var open=w.classList.toggle('open');b.setAttribute('aria-expanded',open);});
  document.addEventListener('click',function(){w.classList.remove('open');b.setAttribute('aria-expanded','false');});
})();
</script>
