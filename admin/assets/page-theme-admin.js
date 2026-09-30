/**
 * The page-theme editor (admin/page-theme.php): keeps the live preview and
 * the contrast warning in step with what is in the form, before it is saved.
 *
 * The colour fields themselves (swatch and hex in step) are
 * admin/assets/theme-admin.js, the site theme's own control, loaded next to
 * this one. This file only listens.
 *
 * PREVIEW. The frame shows admin/page-theme-preview.php; on a change this
 * reloads it with the form's values in the query string, at most once per
 * pause in typing. The server validates every value exactly as a save does
 * and shows the site theme's value for one it would refuse, so a half-typed
 * colour is harmless.
 *
 * CONTRAST. The server rendered the warning for the stored values
 * (App\Service\Theme\ThemeColor::contrastWarnings(), shared with the colour
 * palettes). This recomputes it for the values on screen with the same
 * formula, MygdalaTheme.contrastRatio() in admin/assets/theme-admin.js.
 * Which pairs are checked is in the markup (data-contrast-fg /
 * data-contrast-bg), not here, and every word is too.
 *
 * The page works without this script: the preview then shows the values
 * the page was loaded with, and the warning the ones last saved.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-page-theme-form]');
  if (!form || !window.MygdalaTheme) {
    return;
  }

  var HEX = /^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/;
  var MIN_CONTRAST = 4.5;
  var FIELDS = ['primary_color', 'on_primary_color', 'background_color', 'surface_color', 'text_color', 'font_pairing', 'heading_font_family_id', 'body_font_family_id'];

  function hex(value) {
    var v = String(value || '').trim();
    if (!HEX.test(v)) {
      return null;
    }
    v = v.replace('#', '');
    if (v.length === 3) {
      v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2];
    }
    return v.toUpperCase();
  }

  function field(name) {
    return form.querySelector('[name="' + name + '"]');
  }

  // The WCAG formula lives once, in admin/assets/theme-admin.js (loaded
  // first), shared with the colour-palette editor.
  function ratio(a, b) {
    return window.MygdalaTheme.contrastRatio('#' + a, '#' + b);
  }

  var warning = form.querySelector('[data-page-theme-contrast]');
  var separator = (document.documentElement.getAttribute('lang') || 'nl') === 'nl' ? ',' : '.';

  function updateContrast() {
    if (!warning) {
      return;
    }

    var anyFailing = false;
    warning.querySelectorAll('[data-contrast-fg]').forEach(function (item) {
      var fg = hex(field(item.getAttribute('data-contrast-fg')) && field(item.getAttribute('data-contrast-fg')).value);
      var bg = hex(field(item.getAttribute('data-contrast-bg')) && field(item.getAttribute('data-contrast-bg')).value);
      if (!fg || !bg) {
        item.hidden = true;
        return;
      }

      var value = ratio(fg, bg);
      var failing = value < MIN_CONTRAST;
      item.hidden = !failing;
      if (failing) {
        anyFailing = true;
        var slot = item.querySelector('[data-contrast-ratio]');
        if (slot) {
          slot.textContent = (Math.round(value * 10) / 10).toFixed(1).replace('.', separator);
        }
      }
    });

    warning.hidden = !anyFailing;
  }

  var frame = form.querySelector('[data-page-theme-preview]');
  var timer = null;

  function reloadPreview() {
    if (!frame) {
      return;
    }

    var params = new URLSearchParams();
    FIELDS.forEach(function (name) {
      var input = field(name);
      if (input) {
        params.set(name, input.value);
      }
    });

    var src = '/admin/page-theme-preview.php?' + params.toString();
    if (frame.getAttribute('src') !== src) {
      frame.setAttribute('src', src);
    }
  }

  function onChange() {
    updateContrast();
    window.clearTimeout(timer);
    timer = window.setTimeout(reloadPreview, 400);
  }

  form.addEventListener('input', onChange);
  form.addEventListener('change', onChange);
  updateContrast();
})();
