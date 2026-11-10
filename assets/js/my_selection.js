/**
 * Mi selección (/mi-seleccion): dibuja lo marcado (window.KinoWaBatch, wa_batch.js) con su estado,
 * y deja subir/bajar, quitar, reintentar las que fallaron y repetir la última tanda.
 */
(function () {
    const W = window.KinoWaBatch;
    const list = document.getElementById('msList');
    if (!W || !list) return;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const LABEL = { ready: ['Lista', 'ok'], loading: ['Bajando…', 'wait'], sent: ['En una tanda', 'sent'], error: ['No bajó', 'bad'] };
    let lastHtml = '';

    function draw() {
        const items = W.items();
        const perCode = {};
        items.forEach((it) => { perCode[it.sku] = (perCode[it.sku] || 0) + 1; });
        $('msCount').textContent = items.length ? '(' + items.length + ')' : '';
        $('msTools').hidden = !items.length;
        $('msEmptyMsg').hidden = !!items.length;
        $('msOne').hidden = !items.some((it) => perCode[it.sku] > 1);
        const html = items.map((it, i) => {
            const [txt, cls] = LABEL[it.status] || ['', ''];
            return `<li class="ms-item${it.status === 'error' ? ' is-bad' : ''}" data-id="${esc(it.id)}">
                <span class="ms-n">${i + 1}</span>
                <img src="https://lh3.googleusercontent.com/d/${encodeURIComponent(it.id)}=s200" alt="" loading="lazy">
                <span class="ms-info"><strong>${esc(it.sku)}</strong>
                    <span class="ms-st ms-${cls}">${txt}</span>${perCode[it.sku] > 1 ? '<span class="ms-st ms-dup">' + perCode[it.sku] + ' fotos de este código</span>' : ''}</span>
                <span class="ms-act">
                    ${it.status === 'error' ? '<button type="button" data-a="retry" title="Reintentar">🔁</button>' : ''}
                    <button type="button" data-a="up" title="Subir" ${i === 0 ? 'disabled' : ''}>▲</button>
                    <button type="button" data-a="down" title="Bajar" ${i === items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button type="button" data-a="del" title="Quitar">✕</button>
                </span></li>`;
        }).join('');
        if (html !== lastHtml) { list.innerHTML = html; lastHtml = html; }

        const last = W.last();
        const box = $('msLast');
        box.hidden = !last;
        if (last) {
            const when = new Date(last.at).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            box.innerHTML = `<span>Última tanda preparada: <strong>${last.count} foto${last.count === 1 ? '' : 's'}</strong> · ${esc(when)}
                <small>Se abrió el menú Compartir; la página no puede saber si WhatsApp la entregó.</small></span>
                <button type="button" class="btn btn-sm btn-secondary" id="msResend">🔁 Repetir tanda</button>`;
        }
    }

    list.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-a]');
        if (!b) return;
        const id = b.closest('.ms-item').dataset.id;
        if (b.dataset.a === 'del') W.remove(id);
        else if (b.dataset.a === 'up') W.move(id, -1);
        else if (b.dataset.a === 'down') W.move(id, 1);
        else if (b.dataset.a === 'retry') W.retry(id);
    });
    $('msOne').addEventListener('click', () => W.onePerCode());
    $('msEmpty').addEventListener('click', () => { if (confirm('¿Quitar todas las fotos de la selección?')) W.clear(); });
    document.addEventListener('click', (e) => { if (e.target.id === 'msResend') W.resendLast(); });

    W.onChange(draw);
    draw();
})();
