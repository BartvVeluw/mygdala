/*
 * The Mediabanner editor (admin/media-banner.php): shows what only one kind
 * of media needs, for the kind that is chosen, at once.
 *
 *   [data-media-banner-needs="image"]  the focus point, while a picture is chosen
 *   [data-media-banner-needs="video"]  the video options and the poster, while a
 *                                      video is chosen
 *
 * The kind is the chosen library item's own: the Media picker keeps it on its
 * field (data-media-picker-chosen, admin/_media_picker.php), so there is no
 * image/video switch here. Purely a display convenience, like
 * admin/assets/cta-band.js: the server prints the same `hidden` for what is
 * stored, so the form looks the same on first load and without this script,
 * the one form always posts every field, and the endpoint decides what a
 * value means.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-media-banner-form]");
  if (!form) return;

  var picker = form.querySelector("[data-media-banner-main] [data-media-picker]");
  var input = picker ? picker.querySelector("[data-media-picker-input]") : null;

  function sync() {
    var kind = input && input.value !== "" ? picker.getAttribute("data-media-picker-chosen") || "" : "";

    Array.prototype.forEach.call(form.querySelectorAll("[data-media-banner-needs]"), function (part) {
      part.hidden = part.getAttribute("data-media-banner-needs") !== kind;
    });
  }

  form.addEventListener("change", function (event) {
    if (event.target === input) {
      sync();
    }
  });
  sync();
})();
