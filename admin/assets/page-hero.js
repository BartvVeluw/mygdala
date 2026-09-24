/*
 * The Paginakop editor (admin/page-hero.php): shows the parts of the
 * Afbeelding group that belong to the chosen "Afbeeldingsweergave", at once.
 *
 *   [data-page-hero-part="background left right"]  shown for any of the places
 *                                                  it names, hidden otherwise
 *   [data-page-hero-needs-image]                   also hidden while no picture
 *                                                  is chosen in the Media picker
 *   [data-page-hero-focus-frame]                   the focus preview, given the
 *                                                  shape of the chosen place
 *                                                  (its data-shape-* values)
 *
 * Purely a display convenience. The server prints the same `hidden` and the
 * same shape for what is stored, so the form looks the same on first load and
 * without this script, and the one form always posts every field; the
 * endpoint decides what a value means for the chosen place. The focus
 * preview's picture and position are admin/assets/image-focus.js's.
 */
(function () {
  "use strict";

  var select = document.querySelector("[data-page-hero-image-mode]");
  if (!select) return;

  var form = select.closest("form");
  if (!form) return;

  var picker = form.querySelector('[data-media-picker-input][name="media_id"]');
  var frame = form.querySelector("[data-page-hero-focus-frame]");

  function checkedHeight() {
    var checked = form.querySelector('input[name="hero_height"]:checked');
    return checked ? checked.value : "medium";
  }

  function sync() {
    var mode = select.value;
    var hasImage = picker ? picker.value !== "" : false;

    Array.prototype.forEach.call(form.querySelectorAll("[data-page-hero-part]"), function (part) {
      var places = part.getAttribute("data-page-hero-part").split(" ");
      var shown = places.indexOf(mode) !== -1;

      if (shown && part.hasAttribute("data-page-hero-needs-image")) {
        shown = hasImage;
      }
      part.hidden = !shown;
    });

    // A part that needs a picture but belongs to every place (the focus point).
    Array.prototype.forEach.call(form.querySelectorAll("[data-page-hero-needs-image]:not([data-page-hero-part])"), function (part) {
      part.hidden = !hasImage;
    });

    if (frame) {
      var key = mode === "left" || mode === "right" ? "beside" : checkedHeight();
      var shape = frame.getAttribute("data-shape-" + key);
      if (shape) {
        frame.style.aspectRatio = shape;
      }
    }
  }

  select.addEventListener("change", sync);
  form.addEventListener("change", function (event) {
    if (event.target === picker || event.target.name === "hero_height") {
      sync();
    }
  });
})();
