<?php
/**
 * Barra inferior tipo app, solo en el celular (≤ 640 px; estilos al final de style.css, "Etapa 2").
 * Antes de incluirla, la página pone $mnavActive = 'inicio' | 'buscar' | 'carpetas' | 'enviar' (o nada).
 */
$mnavActive = $mnavActive ?? '';
$mnavItems = [
    'inicio'   => ['/', '🏠', 'Inicio'],
    'buscar'   => ['/buscar', '🔍', 'Buscar'],
    'carpetas' => ['/#carpetas', '📂', 'Carpetas'],
    'enviar'   => ['/enviar-catalogo', '📤', 'Enviar'],
];
?>
<nav class="mnav" id="mobileNav" aria-label="Navegación">
    <?php foreach ($mnavItems as $mnavKey => [$mnavHref, $mnavIcon, $mnavLabel]): ?>
        <a href="<?= $mnavHref ?>" class="mnav-item<?= $mnavKey === $mnavActive ? ' active' : '' ?>" data-mnav="<?= $mnavKey ?>"<?= $mnavKey === $mnavActive ? ' aria-current="page"' : '' ?>>
            <span class="mnav-icon" aria-hidden="true"><?= $mnavIcon ?></span>
            <span class="mnav-label"><?= $mnavLabel ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<script>
    // Si la página ya tiene lo que se toca, no recarga: Buscar sube y abre el teclado en el buscador,
    // Carpetas baja a la sección del inicio, Inicio en el inicio sube arriba.
    document.getElementById('mobileNav').addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (!a) return;
        var key = a.dataset.mnav;
        var input = document.getElementById('searchInput');
        var folders = document.getElementById('carpetas');
        if (key === 'buscar' && input) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            input.focus({ preventScroll: true });
        } else if (key === 'carpetas' && folders) {
            e.preventDefault();
            folders.scrollIntoView({ behavior: 'smooth' });
        } else if (key === 'inicio' && location.pathname === '/') {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
</script>
