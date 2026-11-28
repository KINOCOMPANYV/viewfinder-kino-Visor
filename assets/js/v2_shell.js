/* Navegación responsive compartida: teclado, foco y estado accesible del menú. */
(() => {
    const side = document.getElementById('v2Side');
    if (!side) return;
    const toggles = [...document.querySelectorAll('[data-v2-menu-toggle]')];
    const scrim = document.querySelector('.v2-scrim');
    const main = document.querySelector('.v2-main');
    const mobile = window.matchMedia('(max-width: 900px)');
    let previousFocus = null;
    const isOpen = () => mobile.matches && document.body.classList.contains('v2-open');
    const focusable = () => [...side.querySelectorAll('a[href],button:not([disabled]),summary')].filter((el) => el.getClientRects().length);
    function sync() {
        const open = isOpen();
        toggles.forEach((el) => {
            el.setAttribute('aria-expanded', String(open));
            el.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
        });
        side.inert = mobile.matches && !open;
        if (mobile.matches && !open) side.setAttribute('aria-hidden', 'true');
        else side.removeAttribute('aria-hidden');
        if (main) main.inert = open;
    }
    function close() {
        document.body.classList.remove('v2-open'); sync();
        if (previousFocus?.isConnected) previousFocus.focus();
    }
    toggles.forEach((el) => el.addEventListener('click', () => {
        if (isOpen()) return close();
        previousFocus = document.activeElement;
        document.body.classList.add('v2-open'); sync();
        focusable()[0]?.focus();
    }));
    scrim?.addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (!isOpen()) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        if (event.key !== 'Tab') return;
        const items = focusable(), first = items[0], last = items[items.length - 1];
        if (!first) return;
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    mobile.addEventListener('change', () => { document.body.classList.remove('v2-open'); sync(); });
    sync();
})();
