/**
 * Shared client-side form validation (VAL-01).
 *
 * VAL-01 asks for validation "di frontend dan backend; backend adalah sumber
 * kebenaran". This file is the frontend half. It is deliberately built so that
 * the two halves cannot drift apart:
 *
 *  1. THE RULES ARE NOT WRITTEN HERE. They are read from the constraint
 *     attributes already present in the markup -- required, type="email",
 *     min, max, step, minlength, maxlength, accept. Those same constraints are
 *     what app/Support/Validator.php enforces server-side. Adding a rule in one
 *     place therefore means editing the markup, which both halves read.
 *
 *  2. THE WORDING IS COPIED FROM THE SERVER. Validator::label() derives a label
 *     from the field name and prefixes messages like "Purchase price must be at
 *     least 0." labelFor() below reproduces that exactly, so a user sees the
 *     same sentence whether this script ran, was blocked, or was never loaded.
 *
 *  3. IT DECIDES NOTHING. The only thing it can do is cancel a submit. Anything
 *     that reaches the server -- curl, a disabled-JS browser, a tampered page --
 *     is validated again by Validator and rejected there. Removing this file
 *     changes the user experience and no security property.
 *
 * Opt-in is by `novalidate` on the form. That attribute already means "this
 * application validates the form, not the browser", so it needs no separate
 * marker: feature detection rather than a flag. Search and filter forms (GET)
 * carry no novalidate and are left alone -- a blank filter is a valid filter.
 */
