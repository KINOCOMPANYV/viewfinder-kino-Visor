/* Hacer pedido / Nuevo pedido: explorador visual (carpetas → fotos → tocar para agregar) + carrito (window.kinoCart). */
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const meta = {};            // sku → {name, cover, price, stock}
    const st = { folder: '', q: '', page: 1, pages: 1, todo: false, seq: 0 };
    let timer = 0;

    const qtyOf = (sku) => { const it = kinoCart.read().find((i) => i.sku === sku); return it ? it.qty : 0; };
    const setQty = (sku, q) => {
        const items = kinoCart.read();
        const it = items.find((i) => i.sku === sku);
        if (it) it.qty = Math.min(999, q); else if (q > 0) items.push({ sku, qty: Math.min(999, q) });
        kinoCart.write(items.filter((i) => i.qty > 0));
    };

    async function api(params) {
        const u = '/api/pedido/explorar?' + new URLSearchParams(params).toString();
        const r = await fetch(u, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
    }

    // ── Explorador ──
    function tile(p) {
        const q = qtyOf(p.sku);
        const out = p.stock != null && p.stock <= 0;
        return '<div class="mo-tile' + (q ? ' in' : '') + '" data-sku="' + esc(p.sku) + '">' +
            '<button type="button" class="mo-pic" data-add="1" aria-label="Agregar ' + esc(p.sku) + '"><img src="' + esc(p.cover) + '" alt="" loading="lazy">' +
            (q ? '<span class="mo-badge-q">' + q + '</span>' : '') + '</button>' +
            '<div class="mo-tinfo"><b>' + esc(p.sku) + '</b>' + (p.name ? '<small>' + esc(p.name) + '</small>' : '') +
            '<span class="mo-price">' + (p.price ? money(p.price) : '') + (p.stock != null ? ' <i class="mo-st ' + (out ? 'out' : 'in') + '">' + (out ? 'Out stock' : 'In Stock') + '</i>' : '') + '</span></div>' +
            '<div class="mo-step">' + (q ? '<button type="button" data-d="-1" aria-label="Menos">−</button><span>' + q + '</span><button type="button" data-d="1" aria-label="Más">+</button>'
                : '<button type="button" class="mo-addbtn" data-add="1">+ Agregar</button>') + '</div></div>';
    }
    function drawItems(items, append) {
        items.forEach((p) => { meta[p.sku] = p; });
        const html = items.map(tile).join('');
        $('moItems').innerHTML = append ? $('moItems').innerHTML + html : html;
    }
    function crumbs(path) {
        const bits = ['<a href="#" data-f="">Todas las carpetas</a>'];
        path.forEach((c, i) => bits.push(i === path.length - 1 ? '<span>' + esc(c.name.replace(/^\d+\s*[-.)]\s*/, '')) + '</span>' : '<a href="#" data-f="' + esc(c.id) + '">' + esc(c.name.replace(/^\d+\s*[-.)]\s*/, '')) + '</a>'));
        if (st.q) bits.push('<span>Búsqueda: «' + esc(st.q) + '»</span>');
        $('moCrumbs').innerHTML = bits.join(' › ');
    }
    async function load(append) {
        const my = ++st.seq;
        const params = {}; if (st.folder) params.carpeta = st.folder; if (st.q) params.q = st.q; if (st.todo) params.todo = 1; params.p = st.page;
        $('moHint').textContent = append ? '' : 'Cargando…';
        let j;
        try { j = await api(params); } catch (e) { $('moHint').textContent = 'No se pudo cargar el catálogo. Intenta de nuevo.'; return; }
        if (my !== st.seq) return;
        st.pages = j.pages;
        crumbs(j.path);
        if (!append) {
            $('moFolders').innerHTML = j.folders.map((f) => '<button type="button" class="mo-folder" data-f="' + esc(f.id) + '">' +
                (f.cover ? '<img src="' + esc(f.cover) + '" alt="" loading="lazy">' : '<span class="mo-noimg"></span>') + '<span>' + esc(f.name) + '</span></button>').join('');
        }
        drawItems(j.items, append);
        $('moMore').hidden = st.page >= j.pages;
        let hint = '';
        if (!j.items.length && !j.folders.length) hint = st.q ? 'Sin resultados para «' + st.q + '».' : 'No hay productos aquí.';
        else if (j.folders.length && !j.items.length && !st.q) hint = 'Elige una carpeta' + (st.folder ? ' o ' : '') ;
        if (st.folder && j.folders.length && !j.items.length && !st.q) { $('moHint').innerHTML = 'Elige una subcarpeta o <a href="#" data-todo="1">ver todos los productos de esta carpeta</a>.'; }
        else $('moHint').textContent = hint || (j.total ? j.total + ' producto' + (j.total === 1 ? '' : 's') : '');
    }
    function go(folder, todo) { st.folder = folder; st.todo = !!todo; st.page = 1; st.q = ''; $('moQ').value = ''; load(false); window.scrollTo({ top: 0, behavior: 'smooth' }); }

    document.addEventListener('click', (ev) => {
        const f = ev.target.closest('[data-f]'); if (f) { ev.preventDefault(); go(f.dataset.f, false); return; }
        const t = ev.target.closest('[data-todo]'); if (t) { ev.preventDefault(); go(st.folder, true); return; }
    });
    $('moItems').addEventListener('click', (ev) => {
        const tl = ev.target.closest('.mo-tile'); if (!tl) return;
        const sku = tl.dataset.sku, b = ev.target.closest('button'); if (!b) return;
        if (b.dataset.add) setQty(sku, qtyOf(sku) + 1);
        else if (b.dataset.d) setQty(sku, qtyOf(sku) + parseInt(b.dataset.d, 10));
    });
    $('moMoreBtn').addEventListener('click', () => { st.page++; load(true); });
    $('moQ').addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => { st.q = $('moQ').value.trim(); if (st.q.length === 1) return; st.page = 1; load(false); }, 300);
    });

    // ── Carrito ──
    async function ensureMeta(skus) {
        const need = skus.filter((s) => !meta[s]);
        if (!need.length) return;
        try { (await api({ skus: need.join(',') })).items.forEach((p) => { meta[p.sku] = p; }); } catch (e) { /* se dibuja sin foto */ }
    }
    async function drawCart() {
        const items = kinoCart.read();
        await ensureMeta(items.map((i) => i.sku));
        let total = 0, units = 0;
        $('moCart').innerHTML = items.map((i) => {
            const m = meta[i.sku] || {};
            const p = m.price || 0; total += p * i.qty; units += i.qty;
            return '<li class="mo-line" data-sku="' + esc(i.sku) + '">' +
                (m.cover ? '<img src="' + esc(m.cover) + '" alt="" loading="lazy">' : '<span class="mo-noimg"></span>') +
                '<div class="mo-info"><b>' + esc(i.sku) + '</b><small>' + esc(m.name || '') + '</small><small>' + (p ? money(p) + ' c/u' : 'Sin precio en tabla') + '</small></div>' +
                '<div class="mo-qty"><button type="button" data-d="-1" aria-label="Menos">−</button><span>' + i.qty + '</span><button type="button" data-d="1" aria-label="Más">+</button></div>' +
                '<button type="button" class="mo-del" data-del="1" aria-label="Quitar">✕</button></li>';
        }).join('');
        $('moEmpty').hidden = items.length > 0;
        $('moTotal').hidden = items.length === 0;
        $('moSend').disabled = items.length === 0;
        $('moCount').textContent = items.length ? '(' + items.length + ' ref.)' : '';
        $('moTotalV').textContent = money(total);
        $('moHeadTotal').textContent = items.length ? money(total) : '';
        $('moBar').hidden = items.length === 0;
        $('moBarN').textContent = items.length; $('moBarT').textContent = money(total);
        // refresca los contadores de las tarjetas visibles
        document.querySelectorAll('.mo-tile').forEach((tl) => { const p = meta[tl.dataset.sku]; if (p) tl.outerHTML = tile(p); });
    }
    $('moCart').addEventListener('click', (ev) => {
        const b = ev.target.closest('button'); if (!b) return;
        const sku = b.closest('.mo-line').dataset.sku;
        if (b.dataset.del) setQty(sku, 0); else setQty(sku, qtyOf(sku) + parseInt(b.dataset.d, 10));
    });
    const sheet = (on) => document.body.classList.toggle('mo-sheet-open', on);
    $('moBar').addEventListener('click', () => sheet(true));
    $('moClose').addEventListener('click', () => sheet(false));

    // ── Envío ──
    $('moSend').addEventListener('click', async () => {
        const err = $('moErr'); err.hidden = true;
        const btn = $('moSend'); btn.disabled = true; btn.textContent = 'Enviando…';
        try {
            const r = await fetch('/hacer-pedido/enviar', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ csrf: window.MO_CSRF, staff: !!window.MO_STAFF, name: $('moName').value, phone: $('moPhone').value, note: $('moNote').value, website: $('moWeb').value, items: kinoCart.read() })
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || 'No se pudo enviar el pedido.');
            kinoCart.write([]);
            $('moDoneId').textContent = j.number || (j.id ? '#' + j.id : '');
            sheet(false); $('moWork').hidden = true; $('moBar').hidden = true; $('moDone').hidden = false;
        } catch (e) {
            err.textContent = e.message; err.hidden = false;
            btn.disabled = false; btn.textContent = 'Enviar pedido';
        }
    });
    const CK = window.MO_STAFF ? 'kinoClienteVendedor' : 'kinoCliente';
    if (!window.MO_STAFF) try { const s = JSON.parse(localStorage.getItem(CK) || 'null'); if (s) { $('moName').value = s.name || ''; $('moPhone').value = s.phone || ''; } } catch (e) {}
    if (!window.MO_STAFF) ['moName', 'moPhone'].forEach((id) => $(id).addEventListener('change', () => {
        try { localStorage.setItem(CK, JSON.stringify({ name: $('moName').value, phone: $('moPhone').value })); } catch (e) {}
    }));
    window.addEventListener('kinoCart', drawCart);
    load(false);
    drawCart();
})();
