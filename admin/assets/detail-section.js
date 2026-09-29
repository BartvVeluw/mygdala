/*
 * The Detailsectie editor (admin/detail-section.php): the image position
 * shows only while a main image is chosen (Detailsectie 2.0).
 *
 *   [data-detail-image-position]  hidden while the main image's picker is
 *                                 empty; the field keeps its value, so a
 *                                 position chosen before stays stored
 *
 * Purely a display convenience, like admin/assets/cta-band.js. The server
 * prints the same `hidden` for what is stored (an image from before the
 * library counts as an image), the one form always posts the field, and the
 * website ignores the position without an image. A gallery item's source
 * follows admin/assets/navigation-item.js, not this file.
 *
 * A gallery row's focus frame follows its source through
 * admin/assets/gallery-source.js, not this file.
 */
(function () {
  "use strict";

  var picker = document.querySelector('[data-media-picker-input][name="main_media_id"]');
  var position = document.querySelector("[data-detail-image-position]");
  if (!picker || !position) return;

  // An image from before the library has no picker value and still has a
  // position; only choosing or clearing in the picker changes what is shown.
  var legacy = !position.hidden && picker.value === "";

  function sync() {
    position.hidden = picker.value === "" && !legacy;
  }

  picker.addEventListener("change", function () {
    legacy = false;
    sync();
  });
})();
