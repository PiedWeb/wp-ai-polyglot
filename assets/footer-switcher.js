document.addEventListener('DOMContentLoaded', function () {
    var w = document.getElementById('polyglot-fb-select');
    if (!w) {
        return;
    }
    var b = w.querySelector('.polyglot-fb-toggle');
    b.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = w.classList.toggle('open');
        b.setAttribute('aria-expanded', open);
    });
    document.addEventListener('click', function () {
        w.classList.remove('open');
        b.setAttribute('aria-expanded', 'false');
    });
});
