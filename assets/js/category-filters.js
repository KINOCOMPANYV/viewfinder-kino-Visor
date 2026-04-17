/**
 * category-filters.js — Filtros de categoría client-side para la página de búsqueda.
 * Filtra/muestra tarjetas .parent-card por [data-category] sin recarga de página.
 * Se activa solo cuando existe #catFilterBar en el DOM.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const bar = document.getElementById('catFilterBar');
        if (!bar) return; // No hay categorías suficientes — salir

        const chips = bar.querySelectorAll('.cat-chip');
        const grid  = document.getElementById('productGrid');
        if (!grid) return;

        let activeCategory = ''; // '' = todas

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                activeCategory = chip.dataset.cat || '';

                // Actualizar estado visual de chips
                chips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');

                // Filtrar tarjetas
                const cards = grid.querySelectorAll('.parent-card');
                let visibleCount = 0;

                cards.forEach(function (card) {
                    const cardCat = (card.dataset.category || '').toLowerCase();
                    const show = activeCategory === '' || cardCat === activeCategory;

                    if (show) {
                        card.style.display = '';
                        card.style.animation = 'fadeInCard 0.25s ease both';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Actualizar contador de resultados visible
                const counter = document.querySelector('.search-main > p[style]');
                if (counter && activeCategory !== '') {
                    const label = chip.textContent.trim();
                    bar.dataset.prevCounter = bar.dataset.prevCounter || counter.innerHTML;
                    counter.innerHTML = `<strong style="color:var(--color-primary)">${label}</strong>: ${visibleCount} producto${visibleCount !== 1 ? 's' : ''}`;
                } else if (counter && bar.dataset.prevCounter) {
                    counter.innerHTML = bar.dataset.prevCounter;
                    delete bar.dataset.prevCounter;
                }
            });
        });
    });

})();
