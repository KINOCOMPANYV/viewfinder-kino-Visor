<?php
/**
 * Ficha de producto — detalle completo por SKU.
 */
$sku = $_GET['sku'] ?? '';
$db = getDB();

$stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
$stmt->execute([$sku]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    include __DIR__ . '/../../templates/404.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>
        <?= e($product['sku']) ?> —
        <?= e($product['name']) ?> · Viewfinder
    </title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
        </div>
    </header>

    <section class="product-detail">
        <div class="container">
            <div class="breadcrumb">
                <a href="/">Inicio</a> ›
                <a href="/buscar">Catálogo</a> ›
                <span style="color:var(--color-text)">
                    <?= e($product['sku']) ?>
                </span>
            </div>

            <div class="detail-grid">
                <!-- Image -->
                <div class="main-image">
                    <?php if ($product['cover_image_url']): ?>
                        <img src="<?= e($product['cover_image_url']) ?>" alt="<?= e($product['name']) ?>">
                    <?php else: ?>
                        ⌚
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div class="info">
                    <span class="sku-badge">
                        <?= e($product['sku']) ?>
                    </span>
                    <h1>
                        <?= e($product['name']) ?>
                    </h1>

                    <?php if ($product['price_suggested'] > 0): ?>
                        <p style="font-size:1.5rem; font-weight:700; color:var(--color-primary); margin:0.5rem 0 1.5rem;">
                            <?= formatPrice($product['price_suggested']) ?>
                        </p>
                    <?php endif; ?>

                    <!-- Meta info -->
                    <ul class="meta-list">
                        <?php if ($product['category']): ?>
                            <li><span>Categoría</span><span>
                                    <?= e($product['category']) ?>
                                </span></li>
                        <?php endif; ?>
                        <?php if ($product['gender']): ?>
                            <li><span>Género</span><span>
                                    <?= e(ucfirst($product['gender'])) ?>
                                </span></li>
                        <?php endif; ?>
                        <?php if ($product['movement']): ?>
                            <li><span>Movimiento</span><span>
                                    <?= e($product['movement']) ?>
                                </span></li>
                        <?php endif; ?>
                        <li>
                            <span>Estado</span>
                            <span>
                                <?php if ($product['status'] === 'active'): ?>
                                    <span class="badge badge-active">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-discontinued">Descontinuado</span>
                                <?php endif; ?>
                            </span>
                        </li>
                    </ul>

                    <!-- Description -->
                    <?php if ($product['description']): ?>
                        <h3 style="font-size:0.9rem; color:var(--color-text-muted); margin-bottom:0.5rem;">Descripción</h3>
                        <div class="description-box" id="descriptionBox">
                            <button class="btn-copy" onclick="copyDescription()" id="copyBtn" title="Copiar descripción">
                                📋 Copiar
                            </button>
                            <p id="descriptionText">
                                <?= e($product['description']) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- Action buttons (Fase 2: Download ZIP) -->
                    <div class="btn-group" style="margin-top:1.5rem;">
                        <button class="btn btn-primary btn-block" disabled style="opacity:0.5;">
                            📸 Descargar Fotos (próximamente)
                        </button>
                        <button class="btn btn-secondary btn-block" disabled style="opacity:0.5;">
                            🎥 Descargar Videos (próximamente)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <p>Solo para distribuidores autorizados · Viewfinder Kino Visor ©
                <?= date('Y') ?>
            </p>
        </div>
    </footer>

    <script>
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
                // Fallback
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
    </script>
</body>

</html>