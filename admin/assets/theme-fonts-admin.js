/**
 * Typografie on Vormgeving (admin/theme.php): reloads the preview frame
 * when the font pairing or a font per role changes, so the choice is seen
 * before it is saved. The frame is the colour palettes' own preview document
 * (admin/color-palette-preview.php), which validates each value like a save
 * and shows the site's value for anything it would refuse. Nothing is
 * stored until Opslaan. See THEMING.md, "Font Library".
 *
 * Without this script the preview shows the saved fonts.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-theme-typography]');
  var frame = form ? form.querySelector('[data-typography-preview]') : null;
  if (!form || !frame) {
    return;
  }

  var FIELDS = ['font_pairing', 'heading_font_family_id', 'body_font_family_id'];
  var timer = null;

  function reload() {
    var params = new URLSearchParams();
    FIELDS.forEach(function (name) {
      var field = form.elements.namedItem(name);
      if (field) {
        params.set(name, field.value);
      }
    });

    var src = '/admin/color-palette-preview.php?' + params.toString();
    if (frame.getAttribute('src') !== src) {
      frame.setAttribute('src', src);
    }
  }

  form.addEventListener('change', function (event) {
    if (FIELDS.indexOf(event.target.name) === -1) {
      return;
    }
    window.clearTimeout(timer);
    timer = window.setTimeout(reload, 250);
  });
})();
