/**
 * Extractor de pedidos PDF (/extractor): lógica pura, sin pantalla (la usa assets/js/extractor.js y se puede probar en Node).
 * Recibe las líneas del PDF ya armadas (todas las páginas seguidas) y devuelve artículos (código, cantidad, precio),
 * datos del pedido, avisos de «Revisión» y el «Mapa del PDF». Pensado para los recibos de KINO
 * («5x 674B-1 POEDAGAR … $430,000.00»), con descripciones en varias líneas y artículos partidos entre páginas.
 * Port de la app Flask EXTRACTOR (ver su FUNCIONAMIENTO.md). Nada sale del dispositivo.
 *
 * Línea = { page, text, cells: [texto por columna], bold: [bool por celda] }.
 */

const MONEY = /^-?\s*\$?\s*\d[\d.,]*$/;
const QTY = /^(\d{1,5})\s*[x×]$/i;
const QTY_CODE = /^(\d{1,5})\s*[x×]\s*(\S+)(.*)$/i;
const CODE = /^[A-Za-z0-9][A-Za-z0-9:.\-\/]*$/;

/** ¿Parece código? Debe tener un dígito y (si no trae cantidad delante) una letra o guion: 810-C1, 312-1, A10. */
export function looksLikeCode(tok, strict) {
    if (!tok || !CODE.test(tok) || !/\d/.test(tok)) return false;
    if (/^[\d.,]+$/.test(tok)) return false; // número suelto o precio
    if (strict && !/[A-Za-z-]/.test(tok)) return false;
    return true;
}

/** "$72,000.00" → 72000 · "$1.000,50" → 1000.5 · "3.200" → 3200 · "$100" → 100. null si no es precio. */
export function readPrice(s) {
    if (s == null) return null;
    const neg = /^\s*-/.test(s);
    let t = String(s).replace(/[^\d.,]/g, '');
    if (!/\d/.test(t)) return null;
    const lastDot = t.lastIndexOf('.'), lastComma = t.lastIndexOf(',');
    let dec = '';
    if (lastDot >= 0 && lastComma >= 0) dec = lastDot > lastComma ? '.' : ',';
    else if (lastDot >= 0 || lastComma >= 0) {
        const sep = lastDot >= 0 ? '.' : ',';
        const count = t.split(sep).length - 1;
        const after = t.length - t.lastIndexOf(sep) - 1;
        if (count === 1 && after !== 3) dec = sep;
    }
    const thou = dec === '.' ? ',' : dec === ',' ? '.' : (lastDot >= 0 ? '.' : ',');
    t = t.split(thou).join('');
    if (dec) t = t.replace(dec, '.');
    const n = parseFloat(t);
    return isNaN(n) ? null : (neg ? -n : n);
}

/** Forma del precio original (símbolo, miles, decimales) para escribir otro número igual. */
export function priceStyle(s) {
    const raw = String(s || '').trim();
    const t = raw.replace(/[^\d.,]/g, '');
    const lastDot = t.lastIndexOf('.'), lastComma = t.lastIndexOf(',');
    let dec = '', decimals = 0;
    if (lastDot >= 0 && lastComma >= 0) dec = lastDot > lastComma ? '.' : ',';
    else if (lastDot >= 0 || lastComma >= 0) {
        const sep = lastDot >= 0 ? '.' : ',';
        if (t.split(sep).length - 1 === 1 && t.length - t.lastIndexOf(sep) - 1 !== 3) dec = sep;
    }
    if (dec) decimals = t.length - t.lastIndexOf(dec) - 1;
    const thou = dec === '.' ? ',' : dec === ',' ? '.' : (t.includes('.') ? '.' : t.includes(',') ? ',' : (dec === ',' ? '.' : ','));
    return { symbol: raw.includes('$'), dec: dec || (thou === '.' ? ',' : '.'), thou, decimals };
}

export function formatPrice(n, style) {
    const st = style || { symbol: true, dec: '.', thou: ',', decimals: 0 };
    const fixed = Math.abs(n).toFixed(st.decimals);
    let [int, frac] = fixed.split('.');
    int = int.replace(/\B(?=(\d{3})+(?!\d))/g, st.thou);
    return (n < 0 ? '-' : '') + (st.symbol ? '$' : '') + int + (frac ? st.dec + frac : '');
}

