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

    let data = null;
    try { data = JSON.parse(sessionStorage.getItem('kinoPedido') || 'null'); } catch (e) { data = null; }
    if (!data || !Array.isArray(data.items) || !data.items.length) { $('olEmpty').hidden = false; return; }

    const order = data.order || {};
    // Cada línea del pedido, en el orden del Extractor; la foto se le pone a esta misma línea (por su código)
    const lines = data.items.map((it, i) => ({ n: i + 1, code: String(it.code || ''), qty: it.qty == null ? null : Number(it.qty), photo: undefined }));
    const has = (v) => v !== null && v !== undefined && String(v).trim() !== '';

    // Datos del encabezado: solo lo que vino del PDF
    const fields = [
        ['Cliente', order.client],
        ['Fecha del pedido', order.date],
        ['Total de ítems', order.itemsPdf],
        ['Unidades', order.noQty ? null : order.unitsPdf],
        ['Valor total', order.totalText],
    ];
    if (has(order.number)) fields.unshift(['N.º de pedido', order.number]);
    const problems = [];
    fields.forEach(([k, v]) => { if (!has(v)) problems.push(k + ': no se pudo leer del PDF; no se inventó un valor.'); });
    if (order.noQty) problems.push('El PDF no trae cantidades: cada referencia sale como «sin cantidad».');
    else if (lines.some((l) => l.qty == null)) problems.push('Alguna referencia no trae cantidad en el PDF.');
    (data.warnings || []).forEach((w) => { if (!problems.includes(w)) problems.push('Extractor: ' + w); });

    $('olOrder').innerHTML = fields.map(([k, v]) => '<div><span>' + esc(k) + '</span>' +
        (has(v) ? esc(v) : '<b class="ol-miss">⚠ ' + MISSING + '</b>') + '</div>').join('');
    const warnBox = $('olWarn');
    if (problems.length) {
        warnBox.hidden = false;
        warnBox.innerHTML = '<strong>Revisar</strong><ul>' + problems.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul>';
    }
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
    let pdfFile = null, busy = false;
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
                    try { imgs.set(id, await KP.prepare(id, 320)); } catch (e) { imgs.set(id, null); failed++; }
                    done++;
                    btn.textContent = `⏳ Fotos ${done}/${ids.length}`;
                }
            };
            await Promise.all([worker(), worker(), worker(), worker()]);

            // 2) Medidas (mm)
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true });
            const W = 210, H = 297, M = 12, BAND = 16, ROW = 30, PH = 26, FOOT = 14, TH = 8;
            const cN = M + 2, cPh = M + 12, cCode = cPh + PH + 6, cQty = W - M - 2;
            const clean = KP.clean;
            const val = (v) => has(v) ? clean(v) : null;

            let y = 0, page = 0;
            const band = () => {
                doc.setFillColor(...C1); doc.rect(0, 0, W, BAND, 'F');
                doc.setFillColor(...C2); doc.rect(0, BAND, W, 1, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(255, 255, 255);
                doc.text('Lista de pedido', M, 10.5);
                if (has(order.number)) { doc.setFontSize(11); doc.setTextColor(...C2); doc.text(clean(order.number), W - M, 10.5, { align: 'right' }); }
            };
            const tableHead = () => {
                doc.setFillColor(242, 242, 242); doc.rect(M, y, W - 2 * M, TH, 'F');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(...GREY);
                doc.text('N.º', cN, y + 5.4); doc.text('Foto', cPh, y + 5.4); doc.text('Código', cCode, y + 5.4);
                doc.text('Cantidad', cQty, y + 5.4, { align: 'right' });
                y += TH + 1;
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
            y += Math.ceil(cells.length / 2) * 13 + 2;
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
            doc.setDrawColor(...C2); doc.setLineWidth(0.5); doc.line(M, y, W - M, y);
            y += 4;
            tableHead();

            // Productos
            for (const l of lines) {
                if (y + ROW > H - FOOT) { newPage(); tableHead(); }
                const img = l.photo ? imgs.get(l.photo.id) : null;
                const cy = y + (ROW - PH) / 2;
                doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...GREY);
                doc.text(String(l.n), cN, y + ROW / 2 + 1.5);
                doc.setDrawColor(225, 225, 225); doc.setLineWidth(0.25); doc.roundedRect(cPh, cy, PH, PH, 1.5, 1.5, 'S');
                if (img) {
                    const s = Math.min((PH - 2) / img.w, (PH - 2) / img.h), iw = img.w * s, ih = img.h * s;
                    doc.addImage(img.data, 'JPEG', cPh + (PH - iw) / 2, cy + (PH - ih) / 2, iw, ih, undefined, 'FAST');
                } else {
                    doc.setFillColor(246, 246, 246); doc.roundedRect(cPh + 0.3, cy + 0.3, PH - 0.6, PH - 0.6, 1.5, 1.5, 'F');
                    doc.setFontSize(7.5); doc.setTextColor(...GREY);
                    doc.text(['Foto no', 'disponible'], cPh + PH / 2, cy + PH / 2 - 0.8, { align: 'center' });
                }
                doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(...INK);
                doc.text(doc.splitTextToSize(clean(l.code), cQty - 30 - cCode)[0] || '', cCode, y + ROW / 2 + 1.8);
                doc.setFontSize(l.qty == null ? 9 : 14); doc.setTextColor(...(l.qty == null ? WARN : INK));
                doc.text(l.qty == null ? 'sin cantidad' : String(l.qty), cQty, y + ROW / 2 + 2, { align: 'right' });
                doc.setDrawColor(235, 235, 235); doc.setLineWidth(0.2); doc.line(M, y + ROW, W - M, y + ROW);
                y += ROW;
            }

            // Pie en cada página
            const pages = doc.getNumberOfPages();
            const footL = clean([order.number, order.client, order.date].filter(has).join(' · '));
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
        lines.slice(0, 12).forEach((l) => { if (l.photo) KP.prepare(l.photo.id, 320).catch(() => {}); });
    }).catch((e) => {
        msg('⚠️ No se pudieron buscar las fotos (' + (e && e.message ? e.message : e) + '). Recarga la página para intentar otra vez.');
        $('olMake').textContent = '📄 Generar PDF';
    });
})();
