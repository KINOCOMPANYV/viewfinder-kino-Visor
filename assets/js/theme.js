/* Modo claro / oscuro (2026-11-25). Oscuro por defecto; la elección se recuerda en este navegador.
   Se carga en <head> para pintar el tema antes de mostrar la página (sin parpadeo). */
(function () {
    var KEY = 'kinoTheme', root = document.documentElement, saved = null;
    try { saved = localStorage.getItem(KEY); } catch (e) {}
    function apply(t) {
        root.setAttribute('data-theme', t);
        var m = document.querySelector('meta[name="theme-color"]');
        if (m) m.setAttribute('content', t === 'light' ? '#fbfbfd' : '#161618');
    }
    var def = root.getAttribute('data-default-theme') === 'light' ? 'light' : 'dark'; /* v2: claro por defecto */
    apply(saved === 'light' || saved === 'dark' ? saved : def);
    var SUN = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
    var MOON = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>';
    document.addEventListener('DOMContentLoaded', function () {
        var host = document.querySelector('.header .header-inner, .v2-top-actions');
        if (!host || host.querySelector('.theme-toggle')) return;
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'theme-toggle';
        function paint() {
            var light = root.getAttribute('data-theme') === 'light';
            b.innerHTML = light ? MOON : SUN;
            b.title = b.setAttribute('aria-label', light ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro') || (light ? 'Modo oscuro' : 'Modo claro');
        }
        b.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            apply(next); try { localStorage.setItem(KEY, next); } catch (e) {} paint();
        });
        paint(); host.insertBefore(b, host.firstChild);
    });
})();
