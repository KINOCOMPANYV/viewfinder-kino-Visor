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
        let orig = 0, now = 0, short = 0;
        lines.forEach((l) => {
            const bp = l.base && l.base.price != null ? +l.base.price : (l.price != null ? +l.price : 0);
            if (l.orig !== false) { orig += bp * (+l.origQty || 0); short += Math.max(0, (+l.origQty || 0) - (+l.qty || 0)); }
            now += (l.price != null ? +l.price : 0) * (+l.qty || 0);
        });
        const k = 1 - pct / 100;
        const head = document.getElementById('ordTotal');
        if (head) head.textContent = 'Total ' + (now > 0 ? money(now * k) : '—');
        return '<div style="flex-basis:100%;padding:.6rem 0;border-top:1px solid var(--color-border);display:flex;gap:1rem;flex-wrap:wrap;align-items:baseline">' +
            '<span class="v2-code">Pedido ' + money(orig * k) + '</span><b style="font-size:1.1rem">Alistado ' + money(now * k) + '</b>' +
            (Math.round(orig) !== Math.round(now) ? '<span style="color:var(--color-' + (now < orig ? 'danger' : 'success') + ')">' + (now < orig ? '−' : '+') + money(Math.abs(orig - now) * k) + '</span>' : '') +
            (short ? '<span class="v2-code">' + short + ' unidad' + (short === 1 ? '' : 'es') + ' faltante' + (short === 1 ? '' : 's') + '</span>' : '') +
            (pct ? '<span class="v2-code">con ' + pct + '% de descuento</span>' : '') + '</div>';
    }
    function draw() {
        list.innerHTML = lines.map((l) => {
            const img = covers[l.code] ? '<img class="v2-thumb al-zoom" loading="lazy" src="' + esc(covers[l.code]) + '" alt="Ver foto grande" title="Ver foto grande" data-code="' + esc(l.code) + '" data-name="' + esc(l.name) + '">' : '';
            const lock = ro || l.checked; // alistado = bloqueado; solo Administración/Facturación lo abren con contraseña
            const off = lock ? ' disabled' : '';
            return '<div class="v2-prod" style="padding:.55rem 0;border-top:1px solid var(--color-border);gap:.7rem;align-items:center;' + (l.qty === 0 ? 'opacity:.5' : '') + '">' + img +
                '<div style="flex:1;min-width:0"><div class="v2-name">' + esc(l.name) + '</div><span class="v2-code">' + esc(l.code) + ' · pedido ' + l.origQty + '</span>' +
                '<input data-i="' + l.id + '" data-f="note" value="' + esc(l.note) + '" placeholder="Nota (faltante, cambio…)"' + off + ' style="display:block;width:100%;margin-top:.3rem;padding:.35rem .5rem;border-radius:6px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit"></div>' +
                '<div style="display:flex;align-items:center;gap:.3rem"><button class="v2-chip" data-a="m" data-i="' + l.id + '"' + off + '>−</button><b>' + l.qty + '</b><button class="v2-chip" data-a="p" data-i="' + l.id + '"' + off + '>+</button></div>' +
                (ro ? '<span class="v2-code">' + (l.checked ? '🔒 Alistado' : '') + '</span>' : l.checked
                    ? '<button class="v2-chip" data-a="u" data-i="' + l.id + '" title="Solo Administración o Facturación">🔒 Alistado · Desbloquear</button>'
                    : '<label style="white-space:nowrap"><input type="checkbox" data-a="c" data-i="' + l.id + '"> Alistado</label>') + '</div>';
        }).join('');
        const p = pending();
        if (ro) { foot.innerHTML = totals() + (d.status === 'listo' ? '🟢 Alistamiento finalizado' + (d.who ? ' · chequeó ' + esc(d.who) : '') : ''); return; }
        foot.innerHTML = totals() + '<span>' + (lines.length - p) + ' de ' + lines.length + ' listos</span>' +
            '<input id="alWho" value="' + esc(who) + '" placeholder="Chequeado por" style="padding:.45rem .6rem;border-radius:8px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit">' +
            '<button class="v2-btn" id="alDone"' + (p ? ' disabled style="opacity:.5;cursor:not-allowed"' : '') + '>✅ Finalizar alistamiento</button>';
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
