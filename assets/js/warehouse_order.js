/**
 * Pedido de bodega (/pedido-bodega): alistar y chequear el pedido que leyó el Extractor (sessionStorage kinoPedido).
 * Por producto: foto, código, nombre, precio, cantidad pedida y final (+/−), cambiar de producto, nota y casilla
 * «Alistado». Total final recalculado contra el original. «Chequeado por» + «Finalizar» arman el PDF «Pedido finalizado»
 * (jsPDF en el dispositivo, nombre editable; pdf_shared.js). El avance se guarda en localStorage (kinoBodega).
 * Con ?id=N (Pedidos bodega, tabla warehouse_orders) el pedido y su avance se leen y guardan en el servidor
 * (GET/POST /pedidos-alistar/api/N); al finalizar queda «listo» (verde). Sin id: pedido de sessionStorage, solo local.
 * Nombre/precio/foto: POST /enviar-catalogo/extraer {codes}.
 */
(async function () {
    const KP = window.KinoPdf;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const msg = (t) => { $('pbMsg').textContent = t || ''; };

    const SID = Number(new URLSearchParams(location.search).get('id')) || 0;
    let data = null, srv = null;
    if (SID) {
        try {
            const r = await fetch('/pedidos-alistar/api/' + SID, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            srv = await r.json();
            if (!r.ok) throw new Error(srv.error || 'error ' + r.status);
            data = srv.order;
        } catch (e) {
            $('pbEmpty').innerHTML = '<p>⚠️ No se pudo abrir el pedido (' + esc(e && e.message ? e.message : e) + ').</p><p><a href="/pedidos-alistar">← Volver a Pedidos bodega</a></p>';
            $('pbEmpty').hidden = false; return;
        }
    } else {
        try { data = JSON.parse(sessionStorage.getItem('kinoPedido') || 'null'); } catch (e) { data = null; }
    }
    if (!data || !Array.isArray(data.items) || !data.items.length) { $('pbEmpty').hidden = false; return; }
    const order = data.order || {};

    // Precio en pesos: «$130,000.00» (recibo) → 130000 · «$12.500» → 12500 · «$12.500,00» → 12500 · «130000» → 130000.
    // El último separador seguido de 1-2 dígitos es decimal; los demás puntos/comas son miles.
    function parsePrice(t) {
        let s = String(t || '').replace(/[^\d.,]/g, '');
        if (!s) return null;
        const m = s.match(/^(.*)[.,](\d{1,2})$/);
        s = m ? m[1].replace(/[.,]/g, '') + '.' + m[2] : s.replace(/[.,]/g, '');
        const n = parseFloat(s);
        return isFinite(n) && n > 0 ? n : null;
    }
    // Lectura antigua (dejaba «$130,000.00» en 13000000): solo para reparar avances guardados con ese error
    function parsePriceOld(t) {
        let s = String(t || '').replace(/[^\d.,]/g, '');
        if (!s) return null;
        if (/,\d{1,2}$/.test(s)) s = s.replace(/\./g, '').replace(',', '.'); else s = s.replace(/[.,]/g, '');
        const n = parseFloat(s);
        return isFinite(n) && n > 0 ? n : null;
    }
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');

    // Líneas del pedido, en el orden del Extractor
    let uid = 0;
    const mk = (o) => Object.assign({ id: ++uid, code: '', name: '', price: null, orig: true, origQty: 1, qty: 1, checked: false, note: '', photo: undefined, base: null }, o);
    // Precio unitario del pedido: si el recibo trae el total de la línea (order.priceIsLineTotal), se divide por la cantidad
    // (pedidos guardados antes de ese dato: se deduce si la suma de precios cuadra con el Total del PDF sin multiplicar).
    const lineTotal = order.priceIsLineTotal != null ? !!order.priceIsLineTotal : (() => {
        const tot = parsePrice(order.totalText);
        if (tot == null) return false;
        let sp = 0, su = 0;
        data.items.forEach((it) => { const p = parsePrice(it.priceText) || 0, q = it.qty == null ? 1 : Number(it.qty); sp += p; su += p * q; });
        return Math.abs(sp - tot) < 1 && Math.abs(su - tot) >= 1;
    })();
    const unitPrice = (t, q, parse) => { const p = parse(t); return p == null ? null : (lineTotal && q > 0 ? Math.round(p / q) : p); };
    let lines = data.items.map((it) => {
        const q = it.qty == null ? 1 : Number(it.qty);
        const l = mk({ code: String(it.code || ''), name: String(it.desc || ''), price: unitPrice(it.priceText, q, parsePrice), origQty: q, qty: q });
        l.base = { code: l.code, name: l.name, price: l.price, photo: null };
        return l;
    });
    let who = '', onlyPending = false;

    // ---- Guardado local ----
    const KEY = (order.number || '') + '|' + (order.client || '') + '|' + data.items.map((i) => i.code + ':' + i.qty).join(',');
    let srvTimer = null, srvBusy = Promise.resolve();
    const cleanLines = () => lines.map((l) => Object.assign({}, l, { photo: l.photo === undefined ? null : l.photo }));
    function saveServer(done) {
        if (!SID) return Promise.resolve();
        clearTimeout(srvTimer); srvTimer = null;
        const body = JSON.stringify({ lines: cleanLines(), who: who.trim(), checked: lines.filter((l) => l.checked).length, total: totals().fin, done: !!done });
        srvBusy = srvBusy.then(() => fetch('/pedidos-alistar/api/' + SID, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CS_CSRF || '', Accept: 'application/json' }, body,
        }).then((r) => r.json().then((j) => { if (!r.ok) throw new Error(j.error || 'error ' + r.status); if (j.status) setStatus(j.status); }))
            .catch((e) => { msg('⚠️ No se guardó en el servidor (' + (e && e.message ? e.message : e) + '). Sigue guardado en este dispositivo.'); $('pbMsg').dataset.keep = '1'; }));
        return srvBusy;
    }
    function save() {
        try { localStorage.setItem('kinoBodega', JSON.stringify({ key: KEY, who, lines })); } catch (e) { /* sin guardado */ }
        if (SID) { clearTimeout(srvTimer); srvTimer = setTimeout(() => saveServer(false), 1200); }
    }
    window.addEventListener('pagehide', () => { if (srvTimer) saveServer(false); });
    let isDone = !!(srv && srv.status === 'listo');
    function setStatus(st) {
        isDone = st === 'listo';
        const b = $('pbState');
        if (!b) return;
        b.hidden = !SID;
        b.className = 'pb-state ' + (isDone ? 'green' : 'red');
        b.innerHTML = isDone
            ? '🟢 <b>Pedido listo</b>' + (srv && srv.who ? ' · chequeó ' + esc(srv.who) : '') + (srv && srv.finished ? ' · ' + esc(new Date(srv.finished * 1000).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })) : '').replace(/\.$/, '') + '. Revisa los cambios y copia el listado abajo.'
            : '🔴 <b>Falta alistar.</b> El avance se guarda solo en el servidor: otro celular puede seguirlo.';
        $('pbDone').textContent = isDone ? '📋 Ver listado para copiar' : '✅ Finalizar pedido';
        drawCopy();
    }
    if (srv && Array.isArray(srv.lines) && srv.lines.length) {
        lines = srv.lines; who = srv.who || ''; uid = lines.reduce((m, l) => Math.max(m, l.id), 0);
        try { if (!who) { const s = JSON.parse(localStorage.getItem('kinoBodega') || 'null'); if (s && s.who) who = s.who; } } catch (e) { /* sin guardado */ }
    } else try {
        const s = JSON.parse(localStorage.getItem('kinoBodega') || 'null');
        if (s && s.key === KEY && Array.isArray(s.lines) && s.lines.length) { lines = s.lines; who = s.who || ''; uid = lines.reduce((m, l) => Math.max(m, l.id), 0); }
        else if (s && s.who) who = s.who; // el nombre de quien chequea se recuerda entre pedidos
    } catch (e) { /* sin guardado */ }

    // Reparar avances guardados con la lectura antigua del precio (p. ej. 13000000 en vez de 130000):
    // solo cambia el precio que coincide exactamente con el valor mal leído del PDF; uno escrito a mano se respeta.
    let fixed = 0;
    lines.forEach((l, i) => {
        const it = data.items[i];
        if (!l.orig || !l.base || !it || String(it.code || '') !== l.base.code) return;
        const bad = parsePriceOld(it.priceText), good = unitPrice(it.priceText, l.origQty, parsePrice);
        if (bad == null || good == null || bad === good) return;
        if (l.base.price === bad) { l.base.price = good; fixed++; }
        if (l.price === bad) l.price = good;
    });
    if (fixed) save();

    // ---- Estado de cada línea ----
    function status(l) {
        if (!l.orig) return { t: 'Agregado', c: 'green' };
        if (l.qty === 0) return { t: 'Quitado', c: 'red' };
        if (l.base && l.code !== l.base.code) return { t: 'Cambiado', c: '' };
        if (l.qty < l.origQty) return { t: 'Menos', c: 'red' };
        if (l.qty > l.origQty) return { t: 'Más', c: 'green' };
        return { t: 'Completo', c: 'green' };
    }
    const sub = (l) => l.price == null ? null : l.price * l.qty;
    function totals() {
        let orig = 0, fin = 0, noPrice = 0;
        lines.forEach((l) => {
            if (l.orig && l.base && l.base.price != null) orig += l.base.price * l.origQty;
            if (l.qty > 0 && l.price == null) noPrice++;
            const s = sub(l);
            if (s != null) fin += s;
        });
        return { orig, fin, noPrice };
    }

    // ---- Pantalla ----
    function drawOrder() {
        const f = [['N.º', order.number], ['Cliente', order.client], ['Fecha', order.date], ['Total de ítems', order.itemsPdf], ['Unidades', order.noQty ? null : order.unitsPdf], ['Valor total (PDF)', order.totalText]]
            .filter((x) => x[1] != null && String(x[1]).trim() !== '');
        $('pbOrder').innerHTML = f.map((x) => '<div><span>' + esc(x[0]) + '</span>' + esc(x[1]) + '</div>').join('') || '<div>Sin datos del pedido</div>';
    }
    function drawProg() {
        const done = lines.filter((l) => l.checked).length;
        $('pbProg').innerHTML = '<b>' + done + ' de ' + lines.length + ' chequeados</b>' +
            '<label><input type="checkbox" id="pbOnly"' + (onlyPending ? ' checked' : '') + '> Ver solo pendientes</label>';
        $('pbOnly').addEventListener('change', (e) => { onlyPending = e.target.checked; drawList(); });
    }
    function itemHtml(l) {
        const st = status(l), s = sub(l);
        const ph = l.photo === undefined ? '<div class="pb-ph none">Buscando…</div>' : l.photo
            ? '<div class="pb-ph"><img src="/enviar-catalogo/foto/' + encodeURIComponent(l.photo.id) + '" alt="" loading="lazy" decoding="async"></div>'
            : '<div class="pb-ph none">Foto no disponible</div>';
        const was = l.base && l.code !== l.base.code ? '<div class="pb-help">Antes: ' + esc(l.base.code) + ' <button type="button" class="btn btn-sm btn-secondary" data-act="undo" data-id="' + l.id + '">Deshacer cambio</button></div>' : '';
        return '<div class="pb-item' + (l.checked ? ' done' : '') + (l.qty === 0 ? ' gone' : '') + '" data-id="' + l.id + '">' + ph +
            '<div><div class="pb-code">' + esc(l.code) + '<span class="pb-tag ' + st.c + '">' + st.t + '</span></div>' +
            '<div class="pb-name">' + esc(l.name || 'Sin nombre') + '</div>' +
            '<div class="pb-help">Pedido: ' + (l.orig ? l.origQty : 0) + (l.orig ? '' : ' (no estaba)') + '</div>' + was + '</div>' +
            '<div class="pb-ctl">' +
            '<label class="pb-chk"><input type="checkbox" data-act="chk" data-id="' + l.id + '"' + (l.checked ? ' checked' : '') + '> Alistado</label>' +
            '<span class="pb-qty"><button type="button" data-act="minus" data-id="' + l.id + '" aria-label="Menos">−</button>' +
            '<input type="number" min="0" inputmode="numeric" value="' + l.qty + '" data-act="qty" data-id="' + l.id + '" aria-label="Cantidad final">' +
            '<button type="button" data-act="plus" data-id="' + l.id + '" aria-label="Más">+</button></span>' +
            '<span class="pb-price" title="Precio unitario (no se puede cambiar)">' + (l.price == null ? 'Sin precio' : money(l.price) + ' c/u') + '</span>' +
            '<span class="pb-sub">' + (s == null ? 'Sin precio' : money(s)) + '</span></div>' +
            '<div class="pb-ctl"><input class="pb-note" maxlength="120" placeholder="Nota (opcional)" value="' + esc(l.note) + '" data-act="note" data-id="' + l.id + '">' +
            '<button type="button" class="btn btn-sm btn-secondary" data-act="swap" data-id="' + l.id + '">🔄 Cambiar producto</button></div>' +
            '<div class="pb-sw" id="sw' + l.id + '" hidden></div></div>';
    }
    function drawList() {
        const shown = lines.filter((l) => !onlyPending || !l.checked);
        $('pbList').innerHTML = shown.map(itemHtml).join('') || '<div class="pb-card">✅ Todo chequeado.</div>';
        $('pbList').querySelectorAll('.pb-sw').forEach((e) => { e.hidden = true; });
        drawTotal(); drawFin();
    }
    function drawTotal() {
        const t = totals(), d = t.fin - t.orig;
        $('pbTotal').innerHTML = '<div><span>Total original (suma de líneas)</span>' + money(t.orig) + '</div>' +
            '<div><span>Total final</span><b class="big">' + money(t.fin) + '</b></div>' +
            '<div><span>Diferencia</span><b class="' + (d > 0 ? 'up' : d < 0 ? 'down' : '') + '">' + (d > 0 ? '+' : d < 0 ? '−' : '') + money(Math.abs(d)) + '</b></div>' +
            (t.noPrice ? '<div><span>Atención</span>' + t.noPrice + ' ítem(s) sin precio no suman</div>' : '');
    }
    function drawFin() {
        const pend = lines.filter((l) => !l.checked).length;
        $('pbDone').disabled = !isDone && (pend > 0 || !who.trim());
        $('pbDone').title = pend ? 'Faltan ' + pend + ' por chequear' : (!who.trim() ? 'Escribe quién chequea' : '');
        if (!$('pbMsg').dataset.keep) msg(isDone ? 'Pedido listo.' : pend ? 'Faltan ' + pend + ' ítem(s) por chequear.' : !who.trim() ? 'Escribe el nombre de quien chequea.' : 'Listo para finalizar.');
    }
    const refresh = () => { save(); drawProg(); drawList(); drawCopy(); };

    // ---- Datos del visor (foto, nombre, precio) ----
    async function lookup(codes) {
        const r = await fetch('/enviar-catalogo/extraer', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CS_CSRF || '', 'Accept': 'application/json' },
            body: JSON.stringify({ codes }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || !j.photos) throw new Error(j.error || 'error ' + r.status);
        return j.photos;
    }
    // ---- Galería: buscar con fotos (misma búsqueda que el visor) y tocar la foto para elegir ----
    const coverSrc = (c) => String(c || '').replace(/^\[VIDEO\]/, '');
    function gallery(inp, grid, onPick) {
        let timer = 0, seq = 0;
        inp.addEventListener('input', () => {
            clearTimeout(timer);
            const q = inp.value.trim();
            if (q.length < 2) { grid.innerHTML = ''; return; }
            timer = setTimeout(async () => {
                const my = ++seq;
                try {
                    const r = await fetch('/api/search?limit=30&q=' + encodeURIComponent(q), { credentials: 'same-origin' });
                    const data = await r.json();
                    if (my !== seq) return;
                    grid.innerHTML = Array.isArray(data) && data.length ? data.map((p) =>
                        '<button type="button" data-sku="' + esc(p.sku) + '" title="' + esc(p.name || '') + '">' +
                        '<img src="' + esc(coverSrc(p.cover_image_url)) + '" alt="" loading="lazy" decoding="async">' +
                        '<b>' + esc(p.sku) + '</b><small>' + esc(p.name || '') + '</small></button>').join('')
                        : '<span class="pb-help">Sin resultados con foto.</span>';
                } catch (e) { if (my === seq) grid.innerHTML = '<span class="pb-help">⚠️ No se pudo buscar.</span>'; }
            }, 250);
        });
        grid.addEventListener('click', (e) => {
            const b = e.target.closest('button[data-sku]'); if (!b) return;
            grid.innerHTML = '';
            onPick(b.dataset.sku);
        });
    }
    const pick = (p, code) => p && Object.prototype.hasOwnProperty.call(p, code) ? p[code] : null;

    // ---- Acciones ----
    const byId = (id) => lines.find((l) => l.id === Number(id));
    $('pbList').addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]'); if (!b) return;
        const l = byId(b.dataset.id); if (!l) return;
        const act = b.dataset.act;
        if (act === 'plus') { l.qty++; refresh(); }
        else if (act === 'minus') { l.qty = Math.max(0, l.qty - 1); refresh(); }
        else if (act === 'undo') { Object.assign(l, { code: l.base.code, name: l.base.name, price: l.base.price, photo: l.base.photo }); refresh(); }
        else if (act === 'swap') {
            const box = $('sw' + l.id); box.hidden = !box.hidden;
            if (!box.hidden) {
                box.innerHTML = '<input class="pb-in" id="swc' + l.id + '" placeholder="Busca por código, nombre o carpeta" autocapitalize="characters" autocomplete="off" aria-label="Buscar el producto nuevo">' +
                    '<button type="button" class="btn btn-sm btn-primary" data-act="swapok" data-id="' + l.id + '">Cambiar</button><span class="pb-help" id="sws' + l.id + '">Escribe y toca la foto del producto nuevo.</span>' +
                    '<div class="pb-pick" id="swp' + l.id + '"></div>';
                const inp = $('swc' + l.id);
                gallery(inp, $('swp' + l.id), (code) => { inp.value = code; swapTo(l); });
                inp.focus();
            }
        } else if (act === 'swapok') swapTo(l);
    });
    $('pbList').addEventListener('change', (e) => {
        const b = e.target.closest('[data-act]'); if (!b) return;
        const l = byId(b.dataset.id); if (!l) return;
        const act = b.dataset.act;
        if (act === 'chk') l.checked = b.checked;
        else if (act === 'qty') l.qty = Math.max(0, Math.floor(Number(b.value) || 0));
        else if (act === 'note') { l.note = b.value.trim(); save(); return; }
        refresh();
    });
    async function swapTo(l) {
        const code = ($('swc' + l.id).value || '').trim(), st = $('sws' + l.id);
        if (!code) return;
        st.textContent = 'Buscando…';
        try {
            const p = pick(await lookup([code]), code);
            if (!p) { st.textContent = '⚠️ No está en el visor (o no tiene foto).'; return; }
            Object.assign(l, { code: p.sku || code, name: p.name || '', price: p.price == null ? null : Number(p.price), photo: { id: String(p.id), sku: String(p.sku || code) }, checked: false });
            refresh();
        } catch (e) { st.textContent = '⚠️ No se pudo buscar (' + (e && e.message ? e.message : e) + ').'; }
    }
    gallery($('pbAddCode'), $('pbAddPick'), (code) => { $('pbAddCode').value = code; $('pbAddBtn').click(); });
    $('pbAddBtn').addEventListener('click', async () => {
        const code = $('pbAddCode').value.trim(), st = $('pbAddMsg');
        if (!code) return;
        st.textContent = 'Buscando…';
        try {
            const p = pick(await lookup([code]), code);
            if (!p) { st.textContent = '⚠️ No está en el visor (o no tiene foto).'; return; }
            lines.push(mk({ code: p.sku || code, name: p.name || '', price: p.price == null ? null : Number(p.price), orig: false, origQty: 0, qty: 1,
                photo: { id: String(p.id), sku: String(p.sku || code) } }));
            $('pbAddCode').value = ''; st.textContent = '✅ Agregado al final de la lista.';
            refresh();
        } catch (e) { st.textContent = '⚠️ No se pudo buscar (' + (e && e.message ? e.message : e) + ').'; }
    });
    $('pbWho').value = who;
    $('pbWho').addEventListener('input', () => { who = $('pbWho').value; delete $('pbMsg').dataset.keep; save(); drawFin(); });

    // ---- Resumen de cambios vs. el pedido original (pedido listo) ----
    function changes() {
        const g = { rm: [], sw: [], less: [], more: [], add: [], note: [] };
        lines.forEach((l) => {
            if (!l.orig) { if (l.qty > 0) g.add.push(l); }
            else if (l.qty === 0) g.rm.push(l);
            else if (l.base && l.code !== l.base.code) g.sw.push(l);
            else if (l.qty < l.origQty) g.less.push(l);
            else if (l.qty > l.origQty) g.more.push(l);
            else if (l.note) g.note.push(l);
        });
        return g;
    }
    const nm = (l) => l.name ? ' ' + l.name : '';
    const qTxt = (l, k) => k === 'sw' ? (l.qty === l.origQty ? 'cant. ' + l.qty : 'cant. ' + l.origQty + ' → ' + l.qty)
        : k === 'add' ? '0 → ' + l.qty : k === 'note' ? 'cant. ' + l.qty : 'pedía ' + l.origQty + ' → ' + l.qty;
    const GROUPS = [['rm', '❌ Quitados'], ['sw', '🔄 Cambiados por otro'], ['less', '➖ Menos cantidad'], ['more', '➕ Más cantidad'], ['add', '🆕 Agregados'], ['note', '📝 Solo con nota']];
    function drawChanges() {
        const box = $('pbChgBox');
        if (!box) return;
        box.hidden = !isDone;
        if (!isDone) return;
        const g = changes(), n = GROUPS.reduce((m, [k]) => m + (k === 'note' ? 0 : g[k].length), 0);
        const uo = lines.reduce((m, l) => m + (l.orig ? l.origQty : 0), 0), uf = lines.reduce((m, l) => m + l.qty, 0);
        const t = totals(), d = t.fin - t.orig;
        $('pbChgInfo').textContent = n ? n + ' cambio(s) de ' + lines.filter((l) => l.orig).length + ' productos del pedido original.' : '✅ Se alistó igual al pedido.';
        $('pbChg').innerHTML = GROUPS.filter(([k]) => g[k].length).map(([k, t]) => '<h3>' + t + ' (' + g[k].length + ')</h3><ul>' + g[k].map((l) =>
            '<li' + (k === 'rm' ? ' class="rm"' : '') + '>' + (k === 'sw' ? '<b>' + esc(l.base.code) + '</b> <span class="arrow">→</span> ' : '') +
            '<b>' + esc(l.code) + '</b>' + esc(nm(l)) + '<span class="q">' + qTxt(l, k) + '</span>' + (l.note ? '<span class="n">📝 ' + esc(l.note) + '</span>' : '') + '</li>').join('') + '</ul>').join('') +
            '<div class="sum"><div><span>Unidades</span> ' + uo + ' → ' + uf + '</div><div><span>Total original</span> ' + money(t.orig) + '</div>' +
            '<div><span>Total final</span> <b>' + money(t.fin) + '</b></div><div><span>Diferencia</span> <b class="' + (d > 0 ? 'up' : d < 0 ? 'down' : '') + '">' +
            (d > 0 ? '+' : d < 0 ? '−' : '') + money(Math.abs(d)) + '</b></div></div>';
    }
    function changesText() {
        const g = changes(), t = totals(), d = t.fin - t.orig;
        const head = 'Pedido' + (order.number ? ' N.º ' + order.number : '') + (order.client ? ' · ' + order.client : '') + ' — cambios en el alistado' + (who.trim() ? ' (chequeó ' + who.trim() + ')' : '');
        const body = GROUPS.filter(([k]) => g[k].length).map(([k, tt]) => tt + ':\n' + g[k].map((l) => '• ' + (k === 'sw' ? l.base.code + ' → ' : '') + l.code + nm(l) + ' (' + qTxt(l, k) + ')' + (l.note ? ' — ' + l.note : '')).join('\n')).join('\n\n');
        return head + '\n\n' + (body || '✅ Se alistó igual al pedido.') + '\n\nTotal original ' + money(t.orig) + ' · Total final ' + money(t.fin) + ' · Diferencia ' + (d > 0 ? '+' : d < 0 ? '−' : '') + money(Math.abs(d));
    }
    $('pbChgCopy').addEventListener('click', async () => {
        let ok = false;
        try { await navigator.clipboard.writeText(changesText()); ok = true; } catch (e) { ok = false; }
        $('pbChgMsg').textContent = ok ? '✅ ¡Copiado! Pégalo en WhatsApp.' : '⚠️ No se pudo copiar.';
        setTimeout(() => { $('pbChgMsg').textContent = ''; }, 3500);
    });
    $('pbChgBack').addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });

    // ---- Listado para copiar (pedido listo): código ‖ cantidad final, como el Extractor pero a la inversa ----
    const copyText = () => lines.filter((l) => l.qty > 0 && l.code).map((l) => [l.code, '', l.qty, 'UNIDAD', ''].join('\t')).join('\n');
    function drawCopy() {
        drawChanges();
        const box = $('pbCopyBox');
        if (!box) return;
        box.hidden = !isDone;
        if (!isDone) return;
        const txt = copyText();
        $('pbCopyText').value = txt;
        $('pbCopyInfo').textContent = txt ? txt.split('\n').length + ' códigos · ' + lines.reduce((n, l) => n + (l.qty > 0 ? l.qty : 0), 0) + ' unidades' : 'Sin códigos con cantidad.';
    }
    $('pbCopyBtn').addEventListener('click', async () => {
        const text = copyText();
        let ok = false;
        try { await navigator.clipboard.writeText(text); ok = true; } catch (e) {
            const ta = $('pbCopyText'); ta.select();
            try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        }
        $('pbCopyMsg').textContent = ok ? '✅ ¡Copiado! Pégalo en Excel o Google Sheets.' : '⚠️ No se pudo copiar; selecciona el texto y cópialo a mano.';
        setTimeout(() => { $('pbCopyMsg').textContent = ''; }, 3500);
    });

    // ---- PDF «Pedido finalizado» (opcional) ----
    const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    const nowText = () => new Date().toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
    const defName = () => 'Pedido_finalizado_' + ((KP.clean(order.number || order.client).replace(/[^\wÁÉÍÓÚÜÑáéíóúüñ -]+/g, '').trim().replace(/\s+/g, '_')) || today()) + '_' + today();
    let pdfFile = null, busy = false;

    async function build() {
        if (!window.jspdf || !window.jspdf.jsPDF) throw new Error('El generador de PDF no cargó; recarga la página');
        const imgs = new Map();
        const todo = lines.filter((l) => l.photo);
        for (let i = 0; i < todo.length; i += 6) {
            await Promise.all(todo.slice(i, i + 6).map((l) => KP.prepare(l.photo.id, 300).then((x) => imgs.set(l.id, x)).catch(() => {})));
            msg('Preparando fotos… ' + Math.min(i + 6, todo.length) + ' de ' + todo.length);
        }
        const doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' });
        const W = 210, M = 10, T = KP.clean;
        const cols = [['N.º', 7], ['Foto', 16], ['Código', 28], ['Producto / nota', 50], ['Precio', 23], ['Ped.', 10], ['Final', 11], ['Subtotal', 25], ['Estado', 20]];
        const t = totals(), d = t.fin - t.orig;
        let y = 0;
        const band = () => {
            doc.setFillColor(28, 28, 30); doc.rect(0, 0, W, 22, 'F');
            doc.setTextColor(201, 168, 76); doc.setFont('helvetica', 'bold'); doc.setFontSize(18); doc.text('Pedido finalizado', M, 14);
            doc.setTextColor(30, 30, 30);
        };
        const head = () => {
            doc.setFillColor(201, 168, 76); doc.rect(M, y, W - 2 * M, 7, 'F');
            doc.setFont('helvetica', 'bold'); doc.setFontSize(8); doc.setTextColor(28, 28, 30);
            let x = M; cols.forEach((c) => { doc.text(c[0], x + 1, y + 4.8); x += c[1]; });
            doc.setTextColor(30, 30, 30); y += 7;
        };
        band(); y = 30;
        doc.setFontSize(10);
        const info = [['Pedido N.º', order.number], ['Cliente', order.client], ['Fecha del pedido', order.date], ['Finalizado', nowText()], ['Chequeado por', who.trim()]].filter((x) => x[1]);
        info.forEach((x) => { doc.setFont('helvetica', 'bold'); doc.text(T(x[0]) + ':', M, y); doc.setFont('helvetica', 'normal'); doc.text(T(x[1]), M + 38, y); y += 5.5; });
        y += 2;
        const cnt = (f) => lines.filter(f).length;
        const sum = [['Total original', money(t.orig)], ['Total final', money(t.fin)], ['Diferencia', (d > 0 ? '+' : d < 0 ? '-' : '') + money(Math.abs(d))]];
        doc.setFillColor(245, 240, 225); doc.rect(M, y, W - 2 * M, 16, 'F');
        sum.forEach((x, i) => { const sx = M + 4 + i * 62; doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.text(x[0], sx, y + 5); doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.text(x[1], sx, y + 12); });
        y += 20; doc.setFontSize(8.5); doc.setFont('helvetica', 'normal');
        doc.text(T(cnt((l) => status(l).t === 'Completo') + ' completos · ' + cnt((l) => status(l).t === 'Menos') + ' con menos · ' + cnt((l) => status(l).t === 'Más') + ' con más · ' +
            cnt((l) => status(l).t === 'Cambiado') + ' cambiados · ' + cnt((l) => status(l).t === 'Agregado') + ' agregados · ' + cnt((l) => status(l).t === 'Quitado') + ' quitados' +
            (t.noPrice ? ' · ' + t.noPrice + ' sin precio' : '')), M, y);
        y += 6; head();
        const RH = 16;
        lines.forEach((l, i) => {
            if (y + RH > 284) { doc.addPage(); y = 12; head(); }
            const st = status(l), s = sub(l);
            if (st.t === 'Menos' || st.t === 'Quitado') { doc.setFillColor(253, 232, 232); doc.rect(M, y, W - 2 * M, RH, 'F'); }
            doc.setDrawColor(210, 210, 210); doc.line(M, y + RH, W - M, y + RH);
            const im = imgs.get(l.id);
            if (im) { try { const k = Math.min(14 / im.w, 14 / im.h); doc.addImage(im.data, 'JPEG', M + 7 + 1 + (14 - im.w * k) / 2, y + 1 + (14 - im.h * k) / 2, im.w * k, im.h * k); } catch (e) { /* sin foto */ } }
            if (l.photo) doc.link(M + 7, y, 16, RH, { url: 'https://drive.google.com/file/d/' + encodeURIComponent(l.photo.id) + '/view' });
            doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(30, 30, 30);
            let x = M; const cell = (txt, w, o) => { doc.text(T(txt), x + 1, y + (o || 6), { maxWidth: w - 2 }); x += w; };
            cell(String(i + 1), 7); x += 16;
            doc.setFont('helvetica', 'bold'); cell(l.code, 28); doc.setFont('helvetica', 'normal');
            const nm = doc.splitTextToSize(T(l.name || 'Sin nombre'), 48).slice(0, 2);
            doc.text(nm, x + 1, y + 5);
            if (l.note) { doc.setFontSize(7); doc.setTextColor(110, 110, 110); doc.text(doc.splitTextToSize(T('Nota: ' + l.note), 48).slice(0, 2), x + 1, y + 5 + nm.length * 3.4 + 0.5); doc.setFontSize(8); doc.setTextColor(30, 30, 30); }
            x += 50;
            cell(l.price == null ? 'Sin precio' : money(l.price), 23); cell(String(l.orig ? l.origQty : 0), 10); cell(String(l.qty), 11);
            doc.setFont('helvetica', 'bold'); cell(s == null ? '-' : money(s), 25); doc.setFont('helvetica', 'normal');
            doc.setTextColor(...(st.c === 'red' ? [190, 40, 50] : st.c === 'green' ? [30, 130, 60] : [160, 110, 20]));
            const stx = st.t + (st.t === 'Cambiado' && l.base ? ' (de ' + l.base.code + ')' : '');
            doc.setFontSize(7); doc.text(doc.splitTextToSize(T(stx), 18), x + 1, y + 6); doc.setTextColor(30, 30, 30);
            y += RH;
        });
        y += 4; if (y > 280) { doc.addPage(); y = 14; }
        doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.text('Total final: ' + money(t.fin) + '   (original ' + money(t.orig) + ')', W - M, y, { align: 'right' });
        const n = doc.getNumberOfPages();
        for (let p = 1; p <= n; p++) { doc.setPage(p); doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); doc.setTextColor(120, 120, 120); doc.text('Pág. ' + p + ' de ' + n, W - M, 292, { align: 'right' }); }
        return new File([doc.output('blob')], defName() + '.pdf', { type: 'application/pdf' });
    }

    let pdfjs = null, pvToken = 0;
    async function showPreview() {
        const pages = $('pbPages');
        pages.innerHTML = '<p>Cargando el previo…</p>';
        $('pbShare').hidden = !KP.canShareFiles;
        $('pbPv').hidden = false; document.body.style.overflow = 'hidden';
        const my = ++pvToken;
        try {
            if (!pdfjs) {
                pdfjs = await import('/assets/vendor/pdfjs/pdf.min.js');
                pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';
            }
            const pdf = await pdfjs.getDocument({ data: new Uint8Array(await pdfFile.arrayBuffer()) }).promise;
            if (my !== pvToken) return;
            pages.innerHTML = '';
            const width = Math.min(820, pages.clientWidth - 32) * (window.devicePixelRatio || 1);
            for (let n = 1; n <= pdf.numPages; n++) {
                const page = await pdf.getPage(n);
                if (my !== pvToken) return;
                const v = page.getViewport({ scale: width / page.getViewport({ scale: 1 }).width });
                const cv = document.createElement('canvas'); cv.width = v.width; cv.height = v.height;
                pages.appendChild(cv);
                await page.render({ canvasContext: cv.getContext('2d'), viewport: v }).promise;
            }
        } catch (e) { pages.innerHTML = '<p>No se pudo mostrar el previo (' + esc(e && e.message ? e.message : e) + '). Puedes descargarlo igual.</p>'; }
    }
    const closePv = () => { pvToken++; $('pbPv').hidden = true; $('pbPages').innerHTML = ''; document.body.style.overflow = ''; };
    $('pbClose').addEventListener('click', closePv);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('pbPv').hidden) closePv(); });
    const outFile = () => KP.named(pdfFile, $('pbName'));
    $('pbDown').addEventListener('click', () => KP.download(outFile(), msg));
    $('pbShare').addEventListener('click', () => KP.share(outFile(), 'Pedido finalizado', msg));

    $('pbDone').addEventListener('click', async () => {
        if (busy) return;
        if (!isDone) {
            $('pbDone').disabled = true; $('pbMsg').dataset.keep = '1';
            if (SID) { if (srv) { srv.who = who.trim(); srv.finished = Math.floor(Date.now() / 1000); } await saveServer(true); }
            else setStatus('listo');
            msg('✅ Pedido finalizado' + (SID ? ' y marcado en verde en Pedidos bodega' : '') + '. Revisa los cambios y copia el listado.');
            delete $('pbMsg').dataset.keep; drawFin();
        }
        drawCopy(); $('pbChgBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // ---- Arranque ----
    $('pbBody').hidden = false; $('pbFin').hidden = false;
    setStatus(isDone ? 'listo' : 'pendiente');
    drawOrder(); drawProg(); drawList();
    (async () => {
        try {
            const need = lines.filter((l) => l.photo === undefined || (l.orig && !l.name) || l.price == null);
            const codes = [...new Set(need.map((l) => l.code).filter(Boolean))];
            if (!codes.length) { lines.forEach((l) => { if (l.photo === undefined) l.photo = null; }); return; }
            const p = await lookup(codes);
            lines.forEach((l) => {
                const x = pick(p, l.code);
                if (l.photo === undefined) l.photo = x && x.id ? { id: String(x.id), sku: String(x.sku || l.code) } : null;
                if (x) {
                    if (!l.name) l.name = x.name || '';
                    if (l.price == null && x.price != null) l.price = Number(x.price); // precio del pedido primero; el del visor solo si falta
                    if (l.base && l.base.code === l.code) { l.base.name = l.name; l.base.price = l.price; l.base.photo = l.photo; }
                }
            });
            save(); drawList();
        } catch (e) { lines.forEach((l) => { if (l.photo === undefined) l.photo = null; }); drawList(); msg('⚠️ No se pudieron buscar las fotos (' + (e && e.message ? e.message : e) + ').'); $('pbMsg').dataset.keep = '1'; }
    })();
})();
