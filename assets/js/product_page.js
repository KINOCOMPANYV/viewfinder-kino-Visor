// Movido desde src/controllers/product.php para que el navegador lo guarde en caché.
// Usa las constantes que la ficha define antes (SKU, SHARE_URL, currentCover, currentMainFileId…).
let mediaFiles = { images: [], videos: [] };
let allGalleryFiles = [];

// Escapa HTML para interpolar texto (nombres de archivo de Drive) en innerHTML
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function copyDescription() {
    const text = document.getElementById('descriptionText').innerText;
    const btn = document.getElementById('copyBtn');

    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = '✅ Copiado';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.textContent = '📋 Copiar';
            btn.classList.remove('copied');
        }, 2000);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = '✅ Copiado';
        setTimeout(() => btn.textContent = '📋 Copiar', 2000);
    });
}

// Load media from Drive API (búsqueda bidireccional)
async function loadMedia() {
    try {
        const resp = await fetch('/api/media/' + encodeURIComponent(SEARCH_SKU));
        const data = await resp.json();
        const files = data.files || [];

        files.forEach(f => {
            const mime = f.mimeType || '';
            if (mime.startsWith('image/')) {
                mediaFiles.images.push(f);
            } else if (mime.startsWith('video/')) {
                mediaFiles.videos.push(f);
            }
        });

        // Cargar imagen principal dinámicamente
        loadMainCover(files);

        updateButtons();
        renderGallery(files);
    } catch (e) {
        console.error('Error cargando media:', e);
        document.getElementById('btnPhotos').textContent = '📸 Sin conexión';
        document.getElementById('btnVideos').textContent = '🎥 Sin conexión';
        // Mostrar placeholder si falla
        const main = document.getElementById('mainCover');
        if (main && !main.querySelector('img')) main.innerHTML = '📷';
    }
}

function loadMainCover(files) {
    const main = document.getElementById('mainCover');
    if (!main) return;

    // Helper: show video as main cover with play overlay
    function showVideoCover() {
        const vid = files.find(f => (f.mimeType || '').startsWith('video/'));
        if (vid) {
            const idx = files.indexOf(vid);
            const thumbUrl = vid.thumbnailLink
                ? vid.thumbnailLink.replace(/=s\d+/, '=s800')
                : '';
            const thumbImg = thumbUrl
                ? `<img src="${thumbUrl}" alt="${esc(vid.name)}" style="width:100%;height:100%;object-fit:cover;">`
                : `<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#0a0a0f);display:flex;align-items:center;justify-content:center;"><span style='font-size:4rem;opacity:0.3'>🎬</span></div>`;
            main.innerHTML = `
                <div class="video-thumb-wrap" style="width:100%;height:100%;cursor:pointer;" onclick="openVideoModal(${idx})">
                    ${thumbImg}
                    <div class="video-play-overlay">
                        <div class="video-play-btn" style="width:64px;height:64px;">
                            <svg viewBox="0 0 24 24" style="width:28px;height:28px;"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                        <span class="video-play-label" style="font-size:0.85rem;">Reproducir video</span>
                    </div>
                    <span class="video-badge" style="font-size:0.75rem;padding:0.25rem 0.6rem;">VIDEO</span>
                </div>`;
            document.getElementById('mainImageName').textContent = vid.name;
            return;
        }
        main.innerHTML = '📷';
    }

    // Helper: try Drive images, fallback to video
    function tryDriveImages() {
        const img = files.find(f => (f.mimeType || '').startsWith('image/'));
        if (img) {
            const url = img.thumbnailLink
                ? img.thumbnailLink.replace(/=s\d+/, '=s600')
                : `https://lh3.googleusercontent.com/d/${img.id}=s600`;
            setCoverImage(main, url, img.id, img.name, showVideoCover);
            return;
        }
        showVideoCover();
    }

    // 1) Si ya tiene cover de BD, usarla (con fallback a Drive si falla)
    if (main.dataset.cover) {
        setCoverImage(main, main.dataset.cover, null, null, tryDriveImages);
        return;
    }

    // 2) Buscar primera imagen de Drive (con fallback a video)
    tryDriveImages();
}

