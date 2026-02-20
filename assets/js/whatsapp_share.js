/**
 * WhatsApp Media Share Modal
 * Abre modal con imágenes del producto, permite seleccionar y compartir
 * Mobile: Web Share API (archivos reales)
 * Desktop: wa.me con links de Drive
 */
(function () {
    // Inyectar modal HTML una sola vez
    const modalHTML = `
    <div class="wa-share-overlay" id="waShareOverlay">
        <div class="wa-share-modal">
            <div class="wa-share-header">
                <h3>📤 Compartir por WhatsApp</h3>
                <button class="wa-share-close" id="waShareClose">✕</button>
            </div>
            <div class="wa-share-product" id="waShareProduct"></div>
            <div class="wa-share-loading" id="waShareLoading">
                <div class="spinner"></div>
                <p>Cargando imágenes...</p>
            </div>
            <div class="wa-share-body" id="waShareBody" style="display:none;">
                <div class="wa-share-toolbar">
                    <label class="wa-select-all">
                        <input type="checkbox" id="waSelectAll" checked> Seleccionar todas
                    </label>
                    <span class="wa-selected-count" id="waSelectedCount"></span>
                </div>
                <div class="wa-share-grid" id="waShareGrid"></div>
            </div>
            <div class="wa-share-empty" id="waShareEmpty" style="display:none;">
                <p>📷 No se encontraron imágenes para este producto</p>
            </div>
            <div class="wa-share-footer" id="waShareFooter" style="display:none;">
                <button class="wa-share-send" id="waShareSend">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    Enviar seleccionadas
                </button>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // Referencias
    const overlay = document.getElementById('waShareOverlay');
    const closeBtn = document.getElementById('waShareClose');
    const productInfo = document.getElementById('waShareProduct');
    const loading = document.getElementById('waShareLoading');
    const body = document.getElementById('waShareBody');
    const empty = document.getElementById('waShareEmpty');
    const footer = document.getElementById('waShareFooter');
    const grid = document.getElementById('waShareGrid');
    const selectAll = document.getElementById('waSelectAll');
    const selectedCount = document.getElementById('waSelectedCount');
    const sendBtn = document.getElementById('waShareSend');

    let currentSku = '';
    let currentName = '';
    let currentImages = []; // {id, url, thumbUrl, name, driveUrl}

    // Cerrar modal
    function closeModal() {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('active')) closeModal();
    });

    // Seleccionar todas
    selectAll.addEventListener('change', () => {
        const checked = selectAll.checked;
        grid.querySelectorAll('.wa-img-check').forEach(cb => cb.checked = checked);
        updateCount();
    });

    // Actualizar contador
    function updateCount() {
        const total = grid.querySelectorAll('.wa-img-check').length;
        const selected = grid.querySelectorAll('.wa-img-check:checked').length;
        selectedCount.textContent = `${selected} de ${total}`;
        sendBtn.disabled = selected === 0;
        selectAll.checked = selected === total;
        selectAll.indeterminate = selected > 0 && selected < total;
    }

    // Abrir modal
    window.openShareModal = function (sku, name) {
        currentSku = sku;
        currentName = name;
        currentImages = [];

        productInfo.innerHTML = `<strong>${sku}</strong> — ${name}`;
        loading.style.display = '';
        body.style.display = 'none';
        empty.style.display = 'none';
        footer.style.display = 'none';
        grid.innerHTML = '';

        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Cargar imágenes desde Drive
        fetch('/api/media/' + encodeURIComponent(sku))
            .then(r => r.json())
            .then(data => {
                const files = (data.files || []).filter(f =>
                    (f.mimeType || '').startsWith('image/')
                );

                loading.style.display = 'none';

                if (files.length === 0) {
                    empty.style.display = '';
                    return;
                }

                // Procesar imágenes
                currentImages = files.map(f => {
                    const thumbUrl = f.thumbnailLink
                        ? f.thumbnailLink.replace(/=s\d+/, '=s300')
                        : `https://lh3.googleusercontent.com/d/${f.id}=s300`;
                    const fullUrl = `https://lh3.googleusercontent.com/d/${f.id}=s1200`;
                    const driveUrl = f.webViewLink || `https://drive.google.com/file/d/${f.id}/view`;
                    return { id: f.id, url: fullUrl, thumbUrl, name: f.name, driveUrl };
                });

                renderGrid();
                body.style.display = '';
                footer.style.display = '';
                updateCount();
            })
            .catch(err => {
                console.error('Error cargando media:', err);
                loading.style.display = 'none';
                empty.innerHTML = '<p>❌ Error al cargar imágenes. Intenta de nuevo.</p>';
                empty.style.display = '';
            });
    };

    function renderGrid() {
        grid.innerHTML = currentImages.map((img, i) => `
            <label class="wa-share-item">
                <input type="checkbox" class="wa-img-check" data-index="${i}" checked>
                <div class="wa-share-thumb">
                    <img src="${img.thumbUrl}" alt="${img.name}" loading="lazy"
                         onerror="this.outerHTML='<div class=\\'wa-thumb-error\\'>📷</div>'">
                    <div class="wa-check-indicator">✓</div>
                </div>
                <span class="wa-share-filename">${img.name.length > 20 ? img.name.substring(0, 17) + '...' : img.name}</span>
            </label>
        `).join('');

        grid.querySelectorAll('.wa-img-check').forEach(cb => {
            cb.addEventListener('change', updateCount);
        });
    }

    // Enviar
    sendBtn.addEventListener('click', async () => {
        const selected = [];
        grid.querySelectorAll('.wa-img-check:checked').forEach(cb => {
            selected.push(currentImages[parseInt(cb.dataset.index)]);
        });

        if (selected.length === 0) return;

        // Intentar Web Share API (mobile) con archivos
        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        const canShareFiles = isMobile && navigator.canShare && navigator.share;

        if (canShareFiles) {
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></div> Preparando...';

            try {
                // Descargar imágenes como blobs
                const filePromises = selected.map(async (img, i) => {
                    try {
                        const resp = await fetch(img.url, { mode: 'cors' });
                        const blob = await resp.blob();
                        return new File([blob], `imagen_${i + 1}.jpg`, { type: blob.type || 'image/jpeg' });
                    } catch {
                        return null;
                    }
                });

                const files = (await Promise.all(filePromises)).filter(Boolean);

                if (files.length > 0) {
                    const shareData = {
                        files: files
                    };

                    if (navigator.canShare(shareData)) {
                        await navigator.share(shareData);
                        closeModal();
                        return;
                    }
                }
            } catch (err) {
                // Si el usuario cancela o falla, caer al fallback
                if (err.name === 'AbortError') {
                    resetSendButton();
                    return;
                }
                console.warn('Web Share failed, falling back:', err);
            }

            resetSendButton();
        }

        // Fallback: WhatsApp con links de Drive
        const productUrl = window.location.origin + '/producto/' + currentSku;
        let text = `📦 *${currentName}*\n🔗 SKU: ${currentSku}\n\n📸 *${selected.length} imagen(es):*\n`;
        selected.forEach((img) => {
            text += `\n🖼️ ${img.driveUrl}`;
        });
        text += `\n\n🔗 Ver ficha completa:\n${productUrl}`;

        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
        closeModal();
    });

    function resetSendButton() {
        sendBtn.disabled = false;
        sendBtn.innerHTML = `
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            Enviar seleccionadas`;
    }
})();
