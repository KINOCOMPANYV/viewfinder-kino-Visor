/**
 * Marcar varias fotos en el catálogo (inicio, resultados de búsqueda/filtro y carpetas) y mandarlas por WhatsApp.
 * Mismo mecanismo que "Enviar catálogo" (/enviar-catalogo): fotos livianas de /enviar-catalogo/foto/{id}
 * que bajan en segundo plano (3 intentos), envío por tandas de 10 con el menú Compartir del celular
 * (solo imágenes, sin texto) y "💾 Guardar" para bajarlas al celular o al computador.
 *
 * Marcado de cada tarjeta (.parent-card):
 *   <label class="search-check-footer"><input type="checkbox" class="search-card-check"
 *          data-sku="SKU" data-file-id="…" data-is-video="0"> …</label>
 * La barra se crea sola. Lo marcado se guarda en el dispositivo (localStorage, etapa C): se mantiene al hacer otra
 * búsqueda, al pasar a otra carpeta, al inicio o a una ficha, y también al cerrar la página o quedarse sin señal.
 * "👁 Ver" abre /mi-seleccion (revisar, ordenar, quitar, reintentar, repetir la última tanda) con window.KinoWaBatch.
 * La galería de la ficha usa las mismas casillas (.search-card-check dentro de .gallery-item) y llama
 * window.KinoWaBatch.sync() cuando termina de dibujarse.
 */
