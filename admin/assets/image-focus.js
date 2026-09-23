/*
 * The focus point of a cropped picture (App\Service\Media\ImageFocus), for
 * the editor of a Kaarten-carrousel card (admin/carousel-card.php).
 *
 * The preview beside the nine points is the card's own frame: the same
 * object-fit: cover, and the object-position the chosen point stands for
 * (data-object-position, written by the server from the same list the
 * website uses). Choosing a point moves the picture at once; choosing
 * another picture in the Media picker puts that one in the frame.
 *
 * Without this script the points are ordinary radio buttons and the preview
 * shows what is stored; the form posts the same value either way.
 */
(function () {
  "use strict";

  Array.prototype.forEach.call(document.querySelectorAll("[data-image-focus]"), function (group) {
    var preview = group.querySelector("[data-image-focus-preview]");
    var frame = group.querySelector("[data-image-focus-frame]");
    if (!preview || !frame) return;

    function show(radio) {
      preview.style.objectPosition = radio.getAttribute("data-object-position") || "50% 50%";
    }

    group.addEventListener("change", function (event) {
      if (event.target.matches('input[type="radio"]')) {
        show(event.target);
      }
    });

    // The picture itself: the Media picker's own preview holds the chosen one.
    var form = group.closest("form");
    if (!form) return;

    form.addEventListener("change", function (event) {
      if (!event.target.matches("[data-media-picker-input]")) return;

      var picker = event.target.closest("[data-media-picker]");
      var chosen = picker ? picker.querySelector("[data-media-picker-preview] img") : null;
      var src = event.target.value !== "" && chosen ? chosen.getAttribute("src") : "";

      if (src) {
        preview.setAttribute("src", src);
      }
      frame.hidden = !src;
    });
  });
})();
