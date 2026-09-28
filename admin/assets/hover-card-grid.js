/*
 * The Hover kaarten grid editor (admin/hover-card-grid.php): shows a choice
 * only for the layout it belongs to, at once.
 *
 *   [data-hover-cards-needs="overlay"]  the veil behind the words, which only
 *                                       an overlay card has
 *
 * Purely a display convenience, like admin/assets/media-banner.js: the server
 * prints the same `hidden` for what is stored, so the form looks the same on
 * first load and without this script, the one form always posts every field,
 * and the endpoint decides what a value means.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-hover-cards-form]");
  if (!form) return;

  function layout() {
    var chosen = form.querySelector("[data-hover-cards-layout]:checked");
    return chosen ? chosen.value : "";
  }

  function sync() {
    var current = layout();

    Array.prototype.forEach.call(form.querySelectorAll("[data-hover-cards-needs]"), function (part) {
      part.hidden = part.getAttribute("data-hover-cards-needs") !== current;
    });
  }

  form.addEventListener("change", function (event) {
    if (event.target && event.target.hasAttribute && event.target.hasAttribute("data-hover-cards-layout")) {
      sync();
    }
  });
  sync();
})();
