/**
 * Live stock lookup on the sales order form (API-01 + Fetch API).
 *
 * Why an API endpoint exists in a server-rendered app: the seller needs to know
 * the balance of a product they have only just chosen. Reloading the whole page
 * to answer that would be wasteful, and holding every product's stock in the
 * initial HTML would not stay accurate. So one JSON endpoint answers exactly
 * that question, on demand.
 *
 * This is a display aid only. The authoritative stock check happens inside the
 * locked transaction at goods issue, so a stale or spoofed reading here cannot
 * cause an oversell.
 */
(function () {
    'use strict';

    var table = document.getElementById('order-lines');
    if (!table) {
        return;
    }

    // Runs only where the product options actually carry a SKU. Feature
    // detection rather than a page flag: the purchase order form uses the same
    // line-item markup but has no availability lookup, and an inline <script>
    // adding a marker class was the only inline script left in the project.
    if (!table.querySelector('option[data-sku]')) {
        return;
    }

    var cache = new Map();

    function fetchAvailability(sku) {
        if (cache.has(sku)) {
            return Promise.resolve(cache.get(sku));
        }

        return fetch('/api/products/' + encodeURIComponent(sku) + '/availability', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (response.status === 401) {
                    throw new Error('Your session has expired. Reload the page and sign in again.');
                }
                if (response.status === 404) {
                    throw new Error('That product no longer exists.');
                }
                if (!response.ok) {
                    throw new Error('Could not read stock right now.');
                }
                return response.json();
            })
            .then(function (data) {
                cache.set(sku, data);
                return data;
            });
    }

    function describe(data) {
        if (!data.warehouses.length) {
            return 'No stock rows for this product yet.';
        }

        var parts = data.warehouses.map(function (w) {
            return w.warehouse + ': ' + w.available;
        });

        return 'Available — total ' + data.total_available + ' (' + parts.join(', ') + ')'
            + (data.is_low_stock ? ' — at or below reorder point' : '');
    }

    function attach(row) {
        var select = row.querySelector('select');
        if (!select || select.dataset.availabilityWired === '1') {
            return;
        }
        select.dataset.availabilityWired = '1';

        var note = document.createElement('p');
        note.className = 'field__hint availability';
        // Announced to assistive technology when it changes, not just visually.
        note.setAttribute('role', 'status');
        select.parentNode.appendChild(note);

        select.addEventListener('change', function () {
            var option = select.options[select.selectedIndex];
            var sku = option && option.dataset ? option.dataset.sku : null;

            if (!sku) {
                note.textContent = '';
                return;
            }

            note.textContent = 'Checking stock…';
            fetchAvailability(sku)
                .then(function (data) { note.textContent = describe(data); })
                .catch(function (error) { note.textContent = error.message; });
        });
    }

    table.querySelectorAll('.line-row').forEach(attach);

    // Rows added by order-lines.js need wiring too.
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1 && node.classList.contains('line-row')) {
                    var stale = node.querySelector('.availability');
                    if (stale) {
                        stale.remove();
                    }
                    node.querySelector('select').dataset.availabilityWired = '';
                    attach(node);
                }
            });
        });
    }).observe(table.querySelector('tbody'), { childList: true });
}());