function setCoverImage(container, url, fileId, fileName, onErrorFallback) {
    const imgEl = document.createElement('img');
    imgEl.alt = PRODUCT_NAME;
    imgEl.style.opacity = '0';
    imgEl.style.transition = 'opacity 0.4s ease';
    imgEl.onclick = () => openMainLightbox();
    imgEl.onload = () => {
        Lightbox.preload(fileId || url); // versión grande lista para el clic
        container.innerHTML = '';
        container.appendChild(imgEl);
        requestAnimationFrame(() => imgEl.style.opacity = '1');
        // Update tracking vars and info bar (portada guardada: el id va en la URL de lh3)
        if (!fileId) {
            const m = String(url).match(/\/d\/([A-Za-z0-9_-]{10,})/);
            fileId = m ? m[1] : null;
        }
        if (fileId) {
            currentMainFileId = fileId;
            currentMainFileName = fileName || '';
            document.getElementById('mainImageName').textContent = fileName || '';
            document.getElementById('mainImageDownload').style.display = '';
            document.getElementById('mainImageWaShare').style.display = '';
        }
    };
    imgEl.onerror = () => {
        if (typeof onErrorFallback === 'function') {
            onErrorFallback();
        } else {
            container.innerHTML = '📷';
        }
    };
    imgEl.src = url;
}

function updateButtons() {
    const btnP = document.getElementById('btnPhotos');
    const btnV = document.getElementById('btnVideos');
    const count = document.getElementById('mediaCount');

    const imgCount = mediaFiles.images.length;
    const vidCount = mediaFiles.videos.length;

    if (imgCount > 0) {
        btnP.textContent = `📸 Descargar Fotos (${imgCount})`;
        btnP.disabled = false;
        btnP.style.opacity = '1';
    } else {
        btnP.textContent = '📸 Sin fotos';
        btnP.disabled = true;
        btnP.style.opacity = '0.4';
    }

    if (vidCount > 0) {
        btnV.textContent = `🎥 Descargar Videos (${vidCount})`;
        btnV.disabled = false;
        btnV.style.opacity = '1';
    } else {
        btnV.textContent = '🎥 Sin videos';
        btnV.disabled = true;
        btnV.style.opacity = '0.4';
    }

    count.textContent = `${imgCount} foto(s) · ${vidCount} video(s) en Drive`;
}

// Show file in main image area when clicking a thumbnail
// Celular: las miniaturas están en varias filas; al tocar una de abajo se sube a ver la foto grande
function mainIntoView() {
    const m = document.getElementById('mainCover');
    if (!m) return;
    const top = m.getBoundingClientRect().top;
    if (top < 70) window.scrollTo({ top: window.scrollY + top - 80, behavior: 'smooth' }); // 80 = encabezado fijo
}

function showInMain(idx) {
    const f = allGalleryFiles[idx];
    if (!f) return;
    const isVideo = (f.mimeType || '').startsWith('video/');

    const main = document.getElementById('mainCover');

    if (isVideo) {
        // Show video preview thumbnail in main area with play overlay
        const videoThumb = f.thumbnailLink
            ? f.thumbnailLink.replace(/=s\d+/, '=s600')
            : '';
        const thumbImg = videoThumb
            ? `<img src="${videoThumb}" alt="${esc(f.name)}" style="width:100%;height:100%;object-fit:contain;">`
            : `<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#0a0a0f);display:flex;align-items:center;justify-content:center;"><span style="font-size:4rem;opacity:0.3">🎬</span></div>`;
        main.innerHTML = `
            <div style="position:relative;width:100%;height:100%;cursor:pointer;" onclick="openVideoModal(${idx})">
                ${thumbImg}
                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,0.35);">
                    <div style="width:64px;height:64px;background:rgba(255,255,255,0.95);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(0,0,0,0.4);">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="#000"><path d="M8 5v14l11-7z"/></svg>
                    </div>
                    <span style="font-size:0.8rem;color:rgba(255,255,255,0.85);margin-top:0.5rem;text-shadow:0 1px 4px rgba(0,0,0,0.7);">Reproducir video</span>
                </div>
            </div>`;
        // Update info bar
        document.getElementById('mainImageName').textContent = f.name;
        currentMainFileId = f.id;
        currentMainFileName = f.name || '';
        document.getElementById('mainImageDownload').style.display = '';
    } else {
        const url = f.thumbnailLink
            ? f.thumbnailLink.replace(/=s\d+/, '=s600')
            : `https://lh3.googleusercontent.com/d/${f.id}=s600`;
        setCoverImage(main, url, f.id, f.name, () => { main.innerHTML = '📷'; });
    }

    // Highlight active thumbnail
    document.querySelectorAll('.thumb-strip-item').forEach((el, i) => {
        el.classList.toggle('active', i === idx);
    });
    document.querySelectorAll('.gallery-item').forEach((el, i) => {
        el.classList.toggle('gallery-active', i === idx);
    });
}

