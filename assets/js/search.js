/**
 * VISOR KINO — Search Autocomplete
 */
(function() {
    const input = document.getElementById('searchInput');
    const dropdown = document.getElementById('autocomplete');
    const form = document.getElementById('searchForm');
    let debounceTimer;
    let activeIndex = -1;

    if (!input || !dropdown) return;

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();

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
    input.addEventListener('keydown', function(e) {
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
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            window.location.href = '/producto/' + items[activeIndex].dataset.sku;
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('active');
        }
    });

    function highlightItem(items) {
        items.forEach(item => item.classList.remove('active'));
        if (activeIndex >= 0 && items[activeIndex]) {
            items[activeIndex].classList.add('active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });
})();
