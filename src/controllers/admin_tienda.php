<?php
/** Admin → 🛍️ Tienda (/admin/tienda): celular de avisos, datos de transferencia, textos del catálogo de venta público (/tienda). */
$flash = $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $phone = preg_replace('/\D+/', '', (string) ($_POST['notify'] ?? '')) ?: '';
        $db = getDB();
        if ($phone !== '' && strlen($phone) < 10) throw new RuntimeException('Escribe un celular válido (10 dígitos).');
        if ($phone !== '') setAppSetting($db, 'tienda_notify_phone', $phone); // vacío = se deja el que ya había (antes bloqueaba todo el guardado, banner incluido)
        setAppSetting($db, 'tienda_bank_info', mb_substr(trim((string) ($_POST['bank'] ?? '')), 0, 800, 'UTF-8'));
        setAppSetting($db, 'tienda_tagline', mb_substr(trim((string) ($_POST['tagline'] ?? '')), 0, 120, 'UTF-8'));
        setAppSetting($db, 'tienda_hero_sku', mb_substr(trim((string) ($_POST['hero_sku'] ?? '')), 0, 60, 'UTF-8'));
        setAppSetting($db, 'tienda_marcas', mb_substr(trim((string) ($_POST['brands'] ?? '')), 0, 200, 'UTF-8') ?: '*');
        setAppSetting($db, 'tienda_delivery', mb_substr(trim((string) ($_POST['delivery'] ?? '')), 0, 200, 'UTF-8'));
        $clip = fn(string $k, int $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n, 'UTF-8');
        setAppSetting($db, 'tienda_banner_on', !empty($_POST['banner_on']) ? '1' : '0');
        setAppSetting($db, 'tienda_banner_kicker', $clip('banner_kicker', 40));
        setAppSetting($db, 'tienda_banner_title', $clip('banner_title', 80));
        setAppSetting($db, 'tienda_banner_text', $clip('banner_text', 160));
        setAppSetting($db, 'tienda_banner_btn', $clip('banner_btn', 30));
        $link = $clip('banner_link', 200);
        setAppSetting($db, 'tienda_banner_link', ($link === '' || $link[0] === '#' || $link[0] === '/' || preg_match('#^https://#i', $link)) ? $link : '#tnCatalog');
        if (!empty($_POST['banner_del'])) {
            $db->prepare("DELETE FROM doc_templates WHERE name = 'tienda_banner'")->execute();
        }
        $f = $_FILES['banner_img'] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 12 * 1048576) throw new RuntimeException('No se pudo subir la imagen (máximo 12 MB).');
            $info = @getimagesize($f['tmp_name']);
            $src = $info ? match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']), IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']), default => false } : false;
            if (!$src) throw new RuntimeException('La imagen debe ser JPG, PNG o WebP.');
            $w = imagesx($src); $h = imagesy($src);
            if ($w > 2000) { $src = imagescale($src, 2000); }
            ob_start(); imagejpeg($src, null, 82); $jpg = ob_get_clean();
            $db->prepare("INSERT INTO doc_templates (name, data) VALUES ('tienda_banner', ?) ON DUPLICATE KEY UPDATE data = VALUES(data)")->execute([base64_encode($jpg)]);
        }
        $flash = 'Guardado.';
    } catch (\Throwable $e) { $error = $e->getMessage(); }
}
// Estado real del banner (leído de la base, no del formulario) para ver si de verdad quedó guardado.
$bnImg = null;
try {
    $bq = getDB()->query("SELECT LENGTH(data) AS n, UNIX_TIMESTAMP(updated_at) AS t FROM doc_templates WHERE name = 'tienda_banner'");
    $bnImg = $bq ? $bq->fetch(PDO::FETCH_ASSOC) : null;
} catch (\Throwable $e) { $bnImg = null; }
try { $bnOn = (string) getDB()->query("SELECT value FROM app_settings WHERE name = 'tienda_banner_on'")->fetchColumn(); } catch (\Throwable $e) { $bnOn = ''; }
$adminNav = 'tienda';
$v2 = ['nav' => 'gestion', 'sub' => 'tienda', 'title' => 'Tienda', 'subtitle' => 'Catálogo de venta público: avisos y datos de pago'];
include __DIR__ . '/../../templates/admin/partials/v2_start.php';
?>
<form method="post" enctype="multipart/form-data" class="v2-card" style="padding:1.25rem;max-width:640px;display:grid;gap:.9rem">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <?php if ($flash): ?><p style="color:var(--color-success)"><?= e($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p style="color:var(--color-danger)"><?= e($error) ?></p><?php endif; ?>
    <p>Tu tienda pública está en <a href="/tienda" target="_blank" rel="noopener"><b>/tienda</b></a>. Solo muestra los productos con el interruptor «Catálogo» encendido en Productos, con foto y con stock. Las categorías «Promocionales» (Productos → Categorías) salen primero.</p>
    <label>Celular que recibe el aviso de cada pedido
        <input class="v2-input" name="notify" value="<?= e(substr(shopNotifyPhone(), 2)) ?>" inputmode="tel" maxlength="20" style="width:100%"></label>
    <label>Datos para transferencia (banco, cuenta, titular, Nequi…)
        <textarea class="v2-input" name="bank" rows="4" maxlength="800" style="width:100%"><?= e(shopBankInfo()) ?></textarea></label>
    <label>Marcas que muestra la tienda = Categoría del producto (separadas por coma; «*» = todas)
        <input class="v2-input" name="brands" value="<?= e(appSetting('tienda_marcas', '*')) ?>" maxlength="200" style="width:100%" placeholder="* = todas"></label>
    <?php
    // Diagnóstico: cuántos productos pasan cada regla de la tienda (para saber por qué /tienda sale vacía).
    $dg = [];
    try {
        $dbq = getDB();
        $stk = stockSql('p');
        $base = "FROM products p WHERE p.archived = 0 AND p.is_active = 1 AND p.cover_image_url > ''";
        $n = fn(string $w, array $a = []) => (function () use ($dbq, $base, $w, $a) { $q = $dbq->prepare("SELECT COUNT(*) $base $w"); $q->execute($a); return (int) $q->fetchColumn(); })();
        $dg['Catálogo encendido + foto'] = $n('');
        $dg['… y con stock > 0'] = $n(" AND $stk > 0");
        [$tr, $tp] = tiendaRulesSql();
        $q3 = $dbq->prepare('SELECT COUNT(*) FROM products p WHERE 1=1' . $tr); $q3->execute($tp);
        $dg['… y de las marcas de arriba (lo que muestra la tienda)'] = (int) $q3->fetchColumn();
    } catch (\Throwable $e) { $dg['Error al contar'] = 0; }
    ?>
    <p style="margin:0;color:var(--color-text-muted);font-size:.9em">Marcas guardadas: <b><?= e(appSetting('tienda_marcas', '*')) ?></b><?php foreach ($dg as $k => $v): ?><br><?= e($k) ?>: <b><?= (int) $v ?></b><?php endforeach; ?></p>
    <label>Frase bajo el nombre (opcional)
        <input class="v2-input" name="tagline" value="<?= e(appSetting('tienda_tagline', 'Medellín · Bogotá · Panamá')) ?>" maxlength="120" style="width:100%"></label>
    <label>Entrega (opcional, se muestra en el pedido)
        <input class="v2-input" name="delivery" value="<?= e(appSetting('tienda_delivery', '')) ?>" maxlength="200" style="width:100%" placeholder="Ej. Envíos a todo el país · Medellín mismo día"></label>
    <label>Reloj de la portada (código del producto; vacío = uno de JOEFOX/POEDAGAR con foto)
        <input class="v2-input" name="hero_sku" value="<?= e(appSetting('tienda_hero_sku', '')) ?>" maxlength="60" style="width:100%" placeholder="Ej. PD1234"></label>
    <fieldset style="border:1px solid var(--color-border);border-radius:12px;padding:1rem;display:grid;gap:.7rem">
        <legend><b>Banner de la portada</b></legend>
        <p style="margin:0;color:var(--color-text-muted)">Estado guardado: <b><?= $bnOn === '1' ? 'encendido' : 'apagado' ?></b> · imagen: <b><?= $bnImg ? 'sí (' . round(((int) $bnImg['n']) * 0.75 / 1024) . ' KB)' : 'no hay' ?></b> · <a href="/tienda?nc=<?= time() ?>" target="_blank" rel="noopener">ver /tienda</a></p>
        <?php if ($bnImg): ?><img src="/tienda/banner?v=<?= (int) $bnImg['t'] ?>" alt="Banner actual" style="max-width:100%;max-height:140px;border-radius:10px;object-fit:cover"><?php endif; ?>
        <label><input type="checkbox" name="banner_on" value="1" <?= appSetting('tienda_banner_on', '0') === '1' ? 'checked' : '' ?>> Mostrar el banner arriba de la tienda</label>
        <label>Imagen (JPG, PNG o WebP; horizontal, ideal 2000×900, fondo oscuro)
            <input class="v2-input" type="file" name="banner_img" accept="image/jpeg,image/png,image/webp" style="width:100%"></label>
        <label><input type="checkbox" name="banner_del" value="1"> Quitar la imagen actual</label>
        <label>Texto pequeño dorado
            <input class="v2-input" name="banner_kicker" value="<?= e(appSetting('tienda_banner_kicker', 'Nueva colección')) ?>" maxlength="40" style="width:100%"></label>
        <label>Titular
            <input class="v2-input" name="banner_title" value="<?= e(appSetting('tienda_banner_title', 'Siente el movimiento.')) ?>" maxlength="80" style="width:100%"></label>
        <label>Subtexto (opcional)
            <input class="v2-input" name="banner_text" value="<?= e(appSetting('tienda_banner_text', '')) ?>" maxlength="160" style="width:100%"></label>
        <label>Botón (opcional)
            <input class="v2-input" name="banner_btn" value="<?= e(appSetting('tienda_banner_btn', '')) ?>" maxlength="30" style="width:100%" placeholder="Ej. Ver colección"></label>
        <label>Enlace del botón (#tnCatalog = bajar al catálogo)
            <input class="v2-input" name="banner_link" value="<?= e(appSetting('tienda_banner_link', '#tnCatalog')) ?>" maxlength="200" style="width:100%"></label>
    </fieldset>
    <button class="btn btn-primary" type="submit" style="justify-self:start">Guardar</button>
</form>
<p class="v2-card" style="padding:1rem 1.25rem;max-width:640px;margin-top:1rem">Al terminar su pedido, el cliente toca <b>«Enviar pedido por WhatsApp»</b> y el mensaje (con el enlace del pedido y sus fotos) llega a este celular. Tú le respondes desde el detalle del pedido.</p>
<?php include __DIR__ . '/../../templates/admin/partials/v2_end.php';
