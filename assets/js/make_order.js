/* Hacer pedido / Nuevo pedido: explorador visual (carpetas → fotos → tocar para agregar) + carrito (window.kinoCart). */
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const meta = {};            // sku → {name, cover, price, stock}
    const st = { folder: '', q: '', page: 1, pages: 1, todo: false, seq: 0, marca: '', genero: '', stock: false, precio: '', orden: '', facets: false };
    let timer = 0;

    const qtyOf = (sku) => { const it = kinoCart.read().find((i) => i.sku === sku); return it ? it.qty : 0; };
    const setQty = (sku, q) => {
        const items = kinoCart.read();
        const it = items.find((i) => i.sku === sku);
        if (it) it.qty = Math.min(999, q); else if (q > 0) items.push({ sku, qty: Math.min(999, q) });
        kinoCart.write(items.filter((i) => i.qty > 0));
    };

    async function api(params) {
        const u = '/api/pedido/explorar?' + (params instanceof URLSearchParams ? params : new URLSearchParams(params)).toString();
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
        const params = new URLSearchParams(); if (st.folder) params.set('carpeta', st.folder); if (st.q) params.set('q', st.q); if (st.todo) params.set('todo', 1); params.set('p', st.page);
        if (st.marca) params.append('marca[]', st.marca);
        if (st.genero) params.set('genero', st.genero);
        if (st.stock) params.set('stock', 'con');
        if (st.precio) { const [a, b] = st.precio.split('-'); if (a) params.set('pmin', a); if (b) params.set('pmax', b); }
        if (st.orden) { const [k, d] = st.orden.split('-'); params.set(k === 'precio' ? 'oprecio' : 'ocodigo', d); }
        if (!st.facets) params.set('facets', 1);
        $('moHint').textContent = append ? '' : 'Cargando…';
        let j;
        try { j = await api(params); } catch (e) { $('moHint').textContent = 'No se pudo cargar el catálogo. Intenta de nuevo.'; return; }
        if (my !== st.seq) return;
        st.pages = j.pages;
        if (j.facets && !st.facets) {
            st.facets = true;
            $('moMarca').innerHTML = '<option value="">Todas las marcas</option>' + j.facets.brands.map((b) => '<option>' + esc(b) + '</option>').join('');
            if (!j.facets.prices) { $('moPrecio').hidden = true; }
        }
        crumbs(j.path);
        const on = !!(st.marca || st.genero || st.stock || st.precio);
        $('moClear').hidden = !on;
        if (!append) {
            $('moFolders').innerHTML = j.folders.map((f) => '<button type="button" class="mo-folder" data-f="' + esc(f.id) + '">' +
                (f.cover ? '<img src="' + esc(f.cover) + '" alt="" loading="lazy">' : '<span class="mo-noimg"></span>') + '<span>' + esc(f.name) + '</span></button>').join('');
        }
        drawItems(j.items, append);
        $('moMore').hidden = st.page >= j.pages;
        let hint = '';
        if (!j.items.length && !j.folders.length) hint = st.q ? 'Sin resultados para «' + st.q + '».' : 'No hay productos aquí.';
        else if (j.folders.length && !j.items.length && !st.q) hint = 'Elige una carpeta o filtra por marca, género o precio.';
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
    // Filtros en vivo: se combinan entre sí y con el buscador y la carpeta
    const refilter = () => { st.page = 1; st.todo = false; load(false); };
    $('moMarca').addEventListener('change', () => { st.marca = $('moMarca').value; refilter(); });
    $('moPrecio').addEventListener('change', () => { st.precio = $('moPrecio').value; refilter(); });
    $('moOrden').addEventListener('change', () => { st.orden = $('moOrden').value; refilter(); });
    $('moStock').addEventListener('click', () => { st.stock = !st.stock; $('moStock').classList.toggle('on', st.stock); refilter(); });
    $('moGenero').addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-g]'); if (!b) return;
        st.genero = st.genero === b.dataset.g ? '' : b.dataset.g;
        $('moGenero').querySelectorAll('.mo-chip').forEach((c) => c.classList.toggle('on', c.dataset.g === st.genero));
        refilter();
    });
    $('moClear').addEventListener('click', () => {
        Object.assign(st, { marca: '', genero: '', stock: false, precio: '' });
        $('moMarca').value = ''; $('moPrecio').value = ''; $('moStock').classList.remove('on');
        $('moGenero').querySelectorAll('.mo-chip').forEach((c) => c.classList.remove('on'));
        refilter();
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
            const body = editing
                ? { csrf: window.MO_CSRF, id: editing.id, token: editing.token, note: $('moNote').value, items: kinoCart.read() }
                : { csrf: window.MO_CSRF, staff: !!window.MO_STAFF, name: $('moName').value, phone: $('moPhone').value, note: $('moNote').value, website: $('moWeb').value, items: kinoCart.read() };
            const r = await fetch(editing ? '/hacer-pedido/mio' : '/hacer-pedido/enviar', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body)
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || 'No se pudo enviar el pedido.');
            kinoCart.write([]);
            if (!editing && j.token && j.id) { lastSent = { id: j.id, token: j.token, number: j.number || '' }; remember(lastSent); }
            else if (editing) lastSent = editing;
            editing = null;
            $('moEditLast').hidden = !lastSent;
            $('moDoneId').textContent = j.number || (j.id ? '#' + j.id : '');
            sheet(false); $('moWork').hidden = true; $('moBar').hidden = true; $('moDone').hidden = false;
        } catch (e) {
            err.textContent = e.message; err.hidden = false;
            btn.disabled = false; btn.textContent = editing ? 'Guardar cambios' : 'Enviar pedido';
        }
    });

    // ── Editar mi pedido mientras está «Recibido» (el cliente no tiene cuenta: id + llave guardada en su dispositivo) ──
    let editing = null, lastSent = null;
    const MK = 'kinoMisPedidos';
    const mine = () => { try { const v = JSON.parse(localStorage.getItem(MK) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
    const remember = (o) => { try { localStorage.setItem(MK, JSON.stringify([o].concat(mine().filter((x) => x.id !== o.id)).slice(0, 5))); } catch (e) {} };
    const mioCall = (o) => fetch('/hacer-pedido/mio', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ csrf: window.MO_CSRF, id: o.id, token: o.token }) }).then((r) => r.json());
    async function startEdit(o) {
        const j = await mioCall(o);
        if (!j.ok || !j.editable) { alert(j.error || 'Tu pedido ya está «' + (j.stageLabel || '') + '» y no se puede cambiar. Escríbenos por WhatsApp.'); return; }
        editing = o;
        kinoCart.write(j.items.map((i) => ({ sku: i.sku, qty: +i.qty })));
        $('moName').value = j.name; $('moPhone').value = j.phone; $('moNote').value = j.note || '';
        $('moName').readOnly = $('moPhone').readOnly = true;
        $('moSend').textContent = 'Guardar cambios';
        $('moDone').hidden = true; $('moWork').hidden = false;
        $('moMine').hidden = false; $('moMine').innerHTML = '✏️ Editando tu pedido <b>' + esc(j.number) + '</b>. Cambia lo que necesites y toca «Guardar cambios».';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    $('moEditLast').addEventListener('click', () => lastSent && startEdit(lastSent));
    if (!window.MO_STAFF) (async () => {
        const o = mine()[0]; if (!o) return;
        try {
            const j = await mioCall(o); if (!j.ok) return;
            const box = $('moMine'); box.hidden = false;
            box.innerHTML = 'Tu último pedido <b>' + esc(j.number) + '</b>: ' + esc(j.stageLabel) + (j.editable ? ' · <button type="button" class="btn btn-sm btn-secondary" id="moEditBtn">✏️ Editar</button>' : '');
            if (j.editable) $('moEditBtn').addEventListener('click', () => startEdit(o));
        } catch (e) { /* sin red */ }
    })();

    // ── Vendedor: elegir un cliente existente (autocompletar por nombre o teléfono) ──
    if (window.MO_STAFF) {
        let ct = 0, found = [];
        $('moName').addEventListener('input', () => {
            clearTimeout(ct);
            const v = $('moName').value.trim();
            const hit = found.find((c) => c.name + ' — ' + c.phone === v);
            if (hit) { $('moName').value = hit.name; $('moPhone').value = hit.phone; return; }
            ct = setTimeout(async () => {
                if (v.length < 2) return;
                try { found = (await (await fetch('/clientes/buscar?q=' + encodeURIComponent(v))).json()).customers || []; } catch (e) { return; }
                $('moCust').innerHTML = found.map((c) => '<option value="' + esc(c.name + ' — ' + c.phone) + '">').join('');
            }, 250);
        });
    }
    const CK = window.MO_STAFF ? 'kinoClienteVendedor' : 'kinoCliente';
    if (!window.MO_STAFF) try { const s = JSON.parse(localStorage.getItem(CK) || 'null'); if (s) { $('moName').value = s.name || ''; $('moPhone').value = s.phone || ''; } } catch (e) {}
    if (!window.MO_STAFF) ['moName', 'moPhone'].forEach((id) => $(id).addEventListener('change', () => {
        try { localStorage.setItem(CK, JSON.stringify({ name: $('moName').value, phone: $('moPhone').value })); } catch (e) {}
    }));
    window.addEventListener('kinoCart', drawCart);
    load(false);
    drawCart();
})();
