/*
 * The Reviews editor (admin/reviews.php): follows the "Weergave" select at
 * once.
 *
 *   [data-reviews-layout]           on the form: the chosen layout, which
 *                                   admin.css reads to mark its sketch
 *   [data-reviews-note="<layout>"]  the one sentence about each layout
 *   [data-reviews-needs="featured"] "Deze review uitlichten", which only
 *                                   the featured layout uses
 *
 * Purely a display convenience, like admin/assets/hover-card-grid.js: the
 * server prints the same state for what is stored, so the form looks the same
 * on first load and without this script, the one form always posts every
 * field, and the endpoint decides what a value means.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-reviews-form]");
  if (!form) return;

  var select = form.querySelector("[data-reviews-layout-select]");
  if (!select) return;

  function sync() {
    var layout = select.value;
    form.setAttribute("data-reviews-layout", layout);

    Array.prototype.forEach.call(form.querySelectorAll("[data-reviews-note]"), function (note) {
      note.hidden = note.getAttribute("data-reviews-note") !== layout;
    });
    Array.prototype.forEach.call(form.querySelectorAll("[data-reviews-needs]"), function (part) {
      part.hidden = part.getAttribute("data-reviews-needs") !== layout;
    });
  }

  select.addEventListener("change", sync);
  // A review added on screen arrives with the server's state of the moment
  // the page was printed; bring it in line with the select.
  form.addEventListener("row-list:added", sync);
  sync();
})();
