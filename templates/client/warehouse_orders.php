<?php
/**
 * Pedidos a alistar (/pedidos-alistar): lista de los pedidos enviados desde Lista Bodega (tabla warehouse_orders).
 * Rojo = falta alistar (abre el checklist /pedido-bodega?id=N); verde = listo (abre para verificar y descargar el PDF).
 * Datos por GET /pedidos-alistar/api. ?nuevo=N resalta el recién enviado.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pedidos a alistar · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .wo-tabs { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; }
        .wo-tabs button { min-height:40px; padding:0 1rem; border-radius:99px; border:1px solid #444; background:#15151c; color:#e8e8f0; font-weight:700; cursor:pointer; }
        .wo-tabs button.on { background:#c9a84c; color:#111; border-color:#c9a84c; }
        .wo-item { display:flex; align-items:center; gap:0.8rem; padding:0.9rem 1rem; margin-bottom:0.6rem; border-radius:12px; text-decoration:none; color:inherit;
            border:1px solid; border-left-width:6px; }
        .wo-item.red { border-color:#e0505a88; border-left-color:#e0505a; background:#e0505a14; }
        .wo-item.green { border-color:#3fb95088; border-left-color:#3fb950; background:#3fb95012; }
        .wo-item.new { box-shadow:0 0 0 2px #c9a84c; }
        .wo-dot { font-size:1.4rem; }
        .wo-main { flex:1; min-width:0; }
        .wo-title { font-weight:800; word-break:break-word; }
        .wo-sub { color:#a0a0b0; font-size:0.85rem; }
        .wo-act { font-weight:800; white-space:nowrap; }
        .wo-item.red .wo-act { color:#ff8a92; } .wo-item.green .wo-act { color:#6fdc84; }
        .wo-empty { background:#14141c; border:1px solid #2a2a35; border-radius:12px; padding:1.1rem; color:#c8c8d0; }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/extractor" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Extractor</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:6rem; max-width:900px;">
        <?= breadcrumbs([['Extractor', '/extractor'], ['Pedidos a alistar', null]]) ?>
        <h1 style="font-size:1.5rem; margin-bottom:0.4rem;">📋 Pedidos a alistar</h1>
        <p class="wo-sub" style="margin-bottom:1rem;">🔴 Falta alistar: tócalo para chequearlo. 🟢 Listo: tócalo para verificar y descargar el PDF.</p>
        <p id="woNote" style="color:#e6c76a; min-height:1rem; margin-bottom:0.6rem;"></p>
        <div class="wo-tabs" id="woTabs"></div>
        <div id="woList"><div class="wo-empty">Cargando…</div></div>
    </main>

    <script>
    (function () {
        const $ = (id) => document.getElementById(id);
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const when = (t) => t ? new Date(t * 1000).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : '';
        const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');
        const qs = new URLSearchParams(location.search), isNew = Number(qs.get('nuevo')) || 0;
        let orders = [], tab = 'pendiente';
        function draw() {
            const pend = orders.filter((o) => o.status !== 'listo'), done = orders.filter((o) => o.status === 'listo');
            $('woTabs').innerHTML = [['pendiente', '🔴 Por alistar (' + pend.length + ')'], ['listo', '🟢 Listos (' + done.length + ')'], ['todos', 'Todos (' + orders.length + ')']]
                .map((t) => '<button type="button" data-t="' + t[0] + '" class="' + (tab === t[0] ? 'on' : '') + '">' + t[1] + '</button>').join('');
            const shown = tab === 'todos' ? orders : tab === 'listo' ? done : pend;
            $('woList').innerHTML = shown.map((o) => {
                const ok = o.status === 'listo';
                const title = (o.number ? 'Pedido ' + o.number : 'Pedido sin número') + (o.client ? ' · ' + o.client : '');
                const sub = ok
                    ? '✅ Listo ' + esc(when(o.finished)) + (o.who ? ' · chequeó ' + esc(o.who) : '') + ' · ' + o.items + ' ítems' + (o.total != null ? ' · ' + money(o.total) : '')
                    : o.checked + ' de ' + o.items + ' chequeados' + (o.date ? ' · fecha ' + esc(o.date) : '') + ' · enviado ' + esc(when(o.created));
                return '<a class="wo-item ' + (ok ? 'green' : 'red') + (o.id === isNew ? ' new' : '') + '" href="/pedido-bodega?id=' + o.id + '">' +
                    '<span class="wo-dot">' + (ok ? '🟢' : '🔴') + '</span><span class="wo-main"><span class="wo-title">' + esc(title) + '</span><br><span class="wo-sub">' + sub + '</span></span>' +
                    '<span class="wo-act">' + (ok ? 'Verificar ›' : 'Alistar ›') + '</span></a>';
            }).join('') || '<div class="wo-empty">' + (tab === 'listo' ? 'Todavía no hay pedidos listos.' : tab === 'pendiente' ? '✅ No hay pedidos por alistar.' : 'No hay pedidos.') +
                ' Para enviar uno: Extractor → Lista Bodega → «📦 Enviar a pedidos a alistar».</div>';
        }
        $('woTabs').addEventListener('click', (e) => { const b = e.target.closest('[data-t]'); if (b) { tab = b.dataset.t; draw(); } });
        fetch('/pedidos-alistar/api', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json().then((j) => { if (!r.ok) throw new Error(j.error || 'error ' + r.status); return j; }))
            .then((j) => { orders = j.orders || [];
                const n = orders.find((o) => o.id === isNew); if (n && n.status === 'listo') tab = 'listo';
                draw();
                if (isNew) $('woNote').textContent = qs.get('ya') ? 'ℹ️ Ese pedido ya estaba en la lista (resaltado); no se duplicó.' : '✅ Pedido enviado. Queda en rojo hasta que lo alisten.'; })
            .catch((e) => { $('woList').innerHTML = '<div class="wo-empty">⚠️ No se pudo cargar la lista (' + esc(e.message) + '). Recarga la página.</div>'; });
    })();
    </script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
