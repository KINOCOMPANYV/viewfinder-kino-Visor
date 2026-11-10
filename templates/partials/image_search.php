<?php
/**
 * Búsqueda por foto. Dos partes:
 *   $imgSearchPart = 'button' → ícono de cámara dentro de la caja de búsqueda (va en .search-field, después del ✕)
 *   $imgSearchPart = 'modal'  → ventana de resultados + script (una vez, al final de la página)
 * Solo sale si hay JINA_API_KEY (ImageSearch::enabled()).
 */
require_once __DIR__ . '/../../src/services/ImageSearch.php';
if (!ImageSearch::enabled()) {
    return;
}
$imgSearchPart = $imgSearchPart ?? 'modal';

if ($imgSearchPart === 'button'): ?>
<button type="button" class="search-photo-btn imgs-open" title="Buscar por foto: toma o sube una foto y te muestra los productos parecidos" aria-label="Buscar por foto"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg></button>
<?php return; endif; ?>

<style>
.imgs-modal { position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,.75); display:none; align-items:flex-start; justify-content:center; overflow-y:auto; padding:1rem; }
.imgs-modal.open { display:flex; }
.imgs-panel { background:var(--color-surface, #12121a); border:1px solid var(--color-border, #333); border-radius:var(--radius, 12px); width:100%; max-width:960px; padding:1rem; margin:auto 0; }
.imgs-head { display:flex; gap:1rem; align-items:center; margin-bottom:1rem; }
.imgs-head img { width:84px; height:84px; object-fit:cover; border-radius:8px; background:#000; flex-shrink:0; }
.imgs-head .imgs-title { flex:1; min-width:0; }
.imgs-head h3 { margin:0 0 .25rem; font-size:1.1rem; }
.imgs-head p { margin:0; color:var(--color-text-muted, #999); font-size:.9rem; }
.imgs-close { background:none; border:none; color:var(--color-text, #fff); font-size:1.6rem; cursor:pointer; line-height:1; }
.imgs-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:.75rem; }
.imgs-card { display:block; background:var(--color-surface-2, #1e1e2a); border-radius:8px; overflow:hidden; text-decoration:none; color:inherit; border:1px solid var(--color-border, #333); }
.imgs-card img { width:100%; aspect-ratio:1; object-fit:cover; display:block; background:#fff; }
.imgs-card div { padding:.4rem .5rem; font-size:.8rem; }
.imgs-card b { display:block; font-size:.85rem; }
.imgs-card span { color:var(--color-text-muted, #999); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.imgs-msg { text-align:center; padding:2rem 1rem; color:var(--color-text-muted, #999); }
.imgs-actions { display:flex; justify-content:center; margin-top:1rem; }
.imgs-again { padding:.7rem 1.5rem; background:var(--color-primary, #c9a84c); color:#000; font-weight:600; border:none; border-radius:var(--radius-sm, 8px); cursor:pointer; font-size:.95rem; }
@media (max-width: 600px) { .imgs-grid { grid-template-columns:repeat(3, 1fr); gap:.4rem; } .imgs-card div { font-size:.7rem; } .imgs-modal { padding:.5rem; } }
</style>
<input type="file" id="imgsFile" accept="image/*" hidden>
<div class="imgs-modal" id="imgsModal" role="dialog" aria-modal="true" aria-label="Buscar por foto">
    <div class="imgs-panel">
        <div class="imgs-head">
            <img id="imgsPreview" alt="Tu foto">
            <div class="imgs-title">
                <h3>Productos parecidos</h3>
                <p id="imgsStatus">Analizando la foto…</p>
            </div>
            <button type="button" class="imgs-close" id="imgsClose" aria-label="Cerrar">×</button>
        </div>
        <div class="imgs-grid" id="imgsGrid"></div>
        <div class="imgs-actions"><button type="button" class="imgs-again imgs-open">📸 Otra foto</button></div>
    </div>
</div>
<script>
(function () {
    const fileInput = document.getElementById('imgsFile');
    const modal = document.getElementById('imgsModal');
    const grid = document.getElementById('imgsGrid');
    const status = document.getElementById('imgsStatus');
    const preview = document.getElementById('imgsPreview');
    if (!fileInput || !modal) return;

    document.querySelectorAll('.imgs-open').forEach(b => b.addEventListener('click', () => { fileInput.value = ''; fileInput.click(); }));
    document.getElementById('imgsClose').addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('open'); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') modal.classList.remove('open'); });

    // Achica a 512 px (lado mayor) en JPEG: sube rápido en el celular y es lo que compara el modelo
    function shrink(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                const s = Math.min(1, 512 / Math.max(img.naturalWidth, img.naturalHeight));
                const c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(img.naturalWidth * s));
                c.height = Math.max(1, Math.round(img.naturalHeight * s));
                const ctx = c.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, c.width, c.height);
                ctx.drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(b => b ? resolve(b) : reject(new Error('blob')), 'image/jpeg', 0.88);
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('img')); };
            img.src = url;
        });
    }

    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        grid.innerHTML = '';
        status.textContent = 'Analizando la foto…';
        modal.classList.add('open');
        let blob;
        try {
            blob = await shrink(file);
        } catch (e) {
            status.textContent = 'No se pudo leer esa imagen. Prueba con otra foto.';
            return;
        }
        preview.src = URL.createObjectURL(blob);
        const fd = new FormData();
        fd.append('foto', blob, 'foto.jpg');
        try {
            const r = await fetch('/buscar-foto', { method: 'POST', body: fd });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.error || (r.status === 429 ? 'Muchas búsquedas seguidas. Espera un minuto.' : 'No se pudo buscar.'));
            const items = data.results || [];
            if (!items.length) {
                status.textContent = data.note || 'No se encontraron productos parecidos.';
                return;
            }
            status.textContent = 'Los más parecidos primero (' + items.length + '). Toca uno para abrirlo.';
            grid.innerHTML = items.map(p =>
                '<a class="imgs-card" href="' + esc(p.url) + '">' +
                '<img src="' + esc(p.thumb) + '" alt="' + esc(p.sku) + '" loading="lazy">' +
                '<div><b>' + esc(p.sku) + '</b><span>' + esc(p.name || '') + '</span></div></a>'
            ).join('');
        } catch (e) {
            status.textContent = e.message || 'No se pudo buscar. Revisa la conexión e intenta de nuevo.';
        }
    });
})();
</script>
