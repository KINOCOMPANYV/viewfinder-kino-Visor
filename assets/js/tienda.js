/* Tienda web (/tienda): explorar, buscar, filtrar, carrito y pedido. Lee /api/pedido/explorar y envía a /hacer-pedido/enviar (tienda=1). */
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const big = (u) => String(u || '').replace(/=s\d+/, '=s900');
    const KEY = 'kinoTienda';
    const meta = {};
    const st = { folder: '', q: '', page: 1, pages: 1, marca: '', genero: '', stock: false, precio: '', orden: '', seq: 0, step: 1, busy: false };
    const nameOf = (m) => (m.name || '').replace(new RegExp('\\s*' + m.sku.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*', 'i'), ' ').trim() || 'Reloj ' + m.sku;
    const isOut = (m) => !!m && m.stock !== null && m.stock !== undefined && m.stock <= 0;
    const PLUS = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    const MINUS = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>';

    // ── Carrito (localStorage) ──
    const read = () => { try { const v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
    const write = (items) => { try { localStorage.setItem(KEY, JSON.stringify(items.filter((i) => i.qty > 0))); } catch (e) {} paintCart(); };
    const qtyOf = (sku) => (read().find((i) => i.sku === sku) || { qty: 0 }).qty;
    function setQty(sku, q) {
        const items = read(); const it = items.find((i) => i.sku === sku);
        q = Math.max(0, Math.min(99, q));
        if (it) it.qty = q; else if (q > 0) items.push({ sku, qty: q });
        write(items);
    }
    const totals = () => read().reduce((a, i) => { const m = meta[i.sku]; a.n += i.qty; a.refs++; a.t += m ? m.price * i.qty : 0; return a; }, { n: 0, refs: 0, t: 0 });

    function paintCart() {
        const t = totals();
        const badge = $('tnCartN'); badge.hidden = t.n < 1; badge.textContent = t.n > 99 ? '99+' : t.n;
        const bar = $('tnBar'); bar.hidden = t.n < 1 || $('tnDrawer').classList.contains('open');
        $('tnBarN').textContent = t.n + (t.n === 1 ? ' unidad' : ' unidades'); $('tnBarT').textContent = t.t ? money(t.t) : '';
        // tarjetas visibles
        document.querySelectorAll('.tn-card').forEach((c) => paintAdd(c, c.dataset.sku));
        if ($('tnDrawer').classList.contains('open')) paintLines();
    }
    function paintAdd(card, sku) {
        const q = qtyOf(sku); const box = card.querySelector('.tn-act'); if (!box) return;
        const had = box.contains(document.activeElement) ? (document.activeElement.dataset.d || '1') : null;
        if (isOut(meta[sku]) && q === 0) box.innerHTML = '<button type="button" class="tn-btn tn-btn-primary" disabled>Agotado</button>';
        else if (q > 0) box.innerHTML = '<div class="tn-step"><button type="button" data-d="-1" aria-label="Quitar uno">' + MINUS + '</button><span><b>' + q + '</b><i> en tu pedido</i></span><button type="button" data-d="1" aria-label="Agregar otro">' + PLUS + '</button></div>';
        else box.innerHTML = '<button type="button" class="tn-btn tn-btn-primary" data-d="1">Agregar al pedido</button>';
        if (had) { const b = box.querySelector('[data-d="' + had + '"]') || box.querySelector('button'); if (b) b.focus({ preventScroll: true }); }
    }

    // ── API ──
    async function api(p) {
        const r = await fetch('/api/pedido/explorar?' + new URLSearchParams(p).toString(), { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
    }
    function params() {
        const p = { todos: '1', todo: '1', p: st.page };
        if (st.folder) p.carpeta = st.folder;
        if (st.q) p.q = st.q;
        if (st.marca) p['marca[]'] = st.marca;
        if (st.genero) p.genero = st.genero;
        if (st.stock) p.stock = 'con';
        if (st.precio) { const [a, b] = st.precio.split('-'); if (a) p.pmin = a; if (b) p.pmax = b; }
        if (st.orden) { const [k, d] = st.orden.split('-'); if (k === 'precio') p.oprecio = d; else p.ocodigo = d; }
        return p;
    }

    // ── Pintar catálogo ──
    function card(it, i) {
        meta[it.sku] = it;
        const out = isOut(it);
        const low = !out && it.stock !== null && it.stock <= 3;
        const exact = !!st.q && it.sku.toLowerCase() === st.q.toLowerCase();
        return '<article class="tn-card' + (out ? ' out' : '') + (exact ? ' exact' : '') + '" data-sku="' + esc(it.sku) + '" style="--i:' + (i % 12) + '">'
            + '<button type="button" class="tn-ph" data-quick="' + esc(it.sku) + '" aria-label="Ver detalle de ' + esc(it.sku) + '">'
            + '<img src="' + esc(it.cover) + '" alt="' + esc(it.name || it.sku) + '" loading="lazy" decoding="async" width="400" height="400">'
            + (out ? '<span class="tn-badge">Agotado</span>' : (exact ? '<span class="tn-badge ok">Referencia exacta</span>' : '')) + '</button>'
            + '<div class="tn-info"><span class="tn-code">' + esc(it.sku) + '</span><span class="tn-nm">' + esc(nameOf(it)) + '</span>'
            + '<div class="tn-pr">' + (it.price ? '<b>' + money(it.price) + '</b><small>COP</small>' : '<b class="ask">Consultar precio</b>') + (low ? '<small class="low">Quedan ' + it.stock + '</small>' : '') + '</div>'
            + '<button type="button" class="tn-detail" data-quick="' + esc(it.sku) + '">Ver detalle</button>'
            + '<div class="tn-act"></div></div></article>';
    }
    async function load(reset) {
        const seq = ++st.seq; const grid = $('tnGrid');
        if (reset) { st.page = 1; grid.innerHTML = '<div class="tn-skel"></div>'.repeat(8); $('tnEmpty').hidden = true; $('tnMore').hidden = true; }
        let d;
        try { d = await api(params()); } catch (e) { if (seq === st.seq) grid.innerHTML = '<p class="tn-empty">No pudimos cargar los productos. Revisa tu conexión e intenta de nuevo.</p>'; return; }
        if (seq !== st.seq) return;
        if (reset) { grid.innerHTML = ''; paintCats(d); }
        const base = grid.children.length;
        grid.insertAdjacentHTML('beforeend', d.items.map((it, i) => card(it, base + i)).join(''));
        grid.querySelectorAll('.tn-card:not([data-ok])').forEach((c) => { c.dataset.ok = '1'; paintAdd(c, c.dataset.sku); });
        st.pages = d.pages;
        $('tnMore').hidden = st.page >= st.pages;
        $('tnEmpty').hidden = grid.children.length > 0;
        $('tnCount').textContent = d.total ? d.total.toLocaleString('es-CO') + (d.total === 1 ? ' producto' : ' productos') : '';
        if (d.facets && d.facets.brands) fillBrands(d.facets.brands);
        paintCart();
    }
    const tile = (f) => '<button type="button" class="tn-cat" data-f="' + esc(f.id) + '"><span class="tn-cat-ph">' + (f.cover ? '<img src="' + esc(f.cover) + '" alt="" loading="lazy" decoding="async" data-fb="' + esc(f.name.charAt(0)) + '">' : esc(f.name.charAt(0))) + '</span><span class="tn-cat-nm">' + esc(f.name) + '</span></button>';
    function paintCats(d) {
        const path = d.path || [];
        const home = isHome();
        $('tnMain').classList.toggle('tn-searching', !home);
        $('tnCrumbs').innerHTML = home ? '' : '<button type="button" data-home>Inicio</button>' + path.map((p, i) => ' › <button type="button" data-f="' + esc(p.id) + '"' + (i === path.length - 1 ? ' aria-current="page"' : '') + '>' + esc(p.name) + '</button>').join('');
        $('tnTitle').textContent = st.q ? 'Resultados para «' + st.q + '»' : (path.length ? path[path.length - 1].name : (st.marca ? st.marca : (home ? 'Todos los productos' : 'Resultados')));
        const folders = (d.folders || []);
        const cats = $('tnCats'), subs = $('tnSubs');
        cats.innerHTML = home ? folders.map(tile).join('') : '';
        cats.closest('.tn-sec').hidden = !(home && folders.length);
        subs.innerHTML = (!home && st.folder) ? folders.map(tile).join('') : '';
        subs.hidden = !subs.innerHTML;
    }
    function fillBrands(b) {
        const names = b.map((x) => typeof x === 'string' ? x : (x.name || x.brand || '')).filter(Boolean);
        const s = $('tnMarca'); if (s.options.length <= 1) names.forEach((n) => s.add(new Option(n, n)));
        const l = $('tnBrandList'); if (!l.childElementCount) l.innerHTML = names.map((n) => '<button type="button" class="tn-chip" data-marca="' + esc(n) + '">' + esc(n) + '</button>').join('');
    }
    function isHome() { return !(st.q || st.folder || filtersCount()); }
    function resetAll() {
        st.folder = st.q = st.marca = st.genero = st.precio = st.orden = ''; st.stock = false;
        $('tnQ').value = ''; $('tnMarca').value = $('tnPrecio').value = $('tnOrden').value = '';
        reload(); window.scrollTo({ top: 0 });
    }
    function pickBrand(name) {
        const s = $('tnMarca'); if (![...s.options].some((o) => o.value === name)) s.add(new Option(name, name));
        st.marca = name; s.value = name; st.folder = ''; st.q = ''; $('tnQ').value = '';
        reload(); window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function filtersCount() { return [st.marca, st.genero, st.stock, st.precio, st.orden].filter(Boolean).length; }
    function syncFilterUI() {
        const n = filtersCount(); $('tnFilterN').hidden = !n; $('tnFilterN').textContent = n; $('tnClear').hidden = !n;
        $('tnGenero').querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.g === st.genero)));
        $('tnStock').setAttribute('aria-pressed', String(st.stock));
    }
    const reload = () => { syncFilterUI(); load(true); };

    // ── Eventos del catálogo ──
    let tm = 0;
    $('tnQ').addEventListener('input', (e) => { clearTimeout(tm); tm = setTimeout(() => { st.q = e.target.value.trim(); reload(); }, 280); });
    $('tnFilterBtn').addEventListener('click', () => { const f = $('tnFilters'); f.hidden = !f.hidden; $('tnFilterBtn').setAttribute('aria-expanded', String(!f.hidden)); });
    $('tnMarca').addEventListener('change', (e) => { st.marca = e.target.value; reload(); });
    $('tnPrecio').addEventListener('change', (e) => { st.precio = e.target.value; reload(); });
    $('tnOrden').addEventListener('change', (e) => { st.orden = e.target.value; reload(); });
    $('tnStock').addEventListener('click', () => { st.stock = !st.stock; reload(); });
    $('tnGenero').addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; st.genero = st.genero === b.dataset.g ? '' : b.dataset.g; reload(); });
    $('tnClear').addEventListener('click', () => { st.marca = st.genero = st.precio = st.orden = ''; st.stock = false; $('tnMarca').value = $('tnPrecio').value = $('tnOrden').value = ''; reload(); });
    $('tnMore').addEventListener('click', () => { st.page++; load(false); });
    const goFolder = (e) => { const b = e.target.closest('[data-f]'); if (!b) return; st.folder = b.dataset.f; st.q = ''; $('tnQ').value = ''; reload(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    $('tnCats').addEventListener('click', goFolder);
    $('tnSubs').addEventListener('click', goFolder);
    $('tnCrumbs').addEventListener('click', (e) => { if (e.target.closest('[data-home]')) return resetAll(); goFolder(e); });
    document.addEventListener('click', (e) => { const b = e.target.closest('[data-marca]'); if (b) pickBrand(b.dataset.marca); });
    $('tnAllBrands').addEventListener('click', () => { const l = $('tnBrandList'); l.hidden = !l.hidden; $('tnAllBrands').setAttribute('aria-expanded', String(!l.hidden)); $('tnAllBrands').textContent = l.hidden ? 'Ver todas las marcas' : 'Ocultar marcas'; });
    document.addEventListener('error', (e) => { const t = e.target; if (t && t.tagName === 'IMG' && t.dataset.fb) { const sp = document.createElement('span'); sp.textContent = t.dataset.fb; t.replaceWith(sp); } }, true);
    $('tnGrid').addEventListener('click', (e) => {
        const c = e.target.closest('.tn-card'); if (!c) return;
        const d = e.target.closest('[data-d]');
        if (d) { setQty(c.dataset.sku, qtyOf(c.dataset.sku) + Number(d.dataset.d)); if (Number(d.dataset.d) > 0) bump(); return; }
        if (e.target.closest('[data-quick]')) quick(c.dataset.sku);
    });
    function bump() { const b = $('tnCartBtn'); b.classList.add('bump'); setTimeout(() => b.classList.remove('bump'), 180); }

    // ── Vista rápida ──
    function quick(sku) {
        const m = meta[sku]; if (!m) return; const q = $('tnQuick'); const out = isOut(m);
        q.innerHTML = '<div class="tn-q" style="position:relative"><button class="tn-qclose" type="button" data-x aria-label="Cerrar"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>'
            + '<img src="' + esc(big(m.cover)) + '" alt="' + esc(nameOf(m)) + '"><div class="tn-qbody"><span class="tn-code">' + esc(sku) + '</span><h3>' + esc(nameOf(m)) + '</h3>'
            + '<div class="tn-qprice">' + (m.price ? money(m.price) + ' <small>COP</small>' : 'Consultar precio') + '</div>' + (out ? '<small class="tn-outtag">Agotado</small>' : '')
            + '<div style="margin-top:auto"></div><button type="button" class="tn-btn tn-btn-primary" data-add' + (out ? ' disabled' : '') + '>' + PLUS + ' Agregar al pedido</button></div></div>';
        q.showModal();
    }
    $('tnQuick').addEventListener('click', (e) => {
        const q = $('tnQuick'); if (e.target === q || e.target.closest('[data-x]')) return q.close();
        if (e.target.closest('[data-add]')) { const sku = q.querySelector('.tn-code').textContent; setQty(sku, qtyOf(sku) + 1); bump(); q.close(); }
    });

    // ── Cajón del pedido ──
    const drawer = $('tnDrawer');
    let savedY = 0;
    const isOpen = () => drawer.classList.contains('open');
    function openDrawer() {
        if (isOpen()) return; savedY = window.scrollY;
        drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); $('tnScrim').hidden = false; document.body.classList.add('tn-lock'); $('tnBar').hidden = true;
        try { history.pushState({ tnDrawer: 1 }, ''); } catch (e) {}
        step(1); ensureMeta().then(paintLines); drawer.focus();
    }
    function hideDrawer() {
        if (!isOpen()) return;
        drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); $('tnScrim').hidden = true; document.body.classList.remove('tn-lock'); paintCart();
        window.scrollTo({ top: savedY }); $('tnCartBtn').focus({ preventScroll: true });
    }
    // Cerrar con ✕ o fuera del cajón vuelve atrás en el historial (así el botón «atrás» del celular también cierra el pedido y no sale de la tienda)
    function closeDrawer() { if (isOpen() && history.state && history.state.tnDrawer) history.back(); else hideDrawer(); }
    window.addEventListener('popstate', hideDrawer);
    $('tnCartBtn').addEventListener('click', openDrawer); $('tnBar').addEventListener('click', openDrawer);
    $('tnClose').addEventListener('click', closeDrawer); $('tnScrim').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer(); });

    async function ensureMeta() {
        const need = read().map((i) => i.sku).filter((s) => !meta[s]);
        if (!need.length) return;
        try { const d = await api({ skus: need.join(',') }); d.items.forEach((it) => { meta[it.sku] = it; }); } catch (e) {}
        // códigos que ya no están en el catálogo se quitan solos
        const gone = need.filter((s) => !meta[s]);
        if (gone.length) write(read().filter((i) => !gone.includes(i.sku)));
    }
    function paintLines() {
        const items = read().filter((i) => meta[i.sku]); const ul = $('tnLines');
        $('tnCartEmpty').hidden = items.length > 0;
        ul.innerHTML = items.map((i) => { const m = meta[i.sku]; return '<li class="tn-line" data-sku="' + esc(i.sku) + '"><img src="' + esc(m.cover) + '" alt="">'
            + '<div class="nm">' + esc(nameOf(m)) + '<small>' + esc(i.sku) + '</small>' + (isOut(m) ? '<small class="tn-outtag">Agotado: quítalo para continuar</small>' : '') + '<div class="pr">' + (m.price ? money(m.price * i.qty) : 'Consultar') + '</div></div>'
            + '<div class="tn-qty"><button type="button" data-d="-1" aria-label="Quitar uno">' + MINUS + '</button><b>' + i.qty + '</b><button type="button" data-d="1" aria-label="Agregar otro">' + PLUS + '</button></div></li>'; }).join('');
        const t = totals(); $('tnTotal').textContent = money(t.t); $('tnNext').disabled = !items.length || items.some((i) => isOut(meta[i.sku]));
    }
    $('tnLines').addEventListener('click', (e) => { const d = e.target.closest('[data-d]'); if (!d) return; const sku = d.closest('.tn-line').dataset.sku; setQty(sku, qtyOf(sku) + Number(d.dataset.d)); });

    const TITLES = { 1: 'Mi pedido', 2: 'Datos de entrega', 3: 'Forma de pago' };
    const NEXT = { 1: 'Continuar', 2: 'Continuar al pago', 3: 'Enviar pedido' };
    function step(n) {
        st.step = n;
        [1, 2, 3].forEach((k) => { $('tnS' + k).hidden = k !== n; });
        $('tnDone').hidden = true; $('tnSteps').hidden = false; $('tnFoot').hidden = false; $('tnErr').hidden = true;
        $('tnSteps').querySelectorAll('li').forEach((li, i) => li.classList.toggle('on', i < n));
        $('tnDTitle').textContent = TITLES[n]; $('tnNext').textContent = NEXT[n]; $('tnBack').hidden = n === 1;
        $('tnDBody') ; if (n === 3) paintSummary(); paintLines();
    }
    $('tnBack').addEventListener('click', () => step(st.step - 1));
    document.querySelectorAll('input[name=tnPay]').forEach((r) => r.addEventListener('change', paintPay));
    function pay() { return (document.querySelector('input[name=tnPay]:checked') || {}).value || 'contra_entrega'; }
    function paintPay() { const b = $('tnBank'); if (b) b.hidden = pay() !== 'transferencia'; }
    function paintSummary() {
        const t = totals(); paintPay();
        $('tnSum').innerHTML = '<span><b>' + esc($('tnName').value) + '</b> · ' + esc($('tnPhone').value) + '</span><span>' + esc($('tnAddr').value) + ', ' + esc($('tnCity').value) + '</span><span>' + t.refs + (t.refs === 1 ? ' referencia' : ' referencias') + ' · ' + t.n + ' unidades</span>';
    }
    function fail(msg, el) { const e = $('tnErr'); e.textContent = msg; e.hidden = false; if (el) { el.closest('.tn-f').classList.add('bad'); el.focus(); } }
    drawer.addEventListener('input', (e) => { const f = e.target.closest('.tn-f'); if (f) f.classList.remove('bad'); });
    // recordar datos del cliente en este dispositivo
    const FK = 'kinoTiendaCliente';
    try { const s = JSON.parse(localStorage.getItem(FK) || '{}'); ['Name', 'Phone', 'City', 'Barrio', 'Addr'].forEach((k) => { if (s[k]) $('tn' + k).value = s[k]; }); } catch (e) {}
    const saveClient = () => { try { localStorage.setItem(FK, JSON.stringify(Object.fromEntries(['Name', 'Phone', 'City', 'Barrio', 'Addr'].map((k) => [k, $('tn' + k).value])))); } catch (e) {} };

    $('tnNext').addEventListener('click', async () => {
        if (st.step === 1) return step(2);
        if (st.step === 2) {
            if (!$('tnName').value.trim()) return fail('Escribe tu nombre.', $('tnName'));
            if (!/\d{7,}/.test($('tnPhone').value.replace(/\D+/g, ''))) return fail('Escribe un WhatsApp o celular válido.', $('tnPhone'));
            if (!$('tnCity').value.trim()) return fail('Escribe tu ciudad.', $('tnCity'));
            if (!$('tnAddr').value.trim()) return fail('Escribe la dirección de entrega.', $('tnAddr'));
            saveClient(); return step(3);
        }
        if (st.busy) return; st.busy = true; const btn = $('tnNext'); btn.disabled = true; btn.textContent = 'Enviando…'; $('tnErr').hidden = true;
        const addr = $('tnAddr').value.trim() + ($('tnBarrio').value.trim() ? ' (' + $('tnBarrio').value.trim() + ')' : '');
        try {
            const r = await fetch('/hacer-pedido/enviar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
                csrf: window.TN.csrf, tienda: 1, name: $('tnName').value.trim(), phone: $('tnPhone').value.trim(), city: $('tnCity').value.trim(), address: addr,
                pay: pay(), note: $('tnNote').value.trim(), website: $('tnWeb').value, items: read().map((i) => ({ sku: i.sku, qty: i.qty })) }) });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || !d.ok) { fail(d.error || 'No se pudo enviar el pedido. Intenta de nuevo.'); btn.disabled = false; btn.textContent = NEXT[3]; st.busy = false; return; }
            done(d);
        } catch (e) { fail('Sin conexión. Revisa tu internet e intenta de nuevo.'); btn.disabled = false; btn.textContent = NEXT[3]; }
        st.busy = false;
    });
    function done(d) {
        const t = totals(); const no = d.number || ('#' + d.id); const link = d.token ? location.origin + '/pedido/' + d.token : '';
        const lines = read().filter((i) => meta[i.sku]).map((i) => '• ' + i.qty + ' × ' + i.sku + ' ' + nameOf(meta[i.sku])).join('\n');
        const msg = 'Hola Kino Company, soy ' + $('tnName').value.trim() + '. Hice el pedido ' + no + ' (' + t.n + ' unidades, ' + money(t.t) + ').\nPago: ' + (pay() === 'transferencia' ? 'transferencia' : 'contra entrega') + '\nEntrega: ' + $('tnAddr').value.trim() + ', ' + $('tnCity').value.trim() + '\n\n' + lines + (link ? '\n\nMi pedido con fotos: ' + link : '');
        $('tnDoneNo').textContent = no;
        const wa = $('tnDoneWa'); wa.href = 'https://wa.me/' + window.TN.notify + '?text=' + encodeURIComponent(msg); wa.hidden = false;
        let mine = $('tnPhone').value.replace(/\D+/g, ''); if (mine.length === 10) mine = '57' + mine;
        const own = 'Mi compra en Kino Company, pedido ' + no + ' (' + t.n + ' unidades, ' + money(t.t) + '):\n\n' + lines + '\n\nEntrega: ' + $('tnAddr').value.trim() + ', ' + $('tnCity').value.trim() + (link ? '\n\nMi pedido con fotos: ' + link : '');
        $('tnDoneMine').href = 'https://wa.me/' + mine + '?text=' + encodeURIComponent(own);
        const v = $('tnDoneView'); v.hidden = !link; if (link) v.href = '/pedido/' + d.token;
        write([]); ['tnS1', 'tnS2', 'tnS3'].forEach((k) => { $(k).hidden = true; });
        $('tnDone').hidden = false; $('tnSteps').hidden = true; $('tnFoot').hidden = true; $('tnBack').hidden = true; $('tnDTitle').textContent = 'Listo';
    }
    $('tnDoneAgain').addEventListener('click', () => { closeDrawer(); step(1); });

    // ── Inicio ──
    api({ facets: '1', todos: '1', todo: '1', p: 1 }).then((d) => { if (d.facets) fillBrands(d.facets.brands || []); }).catch(() => {});
    paintCart(); ensureMeta().then(paintCart);
    load(true);
})();