// Global state for video modal (redundant storage)
window.KINO_VIDEO_STATE = { id: '', name: '' };

function openVideoModal(idx) {
    const f = allGalleryFiles[idx];
    if (!f) return;
    openVideoModalDirect(f.id, f.name);
}

function openVideoModalDirect(fileId, fileName) {
    console.log('--- OPEN VIDEO MODAL ---', { fileId, fileName });
    if (!fileId) {
        console.error('Error: openVideoModalDirect llamado sin fileId');
        return;
    }

    const modal = document.getElementById('videoModal');
    const player = document.getElementById('videoModalPlayer');
    const title = document.getElementById('videoModalTitle');
    const dlBtn = document.getElementById('videoModalDownload');

    // 1. Store in global object
    window.KINO_VIDEO_STATE = { id: fileId, name: fileName || '' };

    // 2. Store in sessionStorage (cache backup)
    try {
        sessionStorage.setItem('last_video_id', fileId);
        sessionStorage.setItem('last_video_name', fileName || '');
    } catch(e) {}

    // 3. Store in button attributes
    if (dlBtn) {
        dlBtn.setAttribute('data-file-id', fileId);
        dlBtn.setAttribute('data-file-name', fileName || '');
    }

    player.innerHTML = `<iframe src="https://drive.google.com/file/d/${fileId}/preview" allow="autoplay; encrypted-media" allowfullscreen style="width:100%;height:100%;border:none;"></iframe>`;
    title.textContent = fileName || '';

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
        modal.querySelector('.video-modal-content').style.transform = 'scale(1)';
    }, 10);
}

function closeVideoModal(e, force) {
    if (!force && e.target !== e.currentTarget) return;
    const modal = document.getElementById('videoModal');
    const player = document.getElementById('videoModalPlayer');
    modal.querySelector('.video-modal-content').style.transform = 'scale(0.9)';
    setTimeout(() => {
        modal.classList.remove('active');
        player.innerHTML = '';
        document.body.style.overflow = '';
    }, 200);
}

function downloadModalVideo(e) {
    const dlBtn = document.getElementById('videoModalDownload');

    // Try reading from 3 sources
    let fid = window.KINO_VIDEO_STATE.id;
    let fname = window.KINO_VIDEO_STATE.name;

    if (!fid) {
        fid = dlBtn ? dlBtn.getAttribute('data-file-id') : null;
        fname = dlBtn ? dlBtn.getAttribute('data-file-name') : '';
    }

    if (!fid) {
        fid = sessionStorage.getItem('last_video_id');
        fname = sessionStorage.getItem('last_video_name');
    }

    console.log('--- ATTEMPT DOWNLOAD ---', { fid, fname, global: window.KINO_VIDEO_STATE.id, attr: dlBtn.getAttribute('data-file-id') });

    if (fid && fid !== 'undefined' && fid !== 'null' && fid !== '') {
        downloadFile(fid, fname, e);
    } else {
        console.error('CRITICAL: No video ID found in any storage!');
        alert('Error: No se ha detectado el ID del video.\n\nPor favor intenta esto:\n1. Cierra el video.\n2. Presiona Ctrl+F5 (o recarga la página).\n3. Abre el video de nuevo.');
    }
}

