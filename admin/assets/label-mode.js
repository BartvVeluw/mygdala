/*
 * What stands above a title (admin/_label_mode_field.php): the part that
 * belongs to the chosen mode shows, the others hide — the own words for
 * "Eigen tekst", the icon picker for "Icoon".
 *
 * Purely a display convenience: the server prints the same `hidden` for the
 * stored choice and every part is always posted, so the endpoint decides
 * what a mode keeps. This file holds no text of its own (ADMIN-UI.md).
 */
(function () {
  "use strict";

  function sync(field) {
    var select = field.querySelector("[data-label-mode]");
    if (!select) return;

    field.querySelectorAll("[data-label-mode-when]").forEach(function (part) {
      part.hidden = part.getAttribute("data-label-mode-when") !== select.value;
    });
  }

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element) || !target.matches("[data-label-mode]")) return;

    var field = target.closest("[data-label-mode-field]");
    if (field) sync(field);
  });

  document.querySelectorAll("[data-label-mode-field]").forEach(sync);
})();