/** Orden natural: A2 antes que A10. */
export function naturalCompare(a, b) {
    return String(a).localeCompare(String(b), 'es', { numeric: true, sensitivity: 'base' });
}

const isMoneyCell = (c) => MONEY.test(c.trim()) && /\d/.test(c) && (c.includes('$') || /[.,]\d{3}\b/.test(c));
const START = /^(\d+)\s+(art[ií]culos?|items?|referencias|relojes)\b/i;
const TOTAL = /^total\s*:?\s*(\$?\s*\d[\d.,]*)?\s*$/i;

/** Clasifica una línea. */
function classify(line) {
    const cells = line.cells.map((c) => c.trim()).filter(Boolean);
    const text = line.text.trim();
    const money = cells.filter(isMoneyCell);
    const rest = cells.filter((c) => !isMoneyCell(c));
    const first = rest[0] || '';
    if (!rest.length && money.length) return { kind: 'price', price: money[money.length - 1] };
    let m = first.match(QTY);
    if (m && rest.length === 1) return { kind: 'qty', qty: +m[1], price: money[0] || null };
    m = first.match(QTY_CODE);
    if (m && looksLikeCode(m[2], false)) return { kind: 'full', qty: +m[1], code: m[2], desc: (m[3] || '').trim(), price: money[0] || null };
    // Tabla: «código || cantidad || $subtotal»
    if (rest.length >= 2 && looksLikeCode(rest[0].split(/\s+/)[0], false) && /^\d{1,5}$/.test(rest[1]))
        return { kind: 'full', qty: +rest[1], code: rest[0].split(/\s+/)[0], desc: rest[0].split(/\s+/).slice(1).join(' '), price: money[0] || null };
    const tok = first.split(/\s+/)[0];
    if (looksLikeCode(tok, true)) return { kind: 'code', code: tok, desc: first.slice(tok.length).trim(), price: money[0] || null };
    return { kind: 'desc', text };
}

/**
 * @param {Array} lines  líneas de todas las páginas, en orden
 * @returns {{items, order, warnings, map}}
 */
