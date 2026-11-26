/* Contador del carrito «Hacer pedido» (localStorage kinoCarrito) sobre el botón del encabezado.
   Expone window.kinoCart para las páginas que agregan productos (ficha, Hacer pedido). */
(function () {
    const KEY = window.KINO_CART_KEY || 'kinoCarrito'; // el pedido del vendedor usa otro carrito para no mezclarse con el de un cliente
    const read = () => { try { const v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
    const write = (items) => { try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) { /* sin almacenamiento */ } paint(); window.dispatchEvent(new Event('kinoCart')); };
    function paint() {
        const el = document.getElementById('cartCount');
        if (!el) return;
        const n = read().reduce((a, i) => a + (i.qty || 0), 0);
        el.hidden = n < 1;
        el.textContent = n > 99 ? '99+' : String(n);
    }
    window.kinoCart = {
        read, write,
        add(sku, qty) {
            const items = read();
            const it = items.find(i => i.sku === sku);
            if (it) it.qty = Math.min(999, it.qty + (qty || 1)); else items.push({ sku, qty: Math.min(999, qty || 1) });
            write(items);
        }
    };
    paint();
    window.addEventListener('storage', paint);
})();
