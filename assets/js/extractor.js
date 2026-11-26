// Pantalla del Extractor de pedidos PDF (/extractor). La lógica está en extractor_core.js; el PDF se lee aquí con pdf.js.
const V = new URL(import.meta.url).search; // misma versión (?v=) para el módulo de lógica
const core = await import('./extractor_core.js' + V);
const PDFJS = '/assets/vendor/pdfjs/pdf.min.js';
const WORKER = '/assets/vendor/pdfjs/pdf.worker.min.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

let file = null;
let result = null; // {items, order, warnings, map}
let rows = [];     // items en el orden mostrado
let pdfjs = null;
let fromPaste = false; // la lista viene de un listado pegado, no de un PDF

async function loadPdfjs() {
    if (!pdfjs) {
        pdfjs = await import(PDFJS);
        pdfjs.GlobalWorkerOptions.workerSrc = WORKER;
    }
    return pdfjs;
}

function setFile(f) {
    if (!f) return;
    if (!/pdf$/i.test(f.type) && !/\.pdf$/i.test(f.name)) { $('exStatus').textContent = '⚠️ Sube un PDF válido.'; return; }
    file = f;
    $('exFileName').textContent = '✅ ' + f.name + ' adjunto';
    $('exIcon').textContent = '📁';
    $('exDrop').classList.add('ok');
    $('exClear').hidden = false;
    $('exRun').disabled = false;
    $('exStatus').textContent = '';
}

