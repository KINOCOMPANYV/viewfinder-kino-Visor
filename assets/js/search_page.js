// Movido desde src/controllers/search.php para que el navegador lo guarde en caché (antes iba dentro de cada página).
// Lazy-load covers from Drive for products without cover_image_url
document.addEventListener('DOMContentLoaded', () => {
    // Collect SKUs that need covers (main card images)
    const skuElements = {};
    document.querySelectorAll('.cover-placeholder').forEach(ph => {
        const cardImage = ph.closest('.card-image');
        if (cardImage && cardImage.dataset.sku) {
            const sku = cardImage.dataset.sku;
            if (!skuElements[sku]) skuElements[sku] = [];
            skuElements[sku].push({ el: cardImage, type: 'main' });
        }
    });

    // Collect SKUs from child thumbnails that need their REAL per-variant cover:
    //   1) placeholder vacío (sin ninguna imagen)
    //   2) fallback de familia (data-fallback="1") → muestra la portada de otra
    //      variante/padre hasta que se resuelve la suya propia.
    // Sin esto, las miniaturas con fallback solo se corregían al hacer click.
    document.querySelectorAll('.child-thumb').forEach(thumb => {
        if (!thumb.dataset.sku) return;
        const hasPlaceholder = thumb.querySelector('.child-placeholder');
        const isFallback = thumb.dataset.fallback === '1';
        if (hasPlaceholder || isFallback) {
            const sku = thumb.dataset.sku;
            if (!skuElements[sku]) skuElements[sku] = [];
            skuElements[sku].push({ el: thumb, type: 'child' });
        }
    });

    const allSkus = Object.keys(skuElements);
    if (allSkus.length === 0) return;

    // Fetch in batches of 50
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
                        // child-thumb: puede tener placeholder vacío o una imagen de fallback
                        const placeholder = entry.el.querySelector('.child-placeholder');
                        if (placeholder) {
                            const img = document.createElement('img');
                            img.src = cover.url;
                            img.alt = sku;
                            img.loading = 'lazy';
                            img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                            img.onerror = () => { img.outerHTML = '<span class="child-placeholder">📷</span>'; };
                            placeholder.replaceWith(img);
                            entry.el.dataset.fallback = '0';
                        } else {
                            // Fallback de familia: reemplazar por la portada REAL de esta variante
                            const existing = entry.el.querySelector('img');
                            if (existing) {
                                existing.removeAttribute('srcset');
                                existing.src = cover.url;
                                entry.el.dataset.fallback = '0';
                            }
                        }
                    }
                });
            });
        })
        .catch(() => {});
    }
});

async function previewVariant(thumbEl, skuEnc, skuLabel) {
    const card = thumbEl.closest('.parent-card');
    if (!card) return;

    const mainImgContainer = card.querySelector('.card-image');
    const skuText = card.querySelector('.dynamic-card-sku');

    // Feedback inmediato: resaltar chip y actualizar el código
    card.querySelectorAll('.child-thumb').forEach(t => t.style.boxShadow = 'none');
    thumbEl.style.boxShadow = 'var(--glow-accent)';
    if (skuText) skuText.textContent = skuLabel;
    if (window.KinoMedia) KinoMedia.retarget(card, skuLabel, '/producto/' + skuEnc);

    function setMain(url) {
        const mainImg = mainImgContainer.querySelector('img');
        if (mainImg) {
            mainImg.removeAttribute('srcset');
            mainImg.src = url;
        } else {
            const ph = mainImgContainer.querySelector('.cover-placeholder');
            if (ph) {
                ph.outerHTML = `<img src="${url}" class="img-fade-in loaded" style="width:100%;height:100%;object-fit:cover;">`;
            }
        }
    }

    const thumbImg = thumbEl.querySelector('img');
    const isFallback = thumbEl.dataset.fallback === '1';

    // Chip con portada REAL propia: usarla directo
    if (thumbImg && !isFallback) {
        setMain(thumbImg.src.replace(/=s\d+/, '=s600'));
        return;
    }

    // Chip sin portada propia (fallback de familia o placeholder):
    // buscar la foto real de ESTA variante en Drive vía /api/media.
    // El backend además la cachea y le auto-asigna portada para el futuro.
    thumbEl.style.opacity = '0.5';
    try {
        const resp = await fetch('/api/media/' + skuEnc);
        const data = await resp.json();
        const img = (data.files || []).find(f => (f.mimeType || '').startsWith('image/'));
        if (img) {
            const big = img.thumbnailLink
                ? img.thumbnailLink.replace(/=s\d+/, '=s600')
                : `https://lh3.googleusercontent.com/d/${img.id}=s600`;
            setMain(big);
            // Actualizar el chip con su foto real
            const small = img.thumbnailLink
                ? img.thumbnailLink.replace(/=s\d+/, '=s150')
                : `https://lh3.googleusercontent.com/d/${img.id}=s150`;
            if (thumbImg) {
                thumbImg.src = small;
            } else {
                const ph = thumbEl.querySelector('.child-placeholder');
                if (ph) ph.outerHTML = `<img src="${small}" style="width:100%;height:100%;object-fit:cover;">`;
            }
            thumbEl.dataset.fallback = '0';
        } else if (thumbImg) {
            // La variante no tiene fotos propias en Drive: mostrar el fallback
            setMain(thumbImg.src.replace(/=s\d+/, '=s600'));
        }
    } catch (e) {
        if (thumbImg) setMain(thumbImg.src.replace(/=s\d+/, '=s600'));
    }
    thumbEl.style.opacity = '';
}

function scrollChildren(btn, direction) {
    const wrapper = btn.closest('.children-scroll-wrapper');
    const row = wrapper.querySelector('.children-row');
    row.scrollBy({ left: direction * 120, behavior: 'smooth' });
    setTimeout(() => {
        const leftBtn = wrapper.querySelector('.scroll-left');
        const rightBtn = wrapper.querySelector('.scroll-right');
        if (leftBtn) leftBtn.classList.toggle('hidden', row.scrollLeft <= 0);
        if (rightBtn) rightBtn.classList.toggle('hidden', row.scrollLeft + row.clientWidth >= row.scrollWidth - 2);
    }, 350);
}

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

// Filtros de la búsqueda (etapa B): cambiar uno vuelve a buscar con lo escrito en el buscador.
// Los vacíos no van en la URL. El precio espera a que se termine de escribir (change / Enter).
(function () {
    const ff = document.getElementById('filterForm');
    if (!ff) return;
    const go = () => {
        const input = document.getElementById('searchInput');
        const qs = new URLSearchParams();
        const q = input ? input.value.trim() : '';
        if (q) qs.set('q', q);
        new FormData(ff).forEach((v, k) => { if (k !== 'q' && String(v).trim() !== '') qs.set(k, v); });
        window.location.href = '/buscar' + (qs.toString() ? '?' + qs.toString() : '');
    };
    ff.addEventListener('change', go);
    ff.addEventListener('submit', e => { e.preventDefault(); go(); });
})();
