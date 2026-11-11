/**
 * Enviar catálogo → «📷 Leer lista de una foto»: lee el texto de una foto (captura de WhatsApp, lista impresa,
 * factura) EN EL DISPOSITIVO con Tesseract (assets/vendor/tesseract, sin CDN; la foto no se envía a ningún lado)
 * y lo agrega a la caja de texto para revisarlo antes de «⚡ Generar lista».
 * La primera vez baja el lector (~6 MB); después queda en la caché del navegador.
 */
(function () {
    const input = document.getElementById('csOcrFile');
    const box = document.getElementById('csText');
    const out = document.getElementById('csOcrMsg');
    if (!input || !box) return;
    const BASE = '/assets/vendor/tesseract/';
    const say = (t) => { if (out) out.textContent = t || ''; };
    let workerP = null;

    function loadScript(src) {
        return new Promise((ok, bad) => {
            if (window.Tesseract) return ok();
            const s = document.createElement('script');
            s.src = src; s.onload = ok; s.onerror = () => bad(new Error('no se pudo cargar el lector'));
            document.head.appendChild(s);
        });
    }
    function getWorker() {
        if (!workerP) {
            workerP = (async () => {
                await loadScript(BASE + 'tesseract.min.js');
                const w = await window.Tesseract.createWorker('eng', 1, {
                    workerPath: BASE + 'worker.min.js',
                    // El worker se carga desde su archivo (no como blob): así el núcleo encuentra su .wasm al lado
                    workerBlobURL: false,
                    corePath: BASE + 'tesseract-core-lstm.js',
                    langPath: BASE.replace(/\/$/, ''),
                    logger: (m) => {
                        if (m.status === 'recognizing text') say(`🔎 Leyendo la foto… ${Math.round((m.progress || 0) * 100)}%`);
                        else if (/load|initiali/i.test(m.status || '')) say('⏳ Preparando el lector (solo la primera vez tarda un poco)…');
                    },
                });
                await w.setParameters({ preserve_interword_spaces: '1' });
                return w;
            })();
            workerP.catch(() => { workerP = null; });
        }
        return workerP;
    }

    // Foto muy grande → máx. 2000 px (más rápido y suficiente para leer); se ignora la orientación EXIF rara
    async function shrink(file) {
        try {
            const img = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const k = Math.min(1, 2000 / Math.max(img.width, img.height));
            if (k === 1) { img.close && img.close(); return file; }
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
            const g = c.getContext('2d');
            g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
            g.drawImage(img, 0, 0, c.width, c.height);
            img.close && img.close();
            return await new Promise((ok) => c.toBlob((b) => ok(b || file), 'image/png'));
        } catch (e) { return file; }
    }

    // Arreglos típicos de la lectura: rayas largas → guion, espacios de sobra
    const tidy = (t) => String(t || '')
        .replace(/[‐‑‒–—―]/g, '-')
        .replace(/[ \t]+/g, ' ')
        .split('\n').map((l) => l.trim()).filter(Boolean).join('\n');

    input.addEventListener('change', async () => {
        const f = input.files && input.files[0];
        input.value = '';
        if (!f) return;
        const btn = input.closest('label');
        if (btn) btn.classList.add('is-busy');
        say('⏳ Preparando el lector…');
        try {
            // Si el lector se traba (celular sin memoria, red cortada), no dejar el mensaje colgado
            const limit = (p, ms) => Promise.race([p, new Promise((_, bad) => setTimeout(() => bad(new Error('tardó demasiado')), ms))]);
            const w = await limit(getWorker(), 120000);
            const { data } = await limit(w.recognize(await shrink(f)), 90000);
            const text = tidy(data && data.text);
            if (!text) { say('No se encontró texto en la foto. Prueba con una foto más nítida y derecha.'); return; }
            box.value = box.value.trim() ? box.value.trim() + '\n' + text : text;
            box.dispatchEvent(new Event('input', { bubbles: true }));
            box.focus();
            const lines = text.split('\n').length;
            say(`✅ Se leyeron ${lines} línea${lines === 1 ? '' : 's'}. Revisa el texto (puede confundir letras) y toca ⚡ Generar lista.`);
        } catch (e) {
            say('No se pudo leer la foto: ' + (e && e.message ? e.message : e) + '. Puedes copiar el texto con Google Lens / Texto en vivo y pegarlo.');
        } finally {
            if (btn) btn.classList.remove('is-busy');
        }
    });
})();
