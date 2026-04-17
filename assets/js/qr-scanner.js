/**
 * qr-scanner.js — Escáner QR para el buscador de la landing.
 * Se activa solo cuando existe #btnQrScan en el DOM.
 * Usa la librería html5-qrcode (cargada vía CDN con defer).
 * Al escanear un QR, inyecta el texto en #searchInput y lanza la búsqueda.
 */

(function () {
    'use strict';

    // Esperar a que el DOM y la librería estén listos
    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById('btnQrScan');
        if (!btn) return; // No estamos en la landing

        btn.addEventListener('click', openQrModal);
    });

    // ── Crear modal del escáner ───────────────────────────────────────────────
    function buildModal() {
        if (document.getElementById('kinoQrModal')) return;

        const modal = document.createElement('div');
        modal.id = 'kinoQrModal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Escáner de código QR');
        modal.style.cssText = `
            display:none;
            position:fixed;
            inset:0;
            z-index:9998;
            background:rgba(0,0,0,0.88);
            backdrop-filter:blur(10px);
            -webkit-backdrop-filter:blur(10px);
            align-items:center;
            justify-content:center;
            flex-direction:column;
            gap:1rem;
            padding:1.5rem;
        `;

        modal.innerHTML = `
            <div style="
                background:var(--color-surface,#111118);
                border:1px solid var(--color-border,#2a2a3a);
                border-radius:16px;
                padding:1.5rem;
                max-width:400px;
                width:100%;
                text-align:center;
                position:relative;
                box-shadow:0 24px 80px rgba(0,0,0,0.7);
            ">
                <button id="kinoQrClose" style="
                    position:absolute;top:0.75rem;right:0.75rem;
                    background:rgba(255,255,255,0.08);border:none;
                    color:#fff;width:32px;height:32px;border-radius:50%;
                    font-size:1.1rem;cursor:pointer;display:flex;
                    align-items:center;justify-content:center;
                    transition:background 0.2s;
                " aria-label="Cerrar">&times;</button>

                <h3 style="margin:0 0 0.5rem;font-size:1.05rem;color:var(--color-text,#e8e8f0);">
                    📷 Escanear código QR
                </h3>
                <p style="font-size:0.8rem;color:var(--color-text-muted,#888);margin:0 0 1rem;">
                    Apunta la cámara al código QR del producto
                </p>

                <div id="kinoQrReader" style="
                    width:100%;border-radius:12px;overflow:hidden;
                    background:#000;min-height:200px;
                "></div>

                <p id="kinoQrStatus" style="
                    margin:0.75rem 0 0;font-size:0.8rem;
                    color:var(--color-text-muted,#888);
                    min-height:1.2em;
                ">Iniciando cámara…</p>

                <button id="kinoQrCancel" style="
                    margin-top:0.75rem;
                    background:none;
                    border:1px solid var(--color-border,#2a2a3a);
                    color:var(--color-text-muted,#888);
                    padding:0.4rem 1.2rem;
                    border-radius:8px;
                    font-size:0.82rem;
                    cursor:pointer;
                    transition:all 0.2s;
                    font-family:var(--font,inherit);
                ">Cancelar</button>
            </div>
        `;

        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeQrModal();
        });
        document.getElementById('kinoQrClose').addEventListener('click', closeQrModal);
        document.getElementById('kinoQrCancel').addEventListener('click', closeQrModal);
    }

    // ── Estado global del escáner ─────────────────────────────────────────────
    let html5QrCode = null;
    let scannerRunning = false;

    function openQrModal() {
        // Verificar que la librería ya cargó
        if (typeof Html5Qrcode === 'undefined') {
            alert('⚠️ La librería de escaneo aún está cargando. Intenta en un momento.');
            return;
        }

        buildModal();

        const modal = document.getElementById('kinoQrModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Limpiar el contenedor antes de iniciar
        const reader = document.getElementById('kinoQrReader');
        reader.innerHTML = '';

        setStatus('Iniciando cámara…');

        // Crear instancia del escáner
        html5QrCode = new Html5Qrcode('kinoQrReader');

        // Configuración: preferir cámara trasera
        const config = {
            fps: 10,
            qrbox: { width: 220, height: 220 },
            aspectRatio: 1.0,
        };

        html5QrCode.start(
            { facingMode: 'environment' },
            config,
            onQrSuccess,
            onQrError
        ).then(function () {
            scannerRunning = true;
            setStatus('Apunta al código QR…');
        }).catch(function (err) {
            // Intentar con cámara frontal como fallback
            html5QrCode.start(
                { facingMode: 'user' },
                config,
                onQrSuccess,
                onQrError
            ).then(function () {
                scannerRunning = true;
                setStatus('Apunta al código QR…');
            }).catch(function () {
                setStatus('❌ No se pudo acceder a la cámara. Verifica los permisos.');
            });
        });
    }

    function onQrSuccess(decodedText) {
        // Detener escáner inmediatamente
        stopScanner();
        closeQrModal();

        // Limpiar el texto: puede ser una URL completa o solo el SKU
        let sku = decodedText.trim();

        // Si es una URL del tipo /producto/{sku}, extraer solo el SKU
        const match = sku.match(/\/producto\/([^?#]+)/);
        if (match) {
            sku = decodeURIComponent(match[1]);
        }

        // Inyectar en el buscador y lanzar la búsqueda
        const input = document.getElementById('searchInput');
        if (input) {
            input.value = sku;
            input.dispatchEvent(new Event('input', { bubbles: true }));

            // Pequeño feedback visual
            input.style.transition = 'box-shadow 0.3s';
            input.style.boxShadow = '0 0 0 3px rgba(201,168,76,0.5)';
            setTimeout(function () {
                input.style.boxShadow = '';
            }, 1000);

            // Auto-submit si es un SKU limpio (sin espacios ni caracteres raros)
            const form = document.getElementById('searchForm');
            if (form && /^[a-zA-Z0-9\-_.]+$/.test(sku)) {
                setTimeout(function () { form.submit(); }, 400);
            }
        }
    }

    function onQrError(error) {
        // Ignorar errores de "no QR found" — son normales durante el escaneo continuo
        // Solo loguear errores graves
        if (error && !error.includes && typeof error === 'string' && error.indexOf('NotFoundException') === -1) {
            console.warn('[QR Scanner]', error);
        }
    }

    function stopScanner() {
        if (html5QrCode && scannerRunning) {
            html5QrCode.stop().catch(function () {});
            scannerRunning = false;
        }
    }

    function closeQrModal() {
        stopScanner();
        const modal = document.getElementById('kinoQrModal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    function setStatus(msg) {
        const el = document.getElementById('kinoQrStatus');
        if (el) el.textContent = msg;
    }

    // Cerrar con Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && scannerRunning) {
            closeQrModal();
        }
    });

})();
