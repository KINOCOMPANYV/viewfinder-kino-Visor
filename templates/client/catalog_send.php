<?php
/**
 * Enviar catálogo por lista (público: /enviar-catalogo, botón en el inicio y en el catálogo).
 * Se pega un texto, se sacan los códigos (POST /enviar-catalogo/extraer), se muestra la lista con sus fotos
 * en una galería: tocar una foto la marca/desmarca. Las marcadas se guardan en el celular de un toque (para mandarlas
 * luego desde WhatsApp) o se comparten a WhatsApp por tandas de 10. El 📲 de cada foto la manda sola.
 * Las fotos salen de /enviar-catalogo/foto/{id}.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar catálogo · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .cs-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; }
        .cs-card h2 { font-size:1.1rem; margin-bottom:0.35rem; }
        .cs-card p.help { color:var(--color-text-muted); font-size:0.85rem; margin-bottom:0.9rem; }
        .cs-text { width:100%; min-height:160px; resize:vertical; background:var(--color-surface-2); color:inherit; border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:0.75rem; font:inherit; font-size:0.9rem; }
        .cs-options { display:flex; flex-wrap:wrap; gap:0.5rem 1.25rem; margin:0.8rem 0; font-size:0.88rem; color:var(--color-text-muted); }
        .cs-options label { display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; }
        .cs-actions { display:flex; flex-wrap:wrap; gap:0.6rem; align-items:center; }
        .cs-summary { font-size:0.9rem; margin-bottom:0.75rem; }
        .cs-warn { font-size:0.82rem; color:#fbbf24; margin-top:0.5rem; word-break:break-word; }
        .cs-bulk { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.6rem; }
        /* Galería: tocar una foto la marca o desmarca */
        .cs-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:0.5rem; }
        .cs-tile { position:relative; border-radius:10px; overflow:hidden; background:#111; border:3px solid transparent; cursor:pointer; user-select:none; -webkit-user-select:none; -webkit-touch-callout:none; }
        .cs-tile img { display:block; width:100%; aspect-ratio:1; object-fit:cover; pointer-events:none; }
        .cs-tile.on { border-color:#25D366; }
        .cs-tile:not(.on) img { opacity:0.4; }
        .cs-tile .chk { position:absolute; top:6px; left:6px; width:26px; height:26px; border-radius:50%; border:2px solid #fff; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; color:#062b16; }
        .cs-tile.on .chk { background:#25D366; border-color:#25D366; }
        .cs-tile.on .chk::after { content:'✓'; }
        .cs-tile .one { position:absolute; top:4px; right:4px; width:36px; height:36px; border-radius:50%; border:none; background:rgba(0,0,0,0.6); font-size:17px; cursor:pointer; }
        .cs-tile .lbl { position:absolute; left:0; right:0; bottom:0; padding:0.25rem 0.4rem; background:linear-gradient(transparent, rgba(0,0,0,0.85)); font-size:0.72rem; font-family:monospace; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .cs-tile.sent .lbl::before { content:'✅ '; }
        .cs-nophoto { font-size:0.8rem; color:#fbbf24; margin-top:0.6rem; }
        .cs-bar { position:sticky; bottom:0; z-index:5; background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:0.8rem; margin-top:1rem; box-shadow:0 -6px 20px rgba(0,0,0,0.35); }
        .cs-bar .cs-actions .btn { flex:1 1 220px; }
        .cs-big { font-size:1rem; padding:0.75rem 1.1rem; }
        .btn-wa { background:#25D366; color:#062b16; border:none; font-weight:700; }
        .btn-wa:disabled { opacity:0.6; }
        #csBatch:disabled { background:var(--color-surface-2); color:var(--color-text-muted); border:1px solid var(--color-border); opacity:1; }
        #csBatch.pop { animation:csPop 0.6s ease; }
        @keyframes csPop { 0% { transform:scale(1); } 40% { transform:scale(1.05); box-shadow:0 0 0 6px rgba(37,211,102,0.35); } 100% { transform:scale(1); } }
        .cs-more { margin-top:1rem; font-size:0.85rem; }
        .cs-more summary { cursor:pointer; color:var(--color-text-muted); }
        .cs-row { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin-top:0.6rem; font-size:0.85rem; }
        .cs-row input[type=tel] { background:var(--color-surface-2); color:inherit; border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:0.5rem 0.6rem; width:170px; }
        .cs-msg { font-size:0.82rem; color:var(--color-text-muted); margin-top:0.5rem; min-height:1.2em; }
        @media (max-width:640px) {
            .cs-grid { grid-template-columns:repeat(3, 1fr); gap:0.35rem; }
            .cs-options { flex-direction:column; gap:0.15rem; }
            .cs-options label { min-height:40px; font-size:0.92rem; }
            .cs-options input { width:20px; height:20px; }
            .cs-card { padding:1rem; }
            .cs-bulk .btn { flex:1; }
            .cs-bar .cs-actions .btn { flex:1 1 100%; }
            .cs-row { flex-direction:column; align-items:stretch; }
            .cs-row input[type=tel] { width:100%; }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/" class="btn btn-sm btn-secondary" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Catálogo</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem; max-width:860px;">
        <h1 style="font-size:1.5rem; margin-bottom:1.25rem;">📤 Enviar catálogo por lista</h1>

        <section class="cs-card">
            <h2>1. Pega el texto</h2>
            <p class="help">Pega cualquier texto: un chat, un pedido o una lista. El sistema saca los códigos que existen
                en el catálogo (acepta M8363-3, M:8363-3, M-8363-3…) y arma la lista con sus fotos.</p>
            <textarea id="csText" class="cs-text" placeholder="Ej.: Hola, me mandas fotos de M8363-3, X6071 y 1416-1 por favor"></textarea>
            <div class="cs-options">
                <label><input type="radio" name="csMode" value="cover" checked> Solo la foto principal</label>
                <label><input type="radio" name="csMode" value="all"> Todas las fotos de cada código</label>
                <label><input type="checkbox" id="csPrice"> Poner el precio en el texto</label>
            </div>
            <div class="cs-actions">
                <button type="button" id="csGenerate" class="btn btn-primary">⚡ Generar lista</button>
                <button type="button" id="csClear" class="btn btn-secondary btn-sm">Limpiar</button>
            </div>
        </section>

        <section class="cs-card" id="csResult" hidden>
            <h2>2. Elige las fotos</h2>
            <div class="cs-summary" id="csSummary"></div>
            <p class="help">Toca una foto para marcarla o desmarcarla (verde = va). Luego, abajo:
                <strong>💾 Guardar en el celular</strong> y las mandas desde WhatsApp (📎 → Galería, hasta 100 juntas), o
                <strong>📲 Enviar por tandas</strong> directo a WhatsApp, 10 por toque. El 📲 de cada foto la manda sola.</p>
            <div class="cs-bulk">
                <button type="button" id="csAll" class="btn btn-secondary btn-sm">Marcar todas</button>
                <button type="button" id="csNone" class="btn btn-secondary btn-sm">Desmarcar todas</button>
            </div>
            <div class="cs-grid" id="csGrid"></div>
            <div class="cs-nophoto" id="csNoPhoto" hidden></div>
            <div class="cs-warn" id="csNotFound" hidden></div>

            <div class="cs-bar" id="csBar">
                <div class="cs-actions">
                    <button type="button" id="csSave" class="btn btn-secondary cs-big">💾 Guardar en el celular</button>
                    <button type="button" id="csBatch" class="btn btn-wa cs-big">📲 Enviar por tandas</button>
                </div>
                <div class="cs-msg" id="csProgress"></div>
                <div class="cs-msg" id="csMsg"></div>
            </div>

            <details class="cs-more">
                <summary>Más opciones (lista en texto, abrir chat)</summary>
                <div class="cs-row">
                    <span>Abrir un chat:</span>
                    <input type="tel" id="csPhone" placeholder="Número con indicativo, ej. 573001234567" inputmode="tel">
                    <button type="button" id="csOpenChat" class="btn btn-secondary btn-sm">💬 Abrir chat con la lista</button>
                    <button type="button" id="csCopyList" class="btn btn-secondary btn-sm">Copiar lista en texto</button>
                </div>
            </details>
        </section>
    </main>

    <script>
    (function () {
        const CSRF = '<?= csrfToken() ?>';
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
            $('csSave').textContent = sel ? `💾 Guardar ${sel} en el celular` : '💾 Guardar en el celular';
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
                msg('En el computador no se pueden pasar varias fotos directo a WhatsApp: usa "💾 Guardar" y arrástralas juntas al chat de WhatsApp Web.');
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
                await navigator.share({ files, text: listTextOf(part) });
                markSent(part, true);
                tandaNum++;
                const left = pending().length;
                msg(left ? `✅ Tanda ${tandaNum} enviada. Faltan ${left} fotos: el botón verde aparece cuando la siguiente tanda esté lista.` : `✅ Listo: todas enviadas en ${tandaNum} tanda${tandaNum === 1 ? '' : 's'}.`);
                refresh();
            } catch (e) { shareError(e, 'el botón verde'); }
        });

        // 💾 Guardar en el celular: Android/PC = descargas (aparecen en la galería, álbum "Download");
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
                btn.textContent = `💾 Guardando ${i + 1}/${sel.length}…`;
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
                + `✅ ${ok} de ${sel.length} fotos guardadas. Si el navegador pregunta, permite "descargar varios archivos". `
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
                $('csNotFound').hidden = !nf.length;
                $('csNotFound').textContent = nf.length ? '⚠️ No están en el catálogo: ' + nf.join(', ') : '';
                msg(canShareFiles ? '' : 'En el computador: "💾 Guardar" baja las fotos marcadas; arrástralas juntas al chat de WhatsApp Web. El 📲 de cada foto la copia.');
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
                msg('Este navegador no permite copiar imágenes. Usa "💾 Guardar".');
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
    })();
    </script>
</body>

</html>