export function parseOrder(lines) {
    let start = 0, end = lines.length;
    const si = lines.findIndex((l) => START.test(l.text.trim()));
    if (si >= 0) start = si + 1;
    for (let i = start; i < lines.length; i++) if (TOTAL.test(lines[i].text.trim()) || /^total\s*:/i.test(lines[i].text.trim())) { end = i; break; }

    const items = [];
    const warnings = [];
    const map = lines.map((l, i) => ({ page: l.page, text: l.text, tag: i < start ? 'Encabezado' : i > end ? 'Pie' : i === end ? 'Total' : '' }));
    let pendCode = null; // {code, desc, idx, price}
    let pendQty = null;  // {qty, price, idx}
    let last = null;     // último artículo armado (para el precio unitario de la línea siguiente)

    const push = (code, qty, price, idxs) => {
        const it = { code, qty, priceText: price || '', price: price ? readPrice(price) : null, n: items.length + 1 };
        items.push(it);
        const split = new Set(idxs.map((i) => lines[i].page)).size > 1;
        idxs.forEach((i) => { map[i].tag = 'Artículo ' + code + (split ? ' (partido entre páginas)' : ''); });
        last = { it, idx: Math.max(...idxs) };
        return it;
    };
    const dropCode = () => {
        if (!pendCode) return;
        warnings.push('«' + pendCode.code + (pendCode.desc ? ' ' + pendCode.desc : '') + '» empieza como un código pero no tiene cantidad. No se incluyó.');
        map[pendCode.idx].tag = '⚠ código sin cantidad';
        pendCode = null;
    };
    const dropQty = () => {
        if (!pendQty) return;
        warnings.push('«' + lines[pendQty.idx].text.trim() + '» tiene cantidad ' + pendQty.qty + 'x pero no se encontró su código. No se incluyó.');
        map[pendQty.idx].tag = '⚠ cantidad sin código';
        pendQty = null;
    };

    for (let i = start; i < end; i++) {
        const c = classify(lines[i]);
        if (c.kind === 'full') {
            dropCode(); dropQty();
            push(c.code, c.qty, c.price, [i]);
        } else if (c.kind === 'qty') {
            if (pendCode) { push(pendCode.code, c.qty, c.price || pendCode.price, [pendCode.idx, i]); pendCode = null; }
            else { dropQty(); pendQty = { qty: c.qty, price: c.price, idx: i }; }
        } else if (c.kind === 'code') {
            if (pendQty) { push(c.code, pendQty.qty, pendQty.price || c.price, [pendQty.idx, i]); pendQty = null; }
            else { dropCode(); pendCode = { code: c.code, desc: c.desc, idx: i, price: c.price }; }
        } else if (c.kind === 'price') {
            if (last && last.idx === i - 1 && !map[i].tag) map[i].tag = 'Precio unitario de ' + last.it.code;
            else map[i].tag = 'Descripción';
        } else {
            map[i].tag = 'Descripción';
        }
    }
    dropCode(); dropQty();
    if (!items.length) {
        // PDF sin cantidades (p. ej. un catálogo en PDF del visor): se listan solo los códigos, sin inventar cantidad.
        const seen = new Set();
        lines.forEach((l, i) => l.cells.forEach((c) => {
            const tok = c.trim().split(/\s+/)[0];
            if (!looksLikeCode(tok, true) || seen.has(tok)) return;
            seen.add(tok);
            items.push({ code: tok, qty: null, priceText: '', price: null, n: items.length + 1 });
            map[i].tag = 'Código (sin cantidad)';
        }));
        if (items.length) {
            warnings.length = 0;
            warnings.push('Este PDF no trae cantidades: se listaron solo los códigos (' + items.length + ').');
            return { items, order: Object.assign(orderData(lines, 0), { itemsFound: items.length, unitsFound: 0, canCheck: true, noQty: true }), warnings, map };
        }
    }
    items.forEach((it) => { if (it.price == null) warnings.push(it.code + ': no se encontró su precio.'); });

    const order = orderData(lines, start);
    // Verificación contra el propio PDF
    const sumPrice = items.reduce((s, it) => s + (it.price || 0), 0);
    const sumUnit = items.reduce((s, it) => s + (it.price || 0) * it.qty, 0);
    const units = items.reduce((s, it) => s + it.qty, 0);
    order.sumPrice = sumPrice;
    order.unitPrices = false;
    if (order.total != null) {
        if (Math.abs(sumPrice - order.total) < 0.5) order.totalOk = true;
        else if (Math.abs(sumUnit - order.total) < 0.5) { order.totalOk = true; order.unitPrices = true; order.sumPrice = sumUnit; }
        else {
            order.totalOk = false;
            const diff = order.total - sumPrice;
            const st = priceStyle(order.totalText);
            warnings.unshift('La suma de precios no coincide con el Total: ' + (diff > 0 ? 'faltan ' : 'sobran ') + formatPrice(Math.abs(diff), st) + '.');
        }
    }
    order.itemsFound = items.length;
    order.unitsFound = units;
    if (order.itemsPdf != null && order.itemsPdf !== items.length)
        warnings.splice(order.totalOk === false ? 1 : 0, 0, 'El PDF indica ' + order.itemsPdf + ' artículos y se extrajeron ' + items.length + '.');
    if (order.unitsPdf != null && order.unitsPdf !== units)
        warnings.push('El PDF indica ' + order.unitsPdf + ' unidades y se extrajeron ' + units + '.');
    order.canCheck = order.total != null || order.itemsPdf != null || order.unitsPdf != null;
    return { items, order, warnings, map };
}

const MONTHS = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre';

