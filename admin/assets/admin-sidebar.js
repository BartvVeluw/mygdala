/**
 * The sidebar's menus (admin/_header.php, App\Service\AdminNavigation::sidebar()):
 * a click on a menu's line folds its entries open or shut.
 *
 * That is all. The line is a real <button>, so Enter, Space, the tab order
 * and the focus ring are the browser's, and aria-expanded is what a screen
 * reader announces. Which menu starts open is the server's answer (the menu
 * of the screen being shown), so nothing is remembered here and nothing
 * flashes open after the page is drawn.
 */
(function () {
  "use strict";

  document.addEventListener("click", function (event) {
    var toggle = event.target && event.target.closest ? event.target.closest("[data-admin-sidebar-menu-toggle]") : null;
    if (!toggle) return;

    var submenu = document.getElementById(toggle.getAttribute("aria-controls") || "");
    if (!submenu) return;

    var open = toggle.getAttribute("aria-expanded") !== "true";
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    submenu.hidden = !open;
  });
})();
