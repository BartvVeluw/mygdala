/*
 * The Paginakop editor (admin/page-hero.php): shows the parts of the
 * Afbeelding group that belong to the chosen "Afbeeldingsweergave", at once.
 *
 *   [data-page-hero-part="background left right"]  shown for any of the places
 *                                                  it names, hidden otherwise
 *   [data-page-hero-needs-image]                   also hidden while no picture
 *                                                  is chosen in the Media picker
 *   [data-media-sequence-needs]                    the transition and the time
 *                                                  per picture, shown once "Meer
 *                                                  afbeeldingen" has one
 *                                                  (admin/_media_sequence_field.php)
 *
 * Purely a display convenience. The server prints the same `hidden` for what
 * is stored, so the form looks the same on first load and without this
 * script, and the one form always posts every field; the endpoint decides
 * what a value means for the chosen place. The focus preview is the shared
 * field's (admin/assets/image-focus.js), and its shape follows the chosen
 * place and height through CSS alone (admin.css, [data-page-hero-form]).
 */
(function () {
  "use strict";

  var select = document.querySelector("[data-page-hero-image-mode]");
  if (!select) return;

  var form = select.closest("form");
  if (!form) return;

  var picker = form.querySelector('[data-media-picker-input][name="media_id"]');

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

    // The sequence's choices, once there is a picture after the first.
    var further = form.querySelectorAll("[data-media-sequence-list] [data-gallery-item]").length;
    Array.prototype.forEach.call(form.querySelectorAll("[data-media-sequence-needs]"), function (part) {
      part.hidden = further === 0;
    });
  }

  select.addEventListener("change", sync);
  form.addEventListener("change", function (event) {
    // The picker's field, or the list of further pictures telling the form
    // it changed (its marker, admin/assets/product-gallery.js).
    if (event.target === picker || (event.target && event.target.hasAttribute && event.target.hasAttribute("data-gallery-marker"))) {
      sync();
    }
  });
})();
