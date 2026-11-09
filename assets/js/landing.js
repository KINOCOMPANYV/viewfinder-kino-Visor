// Movido desde templates/client/landing.php para que el navegador lo guarde en caché (antes iba dentro de cada página).
(function () {
    const modal = document.getElementById('batchModal');
    const btnOpen = document.getElementById('btnBatchOpen');
    const btnClose = document.getElementById('btnBatchClose');
    const btnSearch = document.getElementById('btnBatchSearch');
    const btnBack = document.getElementById('btnBatchBack');
    const textarea = document.getElementById('batchCodes');
    const countEl = document.getElementById('batchCount');
    const inputSection = document.getElementById('batchInputSection');
    const loadingSection = document.getElementById('batchLoading');
    const resultsSection = document.getElementById('batchResults');
    const resultsGrid = document.getElementById('batchResultsGrid');
    const summaryEl = document.getElementById('batchSummary');
    const waFooter = document.getElementById('batchWaFooter');
    const selectAllCb = document.getElementById('batchSelectAll');
    const selectedCountEl = document.getElementById('batchSelectedCount');
    const btnWaSend = document.getElementById('btnBatchWaSend');

    // El botón que abría este modal ya no está (lo reemplazó "Enviar catálogo"): sin él, no se arma
    if (!modal || !btnOpen) return;

    let batchFoundItems = [];

    function getCodes() {
        return textarea.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    }

    textarea.addEventListener('input', () => {
        const n = getCodes().length;
        countEl.textContent = n + (n === 1 ? ' código' : ' códigos');
    });

    btnOpen.addEventListener('click', (e) => {
        e.preventDefault();
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        textarea.focus();
    });

    function closeModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
    btnClose.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });

    btnBack.addEventListener('click', () => {
        resultsSection.style.display = 'none';
        inputSection.style.display = '';
        waFooter.style.display = 'none';
    });

    // --- Conteo de seleccionadas ---
    function updateSelectedCount() {
        const checks = resultsGrid.querySelectorAll('.batch-card-check:checked');
        const total = resultsGrid.querySelectorAll('.batch-card-check').length;
        selectedCountEl.textContent = checks.length + ' seleccionada' + (checks.length !== 1 ? 's' : '');
        btnWaSend.disabled = checks.length === 0;
        selectAllCb.checked = checks.length === total && total > 0;
        selectAllCb.indeterminate = checks.length > 0 && checks.length < total;
    }

    selectAllCb.addEventListener('change', () => {
        const checked = selectAllCb.checked;
        resultsGrid.querySelectorAll('.batch-card-check').forEach(cb => cb.checked = checked);
        updateSelectedCount();
    });

    btnWaSend.addEventListener('click', async () => {
        const checks = resultsGrid.querySelectorAll('.batch-card-check:checked');
        if (checks.length === 0) return;
        if (checks.length > 20) {
            alert('⚠️ Máximo 10 productos a la vez.\n\nDeselecciona algunos y haz otro envío.');
            return;
        }

        const selected = [];
        checks.forEach(cb => {
            const idx = parseInt(cb.dataset.index);
            if (batchFoundItems[idx]) selected.push(batchFoundItems[idx]);
        });

        const images = selected.filter(s => !s.isVideo);
        const videos = selected.filter(s => s.isVideo);

        // Avisar si hay videos
        if (videos.length > 0) {
            const videoSkus = videos.map(v => v.sku).join(', ');
            if (images.length > 0) {
                alert('🎬 Los siguientes productos son videos:\n' + videoSkus + '\n\nLos videos no se pueden enviar directamente por WhatsApp.\nDescargalos primero a tu dispositivo desde la página del producto y compártelos manualmente.\n\nSe enviarán solo las ' + images.length + ' imágen(es).');
            } else {
                alert('🎬 Los productos seleccionados son videos:\n' + videoSkus + '\n\nLos videos no se pueden enviar directamente por WhatsApp.\nDescargalos primero a tu dispositivo desde la página del producto y compártelos manualmente.');
                return;
            }
        }

        if (images.length === 0) return;

        // Web Share API (mobile)
        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        if (isMobile && navigator.canShare && navigator.share) {
            btnWaSend.disabled = true;
            btnWaSend.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></div> Preparando imágenes...';
            try {
                const files = (await Promise.all(images.map(async (item, i) => {
                    try {
                        const driveIdMatch = item.image ? item.image.match(/\/d\/([^=]+)/) : null;
                        const fetchUrl = driveIdMatch ? `/api/download/${driveIdMatch[1]}` : (item.image || `https://lh3.googleusercontent.com/d/${item.driveId}=s800`);
                        const resp = await fetch(fetchUrl, driveIdMatch ? {} : { mode: 'cors' });
                        const blob = await resp.blob();
                        return new File([blob], `imagen_${i + 1}.jpg`, { type: blob.type || 'image/jpeg' });
                    } catch { return null; }
                }))).filter(Boolean);
                if (files.length > 0 && navigator.canShare({ files })) {
                    await navigator.share({ files });
                    resetWaBtn();
                    return;
                }
            } catch (err) {
                if (err.name === 'AbortError') { resetWaBtn(); return; }
            }
            resetWaBtn();
        }

        // Fallback desktop: WhatsApp con links
        let text = '📦 *Catálogo - ' + images.length + ' producto(s)*\n\n';
        images.forEach((item, i) => {
            const productUrl = window.location.origin + '/producto/' + item.sku;
            text += (i + 1) + '. *' + item.sku + '* - ' + item.name + '\n';
            if (item.image) text += '🖼️ ' + item.image + '\n';
            text += '🔗 ' + productUrl + '\n\n';
        });
        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
    });

    function resetWaBtn() {
        btnWaSend.disabled = false;
        btnWaSend.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> 📲 Enviar por WhatsApp';
    }

    // --- Buscar lote ---
    btnSearch.addEventListener('click', () => {
        const codes = getCodes();
        if (codes.length === 0) { textarea.focus(); return; }

        inputSection.style.display = 'none';
        loadingSection.style.display = '';
        resultsSection.style.display = 'none';
        waFooter.style.display = 'none';
        batchFoundItems = [];

        fetch('/api/batch-search', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ codes })
        })
            .then(r => r.json())
            .then(data => {
                loadingSection.style.display = 'none';
                const results = data.results || {};
                let found = 0, notFound = 0;
                const needsCover = [];
                resultsGrid.innerHTML = '';
                batchFoundItems = [];

                codes.forEach(code => {
                    const item = results[code];
                    const card = document.createElement('div');

                    if (item && item.sku) {
                        const idx = batchFoundItems.length;
                        batchFoundItems.push({
                            sku: item.sku,
                            name: item.name || '',
                            image: (item.image || '').replace(/^\[VIDEO\]/, ''),
                            isVideo: (item.image || '').startsWith('[VIDEO]')
                        });
                        found++;
                        card.className = 'batch-result-card found';
                        const imgHtml = item.image
                            ? `<img src="${item.image}" alt="${item.sku}" loading="lazy" onerror="this.outerHTML='<div class=\\'batch-no-img\\'>📷</div>'">`
                            : '<div class="batch-no-img batch-loading-img" data-sku="' + item.sku + '">⏳</div>';
                        card.innerHTML = `
                            <label class="batch-card-label">
                                <input type="checkbox" class="batch-card-check" data-index="${idx}" checked>
                                <div class="batch-card-check-mark">✓</div>
                                <a href="/producto/${item.sku}" class="batch-result-link" target="_blank" onclick="event.stopPropagation();">
                                    <div class="batch-result-img">${imgHtml}</div>
                                    <div class="batch-result-info">
                                        <span class="batch-result-sku">${item.sku}</span>
                                        <span class="batch-result-name">${item.name || ''}</span>
                                    </div>
                                </a>
                            </label>`;
                        if (!item.image) needsCover.push(item.sku);
                    } else {
                        notFound++;
                        card.className = 'batch-result-card not-found';
                        card.innerHTML = `
                    <div class="batch-result-img"><div class="batch-no-img">❌</div></div>
                    <div class="batch-result-info">
                        <span class="batch-result-sku">${code}</span>
                        <span class="batch-result-status">No encontrado</span>
                    </div>`;
                    }
                    resultsGrid.appendChild(card);
                });

                // Listeners de checkboxes
                resultsGrid.querySelectorAll('.batch-card-check').forEach(cb => {
                    cb.addEventListener('change', updateSelectedCount);
                });

                summaryEl.innerHTML = `<strong>${found}</strong> encontrado${found !== 1 ? 's' : ''} · <strong>${notFound}</strong> no encontrado${notFound !== 1 ? 's' : ''}`;
                resultsSection.style.display = '';

                if (found > 0) {
                    waFooter.style.display = '';
                    selectAllCb.checked = true;
                    updateSelectedCount();
                }

                // Buscar covers de Drive
                if (needsCover.length > 0) {
                    fetch('/api/covers/batch', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ skus: needsCover })
                    })
                        .then(r => r.json())
                        .then(coverData => {
                            const covers = coverData.covers || {};
                            document.querySelectorAll('.batch-loading-img').forEach(el => {
                                const sku = el.dataset.sku;
                                const cover = covers[sku];
                                if (cover && cover.url) {
                                    const imgContainer = el.closest('.batch-result-img');
                                    imgContainer.innerHTML = `<img src="${cover.url}" alt="${sku}" loading="lazy" onerror="this.outerHTML='<div class=\\'batch-no-img\\'>📷</div>'">`;
                                    const item = batchFoundItems.find(f => f.sku === sku);
                                    if (item) item.image = cover.url;
                                } else {
                                    el.textContent = '📷';
                                    el.classList.remove('batch-loading-img');
                                }
                            });
                        })
                        .catch(() => {
                            document.querySelectorAll('.batch-loading-img').forEach(el => {
                                el.textContent = '📷';
                                el.classList.remove('batch-loading-img');
                            });
                        });
                }
            })
            .catch(err => {
                loadingSection.style.display = 'none';
                inputSection.style.display = '';
                alert('Error al buscar. Intenta de nuevo.');
                console.error(err);
            });
    });
})();

