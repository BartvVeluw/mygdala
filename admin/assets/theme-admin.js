/**
 * The colour controls: keeps each colour's swatch and its hex field in step,
 * and paints the small preview from whatever is currently in the form.
 *
 * Used by admin/theme.php and by the Setup Wizard's appearance step
 * (admin/setup.php), which renders the same fields with the same ids because
 * it is the same setting — there is one theme engine, not two. Everything
 * below is guarded on the elements existing, so a page carrying only some of
 * them costs nothing.
 *
 * The TEXT field is the one that submits. The swatch is a convenience on top
 * of it, so a page without JavaScript still saves a colour that was typed,
 * and nothing here is required for the form to work.
 *
 * The preview is deliberately a rough impression built from the same five
 * colours the server uses, not a rendering of the real site: a faithful
 * preview would mean shipping the public stylesheet into the admin, and the
 * value of that is far below its cost. The site itself is one click away.
 */
(function () {
  'use strict';

  var HEX = /^#[0-9A-Fa-f]{6}$/;

  function normalise(value) {
    var v = String(value || '').trim();
    if (v.charAt(0) !== '#') {
      v = '#' + v;
    }
    if (/^#[0-9A-Fa-f]{3}$/.test(v)) {
      v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    }
    return HEX.test(v) ? v.toUpperCase() : null;
  }

  function fieldValue(id) {
    var el = document.getElementById(id);
    return el ? normalise(el.value) : null;
  }

  function paintPreview() {
    var preview = document.querySelector('[data-theme-preview]');
    if (!preview) {
      return;
    }

    var primary = fieldValue('theme-primary_color');
    var onPrimary = fieldValue('theme-on_primary_color');
    var background = fieldValue('theme-background_color');
    var surface = fieldValue('theme-surface_color');
    var text = fieldValue('theme-text_color');

    if (background) { preview.style.background = background; }
    if (text) { preview.style.color = text; }

    var card = preview.querySelector('[data-theme-preview-card]');
    if (card && surface) { card.style.background = surface; }

    var link = preview.querySelector('[data-theme-preview-link]');
    if (link && primary) { link.style.color = primary; }

    var heading = preview.querySelector('[data-theme-preview-heading]');
    if (heading && text) { heading.style.color = text; }

    var button = preview.querySelector('[data-theme-preview-btn]');
    if (button) {
      if (primary) { button.style.background = primary; }
      if (onPrimary) { button.style.color = onPrimary; }
      var shape = document.getElementById('theme-button-shape');
      button.style.borderRadius = (shape && shape.value === 'rounded') ? '12px' : '999px';
    }
  }

  document.querySelectorAll('[data-theme-color-for]').forEach(function (swatch) {
    var hex = document.getElementById(swatch.getAttribute('data-theme-color-for'));
    if (!hex) {
      return;
    }

    swatch.addEventListener('input', function () {
      hex.value = String(swatch.value).toUpperCase();
      paintPreview();
    });

    hex.addEventListener('input', function () {
      var value = normalise(hex.value);
      if (value) {
        swatch.value = value;
      }
      paintPreview();
    });

    // A half-typed hex is normal while typing, but a field left as "#c9a06"
    // should not silently submit and be rejected. Tidy it up on the way out.
    hex.addEventListener('blur', function () {
      var value = normalise(hex.value);
      if (value) {
        hex.value = value;
        swatch.value = value;
        paintPreview();
      }
    });
  });

  var shapeSelect = document.getElementById('theme-button-shape');
  if (shapeSelect) {
    shapeSelect.addEventListener('change', paintPreview);
  }

  paintPreview();
})();
