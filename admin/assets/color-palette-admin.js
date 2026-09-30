/**
 * The colour-palette editor (admin/color-palette.php): repaints the preview
 * and the contrast warning from what is in the form, on every change, before
 * anything is saved.
 *
 * THE PREVIEW, LIVE AND LOCAL. The frame holds admin/color-palette-preview.php,
 * a sample of the website drawn with the real core.css and the palette's
 * tokens on its <main data-page-theme>. On a change this sets the complete
 * token set straight on that <main> — the same properties the server prints,
 * computed by MygdalaTheme.tokens() (admin/assets/theme-admin.js) from the
 * recipe the page carries (data-palette-model, from
 * App\Service\Theme\ThemePalette::recipe()). No reload, no request per colour,
 * no formulas in this file. core.css recomputes the alpha derivations on that
 * element by itself. A half-typed colour is skipped until it is a colour.
 *
 * NOTHING IS SAVED HERE. The form posts as always; the save bar
 * (admin/assets/save-bar.js) tracks the unsaved state and warns before
 * leaving. On load every field goes back to the value the server printed, so
 * a browser that restores form values never shows an unsaved colour as if it
 * were the palette's.
 *
 * CONTRAST. The server rendered the warning for the values it printed
 * (App\Service\Theme\ThemeColor::contrastWarnings()); this recomputes it for
 * what is on screen with the same WCAG formula. Which pairs are checked is in
 * the markup (data-contrast-fg / data-contrast-bg), and every word too.
 *
 * The page works without this script: the preview then shows the colours the
 * page was loaded with, and the warning the ones it was rendered for.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-palette-form]');
  var theme = window.MygdalaTheme;
  if (!form || !theme) {
    return;
  }

  var model;
  try {
    model = JSON.parse(form.getAttribute('data-palette-model') || '');
  } catch (e) {
    return;
  }

  var KEYS = Object.keys(model.direct);
  var frame = form.querySelector('[data-palette-preview]');
  var warning = form.querySelector('[data-palette-contrast]');
  var separator = (document.documentElement.getAttribute('lang') || 'nl') === 'nl' ? ',' : '.';

  function field(name) {
    return form.querySelector('input[name="' + name + '"]');
  }

  /** The five colours on screen; a colour that is not (yet) valid keeps its last good value. */
  var current = {};

  function readColors() {
    KEYS.forEach(function (key) {
      var input = field(key);
      var value = input ? theme.normalise(input.value) : null;
      if (value) {
        current[key] = value;
      }
    });
    return current;
  }

  function previewMain() {
    try {
      var doc = frame && frame.contentDocument;
      return doc ? doc.querySelector('main[data-page-theme]') : null;
    } catch (e) {
      return null;
    }
  }

  function paintPreview() {
    var main = previewMain();
    if (!main) {
      return;
    }
    var colors = readColors();
    if (KEYS.some(function (key) { return !colors[key]; })) {
      return;
    }
    var tokens = theme.tokens(model, colors);
    Object.keys(tokens).forEach(function (property) {
      main.style.setProperty(property, tokens[property]);
    });
  }

  function updateContrast() {
    if (!warning) {
      return;
    }
    var colors = readColors();
    var anyFailing = false;

    warning.querySelectorAll('[data-contrast-fg]').forEach(function (item) {
      var fg = colors[item.getAttribute('data-contrast-fg')];
      var bg = colors[item.getAttribute('data-contrast-bg')];
      if (!fg || !bg) {
        item.hidden = true;
        return;
      }
      var ratio = theme.contrastRatio(fg, bg);
      var failing = ratio < theme.MIN_CONTRAST;
      item.hidden = !failing;
      if (failing) {
        anyFailing = true;
        var slot = item.querySelector('[data-contrast-ratio]');
        if (slot) {
          slot.textContent = (Math.round(ratio * 10) / 10).toFixed(1).replace('.', separator);
        }
      }
    });

    warning.hidden = !anyFailing;
  }

  function update() {
    paintPreview();
    updateContrast();
  }

  // Back to the server's values: an unsaved colour is never shown as saved.
  KEYS.forEach(function (key) {
    var input = field(key);
    if (input && input.value !== input.defaultValue) {
      input.value = input.defaultValue;
      var swatch = form.querySelector('[data-theme-color-for="' + input.id + '"]');
      var value = theme.normalise(input.defaultValue);
      if (swatch && value) {
        swatch.value = value;
      }
    }
  });

  form.addEventListener('input', update);
  form.addEventListener('change', update);
  if (frame) {
    frame.addEventListener('load', update);
  }
  update();
})();
