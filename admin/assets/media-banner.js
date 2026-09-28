/*
 * The Mediabanner editor (admin/media-banner.php): shows what only some items
 * need, for the items that are chosen, at once.
 *
 *   [data-media-banner-needs="main"]        the list of further items, once
 *                                           there is a first one
 *   [data-media-banner-needs="image"]       the focus point, while any picture
 *                                           is chosen (the first or a further one)
 *   [data-media-banner-needs="video"]       a video's controls and its note,
 *                                           while any video is chosen
 *   [data-media-banner-needs="main-video"]  the poster, while the FIRST item
 *                                           is a video
 *   [data-media-banner-needs="play"]        autoplay and repeat: a video, or
 *                                           more than one item
 *   [data-media-banner-needs="sequence"]    the transition, the time per
 *                                           picture and the buttons, for more
 *                                           than one item
 *   [data-media-sequence-needs]             the same, inside that card
 *
 * The kind is each chosen library item's own: the Media picker keeps the
 * first one's on its field (data-media-picker-chosen, admin/_media_picker.php)
 * and every card of the further items carries its own (data-kind,
 * admin/assets/product-gallery.js), so there is no image/video switch here.
 * Purely a display convenience, like admin/assets/cta-band.js: the server
 * prints the same `hidden` for what is stored, so the form looks the same on
 * first load and without this script, the one form always posts every field,
 * and the endpoint decides what a value means.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-media-banner-form]");
  if (!form) return;

  var picker = form.querySelector("[data-media-banner-main] [data-media-picker]");
  var input = picker ? picker.querySelector("[data-media-picker-input]") : null;

  function sync() {
    var main = input && input.value !== "" ? picker.getAttribute("data-media-picker-chosen") || "" : "";
    var further = Array.prototype.map.call(form.querySelectorAll("[data-media-sequence-list] [data-gallery-item]"), function (item) {
      return item.getAttribute("data-kind") === "video" ? "video" : "image";
    });
    var isSequence = main !== "" && further.length > 0;

    var shown = {
      main: main !== "",
      image: main === "image" || (main !== "" && further.indexOf("image") !== -1),
      video: main === "video" || (main !== "" && further.indexOf("video") !== -1),
      "main-video": main === "video",
      play: main === "video" || isSequence,
      sequence: isSequence
    };

    Array.prototype.forEach.call(form.querySelectorAll("[data-media-banner-needs]"), function (part) {
      part.hidden = !shown[part.getAttribute("data-media-banner-needs")];
    });
    Array.prototype.forEach.call(form.querySelectorAll("[data-media-sequence-needs]"), function (part) {
      part.hidden = !isSequence;
    });
  }

  form.addEventListener("change", function (event) {
    // The first item's field, or the list of further items telling the form
    // it changed (its marker, admin/assets/product-gallery.js).
    if (event.target === input || (event.target && event.target.hasAttribute && event.target.hasAttribute("data-gallery-marker"))) {
      sync();
    }
  });
  sync();
})();
