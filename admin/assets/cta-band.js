/*
 * The Oproep met knop editor (admin/cta-band.php): shows the choices that
 * only mean something with a background picture or a text panel, at once.
 *
 *   [data-cta-needs-image]  hidden while no picture is chosen in the Media
 *                           picker (the focus point and the overlay)
 *   [data-cta-needs-panel]  hidden while "Tekstvlak tonen" is off (its opacity)
 *
 * Purely a display convenience, like admin/assets/page-hero.js. The server
 * prints the same `hidden` for what is stored, so the form looks the same on
 * first load and without this script, and the one form always posts every
 * field; the endpoint decides what a value means. The buttons' fields follow
 * their destination through admin/assets/navigation-item.js, not through this
 * file.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-cta-band-form]");
  if (!form) return;

  var picker = form.querySelector('[data-media-picker-input][name="background_media_id"]');
  var panel = form.querySelector("[data-cta-panel-toggle]");

  function sync() {
    var hasImage = picker ? picker.value !== "" : false;
    var hasPanel = panel ? panel.checked : false;

    Array.prototype.forEach.call(form.querySelectorAll("[data-cta-needs-image]"), function (part) {
      part.hidden = !hasImage;
    });
    Array.prototype.forEach.call(form.querySelectorAll("[data-cta-needs-panel]"), function (part) {
      part.hidden = !hasPanel;
    });
  }

  form.addEventListener("change", function (event) {
    if (event.target === picker || event.target === panel) {
      sync();
    }
  });
  sync();
})();