$('exFile').addEventListener('change', (e) => setFile(e.target.files[0]));
const drop = $('exDrop');
['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('on'); }));
['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('on'); }));
drop.addEventListener('drop', (e) => setFile(e.dataTransfer.files[0]));
// Soltar el PDF en cualquier parte de la página también sirve
document.addEventListener('dragover', (e) => e.preventDefault());
document.addEventListener('drop', (e) => { if (!drop.contains(e.target)) { e.preventDefault(); setFile(e.dataTransfer.files[0]); } });

$('exRun').addEventListener('click', async () => {
    if (!file) return;
    const btn = $('exRun');
    btn.disabled = true; btn.textContent = '⏳ Procesando…';
    $('exStatus').textContent = '';
    try {
        const lib = await loadPdfjs();
        const data = new Uint8Array(await file.arrayBuffer());
        const { lines } = await core.readPdfLines(lib, data);
        if (!lines.length) throw new Error('El PDF no tiene texto (¿es una foto escaneada?). Prueba con «📷 Leer lista de una foto» en Enviar fotos con lista.');
        result = core.parseOrder(lines);
        if (!result.items.length) throw new Error('No se hallaron códigos ni cantidades en el PDF.');
        rows = result.items.map((it) => ({ ...it }));
        editing = false; edited = false; fromPaste = false;
        render();
        $('exClear').hidden = false;
        $('exResult').hidden = false;
        $('exResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        $('exResult').hidden = true;
        $('exStatus').textContent = '⚠️ ' + (e && e.message ? e.message : 'Error al procesar el PDF.');
    } finally {
        btn.disabled = false; btn.textContent = '🚀 Procesar PDF';
    }
});

// Listado pegado: arma el mismo resultado que un PDF (sin revisión contra el Total) y los precios que faltan salen del visor.
$('exPaste').addEventListener('input', () => { $('exPasteRun').disabled = !$('exPaste').value.trim(); $('exClear').hidden = false; });
$('exPasteRun').addEventListener('click', async () => {
    const { items, bad } = core.parsePastedList($('exPaste').value);
    const st = $('exPasteStatus');
    if (!items.length) { st.textContent = '⚠️ No se encontró ningún código. Escribe uno por línea, por ejemplo «M8291-1 3 45000».'; return; }
    const tabla = document.querySelector('input[name=exPasteType]:checked').value === 'remision';
    file = null; fromPaste = true; editing = false; edited = false;
    result = { items, warnings: [], map: items.map((it, i) => ({ page: 1, tag: '✓ pegado', text: it.code + ' × ' + it.qty + (it.priceText ? ' ' + it.priceText : '') })),
        order: { format: tabla ? 'tabla' : 'recibo', number: '', client: '', clientPdf: '', date: '', totalText: '', total: null, canCheck: false,
            unitPrices: true, noQty: false, itemsPdf: null, unitsPdf: null, itemsFound: items.length,
            unitsFound: items.reduce((s, it) => s + (it.qty || 0), 0), sumPrice: 0 } };
    rows = items.map((it) => ({ ...it }));
    st.textContent = bad.length ? '⚠️ Se saltaron ' + bad.length + ' línea(s) sin código: ' + bad.slice(0, 3).join(' | ') + (bad.length > 3 ? '…' : '') : '';
    const btn = $('exPasteRun'); btn.disabled = true; btn.textContent = '⏳ Buscando precios…';
    try { await fillVisorPrices(); } catch (e) { st.textContent += ' (No se pudieron traer los precios del visor.)'; }
    btn.disabled = false; btn.textContent = '🚀 Procesar listado';
    result.items = rows.map((it) => ({ ...it })); // «Volver a lo pegado» conserva los precios del visor
    render();
    $('exClear').hidden = false;
    $('exResult').hidden = false;
    $('exResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
});

// Precio del visor (público = SYS × multiplicador). Sin precio en el visor queda como estaba.
async function visorPrices(codes) {
    const r = await fetch('/enviar-catalogo/extraer', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CS_CSRF || '', 'Accept': 'application/json' },
        body: JSON.stringify({ codes: [...new Set(codes)] }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.photos) throw new Error('error ' + r.status);
    return j.photos;
}
// Pone el precio del visor en la línea (guarda el del PDF/escrito para poder volver). Devuelve true si había precio.
function setVisorPrice(it, photos) {
    const p = Object.prototype.hasOwnProperty.call(photos, it.code) ? photos[it.code] : null;
    if (!(p && p.price)) return false;
    if (!it.fromVisor) it.prev = { price: it.price, priceText: it.priceText, lineTotal: it.lineTotal, lineTotalText: it.lineTotalText };
    const st = core.priceStyle(it.prev && it.prev.priceText ? it.prev.priceText : ((rows.find((r) => r.priceText) || {}).priceText || '$0'));
    it.price = p.price; it.priceText = core.formatPrice(p.price, st); it.fromVisor = true;
    refreshLineTotal(it);
    return true;
}
function restorePrice(it) {
    if (!it.fromVisor) return;
    const b = it.prev || { price: null, priceText: '' };
    it.price = b.price; it.priceText = b.priceText || '';
    if (b.lineTotalText !== undefined) { it.lineTotal = b.lineTotal; it.lineTotalText = b.lineTotalText; } else refreshLineTotal(it);
    it.fromVisor = false; it.prev = null;
}
function refreshLineTotal(it) {
    if (it.lineTotalText !== undefined || (result && result.order.format === 'tabla')) {
        it.lineTotal = it.price != null && it.qty != null ? it.price * it.qty : null;
        it.lineTotalText = it.lineTotal == null ? '' : core.formatPrice(it.lineTotal, core.priceStyle(it.priceText));
    }
}
// Listado pegado: las líneas sin precio toman el del visor
async function fillVisorPrices() {
    const need = rows.filter((it) => it.price == null);
    if (!need.length) return;
    const photos = await visorPrices(need.map((it) => it.code));
    need.forEach((it) => setVisorPrice(it, photos));
}

$('exClear').addEventListener('click', () => {
    file = null; result = null; rows = []; editing = false; edited = false; fromPaste = false;
    $('exFile').value = ''; $('exPaste').value = ''; $('exPasteRun').disabled = true; $('exPasteStatus').textContent = '';
    $('exFileName').textContent = 'Arrastra tu PDF aquí o toca para elegirlo';
    $('exIcon').textContent = '📎';
    $('exDrop').classList.remove('ok');
    $('exRun').disabled = true;
    $('exClear').hidden = true;
    $('exResult').hidden = true;
    $('exStatus').textContent = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function render() {
    const { order, warnings, map } = result;
    if (order.clientPdf === undefined) order.clientPdf = order.client || ''; // lo leído del PDF, para volver a él
    // Revisión
    const rv = $('exReview');
    if (order.canCheck) {
        rv.hidden = false;
        rv.className = 'ex-review ' + (warnings.length ? 'bad' : 'ok');
        rv.innerHTML = warnings.length
            ? '<strong>Revisión: ' + warnings.length + ' punto' + (warnings.length > 1 ? 's' : '') + ' a revisar</strong><ul>' + warnings.map((w) => '<li>' + esc(w) + '</li>').join('') + '</ul>'
            : order.format === 'tabla'
                ? '<strong>Revisión: ✓ todo cuadra</strong>: cada línea (cantidad × precio) y la suma con el TOTAL del PDF. Este formato no indica cuántas referencias ni unidades trae.'
                : '<strong>Revisión: ✓ todo cuadra</strong> con el Total, los artículos y las unidades del PDF.';
    } else if (warnings.length) {
        rv.hidden = false; rv.className = 'ex-review bad';
        rv.innerHTML = '<strong>Revisión</strong><ul>' + warnings.map((w) => '<li>' + esc(w) + '</li>').join('') + '</ul>';
    } else rv.hidden = true;

    // El documento que corresponde al PDF sale resaltado; el otro queda disponible
    const isTabla = order.format === 'tabla';
    $('exToOrder').className = 'btn ' + (isTabla ? 'btn-secondary' : 'btn-primary');
    $('exToQuote').className = 'btn ' + (isTabla ? 'btn-primary' : 'btn-secondary');

    // Datos del pedido
    const mark = (ok) => ok == null ? '' : ok ? ' <span class="ex-ok">✓</span>' : ' <span class="ex-bad">✗</span>';
    const st = core.priceStyle(order.totalText || (result.items.find((i) => i.priceText) || {}).priceText || '$0');
    const cell = (label, val) => '<div><span>' + esc(label) + '</span>' + (val === '' || val == null ? '—' : val) + '</div>';
    $('exOrder').innerHTML = [
        cell('Formato', fromPaste ? 'Listado pegado' : order.format === 'tabla' ? 'Cotización en tabla' : 'Recibo'),
        cell('N.º de pedido', esc(order.number)),
        cell('Cliente', '<input type="text" class="ex-edit" id="exClient" maxlength="120" value="' + esc(order.client) + '" placeholder="Escribe el cliente">' +
            (order.clientPdf && order.client !== order.clientPdf ? '<small class="ex-note">Del PDF: ' + esc(order.clientPdf) + '</small>'
                : !order.clientPdf && order.client ? '<small class="ex-note">Escrito a mano</small>' : '')),
        cell('Fecha', esc(order.date)),
        cell('Total del PDF', esc(order.totalText)),
        cell('Suma de PRECIO' + (order.unitPrices ? ' (cantidad × precio)' : ''), order.noQty ? '' : esc(core.formatPrice(order.sumPrice || 0, st)) + mark(order.total != null ? order.totalOk : null)),
        cell('Referencias (PDF / extraídas)', (order.itemsPdf ?? '—') + ' / ' + order.itemsFound + mark(order.itemsPdf != null ? order.itemsPdf === order.itemsFound : null)),
        cell('Unidades (PDF / extraídas)', order.noQty ? '' : (order.unitsPdf ?? '—') + ' / ' + order.unitsFound + mark(order.unitsPdf != null ? order.unitsPdf === order.unitsFound : null)),
    ].join('');

    // Mapa
    let prevPage = null;
    $('exMap').innerHTML = map.map((m) => {
        const pb = prevPage !== null && m.page !== prevPage; prevPage = m.page;
        const warn = /^⚠/.test(m.tag);
        return '<li' + (pb ? ' class="pb"' : '') + '><i>p.' + m.page + '</i><span class="' + (warn ? 'w' : '') + '">' + esc(m.tag || '') + '</span><span>' + esc(m.text) + '</span></li>';
    }).join('');

    renderRows();
}

// Cliente escrito a mano: reemplaza al leído (casilla vacía = vuelve al del PDF). Pasa igual a Lista Bodega / Remisión.
$('exOrder').addEventListener('change', (e) => {
    if (!e.target || e.target.id !== 'exClient' || !result) return;
    const o = result.order;
    o.client = String(e.target.value || '').replace(/\s+/g, ' ').trim() || o.clientPdf || '';
    render();
});
$('exOrder').addEventListener('keydown', (e) => { if (e.target && e.target.id === 'exClient' && e.key === 'Enter') { e.preventDefault(); e.target.blur(); } });

const pct = () => { const n = Math.round(+$('exPct').value); return isNaN(n) ? 40 : Math.min(100, Math.max(0, n)); };
const discText = (it) => it.price == null ? '' : core.formatPrice(it.price * (1 - pct() / 100), core.priceStyle(it.priceText));

let editing = false; // «✏️ Editar lista»: código, cantidad y precio en casillas, ✕ por fila, ➕ Agregar línea
let edited = false;  // la lista ya no es la leída del PDF
const outRows = () => rows.filter((it) => it.code); // filas sin código (recién agregadas y vacías) no salen

function renderRows() {
    $('exDiscHead').textContent = 'PRECIO -' + pct() + '%';
    $('exTable').classList.toggle('ex-editing', editing);
    $('exRows').innerHTML = rows.map((it, i) => editing
        ? '<tr data-i="' + i + '"><td><input class="ex-cell" data-f="code" maxlength="60" value="' + esc(it.code) + '" placeholder="código"></td><td class="sep"></td>' +
          '<td class="num"><input class="ex-cell num" data-f="qty" type="number" min="0" step="1" inputmode="numeric" value="' + (it.qty ?? '') + '"></td><td class="c-u">UNIDAD</td><td class="sep"></td>' +
          '<td class="num c-price"><input class="ex-cell num" data-f="price" maxlength="30" value="' + esc(it.priceText) + '" placeholder="$0"><button type="button" class="ex-vp' + (it.fromVisor ? ' on' : '') + '" data-vp="' + i + '" title="' + (it.fromVisor ? 'Precio del visor (toca para volver al anterior)' : 'Usar el precio del visor') + '" aria-pressed="' + (it.fromVisor ? 'true' : 'false') + '">💲</button></td><td class="num c-disc">' + esc(discText(it)) + '</td>' +
          '<td class="c-x"><button type="button" class="ex-del" data-del="' + i + '" title="Quitar esta línea" aria-label="Quitar">✕</button></td></tr>' +
          '<tr class="ex-note-row" data-i="' + i + '"><td colspan="8"><textarea class="ex-cell ex-linenote" data-f="note" rows="1" maxlength="1000" placeholder="Nota de este producto (opcional, sale en la Cotización)">' + esc(it.note || '') + '</textarea></td></tr>'
        : '<tr><td>' + esc(it.code) + '</td><td class="sep"></td><td class="num">' + (it.qty ?? '') +
          '</td><td class="c-u">UNIDAD</td><td class="sep"></td><td class="num c-price">' + esc(it.priceText) + '</td><td class="num c-disc">' + esc(discText(it)) + '</td><td class="c-x"></td></tr>' +
          (it.note ? '<tr class="ex-note-row"><td colspan="8" class="ex-note-txt">📝 ' + esc(it.note) + '</td></tr>' : '')).join('');
    $('exAddRow').hidden = !editing;
    $('exVpBar').hidden = !editing;
    $('exEdit').textContent = editing ? '✅ Listo' : '✏️ Editar lista';
    $('exReset').hidden = !edited;
    renderSummary();
}

// Resumen de la lista tal como quedó (cuando se editó): referencias, unidades y suma de precios.
function renderSummary() {
    const out = outRows();
    $('exTotal').textContent = out.length;
    const box = $('exEditSum');
    if (!edited) { box.hidden = true; return; }
    const units = out.reduce((s, it) => s + (+it.qty || 0), 0);
    const ref = result.order.totalText || (out.find((it) => it.priceText) || {}).priceText || '$0';
    const unit = result.order.unitPrices; // el precio es por unidad (cotización en tabla): la suma es cantidad × precio
    const sum = out.reduce((s, it) => s + (it.price == null ? 0 : it.price * (unit ? (+it.qty || 0) : 1)), 0);
    box.hidden = false;
    box.innerHTML = '✏️ <b>Lista editada a mano</b>: ' + out.length + ' referencia' + (out.length === 1 ? '' : 's') + ' · ' + units + ' unidades' +
        (out.some((it) => it.price != null) ? ' · suma ' + esc(core.formatPrice(sum, core.priceStyle(ref))) : '') + '. Esto es lo que se copia y pasa a fotos y documentos.';
}

$('exEdit').addEventListener('click', () => {
    editing = !editing;
    if (!editing) rows = outRows(); // quita las líneas sin código
    renderRows();
});
$('exReset').addEventListener('click', () => {
    if (!confirm('¿Volver a la lista tal como se leyó del PDF? Se pierden los cambios a mano.')) return;
    rows = result.items.map((it) => ({ ...it }));
    edited = false;
    renderRows();
});
$('exAddRow').addEventListener('click', () => {
    rows.push({ code: '', qty: 1, priceText: '', price: null, desc: '', n: rows.length + 1000, added: true });
    edited = true;
    renderRows();
    const ins = $('exRows').querySelectorAll('input[data-f="code"]');
    if (ins.length) ins[ins.length - 1].focus();
});
$('exRows').addEventListener('click', (e) => {
    const b = e.target.closest('[data-del]');
    if (!b) return;
    rows.splice(+b.dataset.del, 1);
    edited = true;
    renderRows();
});
// 💲 por línea y para toda la lista: usar el precio del visor o volver al del PDF / escrito a mano
function vpMsg(t) { const m = $('exVpMsg'); m.textContent = t; if (t) setTimeout(() => { if (m.textContent === t) m.textContent = ''; }, 5000); }
$('exRows').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-vp]');
    if (!b) return;
    const it = rows[+b.dataset.vp];
    if (it.fromVisor) restorePrice(it);
    else {
        if (!it.code) return;
        try { if (!setVisorPrice(it, await visorPrices([it.code]))) { vpMsg('«' + it.code + '» no tiene precio en el visor.'); return; } }
        catch (err) { vpMsg('⚠️ No se pudo traer el precio del visor.'); return; }
    }
    edited = true; renderRows();
});
$('exVisorAll').addEventListener('click', async () => {
    const out = outRows(); if (!out.length) return;
    vpMsg('Trayendo precios del visor…');
    try {
        const photos = await visorPrices(out.map((it) => it.code));
        const none = out.filter((it) => !setVisorPrice(it, photos)).map((it) => it.code);
        edited = true; renderRows();
        vpMsg(none.length ? '✅ Listo. Sin precio en el visor (se dejó el que tenían): ' + none.slice(0, 10).join(', ') + (none.length > 10 ? '…' : '') : '✅ Todas las líneas con precio del visor.');
    } catch (err) { vpMsg('⚠️ No se pudo traer el precio del visor.'); }
});
$('exPdfAll').addEventListener('click', () => {
    if (!rows.some((it) => it.fromVisor)) { vpMsg('Ninguna línea usa el precio del visor.'); return; }
    rows.forEach(restorePrice); edited = true; renderRows(); vpMsg('Precios anteriores restaurados.');
});
// Al escribir se actualiza el dato (sin redibujar, para no perder el cursor); al salir de la casilla se limpia el texto.
$('exRows').addEventListener('input', (e) => {
    const inp = e.target, tr = inp.closest('tr[data-i]');
    if (!tr || !inp.dataset.f) return;
    const it = rows[+tr.dataset.i];
    if (inp.dataset.f === 'note') { // nota del producto: varias líneas, crece con el texto
        it.note = inp.value.slice(0, 1000); edited = true; $('exReset').hidden = false;
        inp.style.height = 'auto'; inp.style.height = inp.scrollHeight + 2 + 'px';
        return;
    }
    if (inp.dataset.f === 'code') { it.code = inp.value.replace(/\s+/g, '').toUpperCase(); it.desc = ''; }
    else if (inp.dataset.f === 'qty') { const q = parseInt(inp.value, 10); it.qty = isNaN(q) ? null : Math.max(0, q); }
    else { it.priceText = inp.value.trim(); it.price = core.readPrice(it.priceText); it.fromVisor = false; it.prev = null; const b = tr.querySelector('.ex-vp'); if (b) { b.classList.remove('on'); b.setAttribute('aria-pressed', 'false'); } }
    // Cotización en tabla: el total de la línea sigue a cantidad × precio
    if (it.lineTotalText !== undefined || result.order.format === 'tabla') {
        it.lineTotal = it.price != null && it.qty != null ? it.price * it.qty : null;
        it.lineTotalText = it.lineTotal == null ? '' : core.formatPrice(it.lineTotal, core.priceStyle(it.priceText));
    }
    edited = true;
    tr.querySelector('.c-disc').textContent = discText(it);
    $('exReset').hidden = false;
    renderSummary();
});
$('exRows').addEventListener('change', (e) => {
    const inp = e.target, tr = inp.closest('tr[data-i]');
    if (!tr || inp.dataset.f !== 'code') return;
    inp.value = rows[+tr.dataset.i].code;
});
$('exRows').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input.ex-cell')) { e.preventDefault(); e.target.blur(); } });

$('exShowPrice').addEventListener('change', (e) => $('exTable').classList.toggle('ex-hide-price', !e.target.checked));
$('exShowDisc').addEventListener('change', (e) => $('exTable').classList.toggle('ex-hide-disc', !e.target.checked));
$('exPct').addEventListener('input', () => { if (result) renderRows(); });

$('exSort').addEventListener('click', () => {
    rows.sort((a, b) => core.naturalCompare(a.code, b.code) || a.n - b.n);
    renderRows();
});

// Copia lo que se ve, en el orden mostrado, sin encabezados, separado por tabulaciones (las columnas vacías se mantienen).
$('exCopy').addEventListener('click', async () => {
    const showP = $('exShowPrice').checked, showD = $('exShowDisc').checked;
    const text = outRows().map((it) => {
        const cols = [it.code, '', it.qty ?? '', 'UNIDAD', ''];
        if (showP) cols.push(it.priceText);
        if (showD) cols.push(discText(it));
        return cols.join('\t');
    }).join('\n');
    let ok = false;
    try { await navigator.clipboard.writeText(text); ok = true; } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        ta.remove();
    }
    $('exCopyMsg').textContent = ok ? '✅ ¡Copiado! Pégalo en Excel o Google Sheets.' : '⚠️ No se pudo copiar en este navegador.';
    setTimeout(() => { $('exCopyMsg').textContent = ''; }, 3000);
});

// Pasa los códigos (sin repetir, en el orden mostrado) a Enviar catálogo, que busca sus fotos.
$('exToVisor').addEventListener('click', () => {
    const codes = [...new Set(outRows().map((it) => it.code))];
    try { sessionStorage.setItem('kinoCsText', codes.join('\n')); } catch (e) { alert('No se pudo pasar la lista en este dispositivo.'); return; }
    location.href = '/enviar-catalogo?desde=extractor';
});

// Lista pedido: pasa el pedido tal cual (datos del encabezado, y cada línea con su código y su cantidad, en el
// orden mostrado) a /lista-pedido, que busca la foto de cada código y arma el PDF.
function toDoc(docType) {
    const o = result.order;
    if (fromPaste && o.format === 'tabla') rows.forEach((it) => { // cotización: total de cada línea = cantidad × precio
        it.lineTotal = it.price != null && it.qty != null ? it.price * it.qty : null;
        it.lineTotalText = it.lineTotal == null ? '' : core.formatPrice(it.lineTotal, core.priceStyle(it.priceText));
    });
    if (fromPaste) { // listado pegado: no hay Total ni fecha del PDF; se arman con lo que hay para que el documento salga completo
        const out = outRows(), withP = out.filter((it) => it.price != null);
        o.itemsPdf = out.length; o.unitsPdf = out.reduce((s, it) => s + (+it.qty || 0), 0);
        o.date = new Date().toLocaleDateString('es-CO');
        o.totalText = withP.length ? core.formatPrice(withP.reduce((s, it) => s + it.price * (+it.qty || 0), 0), core.priceStyle(withP[0].priceText)) : '';
    }
    // Lista editada a mano (cantidades, precios, líneas quitadas o agregadas): los totales del PDF ya no valen; se pasan los
    // nuevos (referencias, unidades y valor total, como en el resumen «Lista editada a mano»). El PDF leído no se toca.
    let tot = { itemsPdf: o.itemsPdf, unitsPdf: o.unitsPdf, totalText: o.totalText };
    if (edited && !fromPaste) {
        const out = outRows(), withP = out.filter((it) => it.price != null), unit = o.unitPrices;
        tot = { itemsPdf: out.length, unitsPdf: o.noQty ? o.unitsPdf : out.reduce((s, it) => s + (+it.qty || 0), 0),
            totalText: withP.length ? core.formatPrice(withP.reduce((s, it) => s + it.price * (unit ? (+it.qty || 0) : 1), 0),
                core.priceStyle(o.totalText || withP[0].priceText)) : o.totalText };
    }
    const showP = $('exShowPrice').checked; // «Mostrar precio» apagado: el documento sale sin precios
    const data = {
        file: file ? file.name : (fromPaste ? 'listado pegado' : ''),
        order: { number: o.number || '', client: o.client || '', clientPdf: o.clientPdf ?? o.client ?? '', date: o.date || '', totalText: tot.totalText || '',
            itemsPdf: tot.itemsPdf ?? null, unitsPdf: tot.unitsPdf ?? null, noQty: !!o.noQty,
            // Recibo cuyo precio por línea es el total de la línea (cuadra con el Total sin multiplicar): Pedido de bodega lo divide por la cantidad
            priceIsLineTotal: o.format !== 'tabla' && o.totalOk === true && !o.unitPrices,
            nit: o.nit || '', address: o.address || '', city: o.city || '', phone: o.phone || '', seller: o.seller || '' },
        // Tipo de documento: la cotización en tabla es una factura → Remisión; el recibo es un pedido → Lista Bodega
        docType,
        // Descuento: el % de aquí; si está marcado «Mostrar precio con descuento», el documento abre con esas columnas
        discountPct: pct(), discountCols: $('exShowDisc').checked,
        items: outRows().map((it) => ({ code: it.code, qty: it.qty ?? null, desc: it.desc || '', note: String(it.note || '').trim(), priceText: showP ? (it.priceText || '') : '', lineTotalText: showP ? (it.lineTotalText || '') : '' })),
        // Lista corregida a mano: los avisos de lectura del PDF ya no aplican (no se llevan al recuadro «Revisar» del PDF)
        warnings: edited ? [] : result.warnings.slice(),
        edited,
    };
    try { sessionStorage.setItem('kinoPedido', JSON.stringify(data)); } catch (e) { alert('No se pudo pasar el pedido en este dispositivo.'); return; }
    location.href = '/lista-pedido';
}
$('exToOrder').addEventListener('click', () => toDoc('pedido'));
$('exToQuote').addEventListener('click', () => toDoc('remision'));