(function () {
    const TANDA = 10;
    const canShareFiles = (() => {
        try {
            return !!(navigator.canShare && navigator.canShare({ files: [new File([new Blob(['x'], { type: 'image/jpeg' })], 'x.jpg', { type: 'image/jpeg' })] }));
        } catch (e) { return false; }
    })();
    const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    let sel = new Map();        // fileId → {id, sku, wa, err}  (orden = orden en que se marcaron o el de Mi selección)
    const blobs = new Map();    // fileId → Promise<Blob>
    const loaded = new Set();
    let tandaNum = 0;
    let last = null;            // última tanda abierta para compartir: {ids, at} (no confirma que WhatsApp la entregó)
    let bar, el = {};
    const listeners = [];

    // ---- Selección guardada en el dispositivo: sobrevive al cambiar de página y al cerrar ----
    const STORE = 'kinoWaSel';
    const store = (() => { try { localStorage.setItem('kinoT', '1'); localStorage.removeItem('kinoT'); return localStorage; } catch (e) { return sessionStorage; } })();
    function saveSel() {
        try { store.setItem(STORE, JSON.stringify({ items: Array.from(sel.values()), tanda: tandaNum, last })); } catch (e) {}
    }
    function loadSel() {
        sel = new Map();
        tandaNum = 0;
        last = null;
        try {
            // Antes se guardaba solo en la pestaña: se trae una vez
            let raw = store.getItem(STORE);
            if (!raw && store !== sessionStorage) { raw = sessionStorage.getItem(STORE); sessionStorage.removeItem(STORE); }
            const d = JSON.parse(raw || 'null');
            if (d && Array.isArray(d.items)) {
                d.items.forEach((it) => { if (it && it.id) sel.set(it.id, { id: it.id, sku: it.sku || it.id, wa: !!it.wa, err: !!it.err }); });
                tandaNum = d.tanda | 0;
                last = d.last && Array.isArray(d.last.ids) ? d.last : null;
            }
        } catch (e) {}
    }
    loadSel();

    // ---- Fotos ----
    async function fetchPhoto(id) {
        let last;
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt) await new Promise((ok) => setTimeout(ok, 700 * attempt));
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 30000);
            try {
                let r = await fetch('/enviar-catalogo/foto/' + encodeURIComponent(id), { credentials: 'same-origin', signal: ctrl.signal });
                // Foto de carpeta sin código enlazado: la liviana no existe, va la original
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
    function getBlob(id) {
        if (!blobs.has(id)) {
            const p = fetchPhoto(id);
            p.then(() => loaded.add(id), () => blobs.delete(id));
            blobs.set(id, p);
        }
        return blobs.get(id);
    }
    const ext = (type) => type.includes('png') ? 'png' : type.includes('webp') ? 'webp' : 'jpg';
    const safe = (s) => String(s).replace(/[^\w.-]+/g, '_');
    async function toFile(it) {
        const b = await getBlob(it.id);
        return new File([b], safe(it.sku) + '.' + ext(b.type), { type: b.type || 'image/jpeg' });
    }

    // ---- Tarjetas ----
    // Foto que la tarjeta muestra ahora (la portada puede llegar después por JS o cambiar al elegir variante)
    const CARD = '.parent-card, .gallery-item';
    function fileIdOf(cb) {
        const card = cb.closest(CARD);
        const img = card && card.querySelector('.card-image img');
        const m = img && (img.currentSrc || img.src || '').match(/\/d\/([A-Za-z0-9_-]{10,})/);
        return m ? m[1] : (cb.dataset.fileId || '');
    }
    const checks = () => Array.from(document.querySelectorAll('.search-card-check'));
    const visible = (cb) => { const c = cb.closest(CARD); return !!(c && c.offsetParent !== null); };
    // Las que se pueden marcar con "Marcar todas": visibles (filtro), con foto y que no sean video
    const eligible = (cb) => visible(cb) && cb.dataset.isVideo !== '1' && !!fileIdOf(cb);

    function setCheck(cb, on) {
        if (on) {
            if (cb.dataset.isVideo === '1') { cb.checked = false; msg('🎬 ' + cb.dataset.sku + ' es un video: los videos no se envían por aquí (usa ⬇️ Descargar).'); return false; }
            const id = fileIdOf(cb);
            if (!id) { cb.checked = false; msg('📷 ' + cb.dataset.sku + ' no tiene foto todavía.'); return false; }
            cb.dataset.selId = id;
            if (!sel.has(id)) sel.set(id, { id, sku: cb.dataset.sku || id, wa: false, err: false });
            cb.checked = true;
        } else {
            const id = cb.dataset.selId || fileIdOf(cb);
            sel.delete(id);
            cb.checked = false;
        }
        return true;
    }

    // Al llegar resultados nuevos (AJAX), dejar marcadas las que ya estaban
    function syncChecks() {
        checks().forEach((cb) => {
            const id = cb.dataset.selId || fileIdOf(cb);
            cb.checked = sel.has(id);
            if (cb.checked) cb.dataset.selId = id;
        });
        render();
    }

    // ---- Barra ----
    function msg(t) { if (el.msg) el.msg.textContent = t || ''; }
    const pending = () => Array.from(sel.values()).filter((it) => !it.wa && !it.err);
    const nextTanda = () => pending().slice(0, TANDA);
    const readyNow = () => { const t = nextTanda(); return t.length && t.every((x) => loaded.has(x.id)) ? t : []; };

    function buildBar() {
        bar = document.createElement('div');
        bar.className = 'search-wa-toolbar wab-bar';
        bar.innerHTML = '<div class="wab-row">'
            + '<label class="wa-toolbar-select" title="Marca o desmarca las fotos que se ven en esta página"><input type="checkbox" class="wab-all"><span>Marcar todas <small>(esta vista)</small></span></label>'
            + '<a class="wab-view" href="/mi-seleccion" title="Revisar, ordenar y quitar"><span class="wa-toolbar-count wab-count">0</span> 👁 Ver</a>'
            + '<button type="button" class="wab-clear" title="Desmarcar todo">✕</button>'
            + '<button type="button" class="wab-save">💾 Guardar</button>'
            + '<button type="button" class="wa-toolbar-send wab-send">📲 Enviar</button>'
            + '</div><div class="wab-msg"></div>';
        document.body.appendChild(bar);
        el = {
            all: bar.querySelector('.wab-all'), count: bar.querySelector('.wab-count'), clear: bar.querySelector('.wab-clear'),
            save: bar.querySelector('.wab-save'), send: bar.querySelector('.wab-send'), msg: bar.querySelector('.wab-msg'),
        };
        el.all.addEventListener('change', () => {
            checks().filter(eligible).forEach((cb) => setCheck(cb, el.all.checked));
            tandaNum = 0; render();
        });
        el.clear.addEventListener('click', () => {
            if (sel.size > 10 && !confirm('¿Desmarcar las ' + sel.size + ' fotos?')) return;
            sel.clear(); tandaNum = 0;
            checks().forEach((cb) => { cb.checked = false; });
            msg(''); render();
        });
        el.send.addEventListener('click', sendTanda);
        el.save.addEventListener('click', save);
    }

    function render() {
        if (!bar) buildBar();
        const n = sel.size;
        bar.classList.toggle('visible', n > 0);
        el.count.textContent = n;
        const vis = checks().filter(eligible);
        const on = vis.filter((cb) => cb.checked).length;
        el.all.checked = vis.length > 0 && on === vis.length;
        el.all.indeterminate = on > 0 && on < vis.length;
        el.save.disabled = !n;
        renderSend();
        preload();
        saveSel();
        listeners.forEach((fn) => { try { fn(); } catch (e) {} });
    }

    function renderSend() {
        const btn = el.send;
        const pend = pending();
        if (!sel.size) { btn.disabled = true; btn.textContent = '📲 Enviar'; return; }
        if (!pend.length && !Array.from(sel.values()).some((x) => x.wa)) { btn.disabled = true; btn.textContent = '📲 Enviar'; return; }
        if (!pend.length) { btn.disabled = false; btn.textContent = '✅ Enviadas · otra vez'; return; }
        const next = nextTanda();
        const ready = readyNow().length;
        const have = next.filter((x) => loaded.has(x.id)).length;
        const total = tandaNum + Math.ceil(pend.length / TANDA);
        btn.disabled = !ready;
        btn.textContent = ready
            ? (total > 1 ? `📲 Enviar ${tandaNum + 1}/${total} (${ready})` : `📲 Enviar ${ready}`)
            : `⏳ ${have}/${next.length}`;
    }

    // Baja las marcadas en segundo plano (6 a la vez), primero la tanda que sigue.
    // Al cambiar de página vuelven a pedirse, pero salen de la caché del navegador (1 día).
    let run = 0;
    function preload() {
        const r = ++run;
        const ids = pending().concat(Array.from(sel.values()).filter((x) => x.wa && !x.err)).map((x) => x.id).filter((id) => !loaded.has(id));
        let i = 0;
        const worker = async () => {
            while (r === run && i < ids.length) {
                const id = ids[i++];
                try { await getBlob(id); } catch (e) { drop(id); }
                if (r === run) { renderSend(); listeners.forEach((fn) => { try { fn(); } catch (e) {} }); }
            }
        };
        for (let k = 0; k < 6; k++) worker();
    }
    // Foto que no bajó (3 intentos): queda en la selección marcada con problema (Mi selección deja reintentar o quitar)
    // y no entra en las tandas
    function drop(id) {
        const it = sel.get(id);
        if (!it) return;
        it.err = true;
        msg('⚠️ No se pudo bajar la foto de ' + it.sku + ' (no va en las tandas; puedes reintentarla en Mi selección).');
        render();
    }

    function shareError(e) {
        if (e && e.name === 'AbortError') msg('Cancelado.');
        else if (e && e.name === 'NotAllowedError') msg('El teléfono pidió otro toque: vuelve a tocar 📲.');
        else msg('No se pudo: ' + (e && e.message ? e.message : e));
    }

    // 📲 Tanda de hasta 10 fotos al menú Compartir (WhatsApp). Solo las imágenes, sin texto.
    async function sendTanda() {
        if (!canShareFiles) {
            msg('En el computador WhatsApp no recibe varias fotos desde la página: usa "💾 Guardar" y arrástralas al chat de WhatsApp Web.');
            return;
        }
        if (!pending().length) { sel.forEach((it) => { it.wa = false; }); tandaNum = 0; msg(''); render(); return; }
        const part = readyNow();
        if (!part.length) return;
        try {
            await navigator.share({ files: await Promise.all(part.map(toFile)) });
            part.forEach((it) => { it.wa = true; });
            tandaNum++;
            last = { ids: part.map((it) => it.id), at: Date.now() };
            saveSel();
            const left = pending().length;
            msg(left ? `✅ Tanda ${tandaNum} enviada. Faltan ${left}: toca 📲 cuando esté lista.` : `✅ Listo: ${sel.size} foto${sel.size === 1 ? '' : 's'} enviada${sel.size === 1 ? '' : 's'}.`);
            renderSend();
        } catch (e) { shareError(e); }
    }

    // 💾 Guardar: Android/PC = descargas; iPhone = Compartir → "Guardar imágenes" (de 10 en 10)
    let savePart = 0;
    async function save() {
        const items = Array.from(sel.values());
        if (!items.length) return;
        const btn = el.save;
        if (isIOS && canShareFiles) {
            const part = items.slice(savePart * TANDA, savePart * TANDA + TANDA);
            if (!part.every((x) => loaded.has(x.id))) { msg('Las fotos siguen bajando: toca 💾 otra vez en un momento.'); preload(); return; }
            try {
                await navigator.share({ files: await Promise.all(part.map(toFile)) });
                const left = items.length - (savePart + 1) * TANDA;
                savePart = left > 0 ? savePart + 1 : 0;
                msg(left > 0 ? 'Elige "Guardar imágenes". Faltan ' + left + ': toca 💾 otra vez.' : '✅ Listo. En WhatsApp: 📎 → Galería y selecciónalas.');
            } catch (e) { shareError(e); }
            return;
        }
        btn.disabled = true;
        let ok = 0;
        const bad = [];
        for (let i = 0; i < items.length; i++) {
            btn.textContent = `💾 ${i + 1}/${items.length}…`;
            try {
                const b = await getBlob(items[i].id);
                const a = document.createElement('a');
                a.href = URL.createObjectURL(b);
                a.download = safe(items[i].sku) + '.' + ext(b.type);
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 4000);
                ok++;
                await new Promise((r) => setTimeout(r, 300));
            } catch (e) { bad.push(items[i].sku); }
        }
        btn.disabled = false; btn.textContent = '💾 Guardar';
        msg((bad.length ? '⚠️ No se pudo bajar: ' + bad.join(', ') + '. ' : '') + `✅ ${ok} guardada${ok === 1 ? '' : 's'}. En WhatsApp: 📎 → Galería (álbum Download).`);
    }

    // Un solo manejador para todas las casillas (también las que llegan por AJAX)
    document.addEventListener('change', (e) => {
        const cb = e.target;
        if (!cb.classList || !cb.classList.contains('search-card-check')) return;
        msg('');
        setCheck(cb, cb.checked);
        render();
    });

    // ---- Para Mi selección (/mi-seleccion) ----
    function statusOf(id) {
        const it = sel.get(id);
        if (!it) return '';
        if (it.err) return 'error';
        if (it.wa) return 'sent';
        return loaded.has(id) ? 'ready' : 'loading';
    }
    async function resendLast() {
        if (!last || !last.ids.length) return;
        if (!canShareFiles) { msg('En el computador usa "💾 Guardar" y arrastra las fotos a WhatsApp Web.'); return; }
        const its = last.ids.map((id) => sel.get(id) || { id, sku: id });
        if (!its.every((x) => loaded.has(x.id))) {
            msg('Preparando la tanda…');
            try { await Promise.all(its.map((x) => getBlob(x.id))); } catch (e) { msg('Una foto de esa tanda no bajó. Intenta de nuevo.'); return; }
            msg('Lista: toca otra vez para compartir.');
            listeners.forEach((fn) => { try { fn(); } catch (e) {} });
            return;
        }
        try {
            await navigator.share({ files: await Promise.all(its.map(toFile)) });
            last.at = Date.now(); saveSel();
            msg('✅ Tanda abierta otra vez para compartir.');
            listeners.forEach((fn) => { try { fn(); } catch (e) {} });
        } catch (e) { shareError(e); }
    }
    window.KinoWaBatch = {
        sync: syncChecks,
        items: () => Array.from(sel.values()).map((it) => Object.assign({ status: statusOf(it.id) }, it)),
        remove(id) { sel.delete(id); syncChecks(); },
        move(id, delta) {
            const arr = Array.from(sel.values());
            const i = arr.findIndex((x) => x.id === id), j = i + delta;
            if (i < 0 || j < 0 || j >= arr.length) return;
            [arr[i], arr[j]] = [arr[j], arr[i]];
            sel = new Map(arr.map((x) => [x.id, x]));
            tandaNum = 0; arr.forEach((x) => { x.wa = false; });
            render();
        },
        retry(id) { const it = sel.get(id); if (it) { it.err = false; blobs.delete(id); msg(''); render(); } },
        // Deja la primera foto de cada código (el resto se quita)
        onePerCode() {
            const seen = new Set();
            Array.from(sel.values()).forEach((it) => { if (seen.has(it.sku)) sel.delete(it.id); else seen.add(it.sku); });
            syncChecks();
        },
        clear() { sel.clear(); tandaNum = 0; msg(''); syncChecks(); },
        last: () => last ? { count: last.ids.length, at: last.at, ready: last.ids.every((id) => loaded.has(id)) } : null,
        resendLast,
        onChange(fn) { listeners.push(fn); },
    };

    function start() {
        const restored = sel.size;
        if (!restored && !checks().length && !document.getElementById('searchResultsContainer') && !document.getElementById('msList')) return;
        syncChecks();
        if (restored && !document.getElementById('msList')) msg(`📌 ${restored} marcada${restored === 1 ? '' : 's'}: quedan guardadas en este dispositivo aunque cierres la página.`);
        const box = document.getElementById('searchResultsContainer');
        if (box && window.MutationObserver) {
            let t;
            new MutationObserver(() => { clearTimeout(t); t = setTimeout(syncChecks, 100); }).observe(box, { childList: true });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    // Botón Atrás (la página vuelve tal cual estaba): traer lo marcado en las otras páginas
    window.addEventListener('pageshow', (e) => { if (e.persisted) { loadSel(); syncChecks(); } });
    // Otra pestaña cambió la selección
    window.addEventListener('storage', (e) => { if (e.key === STORE) { loadSel(); syncChecks(); } });
})();