// Download file via proxy — simple way for browser to handle large files
function downloadFile(fileId, fileName, evt) {
    if (!fileId || fileId === 'undefined') {
        alert('Error: ID de archivo no disponible');
        return;
    }
    const btn = evt && evt.target ? evt.target : null;
    if (btn) {
        const originalText = btn.textContent;
        btn.textContent = '⏳ Iniciando descarga...';
        btn.disabled = true;
        setTimeout(() => {
            btn.textContent = originalText;
            btn.disabled = false;
        }, 2000);
    }
    // Navegar directamente al proxy — el navegador gestiona el stream a disco
    window.location.assign('/api/download/' + fileId);
}

function renderGallery(files) {
    if (files.length === 0) return;

    const gallery = document.getElementById('mediaGallery');
    const grid = document.getElementById('galleryGrid');
    gallery.style.display = 'block';
    allGalleryFiles = files;
    // Las primeras fotos en grande quedan en caché mientras el usuario mira la ficha
    files.filter(f => (f.mimeType || '').startsWith('image/')).slice(0, 4).forEach(f => Lightbox.preload(f.id));

    // Build thumbnail strip
    const strip = document.getElementById('thumbStrip');
    strip.innerHTML = files.map((f, idx) => {
        const isImage = (f.mimeType || '').startsWith('image/');
        const isVideo = (f.mimeType || '').startsWith('video/');
        let thumbSrc = '';
        if (isImage) {
            thumbSrc = f.thumbnailLink
                ? f.thumbnailLink.replace(/=s\d+/, '=s120')
                : `https://lh3.googleusercontent.com/d/${f.id}=s120`;
        } else if (isVideo && f.thumbnailLink) {
            thumbSrc = f.thumbnailLink.replace(/=s\d+/, '=s120');
        }
        const imgTag = thumbSrc
            ? `<img src="${thumbSrc}" alt="${esc(f.name)}" loading="lazy">`
            : `<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:1.5rem;">📄</div>`;
        const badge = isVideo ? '<span class="thumb-video-badge">▶</span>' : '';
        return `<div class="thumb-strip-item" onclick="showInMain(${idx}); mainIntoView()" title="${esc(f.name)}">${imgTag}${badge}</div>`;
    }).join('');

    grid.innerHTML = files.map((f, idx) => {
        const isImage = (f.mimeType || '').startsWith('image/');
        const isVideo = (f.mimeType || '').startsWith('video/');
        const downloadUrl = f.webContentLink || f.webViewLink || '#';

        let mediaHtml;
        if (isImage) {
            const thumbUrl = f.thumbnailLink
                ? f.thumbnailLink.replace(/=s\d+/, '=s200')
                : `https://lh3.googleusercontent.com/d/${f.id}=s200`;
            mediaHtml = `<img data-src="${thumbUrl}" alt="${esc(f.name)}" class="img-fade-in gallery-lazy" style="cursor:pointer;" onerror="this.outerHTML='<div style=\\'display:flex;align-items:center;justify-content:center;height:150px;color:var(--color-text-muted);font-size:2rem;\\'>📷</div>'" onclick="showInMain(${idx})">`;
        } else if (isVideo) {
            const videoThumb = f.thumbnailLink
                ? f.thumbnailLink.replace(/=s\d+/, '=s300')
                : '';
            const thumbContent = videoThumb
                ? `<img src="${videoThumb}" alt="${esc(f.name)}" loading="lazy">`
                : `<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#0a0a0f);display:flex;align-items:center;justify-content:center;"><span style='font-size:2.5rem;opacity:0.3'>🎬</span></div>`;
            mediaHtml = `<div class="video-thumb-wrap" onclick="openVideoModal(${idx})">
                ${thumbContent}
                <div class="video-play-overlay">
                    <div class="video-play-btn">
                        <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    </div>
                    <span class="video-play-label">Reproducir</span>
                </div>
                <span class="video-badge">VIDEO</span>
            </div>
            <button onclick="event.stopPropagation();downloadFile('${f.id}','${esc(f.name.replace(/['"\\]/g, ''))}',event)" style="display:block;width:100%;text-align:center;padding:0.25rem;font-size:0.7rem;color:var(--color-primary);background:none;border:none;cursor:pointer;">⬇️ Descargar video</button>`;
        } else {
            mediaHtml = `<div class="video-placeholder">📄</div>`;
        }

        // Casilla para marcar la foto (los videos se bajan con su botón: no van en las tandas de WhatsApp)
        const checkHtml = isImage ? `
            <label class="search-check-footer">
                <input type="checkbox" class="gallery-check search-card-check" data-index="${idx}" data-file-id="${f.id}" data-sku="${esc(f.name.replace(/\.[^.]+$/, ''))}" data-is-video="0">
                <span class="search-check-icon">✓</span>
                <span class="search-check-text">Seleccionar</span>
            </label>` : '';

        return `
            <div class="gallery-item" data-file-id="${f.id}">
                ${mediaHtml}
                <div style="font-size:0.65rem; color:var(--color-text-muted); padding:0.3rem 0.4rem; text-align:center; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${esc(f.name)}">
                    ${esc(f.name.length > 25 ? f.name.substring(0, 22) + '...' : f.name)}
                </div>
                ${checkHtml}
            </div>
        `;
    }).join('');

    // Lazy-load thumbnails
    const lazyObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    img.onload = () => img.classList.add('loaded');
                }
                lazyObserver.unobserve(img);
            }
        });
    }, { rootMargin: '300px' });

    grid.querySelectorAll('img.gallery-lazy[data-src]').forEach(img => {
        lazyObserver.observe(img);
    });

    // Casillas de la galería: misma barra y selección que el catálogo (assets/js/wa_batch.js):
    // fotos livianas que bajan al marcar, tandas de 10 y lo marcado sigue al cambiar de página.
    if (window.KinoWaBatch) KinoWaBatch.sync();
}

