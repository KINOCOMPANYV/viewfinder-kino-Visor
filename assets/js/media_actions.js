/**
 * Botones "WhatsApp" y "Descargar" de una foto/video (tarjetas, carpetas, ficha, visor grande).
 *
 * Marcado: <button data-media-action="wa|dl" data-file-id="…" data-name="archivo.jpg"
 *                  data-title="SKU — Nombre" data-url="/producto/SKU">
 * - WhatsApp en el celular: manda el archivo con el título y el enlace como pie de foto.
 *   En el computador (o si el celular no deja compartir archivos): abre wa.me con el enlace,
 *   y WhatsApp arma la vista previa con la foto.
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

    async function shareWa(opts) {
        const fileId = opts.fileId || '';
        const pageUrl = absUrl(opts.url) || (fileId ? location.origin + '/og/' + fileId + '.jpg' : location.href);
        const text = (opts.title ? '*' + opts.title + '*\n' : '') + pageUrl;

        if (fileId && isMobile && navigator.canShare && navigator.share) {
            const btn = opts.button;
            const label = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
            try {
                const resp = await fetch('/api/download/' + encodeURIComponent(fileId));
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const blob = await resp.blob();
                const name = opts.name || (blob.type.startsWith('video/') ? 'video.mp4' : 'imagen.jpg');
                const file = new File([blob], name, { type: blob.type || 'image/jpeg' });
                const data = { files: [file], text: text };
                if (navigator.canShare(data)) {
                    await navigator.share(data);
                    return;
                }
            } catch (e) {
                if (e.name === 'AbortError') return; // el usuario cerró el menú de compartir
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = label; }
            }
        }
        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
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
