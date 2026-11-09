<?php
/**
 * Enviar catálogo por lista (público: /enviar-catalogo, botón en el inicio y en el catálogo).
 * Se pega un texto, se sacan los códigos (POST /enviar-catalogo/extraer), se muestra la lista con sus fotos
 * y se envían por WhatsApp una por una: en el celular con el menú "Compartir" del sistema; en el computador
 * copiando cada foto para pegarla en WhatsApp Web. Las fotos salen de /enviar-catalogo/foto/{id}.
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
        .cs-list { display:flex; flex-direction:column; gap:0.5rem; }
        .cs-item { display:flex; gap:0.75rem; align-items:center; padding:0.6rem; border:1px solid var(--color-border); border-radius:var(--radius-sm); background:var(--color-surface-2); }
        .cs-item.off { opacity:0.45; }
        .cs-item.sent { border-color:rgba(52,168,83,0.5); }
        .cs-item.now { border-color:#25D366; box-shadow:0 0 0 2px rgba(37,211,102,0.35); }
        .cs-item .cs-wa { flex-shrink:0; font-size:0.8rem; padding:0.4rem 0.6rem; white-space:nowrap; }
        .cs-bulk { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.6rem; }
        .cs-item input[type=checkbox] { width:20px; height:20px; flex-shrink:0; }
        .cs-item .info { flex:1; min-width:0; }
        .cs-item .sku { font-weight:700; font-family:monospace; font-size:0.95rem; }
        .cs-item .name { color:var(--color-text-muted); font-size:0.82rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .cs-thumbs { display:flex; gap:4px; flex-wrap:wrap; justify-content:flex-end; max-width:50%; }
        .cs-thumbs img { width:56px; height:56px; object-fit:cover; border-radius:6px; background:#111; }
        .cs-thumbs .none { width:56px; height:56px; display:flex; align-items:center; justify-content:center; border-radius:6px; background:#111; font-size:0.7rem; color:var(--color-text-muted); text-align:center; }
        .cs-send { position:sticky; bottom:0; z-index:5; background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1rem; margin-top:1rem; box-shadow:0 -6px 20px rgba(0,0,0,0.35); }
        .cs-now { display:flex; gap:0.75rem; align-items:center; margin-bottom:0.75rem; }
        .cs-now img { width:72px; height:72px; object-fit:cover; border-radius:8px; background:#111; }
        .cs-now .cap { font-size:0.85rem; white-space:pre-line; }
        .cs-now .count { font-size:0.8rem; color:var(--color-text-muted); }
        .cs-big { font-size:1rem; padding:0.75rem 1.1rem; }
        .btn-wa { background:#25D366; color:#062b16; border:none; font-weight:700; }
        .btn-wa:disabled { opacity:0.6; }
        .cs-row { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin-top:0.6rem; font-size:0.85rem; }
        .cs-row input[type=tel] { background:var(--color-surface-2); color:inherit; border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:0.5rem 0.6rem; width:170px; }
        .cs-msg { font-size:0.82rem; color:var(--color-text-muted); margin-top:0.5rem; min-height:1.2em; }
        @media (max-width:640px) {
            .cs-thumbs { max-width:40%; }
            .cs-thumbs img, .cs-thumbs .none { width:48px; height:48px; }
            /* Opciones y botones con tamaño de dedo, todos del mismo ancho */
            .cs-options { flex-direction:column; gap:0.15rem; }
            .cs-options label { min-height:40px; font-size:0.92rem; }
            .cs-options input { width:20px; height:20px; }
            .cs-card { padding:1rem; }
            .cs-bulk .btn { flex:1; }
            .cs-send .cs-actions .btn { flex:1 1 100%; }
            .cs-send .cs-actions .btn-sm { flex:1 1 calc(50% - 0.5rem); }
            .cs-row { flex-direction:column; align-items:stretch; }
            .cs-row input[type=tel] { width:100%; }
            .cs-item .cs-wa { min-height:40px; }
            /* El panel de envío es alto: pegado abajo tapaba toda la lista en el celular */
            .cs-send { position:static; box-shadow:none; }
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
            <h2>2. Revisa la lista</h2>
            <div class="cs-summary" id="csSummary"></div>
            <p class="help">Toca <strong>📲</strong> en cada producto para mandar esa foto por WhatsApp al instante.
                Las casillas ✅ eligen qué entra en "Copiar foto", "Descargar todas" y la lista en texto.</p>
            <div class="cs-bulk">
                <button type="button" id="csAll" class="btn btn-secondary btn-sm">Marcar todos</button>
                <button type="button" id="csNone" class="btn btn-secondary btn-sm">Desmarcar todos</button>
            </div>
            <div class="cs-list" id="csList"></div>
            <div class="cs-warn" id="csNotFound" hidden></div>

            <div class="cs-send" id="csSend">
                <div class="cs-now">
                    <img id="csNowImg" alt="">
                    <div>
                        <div class="count" id="csNowCount"></div>
                        <div class="cap" id="csNowCap"></div>
                    </div>
                </div>
                <div class="cs-actions">
                    <button type="button" id="csCopy" class="btn btn-secondary">📋 Copiar foto</button>
                    <button type="button" id="csCopyCap" class="btn btn-secondary btn-sm">Copiar texto</button>
                    <button type="button" id="csSkip" class="btn btn-secondary btn-sm">Saltar ⏭</button>
                    <button type="button" id="csRestart" class="btn btn-secondary btn-sm">↺ Empezar de nuevo</button>
                </div>
                <div class="cs-row">
                    <span>Abrir un chat:</span>
                    <input type="tel" id="csPhone" placeholder="Número con indicativo, ej. 573001234567" inputmode="tel">
                    <button type="button" id="csOpenChat" class="btn btn-secondary btn-sm">💬 Abrir chat con la lista</button>
                    <button type="button" id="csCopyList" class="btn btn-secondary btn-sm">Copiar lista en texto</button>
                    <button type="button" id="csDownload" class="btn btn-secondary btn-sm">⬇️ Descargar todas</button>
                </div>
                <div class="cs-msg" id="csMsg"></div>
            </div>
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

        let items = [];      // [{sku, name, price, photos:[{id,name,thumb}], on}]
        let queue = [];      // [{item, photo, caption, filename}]
        let pos = 0;
        const blobs = new Map(); // fileId → Promise<Blob>

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

        function extFor(type) {
            return type.includes('png') ? 'png' : type.includes('webp') ? 'webp' : 'jpg';
        }

        function buildQueue() {
            queue = [];
            const all = mode() === 'all';
            items.forEach((it) => {
                if (!it.on || !it.photos.length) return;
                (all ? it.photos : it.photos.slice(0, 1)).forEach((ph, i) => {
                    queue.push({ item: it, photo: ph, caption: caption(it), filename: it.sku.replace(/[^\w.-]+/g, '_') + (i ? '_' + (i + 1) : '') });
                });
            });
            if (pos > queue.length) pos = queue.length;
            renderNow();
            preloadQueue();
        }

        function renderList() {
            const list = $('csList');
            list.innerHTML = items.map((it, idx) => {
                const thumbs = it.photos.length
                    ? (mode() === 'all' ? it.photos : it.photos.slice(0, 1)).slice(0, 6)
                        .map((p) => `<img src="${esc(p.thumb)}" alt="" loading="lazy">`).join('')
                    : '<div class="none">Sin foto</div>';
                return `<label class="cs-item${it.on ? '' : ' off'}" data-idx="${idx}">
                    <input type="checkbox" ${it.on ? 'checked' : ''} ${it.photos.length ? '' : 'disabled'}>
                    ${it.photos.length ? `<button type="button" class="btn btn-wa btn-sm cs-wa" title="Enviar esta foto por WhatsApp">📲</button>` : ''}
                    <div class="info">
                        <div class="sku">${esc(it.sku)}</div>
                        <div class="name">${esc(it.name || '')}${it.price > 0 ? ' · ' + fmtPrice(it.price) : ''}</div>
                    </div>
                    <div class="cs-thumbs">${thumbs}</div>
                </label>`;
            }).join('');
            list.querySelectorAll('.cs-item input').forEach((cb) => {
                cb.addEventListener('change', () => {
                    const it = items[+cb.closest('.cs-item').dataset.idx];
                    it.on = cb.checked;
                    cb.closest('.cs-item').classList.toggle('off', !it.on);
                    pos = 0;
                    buildQueue();
                    renderSummary();
                });
            });
            list.querySelectorAll('.cs-item .cs-wa').forEach((b) => {
                b.addEventListener('click', (e) => {
                    e.preventDefault();
                    sendItem(items[+b.closest('.cs-item').dataset.idx], b);
                });
            });
        }

        function setAll(on) {
            items.forEach((it) => { if (it.photos.length) it.on = on; });
            pos = 0;
            renderList(); buildQueue(); renderSummary();
        }

        function renderSummary(notFound) {
            const withPhoto = items.filter((i) => i.photos.length).length;
            const without = items.length - withPhoto;
            $('csSummary').innerHTML = `<strong>${items.length}</strong> referencias encontradas · `
                + `<strong>${queue.length}</strong> fotos para enviar`
                + (without ? ` · <span style="color:#fbbf24">${without} sin foto</span>` : '');
            if (notFound) {
                const nf = $('csNotFound');
                nf.hidden = !notFound.length;
                nf.textContent = notFound.length ? '⚠️ No están en el catálogo: ' + notFound.join(', ') : '';
            }
        }

        function renderNow() {
            const done = pos >= queue.length;
            // Resalta en la lista el producto que se va a enviar
            document.querySelectorAll('.cs-item.now').forEach((el) => el.classList.remove('now'));
            if (!done && queue[pos]) {
                const cur = document.querySelector(`.cs-item[data-idx="${items.indexOf(queue[pos].item)}"]`);
                if (cur) cur.classList.add('now');
            }
            ['csCopy', 'csCopyCap', 'csSkip'].forEach((id) => { $(id).disabled = done || !queue.length; });
            if (!queue.length) {
                $('csNowImg').removeAttribute('src');
                $('csNowCount').textContent = 'No hay fotos para enviar: marca al menos un producto.';
                $('csNowCap').textContent = '';
                return;
            }
            if (done) {
                $('csNowImg').removeAttribute('src');
                $('csNowCount').textContent = `✅ Listo: ${queue.length} de ${queue.length} enviadas.`;
                $('csNowCap').textContent = '';
                return;
            }
            const q = queue[pos];
            $('csNowImg').src = q.photo.thumb;
            const nProd = new Set(queue.map((x) => x.item)).size;
            $('csNowCount').textContent = `Foto ${pos + 1} de ${queue.length} · ${nProd} producto${nProd === 1 ? '' : 's'} marcado${nProd === 1 ? '' : 's'}`;
            $('csNowCap').textContent = q.caption;
            $('csCopy').textContent = `📋 Copiar foto ${pos + 1} de ${queue.length}`;
            // Precarga la actual y la siguiente: compartir necesita el archivo listo al tocar
            getBlob(q.photo.id).catch(() => {});
            if (queue[pos + 1]) getBlob(queue[pos + 1].photo.id).catch(() => {});
        }

        // Precarga las fotos marcadas de 3 en 3 (en orden) para que el botón 📲 de cada fila comparta al instante
        let preloadRun = 0;
        function preloadQueue() {
            const run = ++preloadRun;
            const ids = [...new Set(queue.map((q) => q.photo.id))];
            let i = 0;
            const worker = async () => {
                while (run === preloadRun && i < ids.length) {
                    try { await getBlob(ids[i++]); } catch (e) { /* sigue */ }
                }
            };
            for (let k = 0; k < 3; k++) worker();
        }

        function itemEntries(it) {
            const all = mode() === 'all';
            return (all ? it.photos : it.photos.slice(0, 1)).map((ph, i) => ({
                item: it, photo: ph, caption: caption(it), filename: it.sku.replace(/[^\w.-]+/g, '_') + (i ? '_' + (i + 1) : ''),
            }));
        }

        // Botón 📲 de cada fila: comparte solo las fotos de ese producto (celular) o copia la foto (computador)
        async function sendItem(it, btn) {
            const entries = itemEntries(it);
            if (!entries.length) return;
            if (!canShareFiles) { await copyPhoto(entries[0]); return; }
            btn.disabled = true;
            try {
                const files = await Promise.all(entries.map(async (q) => {
                    const blob = await getBlob(q.photo.id);
                    return new File([blob], q.filename + '.' + extFor(blob.type), { type: blob.type || 'image/jpeg' });
                }));
                await navigator.share({ files, text: entries[0].caption });
                entries.forEach(markSent);
                msg('');
            } catch (e) {
                if (e && e.name === 'AbortError') msg('Cancelado.');
                else if (e && e.name === 'NotAllowedError') msg('El teléfono pidió otro toque: vuelve a tocar 📲 (la foto ya está lista).');
                else msg('No se pudo enviar: ' + (e && e.message ? e.message : e));
            } finally {
                btn.disabled = false;
            }
        }

        function markSent(q) {
            const el = document.querySelector(`.cs-item[data-idx="${items.indexOf(q.item)}"]`);
            if (el) el.classList.add('sent');
        }

        function next() { pos++; renderNow(); }

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
                items = data.items.map((it) => ({ ...it, on: it.photos.length > 0 }));
                pos = 0;
                blobs.clear();
                $('csResult').hidden = false;
                renderList();
                buildQueue();
                renderSummary(data.not_found || []);
                msg(canShareFiles ? 'Toca 📲 en cada producto para mandarlo por WhatsApp.' : 'En el computador: 📲 copia la foto de esa fila; pégala en el chat de WhatsApp Web (Ctrl+V).');
                $('csResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (e) {
                alert('No se pudo generar la lista: ' + e.message);
            } finally {
                btn.disabled = false; btn.textContent = '⚡ Generar lista';
            }
        });

        $('csClear').addEventListener('click', () => {
            $('csText').value = ''; items = []; queue = []; pos = 0;
            $('csResult').hidden = true; $('csText').focus();
        });

        document.querySelectorAll('input[name=csMode]').forEach((r) => r.addEventListener('change', () => {
            if (!items.length) return;
            pos = 0; renderList(); buildQueue(); renderSummary();
        }));
        $('csPrice').addEventListener('change', () => { if (items.length) buildQueue(); });

        // Computador: copia la foto (PNG) al portapapeles para pegarla en WhatsApp Web
        async function toPng(blob) {
            if (blob.type === 'image/png') return blob;
            const bmp = await createImageBitmap(blob);
            const c = document.createElement('canvas');
            c.width = bmp.width; c.height = bmp.height;
            c.getContext('2d').drawImage(bmp, 0, 0);
            return await new Promise((ok) => c.toBlob(ok, 'image/png'));
        }

        async function copyPhoto(q) {
            if (!navigator.clipboard || !window.ClipboardItem) {
                msg('Este navegador no permite copiar imágenes. Usa "Descargar todas".');
                return false;
            }
            try {
                // El ClipboardItem recibe la promesa: así Safari no pierde el permiso del clic
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': getBlob(q.photo.id).then(toPng) })]);
                markSent(q);
                msg(`Foto copiada (${q.item.sku}). Pégala en WhatsApp Web con Ctrl+V.`);
                return true;
            } catch (e) {
                msg('No se pudo copiar: ' + (e && e.message ? e.message : e));
                return false;
            }
        }

        $('csCopy').addEventListener('click', async () => {
            const q = queue[pos];
            if (!q) return;
            if (await copyPhoto(q)) next();
        });

        $('csCopyCap').addEventListener('click', async () => {
            const q = queue[pos];
            if (!q) return;
            try { await navigator.clipboard.writeText(q.caption); msg('Texto copiado.'); }
            catch (e) { msg('No se pudo copiar el texto.'); }
        });

        $('csSkip').addEventListener('click', next);
        $('csAll').addEventListener('click', () => setAll(true));
        $('csNone').addEventListener('click', () => setAll(false));
        $('csRestart').addEventListener('click', () => {
            pos = 0;
            document.querySelectorAll('.cs-item.sent').forEach((el) => el.classList.remove('sent'));
            renderNow(); msg('');
        });

        function listText() {
            const on = items.filter((i) => i.on);
            return on.map((it) => '• ' + caption(it).replace('\n', ' · ')).join('\n');
        }

        $('csCopyList').addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(listText()); msg('Lista copiada.'); }
            catch (e) { msg('No se pudo copiar la lista.'); }
        });

        $('csOpenChat').addEventListener('click', () => {
            const phone = $('csPhone').value.replace(/\D+/g, '');
            const url = (phone ? 'https://wa.me/' + phone : 'https://wa.me/') + '?text=' + encodeURIComponent(listText());
            window.open(url, '_blank', 'noopener');
        });

        $('csDownload').addEventListener('click', async () => {
            if (!queue.length) return;
            const btn = $('csDownload');
            btn.disabled = true;
            let ok = 0;
            for (let i = 0; i < queue.length; i++) {
                const q = queue[i];
                btn.textContent = `⬇️ ${i + 1}/${queue.length}…`;
                try {
                    const blob = await getBlob(q.photo.id);
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = q.filename + '.' + extFor(blob.type);
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
                    ok++;
                    await new Promise((r) => setTimeout(r, 350));
                } catch (e) { /* sigue con la siguiente */ }
            }
            btn.disabled = false; btn.textContent = '⬇️ Descargar todas';
            msg(`${ok} de ${queue.length} fotos descargadas.`);
        });
    })();
    </script>
</body>

</html>
