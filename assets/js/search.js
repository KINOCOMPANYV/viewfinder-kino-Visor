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
    // Guardar la URL de búsqueda actual en sessionStorage
    // para que el botón "Volver" del producto regrese a esta vista exacta
    try {
        sessionStorage.setItem('lastSearch', window.location.href);
        // Guardar el nombre del álbum actual si existe (para mostrarlo en el botón volver)
        const albumTitle = document.querySelector('.album-title, h1.page-title, [data-album-name]');
        if (albumTitle) {
            sessionStorage.setItem('lastAlbumName', albumTitle.textContent.trim().replace(/^📁\s*/, ''));
        } else {
            // Intentar leer del h1 si hay filtro de álbum
            const h1 = document.querySelector('h1');
            if (h1 && new URLSearchParams(window.location.search).get('album')) {
                sessionStorage.setItem('lastAlbumName', h1.textContent.replace(/^[^\w]+/, '').trim());
            }
        }
    } catch(e) {}

    const urlParams = new URLSearchParams(window.location.search);
    const albumFilter = urlParams.get('album');
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
            const albumParam = albumFilter ? '&album=' + encodeURIComponent(albumFilter) : '';
            
            const resultsContainer = document.getElementById('searchResultsContainer');
            if (resultsContainer) {
                resultsContainer.style.opacity = '0.5';
                fetch('/buscar?ajax=1&q=' + encodeURIComponent(q) + albumParam)
                    .then(r => r.text())
                    .then(html => {
                        resultsContainer.innerHTML = html;
                        resultsContainer.style.opacity = '1';
                        
                        // Hide dropdown
                        dropdown.classList.remove('active');
                        dropdown.innerHTML = '';
                        
                        // Evaluate any inline scripts returned
                        const scripts = resultsContainer.querySelectorAll('script');
                        scripts.forEach(oldScript => {
                            const newScript = document.createElement('script');
                            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                            oldScript.parentNode.replaceChild(newScript, oldScript);
                        });
                        
                        // Trigger global reinit if defined
                        if (typeof window.initGridEvents === 'function') {
                            window.initGridEvents();
                        } else {
                            // Re-trigger DOMContentLoaded so existing scripts bind to new elements safely
                            document.dispatchEvent(new Event('DOMContentLoaded'));
                        }
                    })
                    .catch(() => {
                        resultsContainer.style.opacity = '1';
                    });
            } else {
                // If container not found, do normal autocomplete dropdown
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

                        dropdown.querySelectorAll('.autocomplete-item').forEach(item => {
                            item.addEventListener('click', () => {
                                try { sessionStorage.setItem('lastSearch', window.location.href); } catch(e) {}
                                window.location.href = '/producto/' + item.dataset.sku;
                            });
                        });
                    })
                    .catch(() => dropdown.classList.remove('active'));
            }
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
                try { sessionStorage.setItem('lastSearch', window.location.href); } catch(e) {}
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
