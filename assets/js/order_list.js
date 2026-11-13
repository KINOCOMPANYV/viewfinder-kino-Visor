/**
 * Lista Bodega / Remisión (/lista-pedido): PDF del documento leído en el Extractor (sessionStorage kinoPedido).
 * Recibo (pedido) → «Lista Bodega» negro y dorado; factura en tabla → «Remisión» azul (data.docType, se puede cambiar).
 * Encabezado con cliente, fecha, total de ítems, unidades y valor total TAL CUAL vienen del PDF (no se recalculan con
 * las fotos ni se pone la fecha de hoy); si un dato no se pudo leer, se dice. Luego cada línea del pedido, en el mismo
 * orden: foto, código y cantidad del PDF (solo lectura). Cada línea guarda su código y su foto juntos desde el
 * principio, así no se cruzan referencias. Sin foto → «Foto no disponible» (la línea sale igual).
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
        desc: String(it.desc || ''), price: String(it.priceText || ''), total: String(it.lineTotalText || ''), photo: undefined }));
    const has = (v) => v !== null && v !== undefined && String(v).trim() !== '';

    // Datos del encabezado: solo lo que vino del PDF. Si el PDF no trae el total de ítems o de unidades (p. ej. la
    // cotización en tabla), se puede usar lo contado en sus líneas, marcado «(contado)»: es un dato del PDF, no inventado.
    const counted = { items: lines.length, units: order.noQty ? null : lines.reduce((s, l) => s + (l.qty || 0), 0) };
    // Dos documentos: «Lista Bodega» (recibo = pedido) y «Remisión» (factura en tabla). El tipo lo decide el Extractor
    // según el formato del PDF; se puede cambiar a mano. Cada tipo tiene su título, sus colores y sus casillas guardadas.
    const TYPES = {
        pedido: { name: 'Lista Bodega', title: 'Lista Bodega', file: 'Lista_Bodega_', icon: '🧾', pref: 'kinoOlFields',
            numLabel: 'N.º de pedido', c1: [28, 28, 30], c2: [201, 168, 76],
            def: { number: true, client: true, date: true, items: true, units: true, total: true, nit: false, address: false, city: false, phone: false, seller: false,
                review: true, useCount: true, cN: true, cPhoto: true, cCode: true, cDesc: false, cQty: true, cPrice: false, cTotal: false } },
        remision: { name: 'Remisión', title: 'Remisión', file: 'Remision_', icon: '🚚', pref: 'kinoOlFieldsRemision',
            numLabel: 'N.º de cotización', c1: [31, 58, 95], c2: [110, 168, 220],
            def: { number: true, client: true, date: true, items: true, units: true, total: true, nit: true, address: true, city: true, phone: false, seller: false,
                review: true, useCount: true, cN: true, cPhoto: true, cCode: true, cDesc: false, cQty: true, cPrice: true, cTotal: true } },
    };
    let type = TYPES[data.docType] ? data.docType : 'pedido';
    const T = () => TYPES[type];
    const allFields = [
        { key: 'number', label: 'N.º', v: order.number },
        { key: 'client', label: 'Cliente', v: order.client },
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
        { key: 'cPrice', label: 'Precio', min: 24, avail: lines.some((l) => l.price) },
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
            '<small>' + (data.docType === 'remision' ? 'El PDF es una factura en tabla → Remisión.' : 'El PDF es un recibo (pedido) → Lista Bodega.') + '</small>';
    }
    drawType();

    function drawOrder() {
        $('olOrder').innerHTML = allFields.map((f) => {
            const v = valueOf(f);
            return '<label class="ol-field' + (show[f.key] ? '' : ' off') + '"><span><input type="checkbox" data-k="' + f.key + '"' + (show[f.key] ? ' checked' : '') + '> ' + esc(labelOf(f)) + '</span>' +
                (has(v) ? esc(v) : (f.key === 'number' ? '—' : '<b class="ol-miss">⚠ ' + MISSING + '</b>')) + '</label>';
        }).join('');
        $('olOpts').innerHTML = '<label><input type="checkbox" data-k="review"' + (show.review ? ' checked' : '') + '> Recuadro «Revisar» con avisos</label>' +
            '<div class="ol-cols"><b>Columnas de la tabla:</b> ' + allCols.map((c) => '<label><input type="checkbox" data-k="' + c.key + '"' + (show[c.key] ? ' checked' : '') + '> ' + esc(c.label) + '</label>').join('') + '</div>' +
            (canCount ? '<label><input type="checkbox" data-k="useCount"' + (show.useCount ? ' checked' : '') + '> Si el PDF no trae ítems o unidades, usar lo contado en sus líneas</label>' : '');
        const warnBox = $('olWarn');
        warnBox.hidden = !problems.length;
        warnBox.innerHTML = problems.length ? '<strong>Revisar</strong><ul>' + problems.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul>' : '';
    }
    drawOrder();
    const resetPdf = (why) => { if (pdfFile) { pdfFile = null; $('olShare').hidden = true; $('olDown').hidden = true; $('olMake').textContent = PREVIEW; msg(why); } };
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
        resetPdf('Cambiaste lo que sale en el PDF: vuelve a generarlo.');
    });
    $('olBody').hidden = false;
    $('olBar').hidden = false;

    function drawList() {
        $('olList').innerHTML = lines.map((l) => {
            const ph = l.photo === undefined ? '<span class="ph none">…</span>'
                : l.photo ? '<span class="ph"><img src="https://lh3.googleusercontent.com/d/' + encodeURIComponent(l.photo.id) + '=s200" alt="" loading="lazy"></span>'
                : '<span class="ph none">Foto no disponible</span>';
            return '<li><span class="n">' + l.n + '</span>' + ph + '<span class="cd">' + esc(l.code) +
                (l.photo && l.photo.sku !== l.code ? '<small>en el visor: ' + esc(l.photo.sku) + '</small>' : '') +
                '</span><span class="q">' + (l.qty == null ? 'sin cantidad' : '× ' + esc(l.qty)) + '</span></li>';
        }).join('');
    }
    drawList();

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
        btn.disabled = true; $('olShare').hidden = true; $('olDown').hidden = true; pdfFile = null;
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
            const C1 = T().c1, C2 = T().c2;
            const W = 210, H = 297, M = 12, BAND = 16, FOOT = 13, TH = 8.5;
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

            let y = 0, page = 0;
            const band = () => {
                doc.setFillColor(...C1); doc.rect(0, 0, W, BAND, 'F');
                doc.setFillColor(...C2); doc.rect(0, BAND, W, 1, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(255, 255, 255);
                doc.text(T().title, M, 10.5);
                if (show.number && has(order.number)) { doc.setFontSize(11); doc.setTextColor(...C2); doc.text(clean(order.number), W - M, 10.5, { align: 'right' }); }
            };
            const tableHead = (c) => {
                const x0 = M + c * (TW + GAP);
                doc.setFillColor(...C1); doc.setDrawColor(40, 40, 40); doc.setLineWidth(0.35);
                let x = x0;
                COLS.forEach((w) => { doc.rect(x, y, w, TH, 'FD'); x += w; });
                doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.setTextColor(255, 255, 255);
                x = x0;
                cols.map((c) => c.label).forEach((t, i) => { doc.text(t, x + COLS[i] / 2, y + 5.6, { align: 'center' }); x += COLS[i]; });
                y += TH;
            };
            const newPage = () => { if (page) doc.addPage(); page++; band(); y = BAND + 7; };

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
            for (const l of lines) {
                if (y + ROW > H - FOOT) {
                    if (two && col === 0) { col = 1; leftEnd = y; y = top; }
                    else { newPage(); col = 0; top = y; leftEnd = 0; }
                    tableHead(col);
                }
                const img = l.photo ? imgs.get(l.photo.id) : null;
                doc.setDrawColor(40, 40, 40); doc.setLineWidth(0.35);
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
                        } else {
                            doc.setFillColor(246, 246, 246); doc.rect(x + 1.5, y + 1.5, pw, ph, 'F');
                            doc.setFont('helvetica', 'normal'); doc.setFontSize(6.5); doc.setTextColor(...GREY);
                            doc.text(['Foto no', 'disponible'], cx, mid - 0.4, { align: 'center' });
                        }
                    } else if (c.key === 'cCode') {
                        doc.setFont('helvetica', 'bold'); doc.setFontSize(ROW > 10 ? 11 : 9.5); doc.setTextColor(...INK);
                        const cl = doc.splitTextToSize(clean(l.code), w - 4).slice(0, ROW > 10 ? 2 : 1);
                        doc.text(cl, cx, mid + 1.4 - (cl.length - 1) * 2.3, { align: 'center' });
                    } else if (c.key === 'cDesc') {
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...INK);
                        const dl = doc.splitTextToSize(clean(l.desc), w - 4).slice(0, ROW > 10 ? 3 : 1);
                        doc.text(dl, x + 2, mid + 1 - (dl.length - 1) * 1.6);
                    } else if (c.key === 'cQty') {
                        doc.setFont('helvetica', 'bold'); doc.setFontSize(l.qty == null ? 7.5 : (ROW > 10 ? 12 : 10)); doc.setTextColor(...(l.qty == null ? WARN : INK));
                        doc.text(l.qty == null ? 'sin cant.' : String(l.qty), cx, mid + 1.4, { align: 'center' });
                    } else {
                        const t = c.key === 'cPrice' ? l.price : l.total;
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...INK);
                        doc.text(t ? clean(t) : '', x + w - 2, mid + 1.2, { align: 'right' });
                    }
                    x += w;
                });
                y += ROW;
            }

            // Remisión: el valor total también al final, debajo de la tabla (tal cual viene en el PDF)
            if (type === 'remision' && has(order.totalText)) {
                let ty = Math.max(y, col === 1 ? leftEnd : 0) + 4;
                if (ty + 12 > H - FOOT) { newPage(); ty = y; }
                const bw = 95, bx = W - M - bw;
                doc.setFillColor(...C1); doc.roundedRect(bx, ty, bw, 12, 1.5, 1.5, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(9.5); doc.setTextColor(...C2);
                doc.text('TOTAL REMISIÓN', bx + 4, ty + 7.6);
                doc.setFontSize(13); doc.setTextColor(255, 255, 255);
                doc.text(clean(order.totalText), bx + bw - 4, ty + 8, { align: 'right' });
            }

            // Pie en cada página
            const pages = doc.getNumberOfPages();
            const footL = clean([show.number && order.number, show.client && order.client, show.date && order.date].filter(has).join(' · '));
            for (let p = 1; p <= pages; p++) {
                doc.setPage(p);
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
        $('olPvTitle').textContent = '👁 Previo: ' + T().name + ' · ' + pdfFile.name;
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
    $('olPvDown').addEventListener('click', () => KP.download(pdfFile, msg));
    $('olPvShare').addEventListener('click', () => KP.share(pdfFile, T().title, msg));

    // «Previo» arma el PDF (si cambió algo) y lo muestra; desde ahí se descarga o se envía
    $('olMake').addEventListener('click', () => { if (pdfFile && !busy) showPreview(); else make(); });
    $('olShare').addEventListener('click', () => KP.share(pdfFile, T().title, msg));
    $('olDown').addEventListener('click', () => KP.download(pdfFile, msg));

    lookup().then(() => {
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
