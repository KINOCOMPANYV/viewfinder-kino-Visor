<?php
/** Admin → 🛍️ Tienda (/admin/tienda): celular de avisos, datos de transferencia, textos del catálogo de venta público (/tienda). */
$flash = $error = null;
/** Guarda el formulario «Galería»: título, encendido, textos/enlaces, orden (↑↓), quitar y subir fotos nuevas. */
function tiendaGallerySave(PDO $db): string
{
    setAppSetting($db, 'tienda_gal_on', !empty($_POST['gal_on']) ? '1' : '0');
    setAppSetting($db, 'tienda_gal_title', mb_substr(trim((string) ($_POST['gal_title'] ?? '')), 0, 60, 'UTF-8'));
    $ids = array_map('intval', $db->query('SELECT id FROM tienda_gallery ORDER BY pos, id')->fetchAll(PDO::FETCH_COLUMN));
    $items = is_array($_POST['gal'] ?? null) ? $_POST['gal'] : [];
    $up = $db->prepare('UPDATE tienda_gallery SET caption = ?, link = ? WHERE id = ? AND (caption <> ? OR link <> ?)');
    $del = $db->prepare('DELETE FROM tienda_gallery WHERE id = ?');
    $n = 0;
    foreach ($ids as $k => $id) {
        $it = is_array($items[$id] ?? null) ? $items[$id] : null;
        if (!$it) continue;
        if (!empty($it['del'])) { $del->execute([$id]); unset($ids[$k]); $n++; continue; }
        $cap = mb_substr(trim((string) ($it['caption'] ?? '')), 0, 80, 'UTF-8');
        $lnk = tiendaSafeLink(mb_substr((string) ($it['link'] ?? ''), 0, 200, 'UTF-8'));
        $up->execute([$cap, $lnk, $id, $cap, $lnk]);
    }
    $ids = array_values($ids);
    if (preg_match('/^(\d+):(up|down)$/', (string) ($_POST['gal_move'] ?? ''), $m)) {
        $i = array_search((int) $m[1], $ids, true);
        $j = $i === false ? false : ($m[2] === 'up' ? $i - 1 : $i + 1);
        if ($j !== false && isset($ids[$j])) [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    }
    $files = $_FILES['gal_img'] ?? null;
    $added = 0;
    if ($files && is_array($files['name'] ?? null)) {
        $ins = $db->prepare('INSERT INTO tienda_gallery (pos, mime, width, height, data) VALUES (0, ?, ?, ?, ?)');
        foreach ($files['name'] as $k => $_) {
            if (($files['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (count($ids) >= 24) throw new RuntimeException('La galería admite hasta 24 fotos.');
            if ($files['error'][$k] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$k]) || $files['size'][$k] > 12 * 1048576) throw new RuntimeException('No se pudo subir «' . $files['name'][$k] . '» (máximo 12 MB).');
            [$bin, $mime, $w, $h] = optimizeWebImage($files['tmp_name'][$k], 1000);
            $ins->execute([$mime, $w, $h, $bin]);
            $ids[] = (int) $db->lastInsertId();
            $added++;
        }
    }
    $pos = $db->prepare('UPDATE tienda_gallery SET pos = ? WHERE id = ?');
    foreach ($ids as $i => $id) $pos->execute([($i + 1) * 10, $id]);
    return 'Galería guardada.' . ($added ? " $added foto(s) nueva(s)." : '') . ($n ? " $n quitada(s)." : '');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        if (($_POST['form'] ?? '') === 'gal') { $flash = tiendaGallerySave(getDB()); } else {
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
        }
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
    <?php $galPost = ($_POST['form'] ?? '') === 'gal'; if (!$galPost && $flash): ?><p style="color:var(--color-success)"><?= e($flash) ?></p><?php endif; ?>
    <?php if (!$galPost && $error): ?><p style="color:var(--color-danger)"><?= e($error) ?></p><?php endif; ?>
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
<?php $gal = tiendaGallery(); ?>
<form method="post" action="#galeria" enctype="multipart/form-data" class="v2-card" id="galeria" style="padding:1.25rem;max-width:640px;display:grid;gap:.9rem;margin-top:1rem">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="form" value="gal">
    <h3 style="margin:0">Galería de fotos</h3>
    <?php if ($galPost && $flash): ?><p style="margin:0;color:var(--color-success)"><?= e($flash) ?></p><?php endif; ?>
    <?php if ($galPost && $error): ?><p style="margin:0;color:var(--color-danger)"><?= e($error) ?></p><?php endif; ?>
    <p style="margin:0;color:var(--color-text-muted)">Franja de fotos que se desliza de lado en <a href="/tienda#tnGal" target="_blank" rel="noopener">/tienda</a>, arriba de «Por categoría». Las fotos se optimizan solas al subirlas (máx. 1000 px, WebP). Ideal vertical 4:5.</p>
    <label><input type="checkbox" name="gal_on" value="1" <?= appSetting('tienda_gal_on', '1') === '1' ? 'checked' : '' ?>> Mostrar la galería en la tienda</label>
    <label>Título (opcional)
        <input class="v2-input" name="gal_title" value="<?= e(appSetting('tienda_gal_title', 'POEDAGAR verde')) ?>" maxlength="60" style="width:100%"></label>
    <?php if (!$gal): ?><p style="margin:0;color:var(--color-text-muted)">Aún no hay fotos.</p><?php endif; ?>
    <ol style="list-style:none;margin:0;padding:0;display:grid;gap:.75rem">
    <?php foreach ($gal as $i => $g): $id = $g['id']; ?>
        <li style="display:grid;grid-template-columns:72px 1fr auto;gap:.75rem;align-items:center;padding:.6rem;border:1px solid var(--color-border);border-radius:12px">
            <img src="<?= e($g['src']) ?>" alt="" width="72" height="90" loading="lazy" style="width:72px;height:90px;object-fit:cover;border-radius:8px">
            <div style="display:grid;gap:.4rem;min-width:0">
                <input class="v2-input" name="gal[<?= $id ?>][caption]" value="<?= e($g['caption']) ?>" maxlength="80" placeholder="Texto bajo la foto (opcional)" aria-label="Texto de la foto <?= $i + 1 ?>">
                <input class="v2-input" name="gal[<?= $id ?>][link]" value="<?= e($g['link']) ?>" maxlength="200" placeholder="Enlace (opcional). Ej. /tienda?q=PD1234" aria-label="Enlace de la foto <?= $i + 1 ?>">
                <label style="font-size:.88em;color:var(--color-text-muted)"><input type="checkbox" name="gal[<?= $id ?>][del]" value="1"> Quitar esta foto</label>
            </div>
            <div style="display:grid;gap:.3rem">
                <button class="btn btn-sm" type="submit" name="gal_move" value="<?= $id ?>:up" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Subir foto <?= $i + 1 ?>">↑</button>
                <button class="btn btn-sm" type="submit" name="gal_move" value="<?= $id ?>:down" <?= $i === count($gal) - 1 ? 'disabled' : '' ?> aria-label="Bajar foto <?= $i + 1 ?>">↓</button>
            </div>
        </li>
    <?php endforeach; ?>
    </ol>
    <label>Agregar fotos (puedes elegir varias; JPG, PNG o WebP, máx. 12 MB c/u)
        <input class="v2-input" type="file" name="gal_img[]" accept="image/jpeg,image/png,image/webp" multiple style="width:100%"></label>
    <button class="btn btn-primary" type="submit" style="justify-self:start">Guardar galería</button>
</form>
<p class="v2-card" style="padding:1rem 1.25rem;max-width:640px;margin-top:1rem">Al terminar su pedido, el cliente toca <b>«Enviar pedido por WhatsApp»</b> y el mensaje (con el enlace del pedido y sus fotos) llega a este celular. Tú le respondes desde el detalle del pedido.</p>
<?php include __DIR__ . '/../../templates/admin/partials/v2_end.php';
