<?php
/** Admin → 🛍️ Tienda (/admin/tienda): celular de avisos, datos de transferencia, textos del catálogo de venta público (/tienda). */
$flash = $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $phone = preg_replace('/\D+/', '', (string) ($_POST['notify'] ?? '')) ?: '';
        if (strlen($phone) < 10) throw new RuntimeException('Escribe un celular válido (10 dígitos).');
        $db = getDB();
        setAppSetting($db, 'tienda_notify_phone', $phone);
        setAppSetting($db, 'tienda_bank_info', mb_substr(trim((string) ($_POST['bank'] ?? '')), 0, 800, 'UTF-8'));
        setAppSetting($db, 'tienda_tagline', mb_substr(trim((string) ($_POST['tagline'] ?? '')), 0, 120, 'UTF-8'));
        setAppSetting($db, 'tienda_delivery', mb_substr(trim((string) ($_POST['delivery'] ?? '')), 0, 200, 'UTF-8'));
        $flash = 'Guardado.';
    } catch (\Throwable $e) { $error = $e->getMessage(); }
}
$adminNav = 'tienda';
$v2 = ['nav' => 'gestion', 'sub' => 'tienda', 'title' => 'Tienda', 'subtitle' => 'Catálogo de venta público: avisos y datos de pago'];
include __DIR__ . '/../../templates/admin/partials/v2_start.php';
?>
<form method="post" class="v2-card" style="padding:1.25rem;max-width:640px;display:grid;gap:.9rem">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <?php if ($flash): ?><p style="color:var(--color-success)"><?= e($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p style="color:var(--color-danger)"><?= e($error) ?></p><?php endif; ?>
    <p>Tu tienda pública está en <a href="/tienda" target="_blank" rel="noopener"><b>/tienda</b></a>. Solo muestra los productos con el interruptor «Catálogo» encendido en Productos.</p>
    <label>Celular que recibe el aviso de cada pedido
        <input class="v2-input" name="notify" value="<?= e(substr(shopNotifyPhone(), 2)) ?>" inputmode="tel" maxlength="20" style="width:100%"></label>
    <label>Datos para transferencia (banco, cuenta, titular, Nequi…)
        <textarea class="v2-input" name="bank" rows="4" maxlength="800" style="width:100%"><?= e(shopBankInfo()) ?></textarea></label>
    <label>Frase bajo el nombre (opcional)
        <input class="v2-input" name="tagline" value="<?= e(appSetting('tienda_tagline', 'Medellín · Bogotá · Panamá')) ?>" maxlength="120" style="width:100%"></label>
    <label>Entrega (opcional, se muestra en el pedido)
        <input class="v2-input" name="delivery" value="<?= e(appSetting('tienda_delivery', '')) ?>" maxlength="200" style="width:100%" placeholder="Ej. Envíos a todo el país · Medellín mismo día"></label>
    <button class="btn btn-primary" type="submit" style="justify-self:start">Guardar</button>
</form>
<p class="v2-card" style="padding:1rem 1.25rem;max-width:640px;margin-top:1rem">Al terminar su pedido, el cliente toca <b>«Enviar pedido por WhatsApp»</b> y el mensaje (con el enlace del pedido y sus fotos) llega a este celular. Tú le respondes desde el detalle del pedido.</p>
<?php include __DIR__ . '/../../templates/admin/partials/v2_end.php';
