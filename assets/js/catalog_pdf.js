/**
 * Catálogo en PDF (/catalogo-pdf, docs/PLAN_PDF.md).
 * Lista = lo marcado (localStorage kinoWaSel de wa_batch.js) o, si la URL trae ?desde=lista, la lista que otra
 * pantalla dejó en sessionStorage kinoPdfSrc ({name, items:[{id, sku}]}).
 * Cada foto lleva su código y, si el usuario escribió algo, su texto (uno por código, se recuerda en kinoPdfTexts).
 * Diseño: colores primario/secundario, título, subtítulo, pie de catálogo (kinoPdfOpts), logo propio (kinoPdfLogo) y
 * subtítulos de sección antes de una foto (kinoPdfHeads, por id de foto). El PDF (A4) se arma aquí con jsPDF.
 * Nada de esto se envía al servidor: solo se piden las fotos livianas (/enviar-catalogo/foto/{id}).
 */
(function () {
    const MAX_ITEMS = 300;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const code = (sku) => String(sku || '').replace(/\.\w{2,4}$/i, ''); // como cleanSkuDisplay()
    const canShareFiles = (() => {
        try {
            return !!(navigator.canShare && navigator.canShare({ files: [new File([new Blob(['x'], { type: 'application/pdf' })], 'x.pdf', { type: 'application/pdf' })] }));
        } catch (e) { return false; }
    })();

    // ---- Guardado en el dispositivo ----
    const ls = (() => { try { localStorage.setItem('kinoT', '1'); localStorage.removeItem('kinoT'); return localStorage; } catch (e) { return null; } })();
    function readJson(store, key, def) {
        try { const v = JSON.parse((store && store.getItem(key)) || 'null'); return v == null ? def : v; } catch (e) { return def; }
    }
    function writeJson(key, val) {
        if (!ls) return true;
        try { ls.setItem(key, JSON.stringify(val)); return true; } catch (e) { return false; }
    }
    const asMap = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});

    // ---- La lista ----
    function loadItems() {
        let raw = [];
        let name = '';
        const fromList = new URLSearchParams(location.search).get('desde') === 'lista';
        if (fromList) {
            const d = readJson(sessionStorage, 'kinoPdfSrc', null);
            if (d && Array.isArray(d.items)) { raw = d.items; name = String(d.name || ''); }
        } else {
            let d = readJson(ls, 'kinoWaSel', null);
            if (!d || !Array.isArray(d.items)) d = readJson(sessionStorage, 'kinoWaSel', null);
            if (d && Array.isArray(d.items)) raw = d.items;
        }
        const seen = new Set();
        const items = [];
        raw.forEach((x) => {
            if (!x || !x.id || seen.has(x.id)) return;
            seen.add(x.id);
            items.push({ id: String(x.id), code: code(x.sku || x.id) });
        });
        return { items, name, fromList, cut: Math.max(0, items.length - MAX_ITEMS) };
    }

    const src = loadItems();
    const items = src.items.slice(0, MAX_ITEMS);
    const texts = asMap(readJson(ls, 'kinoPdfTexts', {}));
    const heads = asMap(readJson(ls, 'kinoPdfHeads', {}));
    const saved = asMap(readJson(ls, 'kinoPdfOpts', {}));
    // Antes había un campo "Contacto" (arriba); ahora es el pie de catálogo
    if (saved.footer == null && saved.contact) saved.footer = saved.contact;
    const opts = Object.assign({ title: '', subtitle: '', footer: '', per: 6, c1: '#1c1c1e', c2: '#c9a84c' }, saved);
    delete opts.contact;
    if (![4, 6, 9].includes(+opts.per)) opts.per = 6;
    const HEX = /^#[0-9a-f]{6}$/i;
    if (!HEX.test(opts.c1)) opts.c1 = '#1c1c1e';
    if (!HEX.test(opts.c2)) opts.c2 = '#c9a84c';
    // Lista de un catálogo con nombre (enlace /c/…, catálogo guardado): ese nombre es el título (se puede cambiar)
    if (src.name) opts.title = src.name;
    if (src.fromList) {
        const back = document.querySelector('.header-inner a.btn');
        if (back && history.length > 1) { back.textContent = '← Volver'; back.addEventListener('click', (e) => { e.preventDefault(); history.back(); }); }
    }
    let logo = readJson(ls, 'kinoPdfLogo', null); // {src: dataURL PNG, w, h}
    if (!logo || !logo.src) logo = null;

    $('cpCount').textContent = items.length ? '(' + items.length + ')' : '';
    $('cpEmpty').hidden = !!items.length;
    $('cpBody').hidden = !items.length;
    $('cpBar').hidden = !items.length;
    if (!items.length) return;

    // ---- Colores ----
    const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
    const lum = (h) => {
        const [r, g, b] = rgb(h).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const contrast = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const onColor = (h) => (contrast(h, '#ffffff') >= contrast(h, '#1a1a1a') ? '#ffffff' : '#1a1a1a');
    // Un color sobre fondo blanco: si es muy claro (no se leería), se usa casi negro
    const onWhite = (h) => (contrast(h, '#ffffff') >= 2.2 ? h : '#2a2a2a');
    // Subtítulo sobre la franja: el secundario si se lee, si no el color del texto de la franja
    const subOnBand = () => (contrast(opts.c2, opts.c1) >= 2.2 ? opts.c2 : onColor(opts.c1));
    const PRESETS = [
        ['Negro y dorado', '#1c1c1e', '#c9a84c'],
        ['Azul y plata', '#1e3a5f', '#b8c4d2'],
        ['Verde y crema', '#1f4d3a', '#e0c98f'],
        ['Vino y rosa', '#5a1e2c', '#e8b4b8'],
        ['Grafito y naranja', '#2f3640', '#e1813a'],
        ['Blanco y negro', '#f4f4f2', '#1c1c1e'],
    ];
    $('cpPresets').innerHTML = PRESETS.map(([n, a, b], i) => `<button type="button" class="cp-preset" data-i="${i}" title="${esc(n)}"><span style="background:${a}"></span><span style="background:${b}"></span><small>${esc(n)}</small></button>`).join('');

    // ---- Pantalla ----
    const list = $('cpList');
    list.innerHTML = items.map((it, i) => `<li class="ms-item cp-item" data-id="${esc(it.id)}">
            <div class="cp-headrow"${heads[it.id] ? '' : ' hidden'}><input type="text" maxlength="50" data-head="${esc(it.id)}" value="${esc(heads[it.id] || '')}" placeholder="Subtítulo de sección (ej.: Relojes de dama)"><button type="button" data-a="nohead" title="Quitar subtítulo">✕</button></div>
            <span class="ms-n">${i + 1}</span>
            <img src="https://lh3.googleusercontent.com/d/${encodeURIComponent(it.id)}=s200" alt="" loading="lazy">
            <span class="cp-info"><span class="cp-info-top"><strong>${esc(it.code)}</strong><button type="button" class="cp-addhead" data-a="head"${heads[it.id] ? ' hidden' : ''}>＋ Subtítulo</button></span>
                <textarea rows="2" maxlength="120" data-code="${esc(it.code)}" placeholder="Precio u otra información (opcional)">${esc(texts[it.code] || '')}</textarea>
            </span></li>`).join('');

    $('cpTitle').value = opts.title;
    $('cpSub').value = opts.subtitle;
    $('cpFoot').value = opts.footer;
    $('cpC1').value = opts.c1;
    $('cpC2').value = opts.c2;
    document.querySelectorAll('input[name="cpPer"]').forEach((r) => { r.checked = +r.value === +opts.per; });
    if (src.cut) warn(`El PDF admite hasta ${MAX_ITEMS} fotos: se dejaron las primeras ${MAX_ITEMS} (sobran ${src.cut}).`);

    function warn(t) { const w = $('cpWarn'); w.textContent = t || ''; w.hidden = !t; }
    function msg(t) { $('cpMsg').textContent = t || ''; }

    // Vista previa en pequeño (misma lógica de colores que el PDF)
    function drawPreview() {
        const p = $('cpPrev');
        p.style.setProperty('--p1', opts.c1);
        p.style.setProperty('--p2', opts.c2);
        p.style.setProperty('--pon', onColor(opts.c1));
        p.style.setProperty('--psub', subOnBand());
        p.style.setProperty('--pcode', onWhite(opts.c1));
        $('cpPrevTitle').textContent = opts.title.trim() || 'Título del catálogo';
        $('cpPrevSub').textContent = opts.subtitle.trim();
        $('cpPrevFoot').textContent = opts.footer.trim() || 'Pie de catálogo (contacto)';
        $('cpPrevLogo').innerHTML = logo ? `<img src="${logo.src}" alt="">` : '';
        $('cpPrevLogo').hidden = !logo;
        document.querySelectorAll('.cp-preset').forEach((b) => {
            const [, a, c] = PRESETS[+b.dataset.i];
            b.classList.toggle('on', a === opts.c1.toLowerCase() && c === opts.c2.toLowerCase());
        });
    }
    drawPreview();

    let saveT;
    function saveTexts() {
        clearTimeout(saveT);
        saveT = setTimeout(() => {
            // Solo lo que tiene texto (lo vacío no ocupa espacio)
            Object.keys(texts).forEach((k) => { if (!texts[k]) delete texts[k]; });
            Object.keys(heads).forEach((k) => { if (!heads[k]) delete heads[k]; });
            if (!writeJson('kinoPdfTexts', texts) || !writeJson('kinoPdfHeads', heads)) msg('⚠️ No se pudieron guardar los textos en este dispositivo (memoria llena).');
        }, 300);
    }
    function saveOpts() { writeJson('kinoPdfOpts', opts); drawPreview(); }

    // Un texto por código: si el mismo código tiene varias fotos, todas muestran lo mismo
    list.addEventListener('input', (e) => {
        const el = e.target;
        if (el.dataset.head) { heads[el.dataset.head] = el.value; saveTexts(); stale(); return; }
        if (el.tagName !== 'TEXTAREA') return;
        const c = el.dataset.code;
        texts[c] = el.value;
        list.querySelectorAll('textarea').forEach((o) => { if (o !== el && o.dataset.code === c) o.value = el.value; });
        saveTexts(); stale();
    });
    list.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-a]');
        if (!b) return;
        const li = b.closest('.cp-item');
        const row = li.querySelector('.cp-headrow');
        const input = row.querySelector('input');
        if (b.dataset.a === 'head') {
            row.hidden = false; li.querySelector('.cp-addhead').hidden = true; input.focus();
        } else {
            input.value = ''; heads[li.dataset.id] = '';
            row.hidden = true; li.querySelector('.cp-addhead').hidden = false;
            saveTexts(); stale();
        }
    });
    const bindOpt = (id, key) => $(id).addEventListener('input', (e) => { opts[key] = e.target.value; saveOpts(); stale(); });
    bindOpt('cpTitle', 'title');
    bindOpt('cpSub', 'subtitle');
    bindOpt('cpFoot', 'footer');
    bindOpt('cpC1', 'c1');
    bindOpt('cpC2', 'c2');
    $('cpPresets').addEventListener('click', (e) => {
        const b = e.target.closest('.cp-preset');
        if (!b) return;
        const [, a, c] = PRESETS[+b.dataset.i];
        opts.c1 = a; opts.c2 = c;
        $('cpC1').value = a; $('cpC2').value = c;
        saveOpts(); stale();
    });
    document.querySelectorAll('input[name="cpPer"]').forEach((r) => r.addEventListener('change', () => { opts.per = +r.value; saveOpts(); stale(); }));

    function bulk(mode) {
        const t = $('cpBulkText').value.trim();
        if (mode !== 'clear' && !t) { $('cpBulkText').focus(); msg('Escribe el texto rápido primero.'); return; }
        if (mode === 'clear' && !confirm('¿Borrar los textos de todas las fotos de esta lista?')) return;
        items.forEach((it) => {
            if (mode === 'all') texts[it.code] = t;
            else if (mode === 'empty' && !(texts[it.code] || '').trim()) texts[it.code] = t;
            else if (mode === 'clear') texts[it.code] = '';
        });
        list.querySelectorAll('textarea').forEach((ta) => { ta.value = texts[ta.dataset.code] || ''; });
        msg(''); saveTexts(); stale();
    }
    $('cpBulkAll').addEventListener('click', () => bulk('all'));
    $('cpBulkEmpty').addEventListener('click', () => bulk('empty'));
    $('cpBulkClear').addEventListener('click', () => bulk('clear'));

    // ---- Logo del usuario (opcional; PNG ≤ 400 px en el dispositivo) ----
    function drawLogo() {
        $('cpLogoImg').hidden = !logo;
        $('cpLogoNone').hidden = !!logo;
        $('cpLogoDel').hidden = !logo;
        if (logo) $('cpLogoImg').src = logo.src; else $('cpLogoImg').removeAttribute('src');
        drawPreview();
    }
    drawLogo();
    $('cpLogoFile').addEventListener('change', async (e) => {
        const f = e.target.files && e.target.files[0];
        e.target.value = '';
        if (!f) return;
        try {
            const img = await bitmap(f);
            const k = Math.min(1, 400 / Math.max(img.width, img.height));
            const c = document.createElement('canvas');
            c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            if (img.close) img.close();
            logo = { src: c.toDataURL('image/png'), w: c.width, h: c.height };
            if (!writeJson('kinoPdfLogo', logo)) msg('El logo sale en este PDF, pero no se pudo guardar para la próxima vez (memoria llena).');
            else msg('');
            drawLogo(); stale();
        } catch (err) { msg('No se pudo leer esa imagen. Prueba con una foto PNG o JPG.'); }
    });
    $('cpLogoDel').addEventListener('click', () => {
        logo = null;
        try { ls && ls.removeItem('kinoPdfLogo'); } catch (e) {}
        drawLogo(); stale();
    });

    // ---- Fotos ----
    async function bitmap(blob) {
        if (window.createImageBitmap) {
            try { return await createImageBitmap(blob); } catch (e) { /* Safari viejo: con <img> */ }
        }
        return await new Promise((ok, bad) => {
            const u = URL.createObjectURL(blob);
            const im = new Image();
            im.onload = () => { URL.revokeObjectURL(u); ok(im); };
            im.onerror = () => { URL.revokeObjectURL(u); bad(new Error('imagen dañada')); };
            im.src = u;
        });
    }
    async function fetchPhoto(id) {
        let last;
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt) await new Promise((ok) => setTimeout(ok, 700 * attempt));
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 30000);
            try {
                let r = await fetch('/enviar-catalogo/foto/' + encodeURIComponent(id), { credentials: 'same-origin', signal: ctrl.signal });
                // Foto de carpeta sin código enlazado: no hay liviana, va la original
                if (r.status === 404) r = await fetch('/api/download/' + encodeURIComponent(id), { signal: ctrl.signal });
                if (!r.ok) throw new Error('error ' + r.status);
                const b = await r.blob();
                if (!b.size) throw new Error('foto vacía');
                return b;
            } catch (e) {
                last = e;
                if (e && /error 404/.test(e.message)) break;
            } finally { clearTimeout(timer); }
        }
        throw last;
    }
    // Foto lista para el PDF: JPEG reducido sobre fondo blanco ({data: Uint8Array, w, h})
    const prepared = new Map();
    function prepare(id, maxPx) {
        const key = id + '@' + maxPx;
        if (!prepared.has(key)) {
            const p = (async () => {
                const img = await bitmap(await fetchPhoto(id));
                const k = Math.min(1, maxPx / Math.max(img.width, img.height));
                const c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
                const g = c.getContext('2d');
                g.fillStyle = '#ffffff'; g.fillRect(0, 0, c.width, c.height);
                g.drawImage(img, 0, 0, c.width, c.height);
                if (img.close) img.close();
                const b = await new Promise((ok, bad) => c.toBlob((x) => x ? ok(x) : bad(new Error('sin memoria')), 'image/jpeg', 0.82));
                return { data: new Uint8Array(await b.arrayBuffer()), w: c.width, h: c.height };
            })();
            p.catch(() => prepared.delete(key));
            prepared.set(key, p);
        }
        return prepared.get(key);
    }

    // ---- Texto del PDF: la letra estándar del PDF tiene tildes y ñ, pero no emojis ----
    const OK_CHARS = /[^ -~ -ÿ€•–—‘’“”…]/g;
    const clean = (s) => String(s || '').replace(/\s+/g, ' ').replace(OK_CHARS, '').replace(/\s+/g, ' ').trim();
    const lostChars = (s) => String(s || '').replace(/\s+/g, ' ').trim() !== clean(s);

    // ---- Generar ----
    let pdfFile = null;
    let busy = false;
    function stale() {
        if (busy) return;
        pdfFile = null;
        $('cpShare').hidden = true;
        $('cpDown').hidden = true;
        $('cpMake').textContent = '📄 Generar PDF';
    }

    const LAYOUT = { 4: [2, 2], 6: [2, 3], 9: [3, 3] };
    const MAXPX = { 4: 900, 6: 750, 9: 560 };
    const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    const fileName = () => (clean(opts.title).replace(/[^\wÁÉÍÓÚÜÑáéíóúüñ -]+/g, '').trim().replace(/\s+/g, '_') || 'Catalogo') + '_' + today() + '.pdf';
    const mb = (n) => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' MB';

    // Ubica cada foto (y cada subtítulo de sección) en su página. Un subtítulo empieza fila nueva y nunca queda
    // solo al final de una página.
    function layout(rows, g, hm) {
        const out = [];
        let page = 0, y = g.top, col = 0;
        const newPage = () => { page++; y = g.top; col = 0; };
        rows.forEach((r) => {
            const head = hm[r.it.id] || '';
            if (head) {
                if (col > 0) { y += g.ch + g.gap; col = 0; }
                if (y + g.headH + g.ch > g.bottom + 0.01 && y > g.top) newPage();
                out.push({ type: 'head', page, y, text: head });
                y += g.headH;
            }
            if (col === 0 && y + g.ch > g.bottom + 0.01 && y > g.top) newPage();
            out.push({ type: 'item', page, x: g.M + col * (g.cw + g.gap), y, r });
            col++;
            if (col === g.cols) { col = 0; y += g.ch + g.gap; }
        });
        return { boxes: out, pages: page + 1 };
    }

    async function make() {
        if (busy) return;
        if (!window.jspdf || !window.jspdf.jsPDF) { msg('El generador de PDF no cargó. Revisa la conexión y recarga la página.'); return; }
        busy = true;
        const btn = $('cpMake');
        btn.disabled = true;
        $('cpShare').hidden = true; $('cpDown').hidden = true;
        pdfFile = null;
        const per = +opts.per;
        const maxPx = MAXPX[per];
        warn(src.cut ? `El PDF admite hasta ${MAX_ITEMS} fotos: se dejaron las primeras ${MAX_ITEMS} (sobran ${src.cut}).` : '');
        list.querySelectorAll('.cp-item.is-bad').forEach((li) => li.classList.remove('is-bad'));
        try {
            // 1) Fotos: 4 a la vez, en orden
            const ready = new Array(items.length);
            const bad = [];
            let done = 0, next = 0;
            const worker = async () => {
                while (next < items.length) {
                    const i = next++;
                    try { ready[i] = await prepare(items[i].id, maxPx); } catch (e) { ready[i] = null; bad.push(items[i]); }
                    done++;
                    btn.textContent = `⏳ Fotos ${done}/${items.length}`;
                    msg(`Preparando fotos: ${done} de ${items.length}…`);
                }
            };
            await Promise.all([worker(), worker(), worker(), worker()]);
            const rows = items.map((it, i) => ({ it, img: ready[i] })).filter((x) => x.img);
            bad.forEach((it) => { const li = list.querySelector(`.cp-item[data-id="${CSS.escape(it.id)}"]`); if (li) li.classList.add('is-bad'); });
            if (!rows.length) throw new Error('ninguna foto bajó. Revisa la conexión e intenta otra vez.');
            // Un subtítulo puesto en una foto que no bajó pasa a la siguiente que sí está
            let carry = '';
            const headsUse = {};
            items.forEach((it, i) => {
                const h = clean(heads[it.id]);
                if (!ready[i]) { if (h) carry = h; return; }
                if (h || carry) headsUse[it.id] = h || carry;
                carry = '';
            });

            // 2) Medidas (mm) y colores
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true });
            const W = 210, H = 297, M = 12, GAP = 5;
            const [cols, rowsPerPage] = LAYOUT[per];
            const title = clean(opts.title), subtitle = clean(opts.subtitle), footer = clean(opts.footer);
            const big = !!(title || subtitle || logo);
            const bandH = big ? 26 : 9;
            const top = bandH + 1.2 + 7;
            const footLines = (() => { doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); return footer ? doc.splitTextToSize(footer, W - 2 * M - 24).slice(0, 2) : []; })();
            const bottom = H - (footLines.length > 1 ? 21 : 18);
            const cw = (W - 2 * M - (cols - 1) * GAP) / cols;
            // Con subtítulos de sección se guarda espacio para uno por página, así cada página sigue llevando sus N fotos
            const HEAD_H = 12;
            const ch = (bottom - top - (Object.keys(headsUse).length ? HEAD_H : 0) - (rowsPerPage - 1) * GAP) / rowsPerPage;
            const codePt = cols === 2 ? 12 : 10, textPt = cols === 2 ? 10 : 8.5;
            const lh = (pt) => pt * 0.3528 * 1.25;
            const anyText = rows.some((r) => clean(texts[r.it.code]));
            const textH = lh(codePt) + 3 + (anyText ? 3 * lh(textPt) : 0) + 3;
            const c1 = rgb(opts.c1), c2 = rgb(opts.c2), on1 = rgb(onColor(opts.c1)), sub1 = rgb(subOnBand()), ink1 = rgb(onWhite(opts.c1));
            const { boxes, pages } = layout(rows, { M, top, bottom, cw, ch, cols, gap: GAP, headH: HEAD_H }, headsUse);
            const emojis = rows.some((r) => lostChars(texts[r.it.code]) || lostChars(heads[r.it.id])) || lostChars(opts.title) || lostChars(opts.subtitle) || lostChars(opts.footer);

            const lines = (t, width) => {
                doc.setFont('helvetica', 'normal'); doc.setFontSize(textPt);
                let ls2 = doc.splitTextToSize(t, width);
                if (ls2.length > 3) {
                    ls2 = ls2.slice(0, 3);
                    let last = ls2[2];
                    while (last.length && doc.getTextWidth(last + '…') > width) last = last.slice(0, -1);
                    ls2[2] = last.replace(/\s+$/, '') + '…';
                }
                return ls2;
            };
            const fit = (t, width) => doc.splitTextToSize(t, width)[0] || '';
            let cutText = false;

            function drawFrame(p) {
                // Franja superior a todo el ancho con logo, título y subtítulo; línea fina del secundario debajo
                doc.setFillColor(...c1); doc.rect(0, 0, W, bandH, 'F');
                doc.setFillColor(...c2); doc.rect(0, bandH, W, 1.2, 'F');
                if (big) {
                    let x = M;
                    if (logo) {
                        const k = Math.min(34 / logo.w, 15 / logo.h);
                        const lw = logo.w * k, lhh = logo.h * k;
                        const pw = lw + 4, ph = lhh + 4;
                        doc.setFillColor(255, 255, 255);
                        doc.roundedRect(M, (bandH - ph) / 2, pw, ph, 2, 2, 'F');
                        doc.addImage(logo.src, 'PNG', M + 2, (bandH - lhh) / 2, lw, lhh, 'kinoLogo', 'FAST');
                        x = M + pw + 6;
                    }
                    const tw = W - M - x;
                    if (title) {
                        doc.setFont('helvetica', 'bold'); doc.setFontSize(19); doc.setTextColor(...on1);
                        doc.text(fit(title, tw), x, subtitle ? 12.5 : 15.5);
                    }
                    if (subtitle) {
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(10.5); doc.setTextColor(...sub1);
                        doc.text(fit(subtitle, tw), x, title ? 19.5 : 15);
                    }
                }
                // Pie: línea del secundario, texto de contacto a la izquierda y número de página a la derecha
                const fy = H - (footLines.length > 1 ? 15 : 12);
                doc.setDrawColor(...c2); doc.setLineWidth(0.4); doc.line(M, fy, W - M, fy);
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(85, 85, 85);
                footLines.forEach((ln, i) => doc.text(ln, M, fy + 5 + i * 4));
                doc.setFont('helvetica', 'bold'); doc.setTextColor(...ink1);
                doc.text(`${p + 1} / ${pages}`, W - M, fy + 5, { align: 'right' });
            }

            // 3) Páginas
            let cur = -1;
            for (const b of boxes) {
                if (b.page !== cur) {
                    if (cur >= 0) doc.addPage();
                    cur = b.page;
                    btn.textContent = `⏳ Página ${cur + 1}/${pages}`;
                    msg(`Armando página ${cur + 1} de ${pages}…`);
                    await new Promise((ok) => setTimeout(ok, 0)); // deja respirar la pantalla
                    drawFrame(cur);
                }
                if (b.type === 'head') {
                    doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(...ink1);
                    const t = fit(b.text, W - 2 * M - 20);
                    doc.text(t, M, b.y + 6.5);
                    const ex = M + doc.getTextWidth(t) + 4;
                    doc.setDrawColor(...c2); doc.setLineWidth(0.5);
                    if (ex < W - M) doc.line(ex, b.y + 5, W - M, b.y + 5);
                    continue;
                }
                const { x: cx, y: cy, r } = b;
                // Tarjeta blanca con borde muy suave
                doc.setDrawColor(228, 228, 228); doc.setLineWidth(0.25);
                doc.roundedRect(cx, cy, cw, ch, 2.5, 2.5, 'S');
                // Foto entera (sin recortar), centrada en su recuadro
                const bw = cw - 6, bh = ch - textH - 5;
                const s = Math.min(bw / r.img.w, bh / r.img.h);
                const iw = r.img.w * s, ih = r.img.h * s;
                doc.addImage(r.img.data, 'JPEG', cx + 3 + (bw - iw) / 2, cy + 3 + (bh - ih) / 2, iw, ih, undefined, 'FAST');
                // Código (primario), raya corta (secundario) y texto del cliente
                let ty = cy + 3 + bh + 2 + lh(codePt) * 0.8;
                doc.setTextColor(...ink1); doc.setFont('helvetica', 'bold'); doc.setFontSize(codePt);
                doc.text(fit(clean(r.it.code), bw), cx + cw / 2, ty, { align: 'center' });
                ty += 2.2;
                doc.setDrawColor(...c2); doc.setLineWidth(0.6);
                doc.line(cx + cw / 2 - 5, ty, cx + cw / 2 + 5, ty);
                const t = clean(texts[r.it.code]);
                if (t) {
                    const ls2 = lines(t, bw);
                    if (ls2.length === 3 && ls2[2].endsWith('…') && !t.endsWith('…')) cutText = true;
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(textPt); doc.setTextColor(60, 60, 60);
                    ty += 1;
                    ls2.forEach((ln) => { ty += lh(textPt); doc.text(ln, cx + cw / 2, ty, { align: 'center' }); });
                }
            }

            const blob = doc.output('blob');
            pdfFile = new File([blob], fileName(), { type: 'application/pdf' });
            const notes = [];
            if (bad.length) notes.push(`⚠️ ${bad.length === 1 ? 'No bajó 1 foto' : 'No bajaron ' + bad.length + ' fotos'} (${bad.slice(0, 5).map((x) => x.code).join(', ')}${bad.length > 5 ? '…' : ''}): el PDF salió sin ellas.`);
            if (cutText) notes.push('Algún texto era muy largo y se cortó a 3 líneas.');
            if (emojis) notes.push('Los emojis no salen en el PDF.');
            warn(notes.join(' '));
            msg(`✅ PDF listo: ${rows.length} foto${rows.length === 1 ? '' : 's'}, ${pages} página${pages === 1 ? '' : 's'} · ${mb(blob.size)}.`
                + (canShareFiles ? '' : ' Descárgalo y adjúntalo en WhatsApp Web (📎 → Documento).'));
            $('cpShare').hidden = !canShareFiles;
            $('cpDown').hidden = false;
            btn.textContent = '🔄 Rehacer';
        } catch (e) {
            msg('No se pudo generar el PDF: ' + (e && e.message ? e.message : e));
            btn.textContent = '📄 Generar PDF';
        } finally {
            busy = false;
            btn.disabled = false;
        }
    }
    $('cpMake').addEventListener('click', make);

    $('cpShare').addEventListener('click', async () => {
        if (!pdfFile) return;
        try {
            await navigator.share({ files: [pdfFile], title: clean(opts.title) || 'Catálogo' });
            msg('✅ Compartido. Si no lo viste en WhatsApp, toca 📲 otra vez.');
        } catch (e) {
            if (e && e.name === 'AbortError') msg('Cancelado.');
            else if (e && e.name === 'NotAllowedError') msg('El teléfono pidió otro toque: vuelve a tocar 📲 Compartir.');
            else msg('No se pudo compartir: usa ⬇️ Descargar y adjúntalo en WhatsApp.');
        }
    });
    $('cpDown').addEventListener('click', () => {
        if (!pdfFile) return;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(pdfFile);
        a.download = pdfFile.name;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 60000);
        msg('⬇️ Descargado: ' + pdfFile.name + '. En WhatsApp: 📎 → Documento.');
    });

    // Las fotos empiezan a bajar desde que se abre la página (las primeras), así Generar tarda menos
    items.slice(0, 12).forEach((it) => prepare(it.id, MAXPX[+opts.per]).catch(() => {}));
})();
