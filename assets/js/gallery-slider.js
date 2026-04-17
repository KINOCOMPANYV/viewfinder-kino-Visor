/**
 * gallery-slider.js — Lightbox y navegación de galería mejorada.
 * Solo se activa en la ficha de producto (/producto/).
 * No toca ninguna función existente en product.php.
 *
 * Funciones añadidas:
 *   - Lightbox al hacer click en la imagen principal
 *   - Navegación por flechas del teclado (izq/der) entre miniaturas
 *   - Swipe táctil en la imagen principal para cambiar foto
 *   - Contador de archivo actual (ej: "2 / 7")
 */

(function () {
    'use strict';

    // ── Estado ────────────────────────────────────────────────────────────────
    let currentIndex = 0;
    let totalFiles   = 0;
    let lbOpen       = false;

    // ── Crear lightbox en el DOM ──────────────────────────────────────────────
    function createLightbox() {
        if (document.getElementById('kinoLightbox')) return;

        const lb = document.createElement('div');
        lb.id = 'kinoLightbox';
        lb.setAttribute('role', 'dialog');
        lb.setAttribute('aria-modal', 'true');
        lb.setAttribute('aria-label', 'Imagen ampliada');
        lb.innerHTML = `
            <button id="kinoLightboxClose" aria-label="Cerrar">&times;</button>
            <button class="lightbox-nav prev" id="lbPrev" aria-label="Anterior">&#8249;</button>
            <img id="kinoLightboxImg" src="" alt="" draggable="false">
            <button class="lightbox-nav next" id="lbNext" aria-label="Siguiente">&#8250;</button>
        `;
        document.body.appendChild(lb);

        // Cerrar al click fuera de la imagen
        lb.addEventListener('click', function (e) {
            if (e.target === lb || e.target.id === 'kinoLightboxClose') {
                closeLightbox();
            }
        });

        document.getElementById('lbPrev').addEventListener('click', function (e) {
            e.stopPropagation();
            navigateLightbox(-1);
        });

        document.getElementById('lbNext').addEventListener('click', function (e) {
            e.stopPropagation();
            navigateLightbox(1);
        });
    }

    function openLightbox(idx) {
        const lb  = document.getElementById('kinoLightbox');
        const img = document.getElementById('kinoLightboxImg');
        if (!lb || !img) return;

        const files = window.allGalleryFiles || [];
        const f = files[idx];
        if (!f || !(f.mimeType || '').startsWith('image/')) return;

        const url = f.thumbnailLink
            ? f.thumbnailLink.replace(/=s\d+/, '=s1200')
            : `https://lh3.googleusercontent.com/d/${f.id}=s1200`;

        img.src = url;
        img.alt = f.name || '';
        lb.classList.add('active');
        document.body.style.overflow = 'hidden';
        currentIndex = idx;
        lbOpen = true;

        // Mostrar/ocultar flechas
        updateLbNavButtons(files);
    }

    function closeLightbox() {
        const lb = document.getElementById('kinoLightbox');
        if (lb) lb.classList.remove('active');
        document.body.style.overflow = '';
        lbOpen = false;
    }

    function navigateLightbox(dir) {
        const files = window.allGalleryFiles || [];
        let next = currentIndex + dir;

        // Saltar videos
        while (next >= 0 && next < files.length) {
            if ((files[next].mimeType || '').startsWith('image/')) break;
            next += dir;
        }

        if (next < 0 || next >= files.length) return;

        // También actualizar la miniatura activa en el thumb strip
        const thumbs = document.querySelectorAll('.thumb-strip-item');
        thumbs.forEach((t, i) => t.classList.toggle('active', i === next));

        openLightbox(next);

        // Llamar showInMain si existe en el contexto del producto
        if (typeof window.showInMain === 'function') {
            window.showInMain(next);
        }
    }

    function updateLbNavButtons(files) {
        const prev = document.getElementById('lbPrev');
        const next = document.getElementById('lbNext');
        if (!prev || !next) return;

        // Hay imagen anterior?
        let hasPrev = false;
        for (let i = currentIndex - 1; i >= 0; i--) {
            if ((files[i].mimeType || '').startsWith('image/')) { hasPrev = true; break; }
        }
        // Hay imagen siguiente?
        let hasNext = false;
        for (let i = currentIndex + 1; i < files.length; i++) {
            if ((files[i].mimeType || '').startsWith('image/')) { hasNext = true; break; }
        }

        prev.style.display = hasPrev ? '' : 'none';
        next.style.display = hasNext ? '' : 'none';
    }

    // ── Contador de archivo actual ────────────────────────────────────────────
    function updateFileCounter(idx, total) {
        let counter = document.getElementById('kinoFileCounter');
        if (!counter) {
            counter = document.createElement('div');
            counter.id = 'kinoFileCounter';
            counter.className = 'gallery-file-counter';
            const strip = document.getElementById('thumbStrip');
            if (strip && strip.parentNode) {
                strip.parentNode.insertBefore(counter, strip.nextSibling);
            }
        }
        if (total > 0) {
            counter.textContent = `${idx + 1} / ${total}`;
        }
    }

    // ── Swipe táctil en imagen principal ─────────────────────────────────────
    function initSwipe() {
        const mainCover = document.getElementById('mainCover');
        if (!mainCover) return;

        let touchStartX = 0;
        let touchStartY = 0;

        mainCover.addEventListener('touchstart', function (e) {
            touchStartX = e.changedTouches[0].clientX;
            touchStartY = e.changedTouches[0].clientY;
        }, { passive: true });

        mainCover.addEventListener('touchend', function (e) {
            const dx = e.changedTouches[0].clientX - touchStartX;
            const dy = e.changedTouches[0].clientY - touchStartY;

            // Solo swipe horizontal con al menos 40px de desplazamiento y dominante (no diagonal)
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                navigateByDelta(dx > 0 ? -1 : 1);
            }
        }, { passive: true });
    }

    function navigateByDelta(dir) {
        const files  = window.allGalleryFiles || [];
        const thumbs = document.querySelectorAll('.thumb-strip-item');
        let next = currentIndex + dir;

        while (next >= 0 && next < files.length) {
            if (!(files[next].mimeType || '').startsWith('video/') ||
                (files[next].mimeType || '').startsWith('image/')) break;
            next += dir;
        }

        if (next < 0 || next >= files.length) return;

        thumbs.forEach((t, i) => t.classList.toggle('active', i === next));
        currentIndex = next;

        if (typeof window.showInMain === 'function') {
            window.showInMain(next);
        }

        updateFileCounter(next, files.length);

        // Scroll suave del thumbstrip al elemento activo
        const activeThumb = thumbs[next];
        if (activeThumb) {
            activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    // ── Keyboard navigation ───────────────────────────────────────────────────
    function initKeyboard() {
        document.addEventListener('keydown', function (e) {
            // Solo actuar si estamos en la página de producto
            if (!document.getElementById('mainCover')) return;

            if (lbOpen) {
                if (e.key === 'ArrowLeft')  navigateLightbox(-1);
                if (e.key === 'ArrowRight') navigateLightbox(1);
                if (e.key === 'Escape')     closeLightbox();
                return;
            }

            if (e.key === 'ArrowLeft')  navigateByDelta(-1);
            if (e.key === 'ArrowRight') navigateByDelta(1);
        });
    }

    // ── Hooking en la imagen principal → abrir lightbox ──────────────────────
    function hookMainImageClick() {
        const mainCover = document.getElementById('mainCover');
        if (!mainCover) return;

        // Usamos delegación para capturar también imágenes cargadas dinámicamente
        mainCover.addEventListener('click', function (e) {
            const img = e.target.closest('img');
            if (!img) return;

            // Evitar click si hay un video cargado
            const files = window.allGalleryFiles || [];
            const f = files[currentIndex];
            if (f && (f.mimeType || '').startsWith('video/')) return;

            openLightbox(currentIndex);
        });

        // Añadir cursor zoom-in solo cuando hay imagen
        mainCover.style.cursor = 'zoom-in';
    }

    // ── Interceptar showInMain para actualizar currentIndex ──────────────────
    function patchShowInMain() {
        const original = window.showInMain;
        if (typeof original !== 'function') return;

        window.showInMain = function (idx) {
            currentIndex = idx;
            const files  = window.allGalleryFiles || [];
            updateFileCounter(idx, files.length);
            original(idx);
        };
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Solo activar en la ficha de producto
        if (!document.getElementById('mainCover')) return;

        createLightbox();
        initSwipe();
        initKeyboard();
        hookMainImageClick();

        // Esperar a que renderGallery() pueble allGalleryFiles
        // MutationObserver sobre #thumbStrip para detectar cuando se añaden miniaturas
        const strip = document.getElementById('thumbStrip');
        if (strip) {
            const observer = new MutationObserver(function () {
                patchShowInMain();
                const files = window.allGalleryFiles || [];
                totalFiles  = files.length;
                if (totalFiles > 0) updateFileCounter(0, totalFiles);
                observer.disconnect();
            });
            observer.observe(strip, { childList: true });
        }
    });

})();
