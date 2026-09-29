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
 *   gallery rows                  the frame of an item's focus point
 *                                 (admin/_responsive_image_field.php) shows
 *                                 the picture the row now shows: its library
 *                                 picture (responsive-image.js follows that
 *                                 picker itself) or the chosen item's own,
 *                                 from the choice's data-thumbnail, sent as
 *                                 an "rm:picture" event
 */
(function () {
  "use strict";

  function galleryPicture(row) {
    var type = row.querySelector("[data-nav-link-type]");
    var kind = type ? type.value : "media";

    if (kind === "media") {
      var image = row.querySelector('[data-nav-link-field="media"] [data-media-picker-preview] img');
      var input = row.querySelector('[data-nav-link-field="media"] [data-media-picker-input]');
      return input && input.value !== "" && image ? image.getAttribute("src") || "" : "";
    }

    var select = row.querySelector('[data-destination-select="' + kind + '"]');
    var option = select ? select.options[select.selectedIndex] : null;
    return option ? option.getAttribute("data-thumbnail") || "" : "";
  }

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element) || !target.matches("[data-nav-link-type], [data-destination-select]")) return;

    var row = target.closest('[data-row-list="detail-section-images"] [data-row-list-row]');
    if (!row) return;

    row.dispatchEvent(new CustomEvent("rm:picture", { bubbles: true, detail: { src: galleryPicture(row) } }));
  });
})();

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
