/**
 * Lista Bodega / Remisión (/lista-pedido): PDF del documento leído en el Extractor (sessionStorage kinoPedido).
 * Recibo (pedido) → «Lista Bodega» negro y dorado; factura en tabla → «Remisión» (data.docType, se puede cambiar).
 * La Remisión usa la plantilla editable window.KINO_REMISION (Admin → 🚚 Remisión): título, colores, logos y pie.
 * Encabezado con cliente, fecha, total de ítems, unidades y valor total TAL CUAL vienen del PDF (no se recalculan con
 * las fotos ni se pone la fecha de hoy); si un dato no se pudo leer, se dice. Luego cada línea del pedido, en el mismo
 * orden: foto, código y cantidad del PDF (solo lectura). Cada línea guarda su código y su foto juntos desde el
 * principio, así no se cruzan referencias. El cliente se puede escribir a mano (si no se leyó o para corregirlo): se guarda
 * en kinoPedido, así lo usan los PDF y «Enviar a pedidos bodega». Sin foto → «Foto no disponible» (la línea sale igual).
 * Piezas comunes (fotos, texto, compartir, descargar) en pdf_shared.js.
 */
(function () {
    const KP = window.KinoPdf;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const MISSING = 'No se pudo leer del PDF';
    const PREVIEW = '👁 Previo';
    const msg = (t) => { $('olMsg').textContent = t || ''; };

    let data = null, pdfFile = null;
    try { data = JSON.parse(sessionStorage.getItem('kinoPedido') || 'null'); } catch (e) { data = null; }
    if (!data || !Array.isArray(data.items) || !data.items.length) { $('olEmpty').hidden = false; return; }

    const order = data.order || {};
    // Cada línea del pedido, en el orden del Extractor; la foto se le pone a esta misma línea (por su código)
    const lines = data.items.map((it, i) => ({ n: i + 1, code: String(it.code || ''), qty: it.qty == null ? null : Number(it.qty),
        desc: String(it.desc || ''), note: String(it.note || '').slice(0, 1000), price: String(it.priceText || ''), total: String(it.lineTotalText || ''), photo: undefined }));
    const has = (v) => v !== null && v !== undefined && String(v).trim() !== '';
    // Precio unitario por línea (columna «Precio c/u»): en la cotización en tabla el precio ya es unitario; en el recibo
    // puede ser el total de la línea (order.priceIsLineTotal, o se deduce si la suma de precios cuadra con el Total del
    // PDF sin multiplicar) y entonces se divide por la cantidad. El total de la línea se calcula si no viene.
    const parsePrice = (t) => {
        let s = String(t || '').replace(/[^\d.,]/g, '');
        if (!s) return null;
        const m = s.match(/^(.*)[.,](\d{1,2})$/);
        s = m ? m[1].replace(/[.,]/g, '') + '.' + m[2] : s.replace(/[.,]/g, '');
        const n = parseFloat(s);
        return isFinite(n) && n > 0 ? n : null;
    };
    const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const priceIsLine = order.priceIsLineTotal != null ? !!order.priceIsLineTotal : (() => {
        const tot = parsePrice(order.totalText);
        if (tot == null) return false;
        let sp = 0, su = 0;
        lines.forEach((l) => { const p = parsePrice(l.price) || 0, q = l.qty == null ? 1 : l.qty; sp += p; su += p * q; });
        return Math.abs(sp - tot) < 1 && Math.abs(su - tot) >= 1;
    })();
    lines.forEach((l) => {
        const p = parsePrice(l.price), q = l.qty == null ? null : l.qty;
        l.unit = l.price;
        if (p == null) return;
        if (priceIsLine) {
            if (!l.total) l.total = l.price;
            l.unit = q > 0 ? (q === 1 ? l.price : money(p / q)) : '';
        } else if (!l.total && q > 0) l.total = q === 1 ? l.price : money(p * q);
    });
    // Plantilla de la Remisión (Admin → 🚚 Remisión); color «#rrggbb» → [r, g, b]
    const REM = window.KINO_REMISION || {};
    function rgb(hex, def) { const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || ''); return m ? [1, 2, 3].map((i) => parseInt(m[i], 16)) : def; }
    // Logos de la plantilla → PNG para jsPDF (una vez); el que no cargue se omite
    let remLogos = null;
    function loadLogos() {
        if (remLogos) return remLogos;
        remLogos = Promise.all((REM.logos || []).map((l) => new Promise((ok) => {
            const im = new Image();
            im.onload = () => {
                try {
                    const cv = document.createElement('canvas'); cv.width = im.naturalWidth; cv.height = im.naturalHeight;
                    cv.getContext('2d').drawImage(im, 0, 0);
                    ok({ side: l.side === 'left' ? 'left' : 'right', data: cv.toDataURL('image/png'), w: im.naturalWidth, h: im.naturalHeight });
                } catch (e) { ok(null); }
            };
            im.onerror = () => ok(null);
            im.src = l.src;
        }))).then((a) => a.filter(Boolean));
        return remLogos;
    }

    // Datos del encabezado: solo lo que vino del PDF. Si el PDF no trae el total de ítems o de unidades (p. ej. la
    // cotización en tabla), se puede usar lo contado en sus líneas, marcado «(contado)»: es un dato del PDF, no inventado.
    const counted = { items: lines.length, units: order.noQty ? null : lines.reduce((s, l) => s + (l.qty || 0), 0) };
    // Dos documentos: «Lista Bodega» (recibo = pedido) y «Remisión» (factura en tabla). El tipo lo decide el Extractor
    // según el formato del PDF; se puede cambiar a mano. Cada tipo tiene su título, sus colores y sus casillas guardadas.
    const TYPES = {
        pedido: { name: 'Lista Bodega', title: 'Lista Bodega', file: 'Lista_Bodega_', icon: '🧾', pref: 'kinoOlFields',
            numLabel: 'N.º de pedido', c1: [28, 28, 30], c2: [201, 168, 76],
            def: { number: true, client: true, date: true, items: true, units: true, total: true, nit: false, address: false, city: false, phone: false, seller: false,
                review: true, useCount: true, names: true, cN: true, cPhoto: true, cCode: true, cDesc: false, cQty: true, cUnit: true, cTotal: false } },
        remision: { name: 'Cotización', title: (REM.title || 'Cotización'), file: 'Cotizacion_', icon: '🚚', pref: 'kinoOlFieldsRemision',
            numLabel: 'N.º de cotización', c1: rgb(REM.headerColor, [14, 14, 14]), c2: rgb(REM.accentColor, [211, 35, 45]),
            def: { number: true, client: true, date: true, items: true, units: true, total: true, nit: true, address: true, city: true, phone: false, seller: false,
                review: true, useCount: true, names: true, cN: true, cPhoto: true, cCode: true, cDesc: false, cQty: true, cUnit: true, cTotal: true } },
    };
    let type = TYPES[data.docType] ? data.docType : 'pedido';
    const T = () => TYPES[type];
    const allFields = [
        { key: 'number', label: 'N.º', v: order.number },
        { key: 'client', label: 'Cliente', v: order.client, edit: true }, // se puede escribir o corregir a mano
        { key: 'nit', label: 'NIT', v: order.nit, opt: true },
        { key: 'address', label: 'Dirección', v: order.address, opt: true },
        { key: 'city', label: 'Ciudad', v: order.city, opt: true },
        { key: 'phone', label: 'Teléfono', v: order.phone, opt: true },
        { key: 'seller', label: 'Vendedor', v: order.seller, opt: true },
        { key: 'date', label: 'Fecha', v: order.date },
        { key: 'items', label: 'Total de ítems', v: order.itemsPdf, count: counted.items },
        { key: 'units', label: 'Unidades', v: order.noQty ? null : order.unitsPdf, count: counted.units },
        { key: 'total', label: 'Valor total', v: order.totalText },
    ].filter((f) => !f.opt || has(f.v)); // NIT, dirección… solo si el PDF los trae (la factura en tabla sí)
    const labelOf = (f) => f.key === 'number' ? T().numLabel : f.key === 'date' && type === 'pedido' ? 'Fecha del pedido' : f.label;
    // Qué sale en el PDF: lo elige el usuario y se recuerda en este dispositivo, aparte para cada tipo
    let show = {};
    const loadPrefs = () => {
        show = Object.assign({}, T().def);
        try { Object.assign(show, JSON.parse(localStorage.getItem(T().pref) || '{}')); } catch (e) { /* sin preferencias */ }
    };
    loadPrefs();
    const savePrefs = () => { try { localStorage.setItem(T().pref, JSON.stringify(show)); } catch (e) { /* sin almacenamiento */ } };
    // Valor que se usa: el del PDF; si falta y hay conteo (y se eligió usarlo), el conteo con «(contado)»
    const valueOf = (f) => has(f.v) ? String(f.v) : (show.useCount && f.count != null ? f.count + ' (contado)' : null);
    // Columnas de la tabla; Producto, Precio y Total solo si el PDF los trae (la cotización en tabla sí; el recibo, precio)
    const allCols = [
        { key: 'cN', label: 'N.º', min: 8 },
        { key: 'cPhoto', label: 'Foto', min: 24 },
        { key: 'cCode', label: 'Código', min: 26, flex: 1 },
        { key: 'cDesc', label: 'Producto', min: 50, flex: 3, avail: lines.some((l) => l.desc) },
        { key: 'cQty', label: 'Cantidad', min: 17 },
        { key: 'cUnit', label: 'Precio c/u', min: 24, avail: lines.some((l) => l.unit) },
        { key: 'cTotal', label: 'Total', min: 26, avail: lines.some((l) => l.total) },
    ].filter((c) => c.avail !== false);
    const pickedCols = () => allCols.filter((c) => show[c.key]);
    const canCount = allFields.some((f) => !has(f.v) && f.count != null);
    let fields = [], problems = [];
    function refresh() {
        fields = allFields.filter((f) => show[f.key]).map((f) => [labelOf(f), valueOf(f), f.key]);
        problems = [];
        if (show.review) {
            fields.forEach(([k, v, key]) => { if (key !== 'number' && !has(v)) problems.push(k + ': no se pudo leer del PDF; no se inventó un valor.'); });
            if (order.noQty) problems.push('El PDF no trae cantidades: cada referencia sale como «sin cantidad».');
            else if (lines.some((l) => l.qty == null)) problems.push('Alguna referencia no trae cantidad en el PDF.');
            (data.warnings || []).forEach((w) => { if (!problems.includes(w)) problems.push('Extractor: ' + w); });
        }
    }
    refresh();

    // Título, colores de la pantalla y selector de tipo
    function drawType() {
        document.body.classList.toggle('ol-remision', type === 'remision');
        $('olTitle').textContent = T().icon + ' ' + T().name;
        document.title = T().name + ' · Viewfinder';
        const crumb = document.querySelector('.crumbs [aria-current="page"]');
        if (crumb) crumb.textContent = T().name;
        $('olType').innerHTML = '<b>Tipo:</b> ' + Object.keys(TYPES).map((k) => '<label><input type="radio" name="olType" value="' + k + '"' +
            (k === type ? ' checked' : '') + '> ' + TYPES[k].icon + ' ' + esc(TYPES[k].name) + '</label>').join('') +
            '<small>' + (data.docType === 'remision' ? 'El PDF es una factura en tabla → Cotización.' : 'El PDF es un recibo (pedido) → Lista Bodega.') + '</small>';
    }
    drawType();

    // Cliente escrito a mano: reemplaza al leído (o al que faltaba) en la pantalla, los PDF y el pedido guardado
    // Lo leído del PDF (el Extractor lo pasa aparte si ya se corrigió allá)
    const clientPdf = has(order.clientPdf) ? String(order.clientPdf) : order.clientPdf === undefined && has(order.client) ? String(order.client) : '';
    function setClient(val) {
        const v = String(val || '').replace(/\s+/g, ' ').trim();
        order.client = v || clientPdf || null;
        allFields.find((f) => f.key === 'client').v = order.client;
        data.order = order;
        try { sessionStorage.setItem('kinoPedido', JSON.stringify(data)); } catch (e) { /* sin almacenamiento */ }
    }
    function drawOrder() {
        $('olOrder').innerHTML = allFields.map((f) => {
            const v = valueOf(f);
            if (f.edit) {
                return '<div class="ol-field' + (show[f.key] ? '' : ' off') + '"><span><label><input type="checkbox" data-k="' + f.key + '"' + (show[f.key] ? ' checked' : '') + '> ' + esc(labelOf(f)) + '</label></span>' +
                    '<input type="text" class="ol-edit" id="olClient" maxlength="120" value="' + esc(has(v) ? v : '') + '" placeholder="Escribe el cliente">' +
                    (clientPdf ? (String(v) !== clientPdf ? '<small class="ol-note">Del PDF: ' + esc(clientPdf) + '</small>' : '')
                        : (has(v) ? '<small class="ol-note">Escrito a mano</small>' : '<b class="ol-miss">⚠ ' + MISSING + '</b>')) + '</div>';
            }
            return '<label class="ol-field' + (show[f.key] ? '' : ' off') + '"><span><input type="checkbox" data-k="' + f.key + '"' + (show[f.key] ? ' checked' : '') + '> ' + esc(labelOf(f)) + '</span>' +
                (has(v) ? esc(v) : (f.key === 'number' ? '—' : '<b class="ol-miss">⚠ ' + MISSING + '</b>')) + '</label>';
        }).join('');
        $('olOpts').innerHTML = '<label><input type="checkbox" data-k="review"' + (show.review ? ' checked' : '') + '> Recuadro «Revisar» con avisos</label>' +
            '<label><input type="checkbox" data-k="names"' + (show.names !== false ? ' checked' : '') + '> Nombre del producto encima del código</label>' +
            '<div class="ol-cols"><b>Columnas de la tabla:</b> ' + allCols.map((c) => '<label><input type="checkbox" data-k="' + c.key + '"' + (show[c.key] ? ' checked' : '') + '> ' + esc(c.label) + '</label>').join('') + '</div>' +
            (canCount ? '<label><input type="checkbox" data-k="useCount"' + (show.useCount ? ' checked' : '') + '> Si el PDF no trae ítems o unidades, usar lo contado en sus líneas</label>' : '');
        drawWarn();
    }
    function drawWarn() {
        const warnBox = $('olWarn');
        warnBox.hidden = !problems.length;
        warnBox.innerHTML = problems.length ? '<strong>Revisar</strong><ul>' + problems.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul>' : '';
    }
    drawOrder();
    const resetPdf = (why) => { if (pdfFile) { pdfFile = null; $('olShare').hidden = true; $('olDown').hidden = true; $('olNameRow').hidden = true; $('olMake').textContent = PREVIEW; msg(why); } };
    $('olType').addEventListener('change', (e) => {
        if (!e.target || e.target.name !== 'olType' || !TYPES[e.target.value]) return;
        type = e.target.value;
        loadPrefs(); refresh(); drawType(); drawOrder();
        resetPdf('Cambiaste el tipo: vuelve a generar el PDF.');
    });
    $('olBody').addEventListener('change', (e) => {
        if (e.target && e.target.name === 'olType') return;
        const k = e.target && e.target.dataset ? e.target.dataset.k : null;
        if (!k) return;
        if (!e.target.checked && /^c[A-Z]/.test(k) && pickedCols().length <= 1) { e.target.checked = true; msg('Deja al menos una columna.'); return; }
        show[k] = e.target.checked;
        savePrefs(); refresh(); drawOrder();
        if (k === 'names' || k === 'cUnit') drawList();
        resetPdf('Cambiaste lo que sale en el PDF: vuelve a generarlo.');
    });
    // Escribir el cliente: se aplica al salir de la casilla (o con Enter) y se puede volver a corregir
    $('olOrder').addEventListener('change', (e) => {
        if (!e.target || e.target.id !== 'olClient') return;
        setClient(e.target.value);
        refresh(); drawOrder();
        resetPdf('Cambiaste el cliente: vuelve a generar el PDF.');
    });
    // Notas generales de la Cotización: varias líneas, se guardan con el pedido y salen completas al final del PDF
    const nBox = $('olNotes');
    if (nBox) {
        const grow = () => { nBox.style.height = 'auto'; nBox.style.height = nBox.scrollHeight + 2 + 'px'; };
        nBox.value = String(order.notes || '');
        nBox.addEventListener('input', () => {
            order.notes = nBox.value.slice(0, 2000); data.order = order; grow();
            try { sessionStorage.setItem('kinoPedido', JSON.stringify(data)); } catch (e) { /* sin almacenamiento */ }
        });
        nBox.addEventListener('change', () => resetPdf('Cambiaste las notas: vuelve a generar el PDF.'));
        if (nBox.value) setTimeout(grow, 0);
    }
    $('olOrder').addEventListener('keydown', (e) => { if (e.target && e.target.id === 'olClient' && e.key === 'Enter') { e.preventDefault(); e.target.blur(); } });
    $('olBody').hidden = false;
    $('olBar').hidden = false;

    // Nombre del producto (columna NAME de la Hoja master) por línea; lo llena loadNames()
    const names = {};
    const nameOf = (l) => (show.names !== false ? KP.clean(names[l.code] || '') : '');
    function drawList() {
        $('olList').innerHTML = lines.map((l) => {
            const ph = l.photo === undefined ? '<span class="ph none">…</span>'
                : l.photo ? '<span class="ph"><img src="https://lh3.googleusercontent.com/d/' + encodeURIComponent(l.photo.id) + '=s200" alt="" loading="lazy"></span>'
                : '<span class="ph none">Foto no disponible</span>';
            return '<li><span class="n">' + l.n + '</span>' + ph + '<span class="cd">' + (nameOf(l) ? '<small class="nm">' + esc(nameOf(l)) + '</small>' : '') + esc(l.code) +
                (l.photo && l.photo.sku !== l.code ? '<small>en el visor: ' + esc(l.photo.sku) + '</small>' : '') +
                '</span><span class="q">' + (l.qty == null ? 'sin cantidad' : '× ' + esc(l.qty)) +
                (show.cUnit && l.unit ? '<small class="pu">' + esc(l.unit) + ' c/u</small>' : '') + '</span></li>';
        }).join('');
    }
    drawList();

    // Nombre del producto (columna NAME de la Hoja master), encima del código; se busca por el código del visor
    async function loadNames() {
        const key = (l) => (l.photo && l.photo.sku) || l.code;
        const codes = [...new Set(lines.map(key).filter(Boolean))].slice(0, 400);
        if (!codes.length) return;
        const r = await fetch('/api/nombres?codes=' + encodeURIComponent(codes.join(',')), { headers: { Accept: 'application/json' } });
        const d = await r.json().catch(() => ({}));
        lines.forEach((l) => { const o = d && d[key(l)]; if (o && o.n) names[l.code] = String(o.n); });
    }

    // ---- Fotos: una búsqueda exacta por código (sin corregir lo leído) ----
    async function lookup() {
        const codes = [...new Set(lines.map((l) => l.code).filter(Boolean))];
        const r = await fetch('/enviar-catalogo/extraer', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CS_CSRF || '', 'Accept': 'application/json' },
            body: JSON.stringify({ codes }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || !j.photos) throw new Error(j.error || 'error ' + r.status);
        lines.forEach((l) => {
            const p = Object.prototype.hasOwnProperty.call(j.photos, l.code) ? j.photos[l.code] : null;
            l.photo = p && p.id ? { id: String(p.id), sku: String(p.sku || l.code) } : null;
        });
    }

    // ---- PDF ----
    let busy = false;
    const INK = [30, 30, 30], GREY = [110, 110, 110], WARN = [190, 90, 20];
    const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    const fileName = () => T().file + ((KP.clean(order.number || order.client).replace(/[^\wÁÉÍÓÚÜÑáéíóúüñ -]+/g, '').trim().replace(/\s+/g, '_')) || today()) + '.pdf';

    async function make() {
        if (busy) return;
        if (!window.jspdf || !window.jspdf.jsPDF) { msg('El generador de PDF no cargó. Revisa la conexión y recarga la página.'); return; }
        busy = true;
        const btn = $('olMake');
        btn.disabled = true; $('olShare').hidden = true; $('olDown').hidden = true; $('olNameRow').hidden = true; pdfFile = null;
        try {
            // 1) Fotos (una vez por foto, 4 a la vez); la que no baje queda como «Foto no disponible»
            const ids = !show.cPhoto ? [] : [...new Set(lines.filter((l) => l.photo).map((l) => l.photo.id))];
            const imgs = new Map();
            let done = 0, next = 0, failed = 0;
            const worker = async () => {
                while (next < ids.length) {
                    const id = ids[next++];
                    try { imgs.set(id, await KP.prepare(id, 300)); } catch (e) { imgs.set(id, null); failed++; }
                    done++;
                    btn.textContent = `⏳ Fotos ${done}/${ids.length}`;
                }
            };
            await Promise.all([worker(), worker(), worker(), worker()]);

            // 2) Medidas (mm)
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true });
            const C1 = T().c1, C2 = T().c2, rem = type === 'remision';
            const logos = rem ? await loadLogos() : [];
            const footLines = rem ? (REM.footer || []).filter(has) : [];
            const brands = rem && has(REM.brands) ? REM.brands : '';
            const nFoot = footLines.length + (brands ? 1 : 0);
            const W = 210, H = 297, M = 12, BAND = rem ? 20 : 16, FOOT = rem && nFoot ? 8 + nFoot * 3.7 : 13, TH = 8.5;
            const LINE = rem ? [185, 185, 185] : [40, 40, 40]; // bordes de la tabla
            // Columnas elegidas. Si caben en media hoja: dos tablas lado a lado (~26 por página); si no, una a todo el ancho.
            const cols = pickedCols();
            const ROW = show.cPhoto ? 19 : 9;
            const GAP = 4, half = (W - 2 * M - GAP) / 2;
            const two = cols.reduce((s, c) => s + c.min, 0) <= half;
            const TW = two ? half : W - 2 * M;
            const spare = TW - cols.reduce((s, c) => s + c.min, 0);
            const flexSum = cols.reduce((s, c) => s + (c.flex || 0), 0);
            const COLS = cols.map((c) => c.min + (flexSum ? spare * (c.flex || 0) / flexSum : spare / cols.length));
            const clean = KP.clean;
            const val = (v) => has(v) ? clean(v) : null;
            const fitText = (t, mw) => { if (doc.getTextWidth(t) <= mw) return t; while (t.length > 1 && doc.getTextWidth(t + '…') > mw) t = t.slice(0, -1); return t + '…'; };

            let y = 0, page = 0;
            // Remisión: franja con logos a la izquierda, título y número, y logos a la derecha (separados por una raya fina)
            const remBand = () => {
                doc.setFillColor(...C1); doc.rect(0, 0, W, BAND, 'F');
                doc.setFillColor(...C2); doc.rect(0, BAND, W, 1.2, 'F');
                const LH = 11.5, MAXW = 42, GAPL = 4;
                const size = (lg, h) => { let w = lg.w * h / lg.h; if (w > MAXW) { h = h * MAXW / w; w = MAXW; } return { w, h }; };
                const sep = (x) => { doc.setDrawColor(150, 150, 150); doc.setLineWidth(0.25); doc.line(x, 4, x, BAND - 4); };
                let x = M;
                logos.filter((l) => l.side === 'left').forEach((l) => {
                    const s = size(l, LH);
                    doc.addImage(l.data, 'PNG', x, (BAND - s.h) / 2, s.w, s.h, undefined, 'FAST');
                    x += s.w + GAPL; sep(x); x += GAPL;
                });
                const num = show.number && has(order.number) ? clean(order.number).replace(/^#\s*/, '') : '';
                doc.setFont('helvetica', 'bold'); doc.setFontSize(17); doc.setTextColor(255, 255, 255);
                doc.text(T().title, x, num ? 9.6 : 12);
                let tw = doc.getTextWidth(T().title);
                if (num) {
                    const nt = (has(REM.numPrefix) ? REM.numPrefix + ' ' : '') + num;
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(11); doc.text(nt, x, 15.6);
                    tw = Math.max(tw, doc.getTextWidth(nt));
                }
                // Derecha: de derecha a izquierda; si no caben, se achican todos por igual
                const right = logos.filter((l) => l.side !== 'left');
                if (!right.length) return;
                let sizes = right.map((l) => size(l, LH));
                const room = W - M - (x + tw + 6);
                const need = sizes.reduce((s, z) => s + z.w, 0) + (right.length - 1) * 2 * GAPL;
                if (need > room && room > 0) { const k = Math.max(0.3, (room - (right.length - 1) * 2 * GAPL) / (need - (right.length - 1) * 2 * GAPL)); sizes = sizes.map((z) => ({ w: z.w * k, h: z.h * k })); }
                let rx = W - M;
                for (let i = right.length - 1; i >= 0; i--) {
                    const z = sizes[i];
                    rx -= z.w;
                    doc.addImage(right[i].data, 'PNG', rx, (BAND - z.h) / 2, z.w, z.h, undefined, 'FAST');
                    if (i > 0) { rx -= GAPL; sep(rx); rx -= GAPL; }
                }
            };
            const band = () => {
                if (rem) return remBand();
                doc.setFillColor(...C1); doc.rect(0, 0, W, BAND, 'F');
                doc.setFillColor(...C2); doc.rect(0, BAND, W, 1, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(255, 255, 255);
                doc.text(T().title, M, 10.5);
                if (show.number && has(order.number)) { doc.setFontSize(11); doc.setTextColor(...C2); doc.text(clean(order.number), W - M, 10.5, { align: 'right' }); }
            };
            const tableHead = (c) => {
                const x0 = M + c * (TW + GAP);
                doc.setFillColor(...C1); doc.setDrawColor(...(rem ? C1 : LINE)); doc.setLineWidth(0.35);
                let x = x0;
                COLS.forEach((w) => { doc.rect(x, y, w, TH, 'FD'); x += w; });
                if (rem) { doc.setFillColor(...C2); doc.rect(x0, y + TH - 0.6, x - x0, 0.6, 'F'); }
                doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.setTextColor(255, 255, 255);
                x = x0;
                cols.map((c) => c.label).forEach((t, i) => { doc.text(t, x + COLS[i] / 2, y + 5.6, { align: 'center' }); x += COLS[i]; });
                y += TH;
            };
            const newPage = () => { if (page) doc.addPage(); page++; band(); y = BAND + (rem ? 8 : 7); };

            // Encabezado del pedido (primera página)
            newPage();
            const cells = fields.filter(([, , key]) => key !== 'number');
            const per = cells.length > 6 ? 3 : 2, colW = (W - 2 * M) / per, RH = per === 3 ? 14 : 13; // con muchos datos (remisión), 3 por fila
            cells.forEach(([k, v], i) => {
                const x = M + (i % per) * colW, yy = y + Math.floor(i / per) * RH;
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...GREY);
                doc.text(k.toUpperCase(), x, yy + 3);
                const s = val(v);
                doc.setFont('helvetica', 'bold'); doc.setFontSize(s ? (per === 3 ? 11 : 12.5) : 10.5); doc.setTextColor(...(s ? INK : WARN));
                let tl = doc.splitTextToSize(s || MISSING, colW - 4);
                if (tl.length > 1 && per === 3) { doc.setFontSize(9); tl = doc.splitTextToSize(s, colW - 4).slice(0, 2); doc.text(tl, x, yy + 7.6); }
                else doc.text(tl[0], x, yy + 9);
            });
            if (cells.length && rem && per > 1) { // rayas finas entre las columnas de datos, como en el modelo
                doc.setDrawColor(200, 200, 200); doc.setLineWidth(0.25);
                for (let c = 1; c < per; c++) doc.line(M + c * colW - 4, y, M + c * colW - 4, y + Math.ceil(cells.length / per) * RH - 2);
            }
            if (cells.length) y += Math.ceil(cells.length / per) * RH + 2;
            if (problems.length) {
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5);
                const ls = problems.slice(0, 6).flatMap((p) => doc.splitTextToSize('• ' + clean(p), W - 2 * M - 6)).slice(0, 8);
                const h = 6 + ls.length * 3.8;
                doc.setFillColor(255, 244, 232); doc.setDrawColor(...WARN); doc.setLineWidth(0.3);
                doc.roundedRect(M, y, W - 2 * M, h, 2, 2, 'FD');
                doc.setTextColor(...WARN); doc.setFont('helvetica', 'bold'); doc.text('Revisar:', M + 3, y + 4.5);
                doc.setFont('helvetica', 'normal');
                ls.forEach((ln, i) => doc.text(ln, M + 3, y + 8.5 + i * 3.8));
                y += h + 4;
            }
            y += 2;

            // Productos: tabla(s) con bordes; en dos tablas, primero la de la izquierda y luego la de la derecha.
            let col = 0, top = y, leftEnd = 0;
            tableHead(0);
            // Nota del producto (Extractor → ✏ Editar lista): completa, en una franja bajo su fila
            const noteLines = (l) => { doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5);
                return l.note ? l.note.split(/\n/).map(clean).filter(Boolean).flatMap((p, j) => doc.splitTextToSize((j ? '' : 'Nota: ') + p, TW - 4)) : []; };
            for (const l of lines) {
                const nl = noteLines(l), NH = nl.length ? nl.length * 3.3 + 2 : 0;
                if (y + ROW + NH > H - FOOT) {
                    if (two && col === 0) { col = 1; leftEnd = y; y = top; }
                    else { newPage(); col = 0; top = y; leftEnd = 0; }
                    tableHead(col);
                }
                const img = l.photo ? imgs.get(l.photo.id) : null;
                doc.setDrawColor(...LINE); doc.setLineWidth(0.35);
                const mid = y + ROW / 2;
                let x = M + col * (TW + GAP);
                cols.forEach((c, i) => {
                    const w = COLS[i], cx = x + w / 2;
                    doc.rect(x, y, w, ROW, 'S');
                    if (c.key === 'cN') {
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...GREY);
                        doc.text(String(l.n), cx, mid + 1.1, { align: 'center' });
                    } else if (c.key === 'cPhoto') {
                        const pw = w - 3, ph = ROW - 3;
                        if (img) {
                            const s = Math.min(pw / img.w, ph / img.h), iw = img.w * s, ih = img.h * s;
                            doc.addImage(img.data, 'JPEG', x + 1.5 + (pw - iw) / 2, y + 1.5 + (ph - ih) / 2, iw, ih, undefined, 'FAST');
                            // Tocar la foto abre esa misma foto en Google Drive (enlace oculto, como en el Catálogo en PDF)
                            doc.link(x, y, w, ROW, { url: 'https://drive.google.com/file/d/' + encodeURIComponent(l.photo.id) + '/view' });
                        } else {
                            doc.setFillColor(246, 246, 246); doc.rect(x + 1.5, y + 1.5, pw, ph, 'F');
                            doc.setFont('helvetica', 'normal'); doc.setFontSize(6.5); doc.setTextColor(...GREY);
                            doc.text(['Foto no', 'disponible'], cx, mid - 0.4, { align: 'center' });
                        }
                    } else if (c.key === 'cCode') {
                        const nm = nameOf(l), big = ROW > 10;
                        if (nm) {
                            // Nombre (hasta 2 líneas, o 1 sin foto) encima del código, con «…» si no cabe; sin encimarse
                            const nPt = big ? 7 : 6, nLh = nPt * 0.3528 * 1.2, maxL = big ? 2 : 1;
                            doc.setFont('helvetica', 'normal'); doc.setFontSize(nPt); doc.setTextColor(70, 70, 70);
                            let nl = doc.splitTextToSize(nm, w - 3);
                            if (nl.length > maxL) {
                                nl = nl.slice(0, maxL);
                                let last = nl[maxL - 1];
                                while (last.length && doc.getTextWidth(last + '…') > w - 3) last = last.slice(0, -1);
                                nl[maxL - 1] = last.replace(/\s+$/, '') + '…';
                            }
                            const cPt = big ? 10.5 : 8.5, cH = cPt * 0.3528;
                            const blockH = nl.length * nLh + 0.8 + cH;
                            let ny = mid - blockH / 2 + nLh * 0.8;
                            nl.forEach((ln) => { doc.text(ln, cx, ny, { align: 'center' }); ny += nLh; });
                            doc.setFont('helvetica', 'bold'); doc.setFontSize(cPt); doc.setTextColor(...INK);
                            doc.text(fitText(clean(l.code), w - 3), cx, mid + blockH / 2, { align: 'center' });
                        } else {
                            doc.setFont('helvetica', 'bold'); doc.setFontSize(big ? 11 : 9.5); doc.setTextColor(...INK);
                            const cl = doc.splitTextToSize(clean(l.code), w - 4).slice(0, big ? 2 : 1);
                            doc.text(cl, cx, mid + 1.4 - (cl.length - 1) * 2.3, { align: 'center' });
                        }
                    } else if (c.key === 'cDesc') {
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...INK);
                        const dl = doc.splitTextToSize(clean(l.desc), w - 4).slice(0, ROW > 10 ? 3 : 1);
                        doc.text(dl, x + 2, mid + 1 - (dl.length - 1) * 1.6);
                    } else if (c.key === 'cQty') {
                        doc.setFont('helvetica', 'bold'); doc.setFontSize(l.qty == null ? 7.5 : (ROW > 10 ? 12 : 10)); doc.setTextColor(...(l.qty == null ? WARN : INK));
                        doc.text(l.qty == null ? 'sin cant.' : String(l.qty), cx, mid + 1.4, { align: 'center' });
                    } else {
                        const t = c.key === 'cUnit' ? l.unit : l.total;
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...INK);
                        doc.text(t ? clean(t) : '', x + w - 2, mid + 1.2, { align: 'right' });
                    }
                    x += w;
                });
                if (NH) {
                    const nx = M + col * (TW + GAP);
                    doc.setFillColor(248, 246, 240); doc.setDrawColor(...LINE); doc.setLineWidth(0.35); doc.rect(nx, y + ROW, TW, NH, 'FD');
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); doc.setTextColor(70, 70, 70);
                    doc.text(nl, nx + 2, y + ROW + 3.4);
                }
                y += ROW + NH;
            }

            // Remisión: el valor total también al final, debajo de la tabla (tal cual viene en el PDF)
            if (type === 'remision' && has(order.totalText)) {
                let ty = Math.max(y, col === 1 ? leftEnd : 0) + 4;
                if (ty + 12 > H - FOOT) { newPage(); ty = y; }
                const bw = 95, bx = W - M - bw;
                doc.setFillColor(...C1); doc.roundedRect(bx, ty, bw, 12, 1.5, 1.5, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(9.5); doc.setTextColor(...C2);
                doc.text('TOTAL COTIZACIÓN', bx + 4, ty + 7.6);
                doc.setFontSize(13); doc.setTextColor(255, 255, 255);
                doc.text(clean(order.totalText), bx + bw - 4, ty + 8, { align: 'right' });
                y = ty + 12; if (col === 1) leftEnd = 0;
            }

            // Cotización: notas generales, completas, al final (siguen en otra página si no caben)
            const gNotes = rem && has(order.notes) ? String(order.notes).split(/\n/).map(clean).filter(Boolean) : [];
            if (gNotes.length) {
                doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
                const gl = gNotes.flatMap((p) => doc.splitTextToSize(p, W - 2 * M - 6));
                let ny = Math.max(y, col === 1 ? leftEnd : 0) + 6;
                if (ny + 12 > H - FOOT) { newPage(); ny = y; }
                doc.setFont('helvetica', 'bold'); doc.setFontSize(9.5); doc.setTextColor(...INK); doc.text('NOTAS', M, ny); ny += 2;
                doc.setDrawColor(...C2); doc.setLineWidth(0.4); doc.line(M, ny, M + 18, ny); ny += 4.5;
                doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...INK);
                gl.forEach((ln) => { if (ny + 2 > H - FOOT) { newPage(); ny = y; doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...INK); } doc.text(ln, M + 1, ny); ny += 4; });
            }

            // Pie en cada página
            const pages = doc.getNumberOfPages();
            const footL = clean([show.number && order.number, show.client && order.client, show.date && order.date].filter(has).join(' · '));
            for (let p = 1; p <= pages; p++) {
                doc.setPage(p);
                if (rem && nFoot) { // pie de la plantilla: dirección (negrita), contacto, correo y marcas
                    const fy = H - FOOT + 3;
                    doc.setDrawColor(...C2); doc.setLineWidth(0.5); doc.line(M, fy, W - M, fy);
                    let ly = fy + 4.6;
                    footLines.forEach((t, i) => {
                        doc.setFont('helvetica', i === 0 ? 'bold' : 'normal'); doc.setFontSize(8.5); doc.setTextColor(...INK);
                        doc.text(doc.splitTextToSize(clean(t), W - 2 * M - 16)[0], M, ly); ly += 3.7;
                    });
                    if (brands) { doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...GREY); doc.text(doc.splitTextToSize(clean(brands), W - 2 * M - 16)[0], M, ly); }
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...INK);
                    doc.text(`${p}/${pages}`, W - M, H - 5.5, { align: 'right' });
                    continue;
                }
                doc.setDrawColor(...C2); doc.setLineWidth(0.4); doc.line(M, H - 10, W - M, H - 10);
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...GREY);
                if (footL) doc.text(doc.splitTextToSize(footL, W - 2 * M - 20)[0], M, H - 5.5);
                doc.text(`${p} / ${pages}`, W - M, H - 5.5, { align: 'right' });
            }

            const blob = doc.output('blob');
            pdfFile = new File([blob], fileName(), { type: 'application/pdf' });
            const noPhoto = lines.filter((l) => !l.photo || !imgs.get(l.photo.id)).length;
            msg(`✅ PDF listo: ${lines.length} referencia${lines.length === 1 ? '' : 's'}, ${pages} página${pages === 1 ? '' : 's'} · ${KP.mb(blob.size)}.`
                + (show.cPhoto && noPhoto ? ` ${noPhoto} sin foto (salen con «Foto no disponible»).` : '')
                + (failed ? ` ${failed} foto${failed === 1 ? '' : 's'} no bajó.` : ''));
            $('olShare').hidden = !KP.canShareFiles;
            $('olDown').hidden = false;
            KP.setName(NAMES, pdfFile.name); $('olNameRow').hidden = false;
            btn.textContent = PREVIEW;
            await showPreview();
        } catch (e) {
            msg('No se pudo generar el PDF: ' + (e && e.message ? e.message : e));
            btn.textContent = PREVIEW;
        } finally {
            busy = false; btn.disabled = false;
        }
    }

    // ---- Previo: el PDF ya armado, pintado página por página con pdf.js (funciona igual en celular y computador) ----
    let pdfjs = null, pvToken = 0;
    async function showPreview() {
        if (!pdfFile) return;
        const box = $('olPv'), pages = $('olPvPages');
        $('olPvTitle').textContent = '👁 Previo: ' + T().name;
        $('olPvShare').hidden = !KP.canShareFiles;
        pages.innerHTML = '<p>Cargando el previo…</p>';
        box.hidden = false; document.body.style.overflow = 'hidden';
        const my = ++pvToken;
        try {
            if (!pdfjs) {
                pdfjs = await import('/assets/vendor/pdfjs/pdf.min.js');
                pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';
            }
            const doc = await pdfjs.getDocument({ data: new Uint8Array(await pdfFile.arrayBuffer()) }).promise;
            if (my !== pvToken) return;
            pages.innerHTML = '';
            const width = Math.min(820, pages.clientWidth - 32) * (window.devicePixelRatio || 1);
            for (let n = 1; n <= doc.numPages; n++) {
                const page = await doc.getPage(n);
                if (my !== pvToken) return;
                const vp = page.getViewport({ scale: 1 });
                const v = page.getViewport({ scale: width / vp.width });
                const cv = document.createElement('canvas');
                cv.width = v.width; cv.height = v.height;
                pages.appendChild(cv);
                await page.render({ canvasContext: cv.getContext('2d'), viewport: v }).promise;
            }
        } catch (e) {
            pages.innerHTML = '<p>No se pudo mostrar el previo (' + esc(e && e.message ? e.message : e) + '). Puedes descargarlo igual.</p>';
        }
    }
    const closePreview = () => { pvToken++; $('olPv').hidden = true; $('olPvPages').innerHTML = ''; document.body.style.overflow = ''; };
    $('olPvClose').addEventListener('click', closePreview);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('olPv').hidden) closePreview(); });
    // Nombre del archivo: se puede cambiar antes de Descargar / Enviar (casilla en la barra y en el previo)
    const NAMES = [$('olName'), $('olPvName')];
    KP.linkNames(NAMES);
    const outFile = () => KP.named(pdfFile, $('olName'));
    $('olPvDown').addEventListener('click', () => KP.download(outFile(), msg));
    $('olPvShare').addEventListener('click', () => KP.share(outFile(), T().title, msg));

    // «Previo» arma el PDF (si cambió algo) y lo muestra; desde ahí se descarga o se envía
    $('olMake').addEventListener('click', () => { if (pdfFile && !busy) showPreview(); else make(); });
    $('olShare').addEventListener('click', () => KP.share(outFile(), T().title, msg));
    $('olDown').addEventListener('click', () => KP.download(outFile(), msg));

    lookup().then(async () => {
        try { await loadNames(); } catch (e) { /* sin nombres: sale solo el código */ }
        drawList();
        const none = lines.filter((l) => !l.photo).length;
        $('olMake').disabled = false; $('olMake').textContent = PREVIEW;
        msg(none ? `${none} de ${lines.length} referencia${lines.length === 1 ? '' : 's'} sin foto: salen igual con «Foto no disponible».` : 'Todas las referencias tienen foto.');
        lines.slice(0, 12).forEach((l) => { if (l.photo) KP.prepare(l.photo.id, 300).catch(() => {}); });
    }).catch((e) => {
        msg('⚠️ No se pudieron buscar las fotos (' + (e && e.message ? e.message : e) + '). Recarga la página para intentar otra vez.');
        $('olMake').textContent = PREVIEW;
    });
})();
