/**
 * Lista pedido (/lista-pedido): PDF del pedido leído en el Extractor (sessionStorage kinoPedido).
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
    const msg = (t) => { $('olMsg').textContent = t || ''; };

    let data = null, pdfFile = null;
    try { data = JSON.parse(sessionStorage.getItem('kinoPedido') || 'null'); } catch (e) { data = null; }
    if (!data || !Array.isArray(data.items) || !data.items.length) { $('olEmpty').hidden = false; return; }

    const order = data.order || {};
    // Cada línea del pedido, en el orden del Extractor; la foto se le pone a esta misma línea (por su código)
    const lines = data.items.map((it, i) => ({ n: i + 1, code: String(it.code || ''), qty: it.qty == null ? null : Number(it.qty), photo: undefined }));
    const has = (v) => v !== null && v !== undefined && String(v).trim() !== '';

    // Datos del encabezado: solo lo que vino del PDF. Si el PDF no trae el total de ítems o de unidades (p. ej. la
    // cotización en tabla), se puede usar lo contado en sus líneas, marcado «(contado)»: es un dato del PDF, no inventado.
    const counted = { items: lines.length, units: order.noQty ? null : lines.reduce((s, l) => s + (l.qty || 0), 0) };
    const allFields = [
        { key: 'number', label: 'N.º de pedido', v: order.number },
        { key: 'client', label: 'Cliente', v: order.client },
        { key: 'date', label: 'Fecha del pedido', v: order.date },
        { key: 'items', label: 'Total de ítems', v: order.itemsPdf, count: counted.items },
        { key: 'units', label: 'Unidades', v: order.noQty ? null : order.unitsPdf, count: counted.units },
        { key: 'total', label: 'Valor total', v: order.totalText },
    ];
    // Qué sale en el PDF: lo elige el usuario y se recuerda en este dispositivo
    const PREF = 'kinoOlFields';
    let show = { number: true, client: true, date: true, items: true, units: true, total: true, review: true, useCount: true };
    try { Object.assign(show, JSON.parse(localStorage.getItem(PREF) || '{}')); } catch (e) { /* sin preferencias */ }
    const savePrefs = () => { try { localStorage.setItem(PREF, JSON.stringify(show)); } catch (e) { /* sin almacenamiento */ } };
    // Valor que se usa: el del PDF; si falta y hay conteo (y se eligió usarlo), el conteo con «(contado)»
    const valueOf = (f) => has(f.v) ? String(f.v) : (show.useCount && f.count != null ? f.count + ' (contado)' : null);
    const canCount = allFields.some((f) => !has(f.v) && f.count != null);
    let fields = [], problems = [];
    function refresh() {
        fields = allFields.filter((f) => show[f.key]).map((f) => [f.label, valueOf(f)]);
        problems = [];
        if (show.review) {
            fields.forEach(([k, v]) => { if (k !== 'N.º de pedido' && !has(v)) problems.push(k + ': no se pudo leer del PDF; no se inventó un valor.'); });
            if (order.noQty) problems.push('El PDF no trae cantidades: cada referencia sale como «sin cantidad».');
            else if (lines.some((l) => l.qty == null)) problems.push('Alguna referencia no trae cantidad en el PDF.');
            (data.warnings || []).forEach((w) => { if (!problems.includes(w)) problems.push('Extractor: ' + w); });
        }
    }
    refresh();

    function drawOrder() {
        $('olOrder').innerHTML = allFields.map((f) => {
            const v = valueOf(f);
            return '<label class="ol-field' + (show[f.key] ? '' : ' off') + '"><span><input type="checkbox" data-k="' + f.key + '"' + (show[f.key] ? ' checked' : '') + '> ' + esc(f.label) + '</span>' +
                (has(v) ? esc(v) : (f.key === 'number' ? '—' : '<b class="ol-miss">⚠ ' + MISSING + '</b>')) + '</label>';
        }).join('');
        $('olOpts').innerHTML = '<label><input type="checkbox" data-k="review"' + (show.review ? ' checked' : '') + '> Recuadro «Revisar» con avisos</label>' +
            (canCount ? '<label><input type="checkbox" data-k="useCount"' + (show.useCount ? ' checked' : '') + '> Si el PDF no trae ítems o unidades, usar lo contado en sus líneas</label>' : '');
        const warnBox = $('olWarn');
        warnBox.hidden = !problems.length;
        warnBox.innerHTML = problems.length ? '<strong>Revisar</strong><ul>' + problems.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul>' : '';
    }
    drawOrder();
    $('olBody').addEventListener('change', (e) => {
        const k = e.target && e.target.dataset ? e.target.dataset.k : null;
        if (!k) return;
        show[k] = e.target.checked;
        savePrefs(); refresh(); drawOrder();
        if (pdfFile) { pdfFile = null; $('olShare').hidden = true; $('olDown').hidden = true; $('olMake').textContent = '📄 Generar PDF'; msg('Cambiaste lo que sale en el PDF: vuelve a generarlo.'); }
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
    const C1 = [28, 28, 30], C2 = [201, 168, 76], INK = [30, 30, 30], GREY = [110, 110, 110], WARN = [190, 90, 20];
    const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    const fileName = () => 'Lista_pedido_' + ((KP.clean(order.number || order.client).replace(/[^\wÁÉÍÓÚÜÑáéíóúüñ -]+/g, '').trim().replace(/\s+/g, '_')) || today()) + '.pdf';

    async function make() {
        if (busy) return;
        if (!window.jspdf || !window.jspdf.jsPDF) { msg('El generador de PDF no cargó. Revisa la conexión y recarga la página.'); return; }
        busy = true;
        const btn = $('olMake');
        btn.disabled = true; $('olShare').hidden = true; $('olDown').hidden = true; pdfFile = null;
        try {
            // 1) Fotos (una vez por foto, 4 a la vez); la que no baje queda como «Foto no disponible»
            const ids = [...new Set(lines.filter((l) => l.photo).map((l) => l.photo.id))];
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
            const W = 210, H = 297, M = 12, BAND = 16, ROW = 19, FOOT = 13, TH = 8.5;
            // Dos tablas lado a lado, cada una con columnas como la proforma de muestra: N.º | Foto | Código | Cantidad
            const GAP = 4, TW = (W - 2 * M - GAP) / 2;
            const COLS = [8, 24, TW - 8 - 24 - 21, 21];
            const clean = KP.clean;
            const val = (v) => has(v) ? clean(v) : null;

            let y = 0, page = 0;
            const band = () => {
                doc.setFillColor(...C1); doc.rect(0, 0, W, BAND, 'F');
                doc.setFillColor(...C2); doc.rect(0, BAND, W, 1, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(255, 255, 255);
                doc.text('Lista de pedido', M, 10.5);
                if (show.number && has(order.number)) { doc.setFontSize(11); doc.setTextColor(...C2); doc.text(clean(order.number), W - M, 10.5, { align: 'right' }); }
            };
            const tableHead = (c) => {
                const x0 = M + c * (TW + GAP);
                doc.setFillColor(...C1); doc.setDrawColor(40, 40, 40); doc.setLineWidth(0.35);
                let x = x0;
                COLS.forEach((w) => { doc.rect(x, y, w, TH, 'FD'); x += w; });
                doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.setTextColor(255, 255, 255);
                x = x0;
                ['N.º', 'Foto', 'Código', 'Cantidad'].forEach((t, i) => { doc.text(t, x + COLS[i] / 2, y + 5.6, { align: 'center' }); x += COLS[i]; });
                y += TH;
            };
            const newPage = () => { if (page) doc.addPage(); page++; band(); y = BAND + 7; };

            // Encabezado del pedido (primera página)
            newPage();
            const cells = fields.filter(([k]) => k !== 'N.º de pedido');
            const colW = (W - 2 * M) / 2;
            cells.forEach(([k, v], i) => {
                const x = M + (i % 2) * colW, yy = y + Math.floor(i / 2) * 13;
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...GREY);
                doc.text(k.toUpperCase(), x, yy + 3);
                const s = val(v);
                doc.setFont('helvetica', 'bold'); doc.setFontSize(s ? 12.5 : 10.5); doc.setTextColor(...(s ? INK : WARN));
                doc.text(doc.splitTextToSize(s || MISSING, colW - 4)[0], x, yy + 9);
            });
            if (cells.length) y += Math.ceil(cells.length / 2) * 13 + 2;
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

            // Productos en dos tablas lado a lado (primero la de la izquierda, luego la de la derecha), así caben ~26 por
            // página. Cada fila: N.º | Foto | Código | Cantidad, con bordes.
            let col = 0, top = y;
            tableHead(0);
            for (const l of lines) {
                if (y + ROW > H - FOOT) {
                    if (col === 0) { col = 1; y = top; }
                    else { newPage(); col = 0; top = y; }
                    tableHead(col);
                }
                const x0 = M + col * (TW + GAP);
                const xN = x0, xPh = xN + COLS[0], xCode = xPh + COLS[1], xQty = xCode + COLS[2];
                const img = l.photo ? imgs.get(l.photo.id) : null;
                doc.setDrawColor(40, 40, 40); doc.setLineWidth(0.35);
                let x = x0;
                COLS.forEach((w) => { doc.rect(x, y, w, ROW, 'S'); x += w; });
                const mid = y + ROW / 2;
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...GREY);
                doc.text(String(l.n), xN + COLS[0] / 2, mid + 1.1, { align: 'center' });
                const pw = COLS[1] - 3, ph = ROW - 3;
                if (img) {
                    const s = Math.min(pw / img.w, ph / img.h), iw = img.w * s, ih = img.h * s;
                    doc.addImage(img.data, 'JPEG', xPh + 1.5 + (pw - iw) / 2, y + 1.5 + (ph - ih) / 2, iw, ih, undefined, 'FAST');
                } else {
                    doc.setFillColor(246, 246, 246); doc.rect(xPh + 1.5, y + 1.5, pw, ph, 'F');
                    doc.setFontSize(6.5); doc.setTextColor(...GREY);
                    doc.text(['Foto no', 'disponible'], xPh + COLS[1] / 2, mid - 0.4, { align: 'center' });
                }
                doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(...INK);
                const cl = doc.splitTextToSize(clean(l.code), COLS[2] - 4).slice(0, 2);
                doc.text(cl, xCode + COLS[2] / 2, mid + 1.4 - (cl.length - 1) * 2.3, { align: 'center' });
                doc.setFontSize(l.qty == null ? 7.5 : 12); doc.setTextColor(...(l.qty == null ? WARN : INK));
                doc.text(l.qty == null ? 'sin cant.' : String(l.qty), xQty + COLS[3] / 2, mid + 1.6, { align: 'center' });
                y += ROW;
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
                + (noPhoto ? ` ${noPhoto} sin foto (salen con «Foto no disponible»).` : '')
                + (failed ? ` ${failed} foto${failed === 1 ? '' : 's'} no bajó.` : ''));
            $('olShare').hidden = !KP.canShareFiles;
            $('olDown').hidden = false;
            btn.textContent = '🔄 Rehacer';
        } catch (e) {
            msg('No se pudo generar el PDF: ' + (e && e.message ? e.message : e));
            btn.textContent = '📄 Generar PDF';
        } finally {
            busy = false; btn.disabled = false;
        }
    }

    $('olMake').addEventListener('click', make);
    $('olShare').addEventListener('click', () => KP.share(pdfFile, 'Lista de pedido', msg));
    $('olDown').addEventListener('click', () => KP.download(pdfFile, msg));

    lookup().then(() => {
        drawList();
        const none = lines.filter((l) => !l.photo).length;
        $('olMake').disabled = false; $('olMake').textContent = '📄 Generar PDF';
        msg(none ? `${none} de ${lines.length} referencia${lines.length === 1 ? '' : 's'} sin foto: salen igual con «Foto no disponible».` : 'Todas las referencias tienen foto.');
        lines.slice(0, 12).forEach((l) => { if (l.photo) KP.prepare(l.photo.id, 300).catch(() => {}); });
    }).catch((e) => {
        msg('⚠️ No se pudieron buscar las fotos (' + (e && e.message ? e.message : e) + '). Recarga la página para intentar otra vez.');
        $('olMake').textContent = '📄 Generar PDF';
    });
})();
