// Movido desde templates/client/catalog_send.php para que el navegador lo guarde en caché.
(function () {
    const CSRF = window.CS_CSRF || '';
    const $ = (id) => document.getElementById(id);
    const canShareFiles = (() => {
        try {
            return !!(navigator.canShare && navigator.canShare({ files: [new File([new Blob(['x'], { type: 'image/jpeg' })], 'x.jpg', { type: 'image/jpeg' })] }));
        } catch (e) { return false; }
    })();
    const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const TANDA = 10; // fotos por toque al compartir: más que eso se pone lento o el teléfono lo rechaza

    let items = [];      // [{sku, name, price, photos:[{id,name,thumb}]}]
    let tiles = [];      // [{item, photo, n, on, sent}] una por foto mostrada en la galería
    let batchPart = 0;
    const blobs = new Map();  // fileId → Promise<Blob>
    const loaded = new Set(); // fileIds ya descargados

    const fmtPrice = (n) => n > 0 ? '$' + Math.round(n).toLocaleString('es-CO') : '';
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const mode = () => document.querySelector('input[name=csMode]:checked').value;
    const msg = (t) => { $('csMsg').textContent = t || ''; };

    function caption(it) {
        let c = it.sku;
        if (it.name && it.name !== it.sku) c += ' - ' + it.name;
        if ($('csPrice').checked && it.price > 0) c += '\n' + fmtPrice(it.price);
        return c;
    }

    function getBlob(id) {
        if (!blobs.has(id)) {
            const p = fetch('/enviar-catalogo/foto/' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then((r) => { if (!r.ok) throw new Error('foto ' + r.status); return r.blob(); });
            p.catch(() => blobs.delete(id));
            blobs.set(id, p);
        }
        return blobs.get(id);
    }

    // Baja una foto con hasta 3 intentos (el celular a veces corta la conexión: "Failed to fetch")
    async function fetchPhoto(id) {
        let last;
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt) await new Promise((ok) => setTimeout(ok, 700 * attempt));
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 30000);
            try {
                const r = await fetch('/enviar-catalogo/foto/' + encodeURIComponent(id), { credentials: 'same-origin', signal: ctrl.signal });
                if (!r.ok) throw new Error('error ' + r.status);
                const b = await r.blob();
                if (!b.size) throw new Error('foto vacía');
                return b;
            } catch (e) {
                last = e;
                if (e && /error 404/.test(e.message)) break; // no existe: no vale la pena reintentar
            } finally { clearTimeout(timer); }
        }
        throw last;
    }

    function getBlob(id) {
        if (!blobs.has(id)) {
            const p = fetchPhoto(id);
            p.then(() => loaded.add(id), () => blobs.delete(id));
            blobs.set(id, p);
        }
        return blobs.get(id);
    }

    function extFor(type) {
        return type.includes('png') ? 'png' : type.includes('webp') ? 'webp' : 'jpg';
    }

    const fileName = (t) => t.item.sku.replace(/[^\w.-]+/g, '_') + (t.n ? '_' + (t.n + 1) : '');
    async function toFile(t) {
        const blob = await getBlob(t.photo.id);
        return new File([blob], fileName(t) + '.' + extFor(blob.type), { type: blob.type || 'image/jpeg' });
    }
    const selected = () => tiles.filter((t) => t.on);
    function batches() {
        const sel = selected(), out = [];
        for (let i = 0; i < sel.length; i += TANDA) out.push(sel.slice(i, i + TANDA));
        return out;
    }
    function listTextOf(ts) {
        const seen = new Set();
        return ts.filter((t) => !seen.has(t.item) && seen.add(t.item))
            .map((t) => '• ' + caption(t.item).replace('\n', ' · ')).join('\n');
    }

    function buildTiles() {
        const all = mode() === 'all';
        tiles = [];
        items.forEach((it) => {
            (all ? it.photos : it.photos.slice(0, 1)).forEach((ph, n) => tiles.push({ item: it, photo: ph, n, on: true, sent: false }));
        });
        batchPart = 0; tandaNum = 0;
    }

    function renderGrid() {
        $('csGrid').innerHTML = tiles.map((t, i) => `<div class="cs-tile${t.on ? ' on' : ''}${t.sent ? ' sent' : ''}" data-i="${i}" role="checkbox" aria-checked="${t.on}">
                <img src="${esc(t.photo.thumb)}" alt="${esc(t.item.sku)}" loading="lazy">
                <span class="chk"></span>
                <button type="button" class="one" title="Enviar solo esta foto">📲</button>
                <div class="lbl">${esc(t.item.sku)}${t.n ? ' (' + (t.n + 1) + ')' : ''}</div>
            </div>`).join('');
        const sinFoto = items.filter((it) => !it.photos.length).map((it) => it.sku);
        $('csNoPhoto').hidden = !sinFoto.length;
        $('csNoPhoto').textContent = sinFoto.length ? '📷 Sin foto: ' + sinFoto.join(', ') : '';
    }

    function refresh() {
        const sel = selected().length;
        const b = batches();
        if (batchPart >= b.length) batchPart = 0;
        $('csSummary').innerHTML = `<strong>${items.length}</strong> referencias · <strong>${tiles.length}</strong> fotos · `
            + `<strong style="color:#25D366">${sel}</strong> marcadas`;
        $('csSave').disabled = !sel;
        $('csSave').textContent = sel ? `⬇️ Descargar ${sel} foto${sel === 1 ? '' : 's'}` : '⬇️ Descargar fotos';
        preload();
        renderBatchBtn();
    }

    // ---- Enviar por tandas ----
    // Las fotos bajan en segundo plano. El botón verde se activa solo cuando la tanda siguiente
    // (10 fotos) está completa; mientras tanto muestra "Preparando tanda N: 6/10".
    const pending = () => tiles.filter((t) => t.on && !t.wa);
    const nextTanda = () => pending().slice(0, TANDA);
    const readyNow = () => { const t = nextTanda(); return t.every((x) => loaded.has(x.photo.id)) ? t : []; };
    let tandaNum = 0;
    function renderBatchBtn() {
        const btn = $('csBatch');
        const pend = pending();
        const sel = selected().length;
        btn.disabled = !sel;
        if (!sel) { btn.textContent = '📲 Enviar por tandas'; return; }
        if (!pend.length) {
            btn.textContent = '✅ Todo enviado · tocar para empezar de nuevo';
            $('csProgress').textContent = '';
            return;
        }
        const next = nextTanda();
        const ready = readyNow().length;
        const have = next.filter((t) => loaded.has(t.photo.id)).length;
        const totalT = tandaNum + Math.ceil(pend.length / TANDA);
        const wasReady = btn.classList.contains('ready');
        btn.classList.toggle('ready', !!ready);
        btn.disabled = !ready;
        btn.textContent = ready
            ? `📲 Enviar ${tandaNum ? 'siguiente ' : ''}tanda ${tandaNum + 1} de ${totalT} (${ready} foto${ready === 1 ? '' : 's'})`
            : `⏳ Preparando tanda ${tandaNum + 1} de ${totalT}: ${have}/${next.length}…`;
        if (ready && !wasReady) { btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop'); }
        const loadedPend = pend.filter((t) => loaded.has(t.photo.id)).length;
        $('csProgress').textContent = `Listas ${loadedPend} de ${pend.length} por enviar`
            + (loadedPend < pend.length ? ' · siguen bajando…' : '');
    }

    // Baja las marcadas de 3 en 3 en segundo plano, empezando por la tanda actual, para que compartir sea inmediato
    let preloadRun = 0;
    function preload() {
        const run = ++preloadRun;
        const order = pending().concat(selected().filter((t) => t.wa));
        const ids = [...new Set(order.map((t) => t.photo.id))].filter((id) => !loaded.has(id));
        let i = 0;
        const worker = async () => {
            while (run === preloadRun && i < ids.length) {
                const id = ids[i++];
                try { await getBlob(id); } catch (e) {
                    const bad = tiles.filter((t) => t.photo.id === id && t.on);
                    if (bad.length) { dropFailed(bad); msg(`⚠️ No se pudo bajar: ${bad.map(skuOf).join(', ')} (la desmarqué).`); }
                }
                if (run === preloadRun) renderBatchBtn();
            }
        };
        for (let k = 0; k < 4; k++) worker();
    }

    function markSent(ts, wa) {
        ts.forEach((t) => {
            t.sent = true;
            if (wa) t.wa = true;
            const el = document.querySelector(`.cs-tile[data-i="${tiles.indexOf(t)}"]`);
            if (el) el.classList.add('sent');
        });
    }

    // El teléfono solo deja compartir justo después de un toque: si faltan fotos por bajar,
    // se bajan mostrando el avance y se pide tocar otra vez.
    // Si una foto no baja ni con reintentos, se quita de la tanda (se desmarca) y se sigue con las demás.
    async function ensureLoaded(ts, btn, label) {
        if (ts.every((t) => loaded.has(t.photo.id))) return true;
        btn.disabled = true;
        let done = 0;
        const total = ts.length;
        btn.textContent = `⏳ Preparando 0/${total}…`;
        const res = await Promise.allSettled(ts.map((t) => getBlob(t.photo.id).finally(() => { btn.textContent = `⏳ Preparando ${++done}/${total}…`; })));
        const bad = ts.filter((t, i) => res[i].status === 'rejected');
        dropFailed(bad);
        btn.disabled = false; refresh();
        const okCount = total - bad.length;
        msg((bad.length ? `⚠️ No se pudo bajar: ${bad.map(skuOf).join(', ')} (las desmarqué). ` : '')
            + (okCount ? `Fotos listas: toca otra vez "${label}".` : 'Intenta de nuevo en un momento.'));
        return false;
    }

    const skuOf = (t) => t.item.sku + (t.n ? ' (' + (t.n + 1) + ')' : '');
    function dropFailed(bad) {
        bad.forEach((t) => {
            t.on = false;
            const el = document.querySelector(`.cs-tile[data-i="${tiles.indexOf(t)}"]`);
            if (el) { el.classList.remove('on'); el.setAttribute('aria-checked', 'false'); }
        });
    }

    function shareError(e, what) {
        if (e && e.name === 'AbortError') msg('Cancelado.');
        else if (e && e.name === 'NotAllowedError') msg(`El teléfono pidió otro toque: vuelve a tocar ${what}.`);
        else msg('No se pudo: ' + (e && e.message ? e.message : e));
    }

    // 📲 Enviar por tandas: Compartir del celular con las fotos ya bajadas (hasta 10 por toque)
    $('csBatch').addEventListener('click', async () => {
        if (!canShareFiles) {
            msg('En el computador no se pueden pasar varias fotos directo a WhatsApp: usa "⬇️ Descargar fotos" y arrástralas juntas al chat de WhatsApp Web.');
            return;
        }
        if (!pending().length) { // todo enviado: empezar de nuevo
            tiles.forEach((t) => { t.wa = false; });
            tandaNum = 0; refresh(); msg('');
            return;
        }
        const part = readyNow();
        if (!part.length) { preload(); return; }
        try {
            const files = await Promise.all(part.map(toFile));
            await navigator.share({ files }); // solo las fotos: sin la lista en texto
            markSent(part, true);
            tandaNum++;
            const left = pending().length;
            msg(left ? `✅ Tanda ${tandaNum} enviada. Faltan ${left} fotos: el botón verde aparece cuando la siguiente tanda esté lista.` : `✅ Listo: todas enviadas en ${tandaNum} tanda${tandaNum === 1 ? '' : 's'}.`);
            refresh();
        } catch (e) { shareError(e, 'el botón verde'); }
    });

    // ⬇️ Descargar fotos: Android/PC = descargas (aparecen en la galería, álbum "Download");
    // iPhone = menú Compartir → "Guardar imágenes" (va a Fotos).
    $('csSave').addEventListener('click', async () => {
        const sel = selected();
        if (!sel.length) return;
        const btn = $('csSave');
        if (isIOS && canShareFiles) {
            const b = batches();
            const part = b[batchPart];
            if (!(await ensureLoaded(part, btn, 'Guardar'))) return;
            try {
                await navigator.share({ files: await Promise.all(part.map(toFile)) });
                markSent(part);
                const left = b.length - batchPart - 1;
                batchPart = left ? batchPart + 1 : 0;
                msg(left ? `Elige "Guardar imágenes". Faltan ${left} tanda(s): toca otra vez.` : '✅ Listo. Ahora en WhatsApp: 📎 → Galería y selecciónalas.');
                refresh();
            } catch (e) { shareError(e, 'Guardar'); }
            return;
        }
        btn.disabled = true;
        let ok = 0;
        const bad = [];
        for (let i = 0; i < sel.length; i++) {
            btn.textContent = `⬇️ Descargando ${i + 1}/${sel.length}…`;
            try {
                const blob = await getBlob(sel[i].photo.id);
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = fileName(sel[i]) + '.' + extFor(blob.type);
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 4000);
                markSent([sel[i]]);
                ok++;
                await new Promise((r) => setTimeout(r, 300));
            } catch (e) { bad.push(sel[i]); /* sigue con la siguiente */ }
        }
        btn.disabled = false;
        refresh();
        msg((bad.length ? `⚠️ No se pudo bajar: ${bad.map(skuOf).join(', ')}. ` : '')
            + `✅ ${ok} de ${sel.length} fotos descargadas. Si el navegador pregunta, permite "descargar varios archivos". `
            + 'Ahora en WhatsApp: 📎 → Galería (álbum Download) y selecciónalas todas.');
    });

    // Galería: tocar marca/desmarca; 📲 manda solo esa foto
    $('csGrid').addEventListener('click', async (e) => {
        const el = e.target.closest('.cs-tile');
        if (!el) return;
        const t = tiles[+el.dataset.i];
        if (e.target.closest('.one')) {
            if (!canShareFiles) { await copyPhoto(t); return; }
            try {
                await navigator.share({ files: [await toFile(t)], text: caption(t.item) });
                markSent([t], true); msg(''); renderBatchBtn();
            } catch (err) { shareError(err, '📲 en la foto'); }
            return;
        }
        t.on = !t.on;
        el.classList.toggle('on', t.on);
        el.setAttribute('aria-checked', t.on);
        batchPart = 0;
        refresh();
    });

    function setAll(on) {
        tiles.forEach((t) => { t.on = on; });
        batchPart = 0;
        renderGrid(); refresh();
    }

    $('csGenerate').addEventListener('click', async () => {
        const text = $('csText').value.trim();
        if (!text) { $('csText').focus(); return; }
        const btn = $('csGenerate');
        btn.disabled = true; btn.textContent = 'Buscando…';
        try {
            const res = await fetch('/enviar-catalogo/extraer', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ text }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || ('Error ' + res.status));
            items = data.items;
            blobs.clear(); loaded.clear();
            $('csResult').hidden = false;
            buildTiles(); renderGrid(); refresh();
            const nf = data.not_found || [];
            const fx = data.corrected || [];
            const parts = [];
            if (fx.length) parts.push('🔤 Corregidos (el texto decía otra cosa, revisa que sea el código correcto): ' + fx.map((x) => x.read + ' → ' + x.sku).join(', ') + '.');
            if (nf.length) parts.push('⚠️ No están en el catálogo: ' + nf.join(', '));
            $('csNotFound').hidden = !parts.length;
            $('csNotFound').textContent = parts.join(' ');
            msg(canShareFiles ? '' : 'En el computador: "⬇️ Descargar fotos" baja las fotos marcadas; arrástralas juntas al chat de WhatsApp Web. El 📲 de cada foto la copia.');
            $('csResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            alert('No se pudo generar la lista: ' + e.message);
        } finally {
            btn.disabled = false; btn.textContent = '⚡ Generar lista';
        }
    });

    $('csClear').addEventListener('click', () => {
        $('csText').value = ''; items = []; tiles = [];
        $('csResult').hidden = true; $('csText').focus();
    });

    document.querySelectorAll('input[name=csMode]').forEach((r) => r.addEventListener('change', () => {
        if (!items.length) return;
        buildTiles(); renderGrid(); refresh();
    }));

    // Computador: copia la foto (PNG) al portapapeles para pegarla en WhatsApp Web
    async function toPng(blob) {
        if (blob.type === 'image/png') return blob;
        const bmp = await createImageBitmap(blob);
        const c = document.createElement('canvas');
        c.width = bmp.width; c.height = bmp.height;
        c.getContext('2d').drawImage(bmp, 0, 0);
        return await new Promise((ok) => c.toBlob(ok, 'image/png'));
    }

    async function copyPhoto(t) {
        if (!navigator.clipboard || !window.ClipboardItem) {
            msg('Este navegador no permite copiar imágenes. Usa "⬇️ Descargar fotos".');
            return;
        }
        try {
            // El ClipboardItem recibe la promesa: así Safari no pierde el permiso del clic
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': getBlob(t.photo.id).then(toPng) })]);
            markSent([t]);
            msg(`Foto copiada (${t.item.sku}). Pégala en WhatsApp Web con Ctrl+V.`);
        } catch (e) {
            msg('No se pudo copiar: ' + (e && e.message ? e.message : e));
        }
    }

    $('csAll').addEventListener('click', () => setAll(true));
    // 📌 Las marcadas se suman a "Mi selección" (misma lista que el catálogo, wa_batch.js) y se abre esa pantalla
    $('csToSel').addEventListener('click', () => {
        const ts = selected();
        if (!ts.length) { msg('Marca al menos una foto.'); return; }
        let store;
        try { localStorage.setItem('kinoT', '1'); localStorage.removeItem('kinoT'); store = localStorage; } catch (e) { store = sessionStorage; }
        let d;
        try { d = JSON.parse(store.getItem('kinoWaSel') || 'null'); } catch (e) { d = null; }
        if (!d || !Array.isArray(d.items)) d = { items: [], tanda: 0, last: null };
        const have = new Set(d.items.map((x) => x.id));
        ts.forEach((t) => { if (!have.has(t.photo.id)) { d.items.push({ id: t.photo.id, sku: t.item.sku, wa: false, err: false }); have.add(t.photo.id); } });
        try { store.setItem('kinoWaSel', JSON.stringify(d)); } catch (e) { msg('No se pudo guardar en este dispositivo.'); return; }
        location.href = '/mi-seleccion';
    });
    // Catálogo en PDF (docs/PLAN_PDF.md, P2): las marcadas, en orden, sin tocar Mi selección
    $('csPdf').addEventListener('click', () => {
        const ts = selected();
        if (!ts.length) { msg('Marca al menos una foto.'); return; }
        try { sessionStorage.setItem('kinoPdfSrc', JSON.stringify({ items: ts.map((t) => ({ id: t.photo.id, sku: t.item.sku })), from: ['Enviar catálogo', '/enviar-catalogo'] })); }
        catch (e) { msg('No se pudo preparar el PDF en este dispositivo.'); return; }
        location.href = '/catalogo-pdf?desde=lista';
    });
    $('csNone').addEventListener('click', () => setAll(false));

    $('csCopyList').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(listTextOf(selected())); msg('Lista copiada.'); }
        catch (e) { msg('No se pudo copiar la lista.'); }
    });

    $('csOpenChat').addEventListener('click', () => {
        const phone = $('csPhone').value.replace(/\D+/g, '');
        const url = (phone ? 'https://wa.me/' + phone : 'https://wa.me/') + '?text=' + encodeURIComponent(listTextOf(selected()));
        window.open(url, '_blank', 'noopener');
    });

    // Llegada desde el Extractor de pedidos PDF: trae los códigos en sessionStorage y genera la lista sola.
    try {
        const fromEx = sessionStorage.getItem('kinoCsText');
        if (fromEx) {
            sessionStorage.removeItem('kinoCsText');
            $('csText').value = fromEx;
            $('csGenerate').click();
        }
    } catch (e) { /* sin sessionStorage: se pega a mano */ }
})();
