/*
 * The focus point of a cropped picture (App\Service\Media\ImageFocus), for
 * every field media_focus_field() prints (admin/_media_picker.php): the
 * Kaarten-carrousel card and each item of a Tekst met afbeelding.
 *
 * The preview beside the nine points is the place's own frame: the same
 * object-fit: cover, and the object-position the chosen point stands for
 * (data-object-position, written by the server from the same list the
 * website uses). Choosing a point moves the picture at once; choosing
 * another picture in the Media picker puts that one in the frame.
 *
 * DELEGATED, so a row that admin/assets/row-list.js adds after the page
 * loaded works like one the server printed. A picker and a focus field
 * belong together when they share a row of a list ([data-row-list-row]),
 * else when they share a form.
 *
 * Without this script the points are ordinary radio buttons and the preview
 * shows what is stored; the form posts the same value either way.
 */
(function () {
  "use strict";

  function scopeOf(element) {
    return element.closest("[data-row-list-row]") || element.closest("form");
  }

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    if (target.matches('[data-image-focus] input[type="radio"]')) {
      var group = target.closest("[data-image-focus]");
      var preview = group.querySelector("[data-image-focus-preview]");
      if (preview) {
        preview.style.objectPosition = target.getAttribute("data-object-position") || "50% 50%";
      }
      return;
    }

    if (!target.matches("[data-media-picker-input]")) return;

    // The picture itself: the Media picker's own preview holds the chosen one.
    var scope = scopeOf(target);
    var field = scope ? scope.querySelector("[data-image-focus]") : null;
    if (!field) return;

    var frame = field.querySelector("[data-image-focus-frame]");
    var image = field.querySelector("[data-image-focus-preview]");
    if (!frame || !image) return;

    var picker = target.closest("[data-media-picker]");
    var chosen = picker ? picker.querySelector("[data-media-picker-preview] img") : null;
    var src = target.value !== "" && chosen ? chosen.getAttribute("src") : "";

    if (src) {
      image.setAttribute("src", src);
    }
    frame.hidden = !src;
  });
})();
