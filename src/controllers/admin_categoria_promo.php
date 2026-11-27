<?php
/** POST /admin/categorias/promo (header X-CSRF-TOKEN; category, promo=1|0): marca o quita «Promocional» de una categoría (sale primero en /tienda). */
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) jsonResponse(['ok' => false, 'error' => 'Sesión vencida: recarga la página.'], 403);
$cat = mb_substr(trim((string) ($_POST['category'] ?? '')), 0, 100, 'UTF-8');
if ($cat === '') jsonResponse(['ok' => false, 'error' => 'Falta la categoría.'], 422);
$promo = ($_POST['promo'] ?? '') === '1' ? 1 : 0;
getDB()->prepare('INSERT INTO category_settings (category, is_promo) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_promo = VALUES(is_promo)')->execute([$cat, $promo]);
@unlink(BASE_DIR . '/storage/cache/filter_options.json');
jsonResponse(['ok' => true, 'category' => $cat, 'promo' => $promo]);
