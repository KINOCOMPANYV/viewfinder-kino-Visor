/* Alistamiento dentro del pedido v2 (/pedidos/{id}). Guarda en /pedidos-alistar/api/{id} (misma tabla warehouse_orders). */
(function () {
    const box = document.getElementById('alistar');
    if (!box) return;
    const d = JSON.parse(box.dataset.wh), csrf = box.dataset.csrf, ro = box.dataset.readonly === '1';
    const lines = d.lines, covers = d.covers || {}, list = document.getElementById('alList'), foot = document.getElementById('alFoot'), msg = document.getElementById('alMsg');
    let who = d.who || '', timer = null;
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const pending = () => lines.filter((l) => !l.checked && l.qty > 0).length;
    function save(done, unlock) {
        clearTimeout(timer);
        return fetch('/pedidos-alistar/api/' + d.id, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ lines, who: who.trim(), checked: lines.filter((l) => l.checked).length, done: !!done, unlock: unlock || undefined }),
        }).then((r) => r.json().then((j) => { if (!r.ok) throw new Error(j.error || 'error ' + r.status); return j; }));
    }
    const later = () => { clearTimeout(timer); timer = setTimeout(() => save(false).then(() => { msg.textContent = 'Guardado.'; }).catch((e) => { msg.textContent = '⚠️ No se guardó: ' + e.message; }), 800); };
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const pct = +d.pct || 0;
    // Total en vivo: lo pedido (precio original × cantidad pedida) vs lo alistado (precio × cantidad actual), con descuento.
    function totals() {
        let orig = 0, now = 0, short = 0, more = 0, swapped = 0, added = 0, removed = 0, noted = 0, units = 0, refs = 0;
        lines.forEach((l) => {
            const bp = l.base && l.base.price != null ? +l.base.price : (l.price != null ? +l.price : 0);
            const q = +l.qty || 0, oq = +l.origQty || 0;
            if (l.orig !== false) { orig += bp * oq; if (q < oq) short += oq - q; else if (q > oq) more += q - oq; if (q === 0) removed++; }
            else if (q > 0) added++;
            if (l.base && l.base.code && l.code !== l.base.code) swapped++;
            if (l.note) noted++;
            now += (l.price != null ? +l.price : 0) * q;
            units += q; if (q > 0) refs++;
        });
        const k = 1 - pct / 100, diff = (orig - now) * k;
        const head = document.getElementById('ordTotal');
        if (head) head.textContent = 'Total ' + (now > 0 ? money(now * k) : '—');
        const chg = [];
        if (short) chg.push(short + ' unidad' + (short === 1 ? '' : 'es') + ' faltante' + (short === 1 ? '' : 's'));
        if (more) chg.push(more + ' de más');
        if (removed) chg.push(removed + ' producto' + (removed === 1 ? '' : 's') + ' sin alistar');
        if (swapped) chg.push(swapped + ' cambiado' + (swapped === 1 ? '' : 's'));
        if (added) chg.push(added + ' agregado' + (added === 1 ? '' : 's'));
        if (noted) chg.push(noted + ' con nota');
        return '<div style="flex-basis:100%;margin-top:.4rem;padding:1rem 1.1rem;border-radius:12px;background:var(--color-bg);border:1px solid var(--color-border-hover)">' +
            '<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:baseline">' +
            '<div><div class="v2-code">Cliente</div><b style="font-size:1.15rem">' + esc(d.client || 'Sin nombre') + '</b>' + (d.number ? ' <span class="v2-code">' + esc(d.number) + '</span>' : '') + '</div>' +
            '<div style="text-align:right"><div class="v2-code">Total alistado' + (pct ? ' (con ' + pct + '% de descuento)' : '') + '</div><b style="font-size:1.9rem;line-height:1.1">' + money(now * k) + '</b></div></div>' +
            '<div style="display:flex;gap:1.2rem;flex-wrap:wrap;margin-top:.7rem;padding-top:.7rem;border-top:1px solid var(--color-border)">' +
            '<span><b>' + refs + '</b> referencia' + (refs === 1 ? '' : 's') + ' · <b>' + units + '</b> unidad' + (units === 1 ? '' : 'es') + '</span>' +
            '<span class="v2-code">Pedido original ' + money(orig * k) + '</span>' +
            (Math.round(diff) !== 0 ? '<span style="color:var(--color-' + (diff > 0 ? 'danger' : 'success') + ')">' + (diff > 0 ? '−' : '+') + money(Math.abs(diff)) + ' vs. pedido</span>' : '') + '</div>' +
            '<div style="margin-top:.5rem">' + (chg.length ? '<b>Cambios:</b> ' + chg.join(' · ') : '<span style="color:var(--color-success)">✅ Sin cambios: igual al pedido</span>') + '</div></div>';
    }
    let swapId = 0; // línea con el buscador de cambio abierto
    const coverSrc = (c) => String(c || '').replace(/^\[VIDEO\]/, '');
    function swapBox(l) {
        return '<div style="flex-basis:100%;margin-top:.4rem"><input id="swQ" placeholder="Busca el producto nuevo: código, nombre o carpeta" autocomplete="off" style="width:100%;padding:.4rem .55rem;border-radius:6px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit">' +
            '<div id="swMsg" class="v2-code" style="margin:.3rem 0">Escribe y toca la foto del producto nuevo.</div>' +
            '<div id="swGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.5rem"></div></div>';
    }
    function bindSwap(l) {
        const inp = document.getElementById('swQ'), grid = document.getElementById('swGrid'), st = document.getElementById('swMsg');
        if (!inp) return;
        inp.focus();
        let t = 0, seq = 0;
        inp.addEventListener('input', () => {
            clearTimeout(t);
            const q = inp.value.trim();
            if (q.length < 2) { grid.innerHTML = ''; return; }
            t = setTimeout(() => {
                const my = ++seq;
                fetch('/api/search?limit=30&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then((r) => r.json()).then((data) => {
                    if (my !== seq) return;
                    grid.innerHTML = Array.isArray(data) && data.length ? data.map((x) =>
                        '<button type="button" class="v2-chip" data-sku="' + esc(x.sku) + '" data-name="' + esc(x.name || '') + '" data-cover="' + esc(coverSrc(x.cover_image_url)) + '" style="display:block;text-align:left;padding:.3rem;height:auto">' +
                        '<img src="' + esc(coverSrc(x.cover_image_url)) + '" alt="" loading="lazy" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px"><b style="display:block;font-size:.8rem">' + esc(x.sku) + '</b><small style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(x.name || '') + '</small></button>').join('')
                        : '<span class="v2-code">Sin resultados con foto.</span>';
                }).catch(() => { if (my === seq) st.textContent = '⚠️ No se pudo buscar.'; });
            }, 250);
        });
        grid.addEventListener('click', (e) => {
            const b = e.target.closest('button[data-sku]'); if (!b) return;
            st.textContent = 'Cambiando…';
            fetch('/enviar-catalogo/extraer', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: JSON.stringify({ codes: [b.dataset.sku] }) })
                .then((r) => r.json()).then((j) => {
                    const pr = j && j.photos && j.photos[b.dataset.sku];
                    if (!l.base) l.base = { code: l.code, name: l.name, price: l.price };
                    Object.assign(l, { code: b.dataset.sku, name: b.dataset.name || (pr && pr.name) || '', price: pr && pr.price != null ? Number(pr.price) : null, checked: false });
                    if (b.dataset.cover) covers[l.code] = b.dataset.cover;
                    swapId = 0; draw(); later();
                }).catch(() => { st.textContent = '⚠️ No se pudo cambiar.'; });
        });
    }
    // Agrupado por categoría (promocionales primero, luego A-Z, sin categoría al final); un producto cambiado se queda en el grupo del original.
    const catOf = (l) => (d.cats || {})[l.code] || (l.base && (d.cats || {})[l.base.code]) || '';
    const rank = (c) => { const i = (d.catOrder || []).indexOf(c); return c === '' ? 1e9 : (i < 0 ? 1e6 : i); };
    const displayLines = () => lines.map((l, i) => [l, i]).sort((a, b) => rank(catOf(a[0])) - rank(catOf(b[0])) || a[1] - b[1]).map((x) => x[0]);
    function draw() {
        let lc = null;
        list.innerHTML = displayLines().map((l) => {
            const c = catOf(l); const hd = c !== lc ? (lc = c, '<div class="v2-cathead-d">' + esc(c || 'Sin categoría') + '</div>') : '';
            const img = covers[l.code] ? '<img class="v2-thumb al-zoom" loading="lazy" src="' + esc(covers[l.code]) + '" alt="Ver foto grande" title="Ver foto grande" data-code="' + esc(l.code) + '" data-name="' + esc(l.name) + '">' : '';
            const lock = ro || l.checked; // alistado = bloqueado; solo Administración/Facturación lo abren con contraseña
            const off = lock ? ' disabled' : '';
            return hd + '<div class="v2-prod" style="padding:.55rem 0;border-top:1px solid var(--color-border);gap:.7rem;align-items:center;' + (l.qty === 0 ? 'opacity:.5' : '') + '">' + img +
                '<div style="flex:1;min-width:0"><div class="v2-name">' + esc(l.name) + '</div><span class="v2-code">' + esc(l.code) + ' · pedido ' + l.origQty + '</span>' +
                '<input data-i="' + l.id + '" data-f="note" value="' + esc(l.note) + '" placeholder="Nota (faltante, cambio…)"' + off + ' style="display:block;width:100%;margin-top:.3rem;padding:.35rem .5rem;border-radius:6px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit">' +
                (l.base && l.code !== l.base.code ? '<div class="v2-code" style="margin-top:.25rem">🔄 Cambiado · antes ' + esc(l.base.code) + (lock ? '' : ' <button class="v2-chip" data-a="undo" data-i="' + l.id + '">Deshacer</button>') + '</div>' : '') +
                (lock ? '' : '<button class="v2-chip" data-a="sw" data-i="' + l.id + '" style="margin-top:.3rem">🔄 Cambiar producto</button>') + '</div>' +
                '<div style="display:flex;align-items:center;gap:.3rem"><button class="v2-chip" data-a="m" data-i="' + l.id + '"' + off + '>−</button><b>' + l.qty + '</b><button class="v2-chip" data-a="p" data-i="' + l.id + '"' + off + '>+</button></div>' +
                (ro ? '<span class="v2-code">' + (l.checked ? '🔒 Alistado' : '') + '</span>' : l.checked
                    ? '<button class="v2-chip" data-a="u" data-i="' + l.id + '" title="Solo Administración o Facturación">🔒 Alistado · Desbloquear</button>'
                    : '<label style="white-space:nowrap"><input type="checkbox" data-a="c" data-i="' + l.id + '"> Alistado</label>') + '</div>';
        }).join('');
        const sl = swapId && lines.find((x) => x.id === swapId);
        if (sl && !ro && !sl.checked) {
            const row = list.querySelector('button[data-a="sw"][data-i="' + sl.id + '"]');
            if (row) { row.parentElement.insertAdjacentHTML('beforeend', swapBox(sl)); bindSwap(sl); }
        }
        const p = pending();
        if (ro) { foot.innerHTML = (d.status === 'listo' ? '🟢 Alistamiento finalizado' + (d.who ? ' · chequeó ' + esc(d.who) : '') : '') + totals(); return; }
        foot.innerHTML = '<span>' + (lines.length - p) + ' de ' + lines.length + ' listos</span>' +
            '<input id="alWho" value="' + esc(who) + '" placeholder="Chequeado por" style="padding:.45rem .6rem;border-radius:8px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit">' +
            '<button class="v2-btn" id="alDone"' + (p ? ' disabled style="opacity:.5;cursor:not-allowed"' : '') + '>✅ Finalizar alistamiento</button>' + totals();
        document.getElementById('alWho').oninput = (e) => { who = e.target.value; later(); };
        document.getElementById('alDone').onclick = () => {
            if (pending() || !who.trim()) { msg.textContent = 'Marca todo y escribe quién chequeó.'; return; }
            save(true).then(() => location.reload()).catch((e) => { msg.textContent = '⚠️ ' + e.message; });
        };
    }
    function zoom(code, src, name) {
        const o = document.createElement('div'); o.className = 'al-lb';
        const big = src.replace(/=s\d+(-[a-z0-9-]+)?$/i, '=s1200');
        o.innerHTML = '<button type="button" class="al-lb-x" aria-label="Cerrar">✕</button><img src="' + esc(src) + '" alt=""><div class="al-lb-cap"><b>' + esc(code) + '</b> · ' + esc(name) + '</div>';
        const img = o.querySelector('img'); const hi = new Image(); hi.onload = () => { img.src = big; }; hi.src = big;
        const close = () => { o.remove(); document.removeEventListener('keydown', key); };
        const key = (ev) => { if (ev.key === 'Escape') close(); };
        o.addEventListener('click', close); document.addEventListener('keydown', key); document.body.appendChild(o);
    }
    function unlockLine(l) {
        const pw = window.prompt('Producto alistado. Para cambiarlo escribe la contraseña de un usuario Administración o Facturación:');
        if (pw === null || pw === '') return;
        l.checked = false;
        save(false, { password: pw }).then(() => { msg.textContent = 'Desbloqueado: ya puedes editar ' + l.code + '.'; draw(); })
            .catch((e) => { l.checked = true; msg.textContent = '⚠️ ' + e.message; draw(); });
    }
    list.addEventListener('click', (e) => {
        const z = e.target.closest('.al-zoom'); if (z) { zoom(z.dataset.code, z.src, z.dataset.name); return; }
        const b = e.target.closest('button[data-a]'); if (!b || ro) return;
        const l = lines.find((x) => x.id === +b.dataset.i);
        if (b.dataset.a === 'u') { unlockLine(l); return; }
        if (b.dataset.a === 'sw') { swapId = swapId === l.id ? 0 : l.id; draw(); return; }
        if (b.dataset.a === 'undo' && !l.checked && l.base) { Object.assign(l, { code: l.base.code, name: l.base.name, price: l.base.price }); draw(); later(); return; }
        if (l.checked) return;
        l.qty = Math.max(0, l.qty + (b.dataset.a === 'p' ? 1 : -1)); draw(); later();
    });
    list.addEventListener('change', (e) => {
        const t = e.target; if (ro) return;
        const l = lines.find((x) => x.id === +t.dataset.i); if (!l) return;
        if (t.dataset.a === 'c') l.checked = t.checked; else if (t.dataset.f === 'note') l.note = t.value;
        draw(); later();
    });
    draw();
})();
