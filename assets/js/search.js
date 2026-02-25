/**
 * VISOR KINO — Search Autocomplete + Multi-code support
 * - Autocomplete funciona solo para búsqueda de un solo código
 * - Si detecta comas o saltos de línea, desactiva autocomplete y hace submit
 * - Textarea auto-resize para pegar columnas de códigos
 */
(function () {
    const input = document.getElementById('searchInput');
    const dropdown = document.getElementById('autocomplete');
    const form = document.getElementById('searchForm');
    // Soporte para filtrar por álbum si viene en la URL
    const urlParams = new URLSearchParams(window.location.search);
    const albumFilter = urlParams.get('album');
    if (albumFilter) {
        // Lógica opcional para mostrar qué álbum se está filtrando
    }
    let debounceTimer;
    let activeIndex = -1;

    if (!input || !dropdown) return;

    // === Auto-resize textarea ===
    function autoResize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 192) + 'px'; // max ~12rem
        input.style.overflow = input.scrollHeight > 192 ? 'auto' : 'hidden';
    }

    // Detectar si hay múltiples códigos (comas o saltos de línea)
    function isMultiCode(text) {
        return text.includes(',') || text.includes('\n') || text.includes('\r');
    }

    // Auto-resize al cargar si ya tiene contenido
    if (input.value.trim()) {
        autoResize();
    }

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        autoResize();

        const q = this.value.trim();

        // Si es multi-código, NO mostrar autocomplete
        if (isMultiCode(q)) {
            dropdown.classList.remove('active');
            dropdown.innerHTML = '';
            return;
        }

        if (q.length < 2) {
            dropdown.classList.remove('active');
            dropdown.innerHTML = '';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch('/api/search?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (data.length === 0) {
                        dropdown.classList.remove('active');
                        return;
                    }

                    dropdown.innerHTML = data.map((p, i) => `
                        <div class="autocomplete-item" data-sku="${p.sku}" data-index="${i}">
                            <span class="sku-tag">${p.sku}</span>
                            <span class="product-name">${p.name}</span>
                        </div>
                    `).join('');

                    dropdown.classList.add('active');
                    activeIndex = -1;

                    // Click handlers
                    dropdown.querySelectorAll('.autocomplete-item').forEach(item => {
                        item.addEventListener('click', () => {
                            window.location.href = '/producto/' + item.dataset.sku;
                        });
                    });
                })
                .catch(() => dropdown.classList.remove('active'));
        }, 300);
    });

    // Keyboard navigation
    input.addEventListener('keydown', function (e) {
        const q = this.value.trim();

        // En multi-código: Enter hace submit del form
        if (e.key === 'Enter' && isMultiCode(q)) {
            e.preventDefault();
            form.submit();
            return;
        }

        // En single-code: Enter sin Shift hace submit o navega
        if (e.key === 'Enter' && !e.shiftKey && !isMultiCode(q)) {
            const items = dropdown.querySelectorAll('.autocomplete-item');
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                window.location.href = '/producto/' + items[activeIndex].dataset.sku;
                return;
            }
            // Si no hay item seleccionado, submit normal
            e.preventDefault();
            form.submit();
            return;
        }

        const items = dropdown.querySelectorAll('.autocomplete-item');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            highlightItem(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, -1);
            highlightItem(items);
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('active');
        }
    });

    // Manejar paste: auto-resize después del paste
    input.addEventListener('paste', function () {
        setTimeout(() => {
            autoResize();
            // Si se pegaron múltiples códigos, cerrar autocomplete
            if (isMultiCode(this.value)) {
                dropdown.classList.remove('active');
                dropdown.innerHTML = '';
            }
        }, 50);
    });

    function highlightItem(items) {
        items.forEach(item => item.classList.remove('active'));
        if (activeIndex >= 0 && items[activeIndex]) {
            items[activeIndex].classList.add('active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    // Close on outside click
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });
})();