(function () {
    'use strict';

    var INVALID_CLASS = 'field--invalid';
    var ERROR_CLASS = 'field__error';
    var ERROR_SOURCE = 'client';

    /**
     * Reproduces Validator::label(): the field name with underscores replaced
     * by spaces and the first letter capitalised. Array inputs such as
     * items[quantity][] reduce to their innermost segment, "Quantity".
     */
    function labelFor(field) {
        var name = field.getAttribute('name') || '';
        var segments = name.match(/\[([^\]]+)\]/g);
        if (segments) {
            var last = segments[segments.length - 1].slice(1, -1);
            name = last === '' && segments.length > 1
                ? segments[segments.length - 2].slice(1, -1)
                : last;
        }
        name = name.replace(/_/g, ' ');
        return name.charAt(0).toUpperCase() + name.slice(1);
    }

    /** Bytes, for the file-size rule. Mirrors the upload limit in the markup. */
    function maxBytes(field) {
        var declared = Number(field.dataset.maxBytes);
        return declared > 0 ? declared : 0;
    }

    /**
     * Returns an error message, or null when the field is acceptable.
     *
     * Order matters: "required" is checked first and blank-but-optional exits
     * early, exactly as Validator::passes() does. Without that, an empty
     * optional number field would be reported as "must be a number".
     */
    function checkField(field) {
        var label = labelFor(field);
        var value = field.type === 'file' ? '' : String(field.value).trim();

        if (field.type === 'file') {
            return checkFile(field, label);
        }

        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.required && !field.checked ? label + ' is required.' : null;
        }

        if (value === '') {
            return field.required ? label + ' is required.' : null;
        }

        if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            return label + ' must be a valid email address.';
        }

        if (field.type === 'number') {
            var message = checkNumber(field, label, value);
            if (message !== null) {
                return message;
            }
        }

        if (field.type === 'date') {
            var dateMessage = checkDate(field, label, value);
            if (dateMessage !== null) {
                return dateMessage;
            }
        }

        var minLength = Number(field.getAttribute('minlength'));
        if (minLength > 0 && value.length < minLength) {
            return label + ' must be at least ' + minLength + ' characters.';
        }

        var maxLength = Number(field.getAttribute('maxlength'));
        if (maxLength > 0 && value.length > maxLength) {
            return label + ' must be at most ' + maxLength + ' characters.';
        }

        if (field.tagName === 'SELECT' && field.required && value === '') {
            return label + ' is required.';
        }

        return null;
    }

    function checkNumber(field, label, value) {
        // Number('') is 0 and Number('12abc') is NaN, so the blank case must
        // already have been handled by the caller for this test to be correct.
        var number = Number(value);
        if (!isFinite(number)) {
            return label + ' must be a number.';
        }

        var step = field.getAttribute('step');
        // No step, or step="1", means whole numbers -- the same distinction the
        // server draws between the `int` and `decimal` rules.
        if ((step === null || step === '1') && !Number.isInteger(number)) {
            return label + ' must be a whole number.';
        }

        var min = field.getAttribute('min');
        if (min !== null && number < Number(min)) {
            return label + ' must be at least ' + min + '.';
        }

        var max = field.getAttribute('max');
        if (max !== null && number > Number(max)) {
            return label + ' must be at most ' + max + '.';
        }

        return null;
    }

    function checkDate(field, label, value) {
        // Date.parse accepts a lot; the input is type="date" so the browser
        // already constrains the shape. This catches a pasted or typed value in
        // browsers that fall back to a text field.
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || isNaN(Date.parse(value))) {
            return label + ' must be a valid date.';
        }

        var max = field.getAttribute('max');
        if (max && value > max) {
            // String comparison is safe and exact for ISO-8601 dates.
            return label + ' cannot be later than ' + max + '.';
        }

        var min = field.getAttribute('min');
        if (min && value < min) {
            return label + ' cannot be earlier than ' + min + '.';
        }

        return null;
    }

    /**
     * Mirrors ImageUploader: the type is checked against the accept list and
     * the size against data-max-bytes. The server re-detects the real MIME type
     * with finfo rather than trusting file.type, which a client can lie about --
     * one more reason this check is a courtesy, not a gate.
     */
    function checkFile(field, label) {
        if (field.files.length === 0) {
            return field.required ? label + ' is required.' : null;
        }

        var file = field.files[0];
        var accept = (field.getAttribute('accept') || '').split(',')
            .map(function (type) { return type.trim(); })
            .filter(Boolean);

        // Wording taken from ImageUploader so the sentence is identical whether
        // the file was stopped here or rejected after upload.
        if (accept.length > 0 && accept.indexOf(file.type) === -1) {
            return 'The image must be a JPEG, PNG or WebP file.';
        }

        var limit = maxBytes(field);
        if (limit > 0 && file.size > limit) {
            return 'The image must be ' + Math.floor(limit / 1024) + ' KB or smaller.';
        }

        return null;
    }

    /**
     * The element an error paragraph belongs to. Most fields sit in a .field
     * wrapper; order lines sit in a table cell instead. Falling back to the
     * parent keeps the script working on markup it has not seen.
     */
    function containerFor(field) {
        return field.closest('.field') || field.closest('td') || field.parentElement;
    }

    function clearError(field) {
        var container = containerFor(field);
        if (!container) {
            return;
        }
        container.classList.remove(INVALID_CLASS);
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');

        // Only messages this script produced are removed. Errors rendered by
        // the server on a failed POST are kept: they report things the browser
        // cannot know, such as a duplicate SKU, and clearing them would hide
        // the reason the form came back.
        var existing = container.querySelector('.' + ERROR_CLASS + '[data-source="' + ERROR_SOURCE + '"]');
        if (existing) {
            existing.remove();
        }
    }

    function showError(field, message) {
        clearError(field);
        var container = containerFor(field);
        if (!container) {
            return;
        }

        var id = field.id || field.name.replace(/\W+/g, '-') + '-error';
        var paragraph = document.createElement('p');
        paragraph.className = ERROR_CLASS;
        paragraph.dataset.source = ERROR_SOURCE;
        paragraph.id = id + '-client-error';
        paragraph.textContent = message;

        container.classList.add(INVALID_CLASS);
        container.appendChild(paragraph);

        // Announced by screen readers when focus lands on the field (UI-01).
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', paragraph.id);
    }

    function validateField(field) {
        var message = checkField(field);
        if (message === null) {
            clearError(field);
            return true;
        }
        showError(field, message);
        return false;
    }

    /** Hidden fields and the CSRF token are never user input. */
    function fieldsOf(form) {
        return Array.prototype.filter.call(
            form.querySelectorAll('input, select, textarea'),
            function (field) {
                return field.type !== 'hidden'
                    && field.type !== 'submit'
                    && field.type !== 'button'
                    && !field.disabled;
            },
        );
    }

    function wire(form) {
        // Re-queried on every submit rather than captured once: order lines are
        // added to the DOM after this runs (order-lines.js), and a row that was
        // cloned in must be validated like any other.
        form.addEventListener('submit', function (event) {
            var firstInvalid = null;

            fieldsOf(form).forEach(function (field) {
                if (!validateField(field) && firstInvalid === null) {
                    firstInvalid = field;
                }
            });

            if (firstInvalid !== null) {
                event.preventDefault();
                firstInvalid.focus();
                // The field may be below the fold on a long order form.
                firstInvalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });

        // Validating on blur would scold someone the moment they tab out of a
        // field they have not finished with. Instead a field is only checked
        // live once it has already failed -- then the message clears as soon as
        // the input becomes acceptable, which is the feedback that actually
        // helps.
        form.addEventListener('input', revalidateIfShowing, true);
        form.addEventListener('change', revalidateIfShowing, true);
    }

    function revalidateIfShowing(event) {
        var field = event.target;
        if (!field.hasAttribute('aria-invalid')) {
            return;
        }
        validateField(field);
    }

    document.querySelectorAll('form[novalidate]').forEach(wire);
}());