function downloadMedia(type) {
    const files = type === 'image' ? mediaFiles.images : mediaFiles.videos;
    if (files.length === 0) return;

    // Open each file download link in a new tab
    files.forEach((f, i) => {
        const url = f.webContentLink || f.webViewLink || '#';
        setTimeout(() => {
            window.open(url, '_blank');
        }, i * 300); // Stagger to avoid popup blocker
    });
}

async function setCover(fileId, btn) {
    if (btn.classList.contains('current')) return;
    btn.textContent = '⏳...';
    btn.disabled = true;

    const imageUrl = `https://lh3.googleusercontent.com/d/${fileId}`;
    const form = new FormData();
    form.append('product_id', PRODUCT_ID);
    form.append('image_url', imageUrl);

    try {
        const resp = await fetch('/admin/product/set-cover', { method: 'POST', body: form });
        const data = await resp.json();
        if (data.ok) {
            currentCover = imageUrl;
            // Update main image
            const mainImg = document.querySelector('.main-image');
            mainImg.innerHTML = `<img src="${imageUrl}" alt="${PRODUCT_NAME}" style="cursor:zoom-in;" onclick="openMainLightbox()">`;

            // Reset all cover buttons
            document.querySelectorAll('.btn-set-cover').forEach(b => {
                b.classList.remove('current');
                b.disabled = false;
                b.textContent = '⭐ Hacer principal';
            });
            btn.classList.add('current');
            btn.disabled = true;
            btn.textContent = '⭐ Principal';
        } else {
            alert(data.error || 'Error al cambiar imagen.');
            btn.textContent = '⭐ Hacer principal';
            btn.disabled = false;
        }
    } catch (e) {
        alert('Error de conexión.');
        btn.textContent = '⭐ Hacer principal';
        btn.disabled = false;
    }
}

// Init
loadMedia();
let currentMainFileName = '';

// === Botón volver dinámico ===
(function() {
    try {
        const lastSearch = sessionStorage.getItem('lastSearch');
        const btn = document.getElementById('btnBackCatalog');
        if (lastSearch && btn) {
            const url = new URL(lastSearch);
            // Solo usar si es una página de búsqueda del mismo sitio
            if (url.pathname.startsWith('/buscar') || url.pathname === '/') {
                btn.href = lastSearch;
                const q = url.searchParams.get('q');
                if (q) {
                    btn.textContent = '⬅️ Volver a "' + q + '"';
                } else {
                    btn.textContent = '⬅️ Volver al catálogo';
                }
            }
        }
    } catch(e) {}
})();