function previewVariant(thumbEl, skuEnc, skuLabel) {
    const card = thumbEl.closest('.parent-card');
    if (!card) return;

    // 1. Obtener imagen del thumb
    const thumbImg = thumbEl.querySelector('img');
    const newSrc = thumbImg ? thumbImg.src : null;
    if (!newSrc) return;

    // 2. Actualizar imagen principal (agrandar si es de drive thumbnail=s120 -> s600)
    const mainImgContainer = card.querySelector('.card-image');
    const mainImg = mainImgContainer.querySelector('img');
    const hiresUrl = newSrc.replace(/=s\d+/, '=s600'); 

    if (mainImg) {
        mainImg.removeAttribute('srcset');
        mainImg.src = hiresUrl;
    } else {
        const ph = mainImgContainer.querySelector('.cover-placeholder');
        if (ph) {
            ph.outerHTML = `<img src="${hiresUrl}" class="img-fade-in loaded" style="width:100%;height:100%;object-fit:cover;">`;
        }
    }

    // 3. Actualizar links de la tarjeta para apuntar al hijo seleccionado
    const childUrl = '/producto/' + skuEnc;
    card.querySelectorAll('.dynamic-card-link').forEach(link => {
        link.href = childUrl;
    });

    // 4. Actualizar SKU visual
    const skuText = card.querySelector('.dynamic-card-sku');
    if (skuText) skuText.textContent = skuLabel;

    // 5. WhatsApp y Descargar pasan a compartir el hijo
    if (window.KinoMedia) KinoMedia.retarget(card, skuLabel, '/producto/' + skuEnc);

    // 6. Destacar miniatura activa visualmente
    card.querySelectorAll('.child-thumb').forEach(t => t.style.boxShadow = 'none');
    thumbEl.style.boxShadow = 'var(--glow-accent)';
}

