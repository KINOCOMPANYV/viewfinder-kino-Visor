/* Tienda web (/tienda): explorar, buscar, filtrar, carrito y pedido. Lee /api/pedido/explorar y envía a /hacer-pedido/enviar (tienda=1). */
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const big = (u) => String(u || '').replace(/=s\d+/, '=s900');
    const KEY = 'kinoTienda';
    const meta = {};
    const st = { folder: '', q: '', page: 1, pages: 1, marca: '', cat: '', genero: '', stock: false, precio: '', orden: '', seq: 0, step: 1, busy: false };
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
        $('tnCartT').textContent = t.t ? money(t.t) : ''; $('tnCartBtn').classList.toggle('has', t.n > 0);
        const bar = $('tnBar'); bar.hidden = t.n < 1 || $('tnDrawer').classList.contains('open');
        $('tnBarN').textContent = t.n + (t.n === 1 ? ' unidad' : ' unidades'); $('tnBarT').textContent = t.t ? money(t.t) : '';
        // tarjetas visibles
        document.querySelectorAll('.tn-card').forEach((c) => paintAdd(c, c.dataset.sku));
        if ($('tnDrawer').classList.contains('open')) paintLines();
    }
    const clampQ = (v) => Math.max(1, Math.min(99, parseInt(v, 10) || 1));
    function paintAdd(card, sku) {
        const q = qtyOf(sku); const box = card.querySelector('.tn-act'); if (!box) return;
        const mode = isOut(meta[sku]) && q === 0 ? 'out' : (q > 0 ? 'in' : 'add');
        if (box.dataset.mode === mode) { const i = box.querySelector('.tn-qi'); if (mode === 'in' && i && document.activeElement !== i) i.value = q; return; }
        const had = box.contains(document.activeElement);
        box.dataset.mode = mode;
        const inp = (v) => '<input class="tn-qi" inputmode="numeric" pattern="[0-9]*" maxlength="2" value="' + v + '" aria-label="Cantidad">';
        if (mode === 'out') box.innerHTML = '<button type="button" class="tn-btn tn-btn-primary" disabled>Agotado</button>';
        else if (mode === 'in') box.innerHTML = '<div class="tn-step"><button type="button" data-d="-1" aria-label="Quitar uno">' + MINUS + '</button>' + inp(q) + '<button type="button" data-d="1" aria-label="Agregar otro">' + PLUS + '</button></div><small class="tn-inl">en tu pedido</small>';
        else box.innerHTML = '<div class="tn-pick"><div class="tn-step tn-step-s"><button type="button" data-p="-1" aria-label="Menos">' + MINUS + '</button>' + inp(1) + '<button type="button" data-p="1" aria-label="Más">' + PLUS + '</button></div><button type="button" class="tn-btn tn-btn-primary" data-add>Agregar</button></div>';
        if (had) { const b = box.querySelector('button'); if (b) b.focus({ preventScroll: true }); }
    }

    // ── API ──
    async function api(p) {
        const r = await fetch('/api/pedido/explorar?' + new URLSearchParams(p).toString(), { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
    }
    function params() {
        const p = { tienda: '1', todos: '1', todo: '1', p: st.page };
        if (st.cat) p['cat[]'] = st.cat;
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
        if (reset) { grid.innerHTML = ''; paintCats(); }
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
    let CATS = [];
    const tile = (c) => '<button type="button" class="tn-cat" data-cat="' + esc(c.name) + '"><span class="tn-cat-ph">' + (c.cover ? '<img src="' + esc(c.cover) + '" alt="" loading="lazy" decoding="async" data-fb="' + esc(c.name.charAt(0)) + '">' : esc(c.name.charAt(0))) + (c.promo ? '<i class="tn-promo">Promo</i>' : '') + '</span><span class="tn-cat-nm">' + esc(c.name) + '</span></button>';
    function paintCats() {
        const home = isHome();
        $('tnMain').classList.toggle('tn-searching', !home);
        $('tnCrumbs').innerHTML = home ? '' : '<button type="button" data-home>Inicio</button>' + (st.cat ? ' › <button type="button" aria-current="page">' + esc(st.cat) + '</button>' : '');
        $('tnTitle').textContent = st.q ? 'Resultados para «' + st.q + '»' : (st.cat ? st.cat : (st.marca ? st.marca : (home ? 'Todos los productos' : 'Resultados')));
        const cats = $('tnCats');
        cats.innerHTML = home ? CATS.map(tile).join('') : '';
        cats.closest('.tn-sec').hidden = !(home && CATS.length);
    }
    function fillCats(list) {
        CATS = list || [];
        const s = $('tnCat'); CATS.forEach((c) => addOpt(s, c.name)); s.value = st.cat;
        paintCats();
    }
    function fillBrands(b) {
        const names = b.map((x) => typeof x === 'string' ? x : (x.name || x.brand || '')).filter(Boolean);
        const s = $('tnMarca'); names.forEach((n) => addOpt(s, n)); s.value = st.marca;
        const l = $('tnBrandList'); if (!l.childElementCount) l.innerHTML = names.map((n) => '<button type="button" class="tn-chip" data-marca="' + esc(n) + '">' + esc(n) + '</button>').join('');
    }
    function isHome() { return !(st.q || st.cat || filtersCount()); }
    function resetAll() {
        st.cat = st.q = st.marca = st.genero = st.precio = st.orden = ''; st.stock = false;
        $('tnQ').value = ''; $('tnCat').value = $('tnMarca').value = $('tnPrecio').value = $('tnOrden').value = '';
        reload(); window.scrollTo({ top: 0 });
    }
    function pickBrand(name) {
        const s = $('tnMarca'); addOpt(s, name);
        st.marca = name; s.value = name; st.cat = ''; $('tnCat').value = ''; st.q = ''; $('tnQ').value = '';
        reload(); window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function filtersCount() { return [st.cat, st.marca, st.genero, st.stock, st.precio, st.orden].filter(Boolean).length; }
    function syncFilterUI() {
        const n = filtersCount(); $('tnFilterN').hidden = !n; $('tnFilterN').textContent = n; $('tnClear').hidden = !n;
        $('tnGenero').querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.g === st.genero)));
        $('tnStock').setAttribute('aria-pressed', String(st.stock));
    }
    const reload = () => { syncFilterUI(); writeUrl(); load(true); };

    // ── Enlace para compartir: los filtros viven en la URL (?cat=POEDAGAR&para=hombre…) y se restauran al abrirla ──
    const addOpt = (s, v) => { if (v && ![...s.options].some((o) => o.value === v)) s.add(new Option(v, v)); };
    const GEN = ['hombre', 'mujer', 'unisex'];
    function shareUrl() {
        const u = new URLSearchParams();
        if (st.q) u.set('q', st.q); if (st.cat) u.set('cat', st.cat); if (st.marca) u.set('marca', st.marca);
        if (st.genero) u.set('para', st.genero); if (st.precio) u.set('precio', st.precio); if (st.stock) u.set('stock', '1'); if (st.orden) u.set('orden', st.orden);
        const qs = u.toString(); return location.origin + location.pathname + (qs ? '?' + qs : '');
    }
    function writeUrl() { try { history.replaceState(history.state, '', shareUrl().slice(location.origin.length)); } catch (e) {} $('tnShare').hidden = isHome(); }
    function readUrl() {
        const u = new URLSearchParams(location.search); const g = (k) => (u.get(k) || '').trim().slice(0, 80);
        st.q = g('q'); st.cat = g('cat'); st.marca = g('marca');
        st.genero = GEN.includes(g('para')) ? g('para') : '';
        st.precio = [...$('tnPrecio').options].some((o) => o.value === g('precio')) ? g('precio') : '';
        st.orden = [...$('tnOrden').options].some((o) => o.value === g('orden')) ? g('orden') : '';
        st.stock = g('stock') === '1';
        $('tnQ').value = st.q; addOpt($('tnCat'), st.cat); addOpt($('tnMarca'), st.marca);
        $('tnCat').value = st.cat; $('tnMarca').value = st.marca; $('tnPrecio').value = st.precio; $('tnOrden').value = st.orden;
        if (filtersCount()) { $('tnFilters').hidden = false; $('tnFilterBtn').setAttribute('aria-expanded', 'true'); }
        syncFilterUI(); $('tnShare').hidden = isHome();
    }
    function shareText() {
        const bits = [st.cat, st.marca, st.genero && st.genero.charAt(0).toUpperCase() + st.genero.slice(1), st.q && '«' + st.q + '»'].filter(Boolean);
        return 'Mira estos relojes en Kino Company' + (bits.length ? ': ' + bits.join(' · ') : '');
    }
    let shareTm = 0;
    function shareMsg(t) { const m = $('tnShareMsg'); m.textContent = t; m.hidden = false; clearTimeout(shareTm); shareTm = setTimeout(() => { m.hidden = true; }, 2200); }
    $('tnShareBtn').addEventListener('click', async () => {
        const url = shareUrl();
        if (navigator.share && matchMedia('(pointer: coarse)').matches) { try { await navigator.share({ title: 'Kino Company', text: shareText(), url }); } catch (e) {} return; }
        try { await navigator.clipboard.writeText(url); shareMsg('Enlace copiado'); }
        catch (e) { window.prompt('Copia este enlace:', url); }
    });
    $('tnShareWa').addEventListener('click', () => { window.open('https://wa.me/?text=' + encodeURIComponent(shareText() + '\n' + shareUrl()), '_blank', 'noopener'); });

    // ── Eventos del catálogo ──
    let tm = 0;
    $('tnQ').addEventListener('input', (e) => { clearTimeout(tm); tm = setTimeout(() => { st.q = e.target.value.trim(); reload(); }, 280); });
    $('tnFilterBtn').addEventListener('click', () => { const f = $('tnFilters'); f.hidden = !f.hidden; $('tnFilterBtn').setAttribute('aria-expanded', String(!f.hidden)); });
    $('tnCat').addEventListener('change', (e) => { st.cat = e.target.value; st.q = ''; $('tnQ').value = ''; reload(); });
    $('tnMarca').addEventListener('change', (e) => { st.marca = e.target.value; reload(); });
    $('tnPrecio').addEventListener('change', (e) => { st.precio = e.target.value; reload(); });
    $('tnOrden').addEventListener('change', (e) => { st.orden = e.target.value; reload(); });
    $('tnStock').addEventListener('click', () => { st.stock = !st.stock; reload(); });
    $('tnGenero').addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; st.genero = st.genero === b.dataset.g ? '' : b.dataset.g; reload(); });
    $('tnClear').addEventListener('click', () => { st.cat = st.marca = st.genero = st.precio = st.orden = ''; st.stock = false; $('tnCat').value = $('tnMarca').value = $('tnPrecio').value = $('tnOrden').value = ''; reload(); });
    $('tnMore').addEventListener('click', () => { st.page++; load(false); });
    const goCat = (e) => { const b = e.target.closest('[data-cat]'); if (!b) return; st.cat = b.dataset.cat; st.q = ''; $('tnQ').value = ''; $('tnCat').value = st.cat; reload(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    $('tnCats').addEventListener('click', goCat);
    $('tnCrumbs').addEventListener('click', (e) => { if (e.target.closest('[data-home]')) resetAll(); });
    document.addEventListener('click', (e) => { const b = e.target.closest('[data-marca]'); if (b) pickBrand(b.dataset.marca); });
    $('tnAllBrands').addEventListener('click', () => { const l = $('tnBrandList'); l.hidden = !l.hidden; $('tnAllBrands').setAttribute('aria-expanded', String(!l.hidden)); $('tnAllBrands').textContent = l.hidden ? 'Ver todas las marcas' : 'Ocultar marcas'; });
    document.addEventListener('error', (e) => { const t = e.target; if (t && t.tagName === 'IMG' && t.dataset.fb) { const sp = document.createElement('span'); sp.textContent = t.dataset.fb; t.replaceWith(sp); } }, true);
    $('tnGrid').addEventListener('click', (e) => {
        const c = e.target.closest('.tn-card'); if (!c) return;
        const d = e.target.closest('[data-d]'), p = e.target.closest('[data-p]'), ad = e.target.closest('[data-add]');
        if (d) { setQty(c.dataset.sku, qtyOf(c.dataset.sku) + Number(d.dataset.d)); if (Number(d.dataset.d) > 0) bump(); return; }
        if (p) { const i = c.querySelector('.tn-qi'); i.value = clampQ(Number(i.value) + Number(p.dataset.p)); return; }
        if (ad) { const i = c.querySelector('.tn-qi'); setQty(c.dataset.sku, qtyOf(c.dataset.sku) + clampQ(i.value)); bump(); if (docked()) openDrawer(); return; }
        if (e.target.closest('[data-quick]')) quick(c.dataset.sku);
    });
    $('tnGrid').addEventListener('change', (e) => {
        const i = e.target.closest('.tn-qi'); if (!i) return; const c = i.closest('.tn-card');
        if (c.querySelector('.tn-act').dataset.mode === 'in') setQty(c.dataset.sku, Math.max(0, parseInt(i.value, 10) || 0)); else i.value = clampQ(i.value);
    });
    $('tnGrid').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.classList.contains('tn-qi')) { e.preventDefault(); const c = e.target.closest('.tn-card'); const a = c.querySelector('[data-add]'); if (a) a.click(); else e.target.blur(); } });
    function bump() { const b = $('tnCartBtn'); b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); setTimeout(() => b.classList.remove('bump'), 700); }

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
        if (e.target.closest('[data-add]')) { const sku = q.querySelector('.tn-code').textContent; setQty(sku, qtyOf(sku) + 1); bump(); q.close(); if (docked()) openDrawer(); }
    });

    // ── Cajón del pedido ──
    const drawer = $('tnDrawer');
    let savedY = 0;
    const isOpen = () => drawer.classList.contains('open');
    const mq = window.matchMedia('(min-width: 1100px)'); let dockedNow = false; const docked = () => mq.matches;
    function openDrawer() {
        if (isOpen()) return; savedY = window.scrollY; dockedNow = docked();
        drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); $('tnBar').hidden = true;
        if (dockedNow) document.body.classList.add('tn-dock');
        else { $('tnScrim').hidden = false; document.body.classList.add('tn-lock'); try { history.pushState({ tnDrawer: 1 }, ''); } catch (e) {} }
        if (st.step === 1 || !dockedNow) step(1); ensureMeta().then(paintLines); if (!dockedNow) drawer.focus();
    }
    function hideDrawer() {
        if (!isOpen()) return;
        drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); $('tnScrim').hidden = true; document.body.classList.remove('tn-lock', 'tn-dock'); paintCart();
        if (!dockedNow) { window.scrollTo({ top: savedY }); $('tnCartBtn').focus({ preventScroll: true }); }
    }
    // Cerrar con ✕ o fuera del cajón vuelve atrás en el historial (así el botón «atrás» del celular también cierra el pedido y no sale de la tienda)
    function closeDrawer() { if (isOpen() && !dockedNow && history.state && history.state.tnDrawer) history.back(); else hideDrawer(); }
    window.addEventListener('popstate', () => { if (!dockedNow) hideDrawer(); });
    $('tnCartBtn').addEventListener('click', () => { if (isOpen() && dockedNow) closeDrawer(); else openDrawer(); }); $('tnBar').addEventListener('click', openDrawer);
    $('tnClose').addEventListener('click', closeDrawer); $('tnScrim').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer(); });

    async function ensureMeta() {
        const need = read().map((i) => i.sku).filter((s) => !meta[s]);
        if (!need.length) return;
        try { const d = await api({ tienda: '1', skus: need.join(',') }); d.items.forEach((it) => { meta[it.sku] = it; }); } catch (e) {}
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
    api({ tienda: '1', facets: '1', todos: '1', todo: '1', p: 1 }).then((d) => { if (d.facets) { fillBrands(d.facets.brands || []); fillCats(d.facets.cats); } }).catch(() => {});
    readUrl();
    paintCart(); ensureMeta().then(paintCart);
    load(true);
})();

// ── Galería: flechas (solo si hay scroll y hay ratón/PC) ──
(function () {
    const tr = document.getElementById('tnGalTrack'); if (!tr) return;
    const nav = document.querySelector('.tn-gal-nav'); const [prev, next] = nav ? nav.querySelectorAll('.tn-gal-btn') : [];
    const upd = () => {
        const more = tr.scrollWidth > tr.clientWidth + 4; if (nav) nav.hidden = !more;
        if (prev) prev.disabled = tr.scrollLeft < 4; if (next) next.disabled = tr.scrollLeft + tr.clientWidth >= tr.scrollWidth - 4;
    };
    if (nav) nav.addEventListener('click', (e) => {
        const b = e.target.closest('.tn-gal-btn'); if (!b) return;
        tr.scrollBy({ left: Number(b.dataset.dir) * tr.clientWidth * 0.8 });
    });
    tr.addEventListener('scroll', () => requestAnimationFrame(upd), { passive: true });
    tr.addEventListener('keydown', (e) => { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); tr.scrollBy({ left: (e.key === 'ArrowRight' ? 1 : -1) * tr.clientWidth * 0.6 }); } });
    window.addEventListener('resize', upd); upd();
})();