// === Compartir imagen principal por WhatsApp ===
async function shareMainImageWa() {
    if (!currentMainFileId) {
        // Sin foto todavía: se manda el enlace (WhatsApp arma la vista previa)
        window.open('https://wa.me/?text=' + encodeURIComponent('*' + SKU + ' — ' + PRODUCT_NAME + '*\n' + SHARE_URL), '_blank');
        return;
    }
    const imgUrl = 'https://lh3.googleusercontent.com/d/' + currentMainFileId + '=s1200';
    const productUrl = SHARE_URL;
    const text = '📦 *' + SKU + ' - ' + PRODUCT_NAME + '*\n\n🔗 Ver producto: ' + productUrl;

    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (isMobile && navigator.canShare && navigator.share) {
        const btn = document.getElementById('mainImageWaShare');
        if (btn) { btn.textContent = '⏳ Preparando...'; btn.disabled = true; }
        try {
            const resp = await fetch(`/api/download/${currentMainFileId}`);
            const blob = await resp.blob();
            const ext = (currentMainFileName.match(/\.[^.]+$/) || ['.jpg'])[0];
            const file = new File([blob], currentMainFileName || 'imagen' + ext, { type: blob.type || 'image/jpeg' });
            const shareData = { files: [file], text: text };
            if (navigator.canShare(shareData)) {
                await navigator.share(shareData);
                if (btn) { btn.textContent = '✅ Enviado'; setTimeout(() => { btn.innerHTML = '📤 WhatsApp'; btn.disabled = false; }, 2000); }
                return;
            }
        } catch(e) {
            if (e.name === 'AbortError') { if (btn) { btn.innerHTML = '📤 WhatsApp'; btn.disabled = false; } return; }
        }
        if (btn) { btn.innerHTML = '📤 WhatsApp'; btn.disabled = false; }
    }
    // Fallback desktop: abrir wa.me con link de la imagen + enlace del producto
    const waText = text + '\n\n🖼️ Imagen: ' + imgUrl;
    window.open('https://wa.me/?text=' + encodeURIComponent(waText), '_blank');
}

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeVideoModal(e, true);
});

// Abre en grande la foto principal; con galería cargada se puede pasar entre todas las fotos
function openMainLightbox() {
    const mainImg = document.querySelector('#mainCover img');
    const images = allGalleryFiles
        .filter(f => (f.mimeType || '').startsWith('image/'))
        .map(f => ({ id: f.id, name: f.name, src: (f.thumbnailLink || '').replace(/=s\d+/, '=s600') }));
    let start = images.findIndex(it => it.id === currentMainFileId);
    if (start < 0 && mainImg) {
        // Portada guardada (aún sin galería o sin id conocido): buscarla por id en la URL
        const m = (mainImg.currentSrc || mainImg.src).match(/\/d\/([A-Za-z0-9_-]+)/);
        start = m ? images.findIndex(it => it.id === m[1]) : -1;
        if (start < 0) {
            images.unshift({ id: m ? m[1] : null, name: PRODUCT_NAME, src: mainImg.currentSrc || mainImg.src });
            start = 0;
        }
    }
    if (mainImg) images[start].src = mainImg.currentSrc || mainImg.src; // ya está en caché: se ve al instante
    Lightbox.open(images, Math.max(0, start));
}

// Download the current main image
function downloadMainImage() {
    if (!currentMainFileId) return;
    const url = `https://lh3.googleusercontent.com/d/${currentMainFileId}=s1200`;
    const a = document.createElement('a');
    a.href = url;
    a.download = currentMainFileName || 'image.jpg';
    a.target = '_blank';
    // Blob download for mobile support
    fetch(`/api/download/${currentMainFileId}`)
        .then(r => r.blob())
        .then(blob => {
            const blobUrl = URL.createObjectURL(blob);
            a.href = blobUrl;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
        })
        .catch(() => {
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
}