function scrollChildren(btn, direction) {
    const wrapper = btn.closest('.children-scroll-wrapper');
    const row = wrapper.querySelector('.children-row');
    const scrollAmount = 120;
    row.scrollBy({ left: direction * scrollAmount, behavior: 'smooth' });
    setTimeout(() => {
        const leftBtn = wrapper.querySelector('.scroll-left');
        const rightBtn = wrapper.querySelector('.scroll-right');
        if (leftBtn) leftBtn.classList.toggle('hidden', row.scrollLeft <= 0);
        if (rightBtn) rightBtn.classList.toggle('hidden', row.scrollLeft + row.clientWidth >= row.scrollWidth - 2);
    }, 350);
}

// Auto-hide arrows on page load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.children-scroll-wrapper').forEach(wrapper => {
        const row = wrapper.querySelector('.children-row');
        const leftBtn = wrapper.querySelector('.scroll-left');
        const rightBtn = wrapper.querySelector('.scroll-right');
        if (!row) return;
        const needsScroll = row.scrollWidth > row.clientWidth + 2;
        if (leftBtn) leftBtn.classList.add('hidden');
        if (rightBtn) rightBtn.classList.toggle('hidden', !needsScroll);
    });
});

// Lazy-load covers for child thumbnails with placeholders
document.addEventListener('DOMContentLoaded', () => {
    const skuElements = {};

    // Main card images with placeholders
    document.querySelectorAll('.card-image .cover-placeholder').forEach(ph => {
        const cardImage = ph.closest('.card-image');
        if (cardImage && cardImage.dataset.sku) {
            const sku = cardImage.dataset.sku;
            if (!skuElements[sku]) skuElements[sku] = [];
            skuElements[sku].push({ el: cardImage, type: 'main' });
        }
    });

    // Child thumbnails with placeholders
    document.querySelectorAll('.child-thumb').forEach(thumb => {
        const ph = thumb.querySelector('.child-placeholder');
        if (ph && thumb.dataset.sku) {
            const sku = thumb.dataset.sku;
            if (!skuElements[sku]) skuElements[sku] = [];
            skuElements[sku].push({ el: thumb, type: 'child' });
        }
    });

    const allSkus = Object.keys(skuElements);
    if (allSkus.length === 0) return;

    const batchSize = 50;
    for (let i = 0; i < allSkus.length; i += batchSize) {
        const batch = allSkus.slice(i, i + batchSize);
        fetch('/api/covers/batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ skus: batch })
        })
        .then(r => r.json())
        .then(data => {
            const covers = data.covers || {};
            Object.entries(covers).forEach(([sku, cover]) => {
                if (!cover || !cover.url) return;
                const entries = skuElements[sku] || [];
                entries.forEach(entry => {
                    if (entry.type === 'main') {
                        const placeholder = entry.el.querySelector('.cover-placeholder');
                        if (placeholder) {
                            const img = document.createElement('img');
                            img.src = cover.url;
                            img.alt = sku;
                            img.loading = 'lazy';
                            img.className = 'img-fade-in';
                            img.onload = () => img.classList.add('loaded');
                            img.onerror = () => { img.outerHTML = '<div class="cover-placeholder">📷</div>'; };
                            placeholder.replaceWith(img);
                        }
                    } else {
                        const placeholder = entry.el.querySelector('.child-placeholder');
                        if (placeholder) {
                            const img = document.createElement('img');
                            img.src = cover.url;
                            img.alt = sku;
                            img.loading = 'lazy';
                            img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                            img.onerror = () => { img.outerHTML = '<span class="child-placeholder">📷</span>'; };
                            placeholder.replaceWith(img);
                        }
                    }
                });
            });
        })
        .catch(() => {});
    }
});
