// Movido desde templates/client/landing.php para que el navegador lo guarde en caché (antes iba dentro de cada página).
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
    const hiresUrl = newSrc.replace(/=s\d+(-rj)?/, '=s600-rj'); 

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
