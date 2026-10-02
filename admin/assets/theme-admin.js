/**
 * The colour controls: keeps each colour's swatch and its hex field in step,
 * and paints the small preview from whatever is currently in the form.
 *
 * Used by admin/theme.php and by the Setup Wizard's appearance step
 * (admin/setup.php), which renders the same fields with the same ids because
 * it is the same setting — there is one theme engine, not two. The
 * dashboard's Eigen kleuren on admin/settings.php uses the same control for
 * a different setting, with ids of its own, and builds its live preview on
 * top of it (admin/assets/admin-theme-preview.js). Everything
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

  /* --- Shared colour logic for the live editors -------------------------
     window.MygdalaTheme: what an editor needs to show colours before they
     are saved, in one place — the colour-palette editor
     (admin/assets/color-palette-admin.js) and the page-theme editor
     (admin/assets/page-theme-admin.js).

     derive() carries NO formulas of its own. It evaluates the recipe the
     server prints (App\Service\Theme\ThemePalette::recipe()) with the same
     five operations ThemePalette has — an HSL lightness shift, an sRGB mix,
     the 'r, g, b' channels, the readable step of a status colour and the
     alpha step of the faint text — so a tint in the preview is the tint the
     website will get. luminance()/contrastRatio() are the WCAG formulas of
     App\Service\Theme\ThemeColor. */

  function rgb(hex) {
    var h = hex.replace('#', '');
    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
  }

  function toHex(r, g, b) {
    return '#' + [r, g, b].map(function (c) {
      var v = Math.max(0, Math.min(255, Math.round(c)));
      return (v < 16 ? '0' : '') + v.toString(16).toUpperCase();
    }).join('');
  }

  function channels(hex) {
    return rgb(hex).join(', ');
  }

  function mix(a, b, weight) {
    var w = Math.max(0, Math.min(1, weight));
    var x = rgb(a);
    var y = rgb(b);
    return toHex(x[0] + (y[0] - x[0]) * w, x[1] + (y[1] - x[1]) * w, x[2] + (y[2] - x[2]) * w);
  }

  function toHsl(hex) {
    var c = rgb(hex).map(function (v) { return v / 255; });
    var max = Math.max(c[0], c[1], c[2]);
    var min = Math.min(c[0], c[1], c[2]);
    var l = (max + min) / 2;
    var d = max - min;
    if (d === 0) {
      return [0, 0, l];
    }
    var s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    var h;
    if (max === c[0]) {
      h = ((c[1] - c[2]) / d + (c[1] < c[2] ? 6 : 0)) % 6;
    } else if (max === c[1]) {
      h = (c[2] - c[0]) / d + 2;
    } else {
      h = (c[0] - c[1]) / d + 4;
    }
    return [h * 60, s, l];
  }

  function fromHsl(h, s, l) {
    if (s === 0) {
      return toHex(l * 255, l * 255, l * 255);
    }
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var hp = (h % 360) / 60;
    var x = c * (1 - Math.abs((hp % 2) - 1));
    var m = l - c / 2;
    var wedges = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]];
    var w = wedges[Math.floor(hp) % 6];
    return toHex((w[0] + m) * 255, (w[1] + m) * 255, (w[2] + m) * 255);
  }

  function lighten(hex, delta) {
    var hsl = toHsl(hex);
    return fromHsl(hsl[0], hsl[1], Math.max(0, Math.min(1, hsl[2] + delta)));
  }

  // ThemePalette::readable(): the colour itself when it reaches the ratio
  // on the ground, else the first lightness step away from the ground that does.
  function readable(hex, ground, ratio) {
    if (contrastRatio(hex, ground) >= ratio) {
      return hex;
    }
    var step = contrastRatio(ground, '#000000') >= contrastRatio(ground, '#FFFFFF') ? -0.01 : 0.01;
    var hsl = toHsl(hex);
    var candidate = hex;
    for (var i = 1; i <= 100; i++) {
      var lightness = Math.max(0, Math.min(1, hsl[2] + step * i));
      candidate = fromHsl(hsl[0], hsl[1], lightness);
      if (contrastRatio(candidate, ground) >= ratio || lightness === 0 || lightness === 1) {
        break;
      }
    }
    return candidate;
  }

  // ThemePalette::fade(): the colour at that alpha when it reaches the ratio
  // composited over every ground, else at the first higher alpha that does.
  function fade(hex, alpha, ratio, grounds) {
    var percent = Math.round(alpha * 100);
    for (; percent < 100; percent++) {
      var reads = grounds.every(function (ground) {
        return contrastRatio(mix(ground, hex, percent / 100), ground) >= ratio;
      });
      if (reads) {
        break;
      }
    }
    return 'rgba(' + channels(hex) + ', ' + (percent >= 100 ? '1' : String(percent / 100)) + ')';
  }

  function evaluate(expression, roles, done) {
    if (typeof expression === 'string') {
      if (Object.prototype.hasOwnProperty.call(roles, expression)) {
        return roles[expression];
      }
      return Object.prototype.hasOwnProperty.call(done, expression) ? done[expression] : expression;
    }
    switch (expression[0]) {
      case 'lighten':
        return lighten(evaluate(expression[1], roles, done), Number(expression[2]));
      case 'mix':
        return mix(evaluate(expression[1], roles, done), evaluate(expression[2], roles, done), Number(expression[3]));
      case 'channels':
        return channels(evaluate(expression[1], roles, done));
      case 'readable':
        return readable(evaluate(expression[1], roles, done), evaluate(expression[2], roles, done), Number(expression[3]));
      case 'fade':
        return fade(
          evaluate(expression[1], roles, done),
          Number(expression[2]),
          Number(expression[3]),
          expression.slice(4).map(function (ground) { return evaluate(ground, roles, done); })
        );
    }
    return '';
  }

  /**
   * The complete colour token set of five colours, in the order the server
   * prints it (App\Service\Theme\ThemeCss::paletteDeclarations()).
   * model = {direct, roles, recipe} as printed by the page; colors = the five
   * settings keys => #RRGGBB, already normalised.
   */
  function tokens(model, colors) {
    var out = {};
    Object.keys(model.direct).forEach(function (key) {
      out[model.direct[key]] = colors[key];
    });
    var roles = {};
    Object.keys(model.roles).forEach(function (key) {
      roles[model.roles[key]] = colors[key];
    });
    model.recipe.forEach(function (step) {
      out[step[0]] = evaluate(step[1], roles, out);
    });
    return out;
  }

  function luminance(hex) {
    var c = rgb(hex).map(function (v) {
      var x = v / 255;
      return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }

  function contrastRatio(a, b) {
    var la = luminance(a);
    var lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }

  window.MygdalaTheme = {
    MIN_CONTRAST: 4.5,
    normalise: normalise,
    tokens: tokens,
    contrastRatio: contrastRatio
  };
})();
