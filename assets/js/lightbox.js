/**
 * Visor de foto en grande (ficha de producto).
 * Lightbox.open(items, index): items = [{ id, name, src }] (src = miniatura ya cargada).
 * - Muestra al instante la miniatura en caché y la cambia por la grande (=s1600) cuando llega.
 * - Clic o doble toque en la foto = zoom; con zoom, mover el mouse/dedo recorre la foto.
 * - Flechas / deslizar = foto anterior/siguiente · Esc o clic fuera = cerrar.
 * Lightbox.preload(urlOrId): baja la versión grande en segundo plano (queda en la caché del navegador).
 */
(function () {
    const BIG = 1600;
    const preloaded = new Map(); // url grande → Image (evita bajarla dos veces)
    let items = [];
    let index = 0;
    let root = null;
    let zoomed = false;
    let touchStartX = null;

    function bigUrl(item) {
        if (item.id) return `https://lh3.googleusercontent.com/d/${item.id}=s${BIG}`;
        return String(item.src || '').replace(/=s\d+(-[a-z0-9-]+)?$/i, `=s${BIG}`);
    }

    function preload(itemOrUrl) {
        const url = typeof itemOrUrl === 'string' ? itemOrUrl : bigUrl(itemOrUrl);
        if (!url || preloaded.has(url)) return preloaded.get(url);
        const img = new Image();
        img.decoding = 'async';
        img.fetchPriority = 'low';
        img.src = url;
        preloaded.set(url, img);
        return img;
    }

    function whenIdle(fn) {
        (window.requestIdleCallback || ((cb) => setTimeout(cb, 300)))(fn);
    }

    function build() {
        root = document.createElement('div');
        root.className = 'lightbox';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.innerHTML = `
            <button class="lightbox-btn lightbox-close" aria-label="Cerrar">✕</button>
            <button class="lightbox-btn lightbox-prev" aria-label="Anterior">‹</button>
            <div class="lightbox-stage"><img class="lightbox-img" alt=""></div>
            <button class="lightbox-btn lightbox-next" aria-label="Siguiente">›</button>
            <div class="lightbox-bar"><span class="lightbox-name"></span><span class="lightbox-count"></span></div>`;
        document.body.appendChild(root);

        const img = root.querySelector('.lightbox-img');
        root.querySelector('.lightbox-close').onclick = close;
        root.querySelector('.lightbox-prev').onclick = (e) => { e.stopPropagation(); go(-1); };
        root.querySelector('.lightbox-next').onclick = (e) => { e.stopPropagation(); go(1); };
        root.addEventListener('click', (e) => {
            if (e.target === root || e.target.classList.contains('lightbox-stage')) close();
        });
        img.addEventListener('click', (e) => { e.stopPropagation(); toggleZoom(e); });
        img.addEventListener('mousemove', (e) => { if (zoomed) pan(e.clientX, e.clientY); });
        img.addEventListener('touchmove', (e) => {
            if (zoomed && e.touches.length === 1) { e.preventDefault(); pan(e.touches[0].clientX, e.touches[0].clientY); }
        }, { passive: false });
        root.addEventListener('touchstart', (e) => {
            touchStartX = (!zoomed && e.touches.length === 1) ? e.touches[0].clientX : null;
        }, { passive: true });
        root.addEventListener('touchend', (e) => {
            if (touchStartX === null) return;
            const dx = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
            touchStartX = null;
        });
        document.addEventListener('keydown', (e) => {
            if (!root.classList.contains('open')) return;
            if (e.key === 'Escape') { e.stopPropagation(); close(); }
            else if (e.key === 'ArrowLeft') go(-1);
            else if (e.key === 'ArrowRight') go(1);
        });
    }

    function pan(x, y) {
        const img = root.querySelector('.lightbox-img');
        const r = img.getBoundingClientRect();
        // Con transform el rect ya está escalado: usar la posición relativa dentro de la foto
        const px = Math.min(100, Math.max(0, ((x - r.left) / r.width) * 100));
        const py = Math.min(100, Math.max(0, ((y - r.top) / r.height) * 100));
        img.style.transformOrigin = `${px}% ${py}%`;
    }

    function toggleZoom(e) {
        const img = root.querySelector('.lightbox-img');
        zoomed = !zoomed;
        if (zoomed) pan(e.clientX, e.clientY);
        img.classList.toggle('zoomed', zoomed);
        root.classList.toggle('is-zoomed', zoomed);
    }

    function show() {
        const item = items[index];
        const img = root.querySelector('.lightbox-img');
        zoomed = false;
        img.classList.remove('zoomed');
        root.classList.remove('is-zoomed');
        img.style.transformOrigin = '50% 50%';

        const url = bigUrl(item);
        const ready = preloaded.get(url);
        // Lo que ya está en caché primero (grande si ya bajó, si no la miniatura) y luego la grande
        img.src = ready && ready.complete && ready.naturalWidth ? url : (item.src || url);
        img.alt = item.name || '';
        if (img.src !== url) {
            const big = preload(url);
            const swap = () => { if (items[index] === item) img.src = url; };
            if (big.complete && big.naturalWidth) swap(); else big.addEventListener('load', swap, { once: true });
        }

        root.querySelector('.lightbox-name').textContent = item.name || '';
        root.querySelector('.lightbox-count').textContent = items.length > 1 ? `${index + 1} / ${items.length}` : '';
        root.classList.toggle('single', items.length < 2);

        // Vecinas listas para cuando se pase de foto
        if (items.length > 1) {
            whenIdle(() => {
                preload(items[(index + 1) % items.length]);
                preload(items[(index - 1 + items.length) % items.length]);
            });
        }
    }

    function go(step) {
        if (items.length < 2) return;
        index = (index + step + items.length) % items.length;
        show();
    }

    function open(list, start) {
        items = (list || []).filter((it) => it && (it.id || it.src));
        if (!items.length) return;
        if (!root) build();
        index = Math.min(Math.max(0, start || 0), items.length - 1);
        show();
        root.classList.add('open');
        document.documentElement.classList.add('lightbox-lock');
    }

    function close() {
        if (!root) return;
        root.classList.remove('open', 'is-zoomed');
        document.documentElement.classList.remove('lightbox-lock');
    }

    function toItem(x) {
        if (typeof x !== 'string') return x;
        if (!x.includes('/')) return { id: x };
        const m = x.match(/googleusercontent\.com\/d\/([A-Za-z0-9_-]+)/);
        return m ? { id: m[1] } : { src: x };
    }

    // Portada que ya viene en el HTML: su versión grande se baja apenas el navegador queda libre
    document.addEventListener('DOMContentLoaded', () => {
        const cover = document.querySelector('#mainCover img');
        if (cover) whenIdle(() => preload(toItem(cover.currentSrc || cover.src)));
    });

    window.Lightbox = { open, close, preload: (x) => { if (x) whenIdle(() => preload(toItem(x))); }, bigUrl };
})();
