/**
 * Mi selección (/mi-seleccion): dibuja lo marcado (window.KinoWaBatch, wa_batch.js) con su estado,
 * y deja subir/bajar, quitar, reintentar las que fallaron y repetir la última tanda.
 */
(function () {
    const W = window.KinoWaBatch;
    const list = document.getElementById('msList');
    if (!W || !list) return;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const LABEL = { ready: ['Lista', 'ok'], loading: ['Bajando…', 'wait'], sent: ['En una tanda', 'sent'], error: ['No bajó', 'bad'] };
    let lastHtml = '';

    function draw() {
        const items = W.items();
        const perCode = {};
        items.forEach((it) => { perCode[it.sku] = (perCode[it.sku] || 0) + 1; });
        $('msCount').textContent = items.length ? '(' + items.length + ')' : '';
        $('msTools').hidden = !items.length;
        $('msEmptyMsg').hidden = !!items.length;
        $('msOne').hidden = !items.some((it) => perCode[it.sku] > 1);
        const html = items.map((it, i) => {
            const [txt, cls] = LABEL[it.status] || ['', ''];
            return `<li class="ms-item${it.status === 'error' ? ' is-bad' : ''}" data-id="${esc(it.id)}">
                <span class="ms-n">${i + 1}</span>
                <img src="https://lh3.googleusercontent.com/d/${encodeURIComponent(it.id)}=s200" alt="" loading="lazy">
                <span class="ms-info"><strong>${esc(it.sku)}</strong>
                    <span class="ms-st ms-${cls}">${txt}</span>${gone[it.id] ? '<span class="ms-st ms-bad">Ya no está en el catálogo</span>' : ''}${perCode[it.sku] > 1 ? '<span class="ms-st ms-dup">' + perCode[it.sku] + ' fotos de este código</span>' : ''}</span>
                <span class="ms-act">
                    ${it.status === 'error' ? '<button type="button" data-a="retry" title="Reintentar">🔁</button>' : ''}
                    <button type="button" data-a="up" title="Subir" ${i === 0 ? 'disabled' : ''}>▲</button>
                    <button type="button" data-a="down" title="Bajar" ${i === items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button type="button" data-a="del" title="Quitar">✕</button>
                </span></li>`;
        }).join('');
        if (html !== lastHtml) { list.innerHTML = html; lastHtml = html; }

        const last = W.last();
        const box = $('msLast');
        box.hidden = !last;
        if (last) {
            const when = new Date(last.at).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            box.innerHTML = `<span>Última tanda preparada: <strong>${last.count} foto${last.count === 1 ? '' : 's'}</strong> · ${esc(when)}
                <small>Se abrió el menú Compartir; la página no puede saber si WhatsApp la entregó.</small></span>
                <button type="button" class="btn btn-sm btn-secondary" id="msResend">🔁 Repetir tanda</button>`;
        }
    }

    list.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-a]');
        if (!b) return;
        const id = b.closest('.ms-item').dataset.id;
        if (b.dataset.a === 'del') W.remove(id);
        else if (b.dataset.a === 'up') W.move(id, -1);
        else if (b.dataset.a === 'down') W.move(id, 1);
        else if (b.dataset.a === 'retry') W.retry(id);
    });
    $('msOne').addEventListener('click', () => W.onePerCode());
    $('msEmpty').addEventListener('click', () => { if (confirm('¿Quitar todas las fotos de la selección?')) W.clear(); });
    document.addEventListener('click', (e) => { if (e.target.id === 'msResend') W.resendLast(); });

    // ---- Catálogos guardados (etapa E): en el dispositivo, localStorage 'kinoCatalogs' ----
    // [{id, name, items: [{id, sku}], at}]. Abrir = reemplaza la selección actual (pregunta si hay algo marcado).
    const CKEY = 'kinoCatalogs';
    const readCats = () => { try { const v = JSON.parse(localStorage.getItem(CKEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
    const writeCats = (c) => { try { localStorage.setItem(CKEY, JSON.stringify(c)); return true; } catch (e) { alert('No se pudo guardar en este dispositivo (¿memoria llena o navegación privada?).'); return false; } };
    const snapshot = () => W.items().map((it) => ({ id: it.id, sku: it.sku }));
    let gone = {};   // id de foto → true si ya no está en el catálogo (borrada u oculta)

    function drawCats() {
        const cats = readCats().sort((a, b) => b.at - a.at);
        $('msCatEmpty').hidden = !!cats.length;
        $('msSaveAs').disabled = !W.items().length;
        $('msCatList').innerHTML = cats.map((c) => {
            const missing = c.items.filter((x) => gone[x.id]).length;
            const when = new Date(c.at).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' });
            return `<li class="ms-cat" data-id="${esc(c.id)}">
                <span class="ms-cat-info"><strong>${esc(c.name)}</strong>
                    <small>${c.items.length} foto${c.items.length === 1 ? '' : 's'} · ${esc(when)}${missing ? ' · <b class="ms-bad">' + missing + ' ya no está' + (missing === 1 ? '' : 'n') + '</b>' : ''}</small></span>
                <span class="ms-cat-act">
                    <button type="button" class="btn btn-sm btn-primary" data-c="open">Abrir</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-c="update" title="Reemplazar con la selección actual">Actualizar</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-c="dup">Duplicar</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-c="ren">Renombrar</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-c="del" title="Borrar">🗑</button>
                </span></li>`;
        }).join('');
    }
    // Pregunta al servidor qué fotos ya no existen o se ocultaron (una vez al abrir la página)
    function checkGone() {
        const ids = Array.from(new Set(readCats().flatMap((c) => c.items.map((x) => x.id)).concat(W.items().map((x) => x.id)))).slice(0, 500);
        if (!ids.length) return;
        fetch('/api/fotos-vigentes', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(ids) })
            .then((r) => r.json()).then((d) => {
                const ok = new Set(d.vigentes || []);
                gone = {}; ids.forEach((id) => { if (!ok.has(id)) gone[id] = true; });
                drawCats(); lastHtml = ''; draw();
            }).catch(() => {});
    }
    $('msSaveAs').addEventListener('click', () => {
        const items = snapshot();
        if (!items.length) return;
        const name = (prompt('Nombre del catálogo:', 'Catálogo ' + new Date().toLocaleDateString('es-CO', { day: 'numeric', month: 'long' })) || '').trim();
        if (!name) return;
        const cats = readCats();
        const same = cats.find((c) => c.name.toLowerCase() === name.toLowerCase());
        if (same && !confirm('Ya hay un catálogo «' + same.name + '». ¿Reemplazarlo?')) return;
        const rest = cats.filter((c) => c !== same);
        rest.push({ id: same ? same.id : 'c' + Date.now().toString(36), name: name.slice(0, 60), items, at: Date.now() });
        if (writeCats(rest)) drawCats();
    });
    $('msCatList').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-c]');
        if (!b) return;
        const cats = readCats();
        const c = cats.find((x) => x.id === b.closest('.ms-cat').dataset.id);
        if (!c) return;
        const act = b.dataset.c;
        if (act === 'open') {
            if (W.items().length && !confirm('Se reemplaza lo marcado ahora (' + W.items().length + ' fotos) por «' + c.name + '». ¿Seguir?')) return;
            W.replace(c.items.filter((x) => !gone[x.id]));
            window.scrollTo({ top: $('msList').offsetTop - 80, behavior: 'smooth' });
        } else if (act === 'update') {
            if (!W.items().length) { alert('No hay fotos marcadas para guardar.'); return; }
            if (!confirm('¿Reemplazar «' + c.name + '» con la selección actual (' + W.items().length + ' fotos)?')) return;
            c.items = snapshot(); c.at = Date.now(); writeCats(cats);
        } else if (act === 'dup') {
            cats.push({ id: 'c' + Date.now().toString(36), name: (c.name + ' (copia)').slice(0, 60), items: c.items.slice(), at: Date.now() });
            writeCats(cats);
        } else if (act === 'ren') {
            const n = (prompt('Nuevo nombre:', c.name) || '').trim();
            if (!n) return;
            c.name = n.slice(0, 60); writeCats(cats);
        } else if (act === 'del') {
            if (!confirm('¿Borrar el catálogo «' + c.name + '»? (las fotos marcadas no cambian)')) return;
            writeCats(cats.filter((x) => x !== c));
        }
        drawCats();
    });
    window.addEventListener('storage', (e) => { if (e.key === CKEY) drawCats(); });

    // ---- Cómo salen las fotos (etapa F): foto sola o con su código; vista previa con la primera foto lista ----
    let prevKey = '', prevUrl = '';
    async function drawLook() {
        const items = W.items();
        $('msLook').hidden = !items.length;
        document.querySelectorAll('input[name=msLabel]').forEach((r) => { r.checked = r.value === W.label(); });
        const first = items.find((x) => x.status === 'ready' || x.status === 'sent') || items.find((x) => x.status !== 'error');
        if (!first) return;
        const key = first.id + '|' + W.label();
        if (key === prevKey) return;
        prevKey = key;
        $('msPrevMsg').textContent = 'Preparando vista previa…';
        try {
            const b = await W.preview(first.id);
            if (key !== prevKey || !b) return;
            if (prevUrl) URL.revokeObjectURL(prevUrl);
            prevUrl = URL.createObjectURL(b);
            $('msPrev').src = prevUrl;
            $('msPrevMsg').textContent = '';
        } catch (e) { prevKey = ''; $('msPrevMsg').textContent = 'No se pudo preparar la vista previa.'; }
    }
    document.querySelectorAll('input[name=msLabel]').forEach((r) => r.addEventListener('change', () => W.setLabel(r.value)));

    W.onChange(() => { draw(); drawLook(); $('msSaveAs').disabled = !W.items().length; });
    drawLook();
    draw();
    drawCats();
    checkGone();
})();
