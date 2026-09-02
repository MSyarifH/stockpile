/**
 * Dynamic order lines. Vanilla JS, no framework (§4).
 *
 * This is a convenience for entering an order; it decides nothing. The server
 * re-reads every line from the POST body and validates it independently, so a
 * request that bypasses this script entirely is handled identically (VAL-01).
 */
(function () {
    'use strict';

    var table = document.getElementById('order-lines');
    var addButton = document.getElementById('add-line');
    if (!table || !addButton) {
        return;
    }

    var body = table.querySelector('tbody');

    function rowCount() {
        return body.querySelectorAll('.line-row').length;
    }

    /** Keeps at least one row on screen so the form is never empty. */
    function refreshRemoveButtons() {
        var only = rowCount() === 1;
        body.querySelectorAll('.line-remove').forEach(function (button) {
            button.disabled = only;
        });
    }

    /** Prefills the unit price from the chosen product, still editable. */
    function wirePriceSuggestion(row) {
        var select = row.querySelector('select');
        var price = row.querySelector('input[name="items[purchase_price][]"]');
        if (!select || !price) {
            return;
        }

        select.addEventListener('change', function () {
            var option = select.options[select.selectedIndex];
            var suggested = option && option.dataset ? option.dataset.price : null;
            if (suggested && Number(price.value) === 0) {
                price.value = suggested;
            }
        });
    }

    addButton.addEventListener('click', function () {
        var template = body.querySelector('.line-row');
        var row = template.cloneNode(true);

        row.querySelectorAll('input').forEach(function (input) {
            input.value = input.type === 'number' && input.min === '1' ? '1' : '0';
        });
        row.querySelector('select').selectedIndex = 0;

        body.appendChild(row);
        wirePriceSuggestion(row);
        refreshRemoveButtons();
        row.querySelector('select').focus();
    });

    body.addEventListener('click', function (event) {
        if (!event.target.classList.contains('line-remove')) {
            return;
        }
        if (rowCount() > 1) {
            event.target.closest('.line-row').remove();
            refreshRemoveButtons();
        }
    });

    body.querySelectorAll('.line-row').forEach(wirePriceSuggestion);
    refreshRemoveButtons();
}());
