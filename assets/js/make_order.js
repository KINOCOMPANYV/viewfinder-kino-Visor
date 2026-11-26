/* Hacer pedido (/hacer-pedido): buscar, carrito (window.kinoCart de cart_badge.js), precios de /api/precios, envío. */
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const cover = (u) => String(u || '').replace(/^\[VIDEO\]/, '');
    const meta = {};   // sku → {name, cover, price}
    let timer = 0;

    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const num = (t) => { const d = String(t || '').replace(/\D/g, ''); return d ? parseInt(d, 10) : 0; };

    async function loadMeta(skus) {
        const need = skus.filter((s) => !(s in meta) || meta[s].price === undefined);
        if (!need.length) return;
        try {
            const r = await fetch('/api/precios?codes=' + encodeURIComponent(need.join(',')));
            const j = await r.json();
            need.forEach((s) => { meta[s] = Object.assign(meta[s] || {}, { price: num(j[s]) }); });
        } catch (e) { need.forEach((s) => { meta[s] = Object.assign(meta[s] || {}, { price: 0 }); }); }
        const n = await fetch('/api/nombres?codes=' + encodeURIComponent(need.join(','))).then((r) => r.json()).catch(() => ({}));
        need.forEach((s) => { if (n[s]) meta[s].name = n[s].n; });
    }

    async function drawCart() {
        const items = kinoCart.read();
        await loadMeta(items.map((i) => i.sku));
        $('moEmpty').hidden = items.length > 0;
        $('moTotal').hidden = items.length === 0;
        $('moSend').disabled = items.length === 0;
        $('moCount').textContent = items.length ? '(' + items.length + ' ref.)' : '';
        let total = 0;
        $('moCart').innerHTML = items.map((i) => {
            const m = meta[i.sku] || {};
            const p = m.price || 0; total += p * i.qty;
            return '<li class="mo-line" data-sku="' + esc(i.sku) + '">' +
                (m.cover ? '<img src="' + esc(m.cover) + '" alt="" loading="lazy">' : '<span class="mo-noimg"></span>') +
                '<div class="mo-info"><a href="/producto/' + encodeURIComponent(i.sku) + '"><b>' + esc(i.sku) + '</b></a>' +
                '<small>' + esc(m.name || '') + '</small><small>' + (p ? money(p) + ' c/u' : 'Sin precio en tabla') + '</small></div>' +
                '<div class="mo-qty"><button type="button" data-d="-1" aria-label="Menos">−</button><span>' + i.qty + '</span><button type="button" data-d="1" aria-label="Más">+</button></div>' +
                '<button type="button" class="mo-del" data-del="1" aria-label="Quitar">✕</button></li>';
        }).join('');
        $('moTotalV').textContent = money(total);
    }

    $('moCart').addEventListener('click', (ev) => {
        const b = ev.target.closest('button'); if (!b) return;
        const sku = b.closest('.mo-line').dataset.sku;
        let items = kinoCart.read();
        if (b.dataset.del) items = items.filter((i) => i.sku !== sku);
        else { const it = items.find((i) => i.sku === sku); it.qty = Math.max(1, Math.min(999, it.qty + parseInt(b.dataset.d, 10))); }
        kinoCart.write(items);
    });

    $('moQ').addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const q = $('moQ').value.trim();
            if (q.length < 2) { $('moResults').innerHTML = ''; return; }
            const rows = await fetch('/api/search?limit=12&q=' + encodeURIComponent(q)).then((r) => r.json()).catch(() => []);
            rows.forEach((p) => { meta[p.sku] = Object.assign(meta[p.sku] || {}, { name: p.name, cover: cover(p.cover_image_url) }); });
            $('moResults').innerHTML = rows.length ? rows.map((p) =>
                '<li data-sku="' + esc(p.sku) + '"><img src="' + esc(cover(p.cover_image_url)) + '" alt="" loading="lazy">' +
                '<span><b>' + esc(p.sku) + '</b><small>' + esc(p.name || '') + '</small></span>' +
                '<button type="button" class="btn btn-sm btn-primary">Agregar</button></li>').join('') : '<li class="mo-none">Sin resultados.</li>';
        }, 250);
    });
    $('moResults').addEventListener('click', (ev) => {
        const b = ev.target.closest('button'); if (!b) return;
        kinoCart.add(b.closest('li').dataset.sku, 1);
        b.textContent = '✓ Agregado';
    });

    $('moSend').addEventListener('click', async () => {
        const err = $('moErr'); err.hidden = true;
        const btn = $('moSend'); btn.disabled = true; btn.textContent = 'Enviando…';
        try {
            const r = await fetch('/hacer-pedido/enviar', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ csrf: window.MO_CSRF, name: $('moName').value, phone: $('moPhone').value, note: $('moNote').value, website: $('moWeb').value, items: kinoCart.read() })
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || 'No se pudo enviar el pedido.');
            kinoCart.write([]);
            $('moDoneId').textContent = j.id ? '#' + j.id : '';
            $('moWork').hidden = true; $('moDone').hidden = false;
        } catch (e) {
            err.textContent = e.message; err.hidden = false;
            btn.disabled = false; btn.textContent = 'Enviar pedido';
        }
    });

    try { const s = JSON.parse(localStorage.getItem('kinoCliente') || 'null'); if (s) { $('moName').value = s.name || ''; $('moPhone').value = s.phone || ''; } } catch (e) {}
    ['moName', 'moPhone'].forEach((id) => $(id).addEventListener('change', () => {
        try { localStorage.setItem('kinoCliente', JSON.stringify({ name: $('moName').value, phone: $('moPhone').value })); } catch (e) {}
    }));
    window.addEventListener('kinoCart', drawCart);
    drawCart();
})();
