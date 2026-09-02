/**
 * Client-side convenience only. The server re-validates everything it receives
 * (VAL-01: backend is the source of truth) -- this exists to give feedback
 * before a round trip, never to decide whether the data is acceptable.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[action="/login"]');
    if (!form) {
        return;
    }

    form.addEventListener('submit', function (event) {
        var email = form.querySelector('#email');
        var password = form.querySelector('#password');

        if (!email.value.trim() || !password.value) {
            event.preventDefault();
            var target = !email.value.trim() ? email : password;
            target.focus();
        }
    });
}());
