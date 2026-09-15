/**
 * Polish for the "Master data" menu in the top bar.
 *
 * The menu is a plain <details>/<summary>, so it already opens on click and on
 * Enter, closes on Escape, and reports its expanded state to assistive
 * technology — all without JavaScript. What <details> does NOT do is close when
 * attention moves elsewhere, which leaves an open panel covering the page after
 * the user has moved on.
 *
 * That is the whole job of this file. With it removed the menu still opens,
 * still closes on a second click, and every link inside it still works; it just
 * lingers. Nothing here is required for the navigation to function.
 */
(function () {
    'use strict';

    var menus = document.querySelectorAll('.menu');
    if (menus.length === 0) {
        return;
    }

    function close(menu) {
        menu.open = false;
    }

    // A click anywhere outside an open menu dismisses it, which is what every
    // other dropdown on the web does and therefore what a user expects.
    document.addEventListener('click', function (event) {
        menus.forEach(function (menu) {
            if (menu.open && !menu.contains(event.target)) {
                close(menu);
            }
        });
    });

    // Keyboard users leaving the menu by tabbing get the same behaviour. The
    // check is deferred because at the moment focusout fires, document
    // .activeElement is still the element being left.
    menus.forEach(function (menu) {
        menu.addEventListener('focusout', function () {
            window.setTimeout(function () {
                if (menu.open && !menu.contains(document.activeElement)) {
                    close(menu);
                }
            }, 0);
        });

        // Escape is handled natively only while the summary itself has focus;
        // this extends it to anywhere inside the open panel.
        menu.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.open) {
                close(menu);
                menu.querySelector('summary').focus();
            }
        });
    });
}());
