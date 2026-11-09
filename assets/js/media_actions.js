/**
 * Botones "WhatsApp" y "Descargar" de una foto/video (tarjetas, carpetas, ficha, visor grande).
 *
 * Marcado: <button data-media-action="wa|dl" data-file-id="…" data-name="archivo.jpg"
 *                  data-title="SKU — Nombre" data-url="/producto/SKU">
 * - WhatsApp en el celular: manda solo la imagen (versión liviana) con el código como pie de foto.
 *   En el computador: copia la foto para pegarla en WhatsApp Web; si no se puede, abre wa.me con el enlace.
 * - Descargar: /api/download/{id} (sale como archivo, también en el celular).
 */
(function () {
    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

    function absUrl(path) {
        if (!path) return '';
        return /^https?:\/\//.test(path) ? path : location.origin + path;
    }

    function download(fileId, name) {
        if (!fileId) return;
        const a = document.createElement('a');
        a.href = '/api/download/' + encodeURIComponent(fileId);
        a.download = name || '';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    // Foto liviana (≈800 px, con caché en el servidor) de /enviar-catalogo/foto; si no sirve (video o
    // archivo sin código), el original de /api/download. Se guarda en memoria: el segundo toque es inmediato.
    const blobs = new Map();
    function getBlob(fileId) {
        if (!blobs.has(fileId)) {
            const p = fetch('/enviar-catalogo/foto/' + encodeURIComponent(fileId), { credentials: 'same-origin' })
                .then((r) => (r.ok ? r : fetch('/api/download/' + encodeURIComponent(fileId))))
                .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); });
            p.catch(() => blobs.delete(fileId));
            blobs.set(fileId, p);
        }
        return blobs.get(fileId);
    }

    function toPng(blob) {
        return createImageBitmap(blob).then((bmp) => {
            const c = document.createElement('canvas');
            c.width = bmp.width; c.height = bmp.height;
            c.getContext('2d').drawImage(bmp, 0, 0);
            return new Promise((ok, bad) => c.toBlob((b) => (b ? ok(b) : bad(new Error('png'))), 'image/png'));
        });
    }

    function toast(text) {
        let t = document.getElementById('mediaToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'mediaToast';
            t.className = 'media-toast';
            document.body.appendChild(t);
        }
        t.textContent = text;
        t.classList.add('show');
        clearTimeout(t._h);
        t._h = setTimeout(() => t.classList.remove('show'), 4000);
    }

    // El código del producto: va como pie de la foto ("SKU — Nombre" → "SKU")
    const codeOf = (opts) => String(opts.title || '').split(' — ')[0].trim();

    // WhatsApp de una foto: manda SOLO la imagen con el código como pie (sin enlace).
    // Celular: menú Compartir. Computador: copia la foto para pegarla en WhatsApp Web (Ctrl+V).
    async function shareWa(opts) {
        const fileId = opts.fileId || '';
        const code = codeOf(opts);
        const linkText = (opts.title ? '*' + opts.title + '*\n' : '') + (absUrl(opts.url) || location.href);
        if (!fileId) { window.open('https://wa.me/?text=' + encodeURIComponent(linkText), '_blank'); return; }

        if (isMobile && navigator.canShare && navigator.share) {
            const btn = opts.button;
            const label = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
            try {
                const blob = await getBlob(fileId);
                const ext = blob.type.startsWith('video/') ? 'mp4' : blob.type.includes('png') ? 'png' : 'jpg';
                const name = (code || 'imagen').replace(/[^\w.-]+/g, '_') + '.' + ext;
                const file = new File([blob], name, { type: blob.type || 'image/jpeg' });
                const data = code ? { files: [file], text: code } : { files: [file] };
                if (navigator.canShare(data)) { await navigator.share(data); return; }
            } catch (e) {
                if (e.name === 'AbortError') return; // el usuario cerró el menú de compartir
                if (e.name === 'NotAllowedError') { toast('Foto lista: toca WhatsApp otra vez.'); return; }
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = label; }
            }
        } else if (navigator.clipboard && window.ClipboardItem && window.createImageBitmap) {
            try {
                // El ClipboardItem recibe la promesa: así el navegador no pierde el permiso del clic
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': getBlob(fileId).then(toPng) })]);
                toast('📋 Foto ' + (code ? code + ' ' : '') + 'copiada: pégala en el chat de WhatsApp Web con Ctrl+V.');
                return;
            } catch (e) { /* video o navegador sin permiso: va el enlace */ }
        }
        window.open('https://wa.me/?text=' + encodeURIComponent(linkText), '_blank');
    }

    // Foto que la tarjeta muestra ahora (puede haber cambiado: portada que llegó por JS, variante elegida)
    function idFromCard(btn) {
        const card = btn.closest('.parent-card');
        const img = card && card.querySelector('.card-image img');
        const m = img && (img.currentSrc || img.src || '').match(/\/d\/([A-Za-z0-9_-]{10,})/);
        return m ? m[1] : '';
    }

    // Al elegir una variante en la tarjeta: compartir esa variante
    function retarget(card, sku, url) {
        if (!card) return;
        card.querySelectorAll('[data-media-action]').forEach((b) => {
            b.dataset.title = sku;
            b.dataset.url = url;
            b.dataset.name = '';
        });
    }

    // Un solo manejador para todos los botones (también los que se agregan después por AJAX)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-media-action]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const d = btn.dataset;
        const fromCard = idFromCard(btn);
        const fileId = fromCard || d.fileId || '';
        const name = fromCard && fromCard !== d.fileId ? '' : d.name;
        if (d.mediaAction === 'dl') {
            if (fileId) download(fileId, name);
            else alert('Este producto todavía no tiene foto para descargar.');
        } else if (d.mediaAction === 'wa') {
            shareWa({ fileId, name, title: d.title, url: d.url, button: btn });
        }
    }, true);

    window.KinoMedia = { download, shareWa, retarget };
})();
