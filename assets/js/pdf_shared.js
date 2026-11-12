/**
 * Piezas comunes de los PDF que se arman en el dispositivo con jsPDF: Catálogo en PDF (catalog_pdf.js) y
 * Lista pedido (order_list.js). Bajar fotos livianas (/enviar-catalogo/foto/{id}), pasarlas a JPEG reducido,
 * limpiar texto para la letra del PDF, Compartir y Descargar. Expone window.KinoPdf.
 */
(function () {
    const canShareFiles = (() => {
        try {
            return !!(navigator.canShare && navigator.canShare({ files: [new File([new Blob(['x'], { type: 'application/pdf' })], 'x.pdf', { type: 'application/pdf' })] }));
        } catch (e) { return false; }
    })();

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

    // La letra estándar del PDF tiene tildes y ñ, pero no emojis
    const OK_CHARS = /[^ -~ -ÿ€•–—‘’“”…]/g;
    const clean = (s) => String(s || '').replace(/\s+/g, ' ').replace(OK_CHARS, '').replace(/\s+/g, ' ').trim();
    const lostChars = (s) => String(s || '').replace(/\s+/g, ' ').trim() !== clean(s);

    async function share(file, title, msg) {
        if (!file) return;
        try {
            await navigator.share({ files: [file], title });
            msg('✅ Compartido. Si no lo viste en WhatsApp, toca 📲 otra vez.');
        } catch (e) {
            if (e && e.name === 'AbortError') msg('Cancelado.');
            else if (e && e.name === 'NotAllowedError') msg('El teléfono pidió otro toque: vuelve a tocar 📲 Compartir.');
            else msg('No se pudo compartir: usa ⬇️ Descargar y adjúntalo en WhatsApp.');
        }
    }
    function download(file, msg) {
        if (!file) return;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(file);
        a.download = file.name;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 60000);
        msg('⬇️ Descargado: ' + file.name + '. En WhatsApp: 📎 → Documento.');
    }

    const mb = (n) => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' MB';

    window.KinoPdf = { canShareFiles, bitmap, fetchPhoto, prepare, clean, lostChars, share, download, mb };
})();
