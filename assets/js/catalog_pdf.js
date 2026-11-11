/**
 * Catálogo en PDF (/catalogo-pdf, docs/PLAN_PDF.md etapa P1).
 * Lista = lo marcado (localStorage kinoWaSel de wa_batch.js) o, si la URL trae ?desde=lista, la lista que otra
 * pantalla dejó en sessionStorage kinoPdfSrc ({name, items:[{id, sku}]}).
 * Cada foto lleva su código y, si el usuario escribió algo, su texto (uno por código, se recuerda en kinoPdfTexts).
 * El PDF se arma aquí con jsPDF (A4, 4/6/9 por página, logo/título/contacto opcionales arriba en cada página).
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
    let texts = readJson(ls, 'kinoPdfTexts', {});
    if (typeof texts !== 'object' || Array.isArray(texts)) texts = {};
    const opts = Object.assign({ title: '', contact: '', per: 6 }, readJson(ls, 'kinoPdfOpts', {}));
    if (![4, 6, 9].includes(+opts.per)) opts.per = 6;
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

    // ---- Pantalla ----
    const list = $('cpList');
    list.innerHTML = items.map((it, i) => `<li class="ms-item cp-item" data-id="${esc(it.id)}">
            <span class="ms-n">${i + 1}</span>
            <img src="https://lh3.googleusercontent.com/d/${encodeURIComponent(it.id)}=s200" alt="" loading="lazy">
            <span class="cp-info"><strong>${esc(it.code)}</strong>
                <textarea rows="2" maxlength="120" data-code="${esc(it.code)}" placeholder="Precio u otra información (opcional)">${esc(texts[it.code] || '')}</textarea>
            </span></li>`).join('');

    $('cpTitle').value = opts.title;
    $('cpContact').value = opts.contact;
    document.querySelectorAll('input[name="cpPer"]').forEach((r) => { r.checked = +r.value === +opts.per; });
    if (src.cut) warn(`El PDF admite hasta ${MAX_ITEMS} fotos: se dejaron las primeras ${MAX_ITEMS} (sobran ${src.cut}).`);

    function warn(t) { const w = $('cpWarn'); w.textContent = t || ''; w.hidden = !t; }
    function msg(t) { $('cpMsg').textContent = t || ''; }

    let saveT;
    function saveTexts() {
        clearTimeout(saveT);
        saveT = setTimeout(() => {
            // Solo códigos con texto (los vacíos no ocupan espacio)
            Object.keys(texts).forEach((k) => { if (!texts[k]) delete texts[k]; });
            if (!writeJson('kinoPdfTexts', texts)) msg('⚠️ No se pudieron guardar los textos en este dispositivo (memoria llena).');
        }, 300);
    }
    function saveOpts() { writeJson('kinoPdfOpts', opts); }

    // Un texto por código: si el mismo código tiene varias fotos, todas muestran lo mismo
    list.addEventListener('input', (e) => {
        const ta = e.target;
        if (ta.tagName !== 'TEXTAREA') return;
        const c = ta.dataset.code;
        texts[c] = ta.value;
        list.querySelectorAll('textarea').forEach((o) => { if (o !== ta && o.dataset.code === c) o.value = ta.value; });
        saveTexts(); stale();
    });
    $('cpTitle').addEventListener('input', (e) => { opts.title = e.target.value; saveOpts(); stale(); });
    $('cpContact').addEventListener('input', (e) => { opts.contact = e.target.value; saveOpts(); stale(); });
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

            // 2) Páginas
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true });
            const W = 210, H = 297, M = 10, GAP = 4;
            const [cols, rowsPerPage] = LAYOUT[per];
            const title = clean(opts.title), contact = clean(opts.contact);
            const hasHead = !!(logo || title || contact);
            const headH = hasHead ? 18 : 0;
            const top = M + (hasHead ? headH + 4 : 0);
            const bottom = H - 12;
            const cw = (W - 2 * M - (cols - 1) * GAP) / cols;
            const ch = (bottom - top - (rowsPerPage - 1) * GAP) / rowsPerPage;
            const codePt = cols === 2 ? 12 : 10, textPt = cols === 2 ? 10 : 8.5;
            const lh = (pt) => pt * 0.3528 * 1.2;
            const anyText = rows.some((r) => clean(texts[r.it.code]));
            const textH = lh(codePt) + (anyText ? 3 * lh(textPt) : 0) + 2;
            const pages = Math.ceil(rows.length / per);
            const emojis = rows.some((r) => lostChars(texts[r.it.code])) || lostChars(opts.title) || lostChars(opts.contact);

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
            let cutText = false;

            for (let p = 0; p < pages; p++) {
                if (p) doc.addPage();
                btn.textContent = `⏳ Página ${p + 1}/${pages}`;
                msg(`Armando página ${p + 1} de ${pages}…`);
                await new Promise((ok) => setTimeout(ok, 0)); // deja respirar la pantalla

                if (hasHead) {
                    let x = M;
                    if (logo) {
                        const k = Math.min(36 / logo.w, headH / logo.h);
                        const lw = logo.w * k, lhh = logo.h * k;
                        doc.addImage(logo.src, 'PNG', M, M + (headH - lhh) / 2, lw, lhh, 'kinoLogo', 'FAST');
                        x = M + lw + 5;
                    }
                    doc.setTextColor(20, 20, 20);
                    if (title) { doc.setFont('helvetica', 'bold'); doc.setFontSize(16); doc.text(doc.splitTextToSize(title, W - M - x)[0], x, M + (contact ? 7.5 : 11)); }
                    if (contact) { doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(90, 90, 90); doc.text(doc.splitTextToSize(contact, W - M - x)[0], x, M + (title ? 14 : 11)); }
                    doc.setDrawColor(210, 210, 210); doc.setLineWidth(0.3);
                    doc.line(M, M + headH + 1.5, W - M, M + headH + 1.5);
                }

                rows.slice(p * per, p * per + per).forEach((r, k) => {
                    const cx = M + (k % cols) * (cw + GAP);
                    const cy = top + Math.floor(k / cols) * (ch + GAP);
                    doc.setDrawColor(225, 225, 225); doc.setLineWidth(0.25);
                    doc.roundedRect(cx, cy, cw, ch, 2, 2, 'S');
                    // Foto entera (sin recortar), centrada en su recuadro
                    const bw = cw - 4, bh = ch - textH - 4;
                    const s = Math.min(bw / r.img.w, bh / r.img.h);
                    const iw = r.img.w * s, ih = r.img.h * s;
                    doc.addImage(r.img.data, 'JPEG', cx + 2 + (bw - iw) / 2, cy + 2 + (bh - ih) / 2, iw, ih, undefined, 'FAST');
                    // Código y texto
                    let ty = cy + 2 + bh + 1 + lh(codePt) * 0.8;
                    doc.setTextColor(17, 17, 17); doc.setFont('helvetica', 'bold'); doc.setFontSize(codePt);
                    doc.text(doc.splitTextToSize(clean(r.it.code), bw)[0] || '', cx + cw / 2, ty, { align: 'center' });
                    const t = clean(texts[r.it.code]);
                    if (t) {
                        const ls2 = lines(t, bw);
                        if (ls2.length === 3 && ls2[2].endsWith('…') && !t.endsWith('…')) cutText = true;
                        doc.setFont('helvetica', 'normal'); doc.setFontSize(textPt); doc.setTextColor(50, 50, 50);
                        ls2.forEach((ln) => { ty += lh(textPt); doc.text(ln, cx + cw / 2, ty, { align: 'center' }); });
                    }
                });

                doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(140, 140, 140);
                doc.text(`Página ${p + 1} de ${pages}`, W / 2, H - 6, { align: 'center' });
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
