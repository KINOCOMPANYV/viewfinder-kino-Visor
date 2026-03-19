<!-- Loading Overlay — se muestra al navegar entre páginas -->
<div id="pageLoadingOverlay" class="page-loading-overlay">
    <div class="loading-content">
        <div class="hourglass">⏳</div>
        <p class="loading-text">Cargando...</p>
    </div>
</div>
<style>
.page-loading-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(10, 10, 15, 0.85);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}
.page-loading-overlay.active {
    opacity: 1;
    pointer-events: all;
}
.loading-content {
    text-align: center;
    animation: loadingBounce 0.5s ease;
}
.hourglass {
    font-size: 3.5rem;
    animation: hourglassSpin 1.2s ease-in-out infinite;
    display: inline-block;
    filter: drop-shadow(0 0 20px rgba(201, 168, 76, 0.6));
}
.loading-text {
    margin-top: 1rem;
    color: #e8e8f0;
    font-size: 0.95rem;
    font-weight: 500;
    letter-spacing: 0.5px;
    opacity: 0.8;
}
@keyframes hourglassSpin {
    0%   { transform: rotate(0deg); }
    50%  { transform: rotate(180deg); }
    100% { transform: rotate(180deg); }
}
@keyframes loadingBounce {
    0%   { transform: scale(0.8); opacity: 0; }
    100% { transform: scale(1);   opacity: 1; }
}
</style>
<script>
(function() {
    var overlay = document.getElementById('pageLoadingOverlay');
    if (!overlay) return;

    // Mostrar overlay al hacer clic en links internos
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        // Ignorar links externos, anclas, javascript:, download, target=_blank
        if (!href || href.startsWith('#') || href.startsWith('javascript:') ||
            href.startsWith('http') || href.startsWith('mailto:') ||
            link.hasAttribute('download') ||
            link.getAttribute('target') === '_blank') return;
        // Ignorar links de API (descargas, etc)
        if (href.startsWith('/api/')) return;
        // Mostrar overlay
        overlay.classList.add('active');
    });

    // Mostrar overlay al hacer submit de formularios
    document.addEventListener('submit', function(e) {
        var form = e.target;
        // Solo formularios que navegan (no AJAX)
        if (form.getAttribute('data-ajax') === 'true') return;
        overlay.classList.add('active');
    });

    // Ocultar cuando la página esté lista (back/forward cache)
    window.addEventListener('pageshow', function() {
        overlay.classList.remove('active');
    });

    // Ocultar al cargar
    window.addEventListener('load', function() {
        overlay.classList.remove('active');
    });
})();
</script>
