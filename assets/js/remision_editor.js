/**
 * Admin → 🚚 Remisión: editor de la plantilla (window.RE_TPL). Logos subidos: se pasan a PNG (máx. 600×200 px)
 * en el navegador y viajan como data:image/png;base64 dentro del JSON que se guarda (POST /admin/remision).
 */
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let t = JSON.parse(JSON.stringify(window.RE_TPL || {}));
    let dirty = false;
    const msg = (s) => { $('reMsg').textContent = s || ''; };

    function fill() {
        $('reTitle').value = t.title || '';
        $('reNum').value = t.numPrefix || '';
        $('reHead').value = t.headerColor || '#0e0e0e';
        $('reAccent').value = t.accentColor || '#d3232d';
        for (let i = 0; i < 3; i++) $('reF' + i).value = (t.footer || [])[i] || '';
        $('reBrands').value = t.brands || '';
        drawLogos(); drawPreview();
    }

    function drawLogos() {
        $('reLogos').innerHTML = (t.logos || []).map((l, i) => '<div class="re-logo" data-i="' + i + '">' +
            '<div class="pic" style="background:' + esc(t.headerColor) + '"><img src="' + esc(l.src) + '" alt=""></div>' +
            '<input type="text" data-a="name" value="' + esc(l.name) + '" maxlength="40" aria-label="Nombre del logo">' +
            '<div class="row"><label><input type="radio" name="side' + i + '" data-a="side" value="left"' + (l.side === 'left' ? ' checked' : '') + '> Izquierda</label>' +
            '<label><input type="radio" name="side' + i + '" data-a="side" value="right"' + (l.side !== 'left' ? ' checked' : '') + '> Derecha</label></div>' +
            '<div class="row"><button type="button" class="btn btn-secondary" data-a="up" title="Mover antes">◀</button>' +
            '<button type="button" class="btn btn-secondary" data-a="down" title="Mover después">▶</button>' +
            '<label class="btn btn-secondary" style="cursor:pointer">🔁 Cambiar<input type="file" data-a="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden></label>' +
            '<button type="button" class="btn btn-secondary" data-a="white">⚪ Pasar a blanco</button>' +
            '<button type="button" class="btn btn-secondary" data-a="del">🗑 Quitar</button></div></div>').join('') ||
            '<p class="pb-help">Sin logos.</p>';
    }

    function drawPreview() {
        const L = (t.logos || []).filter((l) => l.side === 'left'), R = (t.logos || []).filter((l) => l.side !== 'left');
        const img = (l) => '<img src="' + esc(l.src) + '" alt="' + esc(l.name) + '">';
        const sep = '<span class="sep"></span>';
        $('rePv').innerHTML = '<div class="re-pv-head" style="background:' + esc(t.headerColor) + ';border-bottom:4px solid ' + esc(t.accentColor) + '">' +
            L.map(img).join(sep) + (L.length ? sep : '') +
            '<div class="tt"><b>' + esc(t.title) + '</b><span>' + esc((t.numPrefix ? t.numPrefix + ' ' : '') + '00106530') + '</span></div>' +
            '<div class="rt">' + R.map(img).join(sep) + '</div></div>' +
            '<div class="re-pv-body">… cliente, NIT, fecha y la tabla de productos (del PDF) …</div>' +
            '<div class="re-pv-foot" style="border-top:2px solid ' + esc(t.accentColor) + '">' +
            (t.footer || []).map((f, i) => f ? '<div' + (i === 0 ? ' class="b"' : '') + '>' + esc(f) + '</div>' : '').join('') +
            (t.brands ? '<div class="m">' + esc(t.brands) + '</div>' : '') + '<span class="pg">1/1</span></div>';
    }

    const changed = () => { dirty = true; drawPreview(); msg('Cambios sin guardar.'); };
    const bind = (id, fn) => $(id).addEventListener('input', (e) => { fn(e.target.value); changed(); });
    bind('reTitle', (v) => { t.title = v; });
    bind('reNum', (v) => { t.numPrefix = v; });
    bind('reHead', (v) => { t.headerColor = v; drawLogos(); });
    bind('reAccent', (v) => { t.accentColor = v; });
    [0, 1, 2].forEach((i) => bind('reF' + i, (v) => { t.footer = t.footer || []; while (t.footer.length < 3) t.footer.push(''); t.footer[i] = v; }));
    bind('reBrands', (v) => { t.brands = v; });

    // Imagen → PNG reducido (máx. 600×200). white = pasa lo negro/gris a blanco y quita el fondo blanco (para la franja oscura).
    function loadImg(src) {
        return new Promise((ok, fail) => { const im = new Image(); im.onload = () => ok(im); im.onerror = () => fail(new Error('No se pudo leer la imagen.')); im.src = src; });
    }
    async function toPng(src, white) {
        const im = await loadImg(src);
        const s = Math.min(1, 600 / im.naturalWidth, 200 / im.naturalHeight);
        const w = Math.max(1, Math.round(im.naturalWidth * s)), h = Math.max(1, Math.round(im.naturalHeight * s));
        const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
        const cx = cv.getContext('2d'); cx.drawImage(im, 0, 0, w, h);
        if (white) {
            const d = cx.getImageData(0, 0, w, h), p = d.data;
            for (let k = 0; k < p.length; k += 4) {
                const a = p[k + 3] / 255, r = p[k] * a + 255 * (1 - a), g = p[k + 1] * a + 255 * (1 - a), b = p[k + 2] * a + 255 * (1 - a);
                const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
                if (mx - mn < 50) { p[k] = p[k + 1] = p[k + 2] = 255; p[k + 3] = Math.round(255 - (r + g + b) / 3); } // gris → blanco con transparencia
                else p[k + 3] = 255; // los colores (p. ej. el rojo) quedan igual
            }
            cx.putImageData(d, 0, 0);
        }
        return cv.toDataURL('image/png');
    }
    const readFile = (f) => new Promise((ok, fail) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = () => fail(r.error); r.readAsDataURL(f); });

    $('reLogos').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-a]'), box = e.target.closest('.re-logo');
        if (!b || !box || b.tagName === 'INPUT') return;
        const i = +box.dataset.i, a = b.dataset.a, L = t.logos;
        if (a === 'up' && i > 0) [L[i - 1], L[i]] = [L[i], L[i - 1]];
        else if (a === 'down' && i < L.length - 1) [L[i + 1], L[i]] = [L[i], L[i + 1]];
        else if (a === 'del') { if (!confirm('¿Quitar el logo «' + L[i].name + '»?')) return; L.splice(i, 1); }
        else if (a === 'white') { try { L[i].src = await toPng(L[i].src, true); } catch (err) { msg('⚠️ ' + err.message); return; } }
        else return;
        drawLogos(); changed();
    });
    $('reLogos').addEventListener('change', async (e) => {
        const el = e.target, box = el.closest('.re-logo');
        if (!box) return;
        const l = t.logos[+box.dataset.i];
        if (el.dataset.a === 'side') { l.side = el.value; changed(); }
        else if (el.dataset.a === 'file' && el.files[0]) {
            try { l.src = await toPng(await readFile(el.files[0]), false); drawLogos(); changed(); } catch (err) { msg('⚠️ ' + err.message); }
        }
    });
    $('reLogos').addEventListener('input', (e) => {
        const box = e.target.closest('.re-logo');
        if (box && e.target.dataset.a === 'name') { t.logos[+box.dataset.i].name = e.target.value; changed(); }
    });
    $('reAdd').addEventListener('change', async (e) => {
        const f = e.target.files[0]; e.target.value = '';
        if (!f) return;
        if ((t.logos || []).length >= 8) { msg('Máximo 8 logos.'); return; }
        try {
            const src = await toPng(await readFile(f), false);
            t.logos = t.logos || [];
            t.logos.push({ id: 'logo' + Date.now().toString(36), name: f.name.replace(/\.[^.]+$/, '').slice(0, 40) || 'Logo', side: 'right', src });
            drawLogos(); changed();
        } catch (err) { msg('⚠️ ' + err.message); }
    });

    async function post(body) {
        const r = await fetch('/admin/remision', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.CS_CSRF || '' },
            body: JSON.stringify(body),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || !j.ok) throw new Error(j.error || 'error ' + r.status);
        return j;
    }
    $('reSave').addEventListener('click', async () => {
        $('reSave').disabled = true; msg('Guardando…');
        try { const j = await post({ action: 'save', template: t }); t = j.template; dirty = false; fill(); msg(j.message); }
        catch (err) { msg('⚠️ ' + err.message); }
        finally { $('reSave').disabled = false; }
    });
    $('reReset').addEventListener('click', async () => {
        if (!confirm('¿Volver a la plantilla de fábrica? Se pierden los cambios y logos subidos.')) return;
        try { const j = await post({ action: 'reset' }); t = j.template; dirty = false; fill(); msg(j.message); }
        catch (err) { msg('⚠️ ' + err.message); }
    });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    fill();
})();
