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
    $('exFileName').textContent = f.name;
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
        rows = result.items.slice();
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

$('exClear').addEventListener('click', () => {
    file = null; result = null; rows = [];
    $('exFile').value = '';
    $('exFileName').textContent = 'Arrastra tu PDF aquí o toca para elegirlo';
    $('exRun').disabled = true;
    $('exClear').hidden = true;
    $('exResult').hidden = true;
    $('exStatus').textContent = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function render() {
    const { order, warnings, map } = result;
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

    $('exTotal').textContent = result.items.length;
    $('exToOrder').textContent = order.format === 'tabla' ? '🚚 Remisión' : '🧾 Lista Bodega';

    // Datos del pedido
    const mark = (ok) => ok == null ? '' : ok ? ' <span class="ex-ok">✓</span>' : ' <span class="ex-bad">✗</span>';
    const st = core.priceStyle(order.totalText || (result.items.find((i) => i.priceText) || {}).priceText || '$0');
    const cell = (label, val) => '<div><span>' + esc(label) + '</span>' + (val === '' || val == null ? '—' : val) + '</div>';
    $('exOrder').innerHTML = [
        cell('Formato', order.format === 'tabla' ? 'Cotización en tabla' : 'Recibo'),
        cell('N.º de pedido', esc(order.number)),
        cell('Cliente', esc(order.client)),
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

const pct = () => { const n = Math.round(+$('exPct').value); return isNaN(n) ? 40 : Math.min(100, Math.max(0, n)); };
const discText = (it) => it.price == null ? '' : core.formatPrice(it.price * (1 - pct() / 100), core.priceStyle(it.priceText));

function renderRows() {
    $('exDiscHead').textContent = 'PRECIO -' + pct() + '%';
    $('exRows').innerHTML = rows.map((it) => '<tr><td>' + esc(it.code) + '</td><td class="sep"></td><td class="num">' + (it.qty ?? '') +
        '</td><td>UNIDAD</td><td class="sep"></td><td class="num c-price">' + esc(it.priceText) + '</td><td class="num c-disc">' + esc(discText(it)) + '</td></tr>').join('');
}

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
    const text = rows.map((it) => {
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
    const codes = [...new Set(rows.map((it) => it.code))];
    try { sessionStorage.setItem('kinoCsText', codes.join('\n')); } catch (e) { alert('No se pudo pasar la lista en este dispositivo.'); return; }
    location.href = '/enviar-catalogo?desde=extractor';
});

// Lista pedido: pasa el pedido tal cual (datos del encabezado, y cada línea con su código y su cantidad, en el
// orden mostrado) a /lista-pedido, que busca la foto de cada código y arma el PDF.
$('exToOrder').addEventListener('click', () => {
    const o = result.order;
    const data = {
        file: file ? file.name : '',
        order: { number: o.number || '', client: o.client || '', date: o.date || '', totalText: o.totalText || '',
            itemsPdf: o.itemsPdf ?? null, unitsPdf: o.unitsPdf ?? null, noQty: !!o.noQty,
            nit: o.nit || '', address: o.address || '', city: o.city || '', phone: o.phone || '', seller: o.seller || '' },
        // Tipo de documento: la cotización en tabla es una factura → Remisión; el recibo es un pedido → Lista Bodega
        docType: o.format === 'tabla' ? 'remision' : 'pedido',
        items: rows.map((it) => ({ code: it.code, qty: it.qty ?? null, desc: it.desc || '', priceText: it.priceText || '', lineTotalText: it.lineTotalText || '' })),
        warnings: result.warnings.slice(),
    };
    try { sessionStorage.setItem('kinoPedido', JSON.stringify(data)); } catch (e) { alert('No se pudo pasar el pedido en este dispositivo.'); return; }
    location.href = '/lista-pedido';
});
