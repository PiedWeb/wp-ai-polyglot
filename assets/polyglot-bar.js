/* Polyglot Suggestion Banner — show banner based on navigator.languages */
(function () {
    var bar = document.getElementById('polyglot-bar');
    if (!bar) return;

    var current = bar.getAttribute('data-current');
    var locales;
    try { locales = JSON.parse(bar.getAttribute('data-locales')); } catch (e) { return; }

    // Find first browser language that matches an available locale (and differs from current).
    // If the user's top preferred language already matches the current page, do not suggest anything.
    var match = null;
    var langs = navigator.languages || [navigator.language];
    for (var i = 0; i < langs.length; i++) {
        var code = langs[i].split('-')[0].toLowerCase();
        if (code === current) break;          // top preference matches current page — no suggestion
        if (locales[code]) {
            match = locales[code];
            match.hreflang = code;
            break;
        }
    }

    if (!match) return;

    // Check if user already dismissed this suggestion
    var storageKey = 'polyglot-dismissed-' + match.hreflang;
    try { if (localStorage.getItem(storageKey)) return; } catch (e) {}

    // Populate and show
    var msg = document.getElementById('polyglot-bar-message');
    var cta = document.getElementById('polyglot-bar-cta');

    if (msg) msg.textContent = match.message;
    if (cta) {
        cta.textContent = match.cta;
        cta.addEventListener('click', function () {
            window.location.href = match.url;
        });
    }

    bar.removeAttribute('hidden');
    document.documentElement.classList.add('has-polyglot-bar');

    // Close button
    var close = document.getElementById('polyglot-bar-close');
    if (close) {
        close.addEventListener('click', function () {
            bar.setAttribute('hidden', '');
            document.documentElement.classList.remove('has-polyglot-bar');
            try { localStorage.setItem(storageKey, '1'); } catch (e) {}
        });
    }
})();