function orderData(lines, start) {
    const o = { number: '', client: '', date: '', total: null, totalText: '', itemsPdf: null, unitsPdf: null };
    const all = lines.map((l) => l.text.trim());
    for (const t of all) {
        const m = t.match(/\b(RECIBO|PEDIDO|ORDEN|FACTURA|COTIZACI[ÓO]N)\s*(?:N[º°o.]*\s*)?#\s*([\w-]+)/i);
        if (m) { o.number = '#' + m[2]; break; }
    }
    if (!o.number) { const m = all.slice(0, 5).join(' ').match(/(^|\s)#\s*([A-Za-z]*-?\d[\w-]*)/); if (m) o.number = '#' + m[2]; }
    for (const t of all) { const m = t.match(/cliente\s*:\s*(.+)$/i); if (m) { o.client = m[1].trim(); break; } }
    if (!o.client) {
        // Sin etiqueta: el segundo nombre en negrita del encabezado (el primero es el vendedor)
        const names = [];
        for (let i = 0; i < Math.min(start || lines.length, lines.length); i++) {
            const l = lines[i];
            if (l.bold && l.bold.every(Boolean) && /^[\p{L} .'&-]+$/u.test(l.text.trim()) && !/^(recibo|pedido|orden|factura|cotizaci)/i.test(l.text.trim()) && !names.includes(l.text.trim())) names.push(l.text.trim());
        }
        if (names.length >= 2) o.client = names[1];
    }
    const dateRe = new RegExp('\\b(\\d{1,2} de (?:' + MONTHS + ') de \\d{4}(?:,? \\d{1,2}:\\d{2})?|\\d{1,2}/\\d{1,2}/\\d{2,4})', 'gi');
    for (const t of all) { const ms = t.match(dateRe); if (ms) o.date = ms[ms.length - 1]; }
    for (const t of all) {
        const m = t.match(/^total\s*:?\s*(-?\$?\s*\d[\d.,]*)/i);
        if (m) { o.totalText = m[1].replace(/\s+/g, ''); o.total = readPrice(m[1]); }
    }
    for (const t of all) {
        const m = t.match(START); if (m && o.itemsPdf == null) o.itemsPdf = +m[1];
        const u = t.match(/\b(?:cant|ctd)\.?\s*:\s*(\d+)/i); if (u && o.unitsPdf == null) o.unitsPdf = +u[1];
    }
    return o;
}

/**
 * Arma las líneas a partir de los textos de pdf.js de una página.
 * items: [{str, x, y, w, h, bold}] → [{page, text, cells, bold}] de arriba abajo.
 */
export function buildLines(page, items) {
    const rows = [];
    for (const it of items) {
        if (!it.str || !it.str.trim()) continue;
        let r = rows.find((r) => Math.abs(r.y - it.y) <= Math.max(2.5, it.h * 0.3));
        if (!r) rows.push(r = { y: it.y, items: [] });
        r.items.push(it);
    }
    rows.sort((a, b) => b.y - a.y);
    return rows.map((r) => {
        r.items.sort((a, b) => a.x - b.x);
        const cells = [], bold = [];
        let cur = '', curBold = true, end = null;
        for (const i of r.items) {
            const gap = end === null ? 0 : i.x - end;
            if (end !== null && gap > Math.max(i.h || 0, 4) * 1.5) { cells.push(cur); bold.push(curBold); cur = ''; curBold = true; }
            else if (end !== null && gap > 0.8 && cur && !/\s$/.test(cur)) cur += ' ';
            cur += i.str;
            curBold = curBold && !!i.bold;
            end = i.x + i.w;
        }
        cells.push(cur); bold.push(curBold);
        const clean = cells.map((c) => c.replace(/\s+/g, ' ').trim());
        return { page, text: clean.join(' '), cells: clean, bold };
    });
}

/** Lee un PDF con pdf.js (pdfjsLib ya cargado) y devuelve sus líneas. */
export async function readPdfLines(pdfjsLib, data) {
    const doc = await pdfjsLib.getDocument({ data, verbosity: 0 }).promise;
    const lines = [];
    for (let p = 1; p <= doc.numPages; p++) {
        const page = await doc.getPage(p);
        try { await page.getOperatorList(); } catch (e) { /* solo para saber qué fuente es negrita */ }
        const tc = await page.getTextContent();
        const boldOf = {};
        const items = tc.items.map((it) => {
            if (it.fontName && !(it.fontName in boldOf)) {
                let b = false;
                try { const f = page.commonObjs.get(it.fontName); b = !!(f && (f.bold || /bold|black|heavy|semibold/i.test(f.name || ''))); } catch (e) { b = false; }
                boldOf[it.fontName] = b;
            }
            return { str: it.str, x: it.transform[4], y: it.transform[5], w: it.width, h: it.height || Math.abs(it.transform[3]), bold: boldOf[it.fontName] };
        });
        lines.push(...buildLines(p, items));
    }
    return { lines, pages: doc.numPages };
}
