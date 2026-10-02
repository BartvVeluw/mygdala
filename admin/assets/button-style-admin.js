/**
 * The button style editor (admin/button-style.php): repaints the preview
 * from what is in the form, on every change, before anything is saved, and
 * shows only the fields that matter for the chosen appearance.
 *
 * THE PREVIEW, LIVE AND LOCAL. The frame holds admin/button-style-preview.php,
 * real .btn samples drawn by the real core.css in the website's tokens. On a
 * change this sets the style's --btn-* properties straight on every sample
 * — the same properties App\Service\Theme\ButtonStyleCss::declarations()
 * prints for the website, composed here from the recipe the form carries
 * (data-button-recipe, ButtonStyleCss::recipe()): the same maps of values,
 * so no value is written twice. Only the composition (which field applies to
 * which appearance) is mirrored, and it is short on purpose; keep it in step
 * with declarations(). No reload, no request per change.
 *
 * NOTHING IS SAVED HERE. The form posts as always; the save bar tracks the
 * unsaved state. The page works without this script: the preview then shows
 * the style as it was loaded, and every field stays visible.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-button-style-form]');
  if (!form) {
    return;
  }

  var recipe;
  try {
    recipe = JSON.parse(form.getAttribute('data-button-recipe') || '');
  } catch (e) {
    return;
  }

  var frame = form.querySelector('[data-button-preview]');
  var HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;

  function control(name) {
    return form.elements.namedItem(name);
  }

  function word(name, fallback) {
    var el = control(name);
    return el && el.value ? el.value : fallback;
  }

  function flag(name) {
    var el = control(name);
    return !!(el && el.checked);
  }

  function hex(value) {
    var match = HEX.exec((value || '').trim());
    if (!match) {
      return null;
    }
    var digits = match[1];
    if (digits.length === 3) {
      digits = digits.replace(/(.)/g, '$1$1');
    }
    return '#' + digits.toUpperCase();
  }

  /** A colour field: a theme colour word, a fixed #RRGGBB, '' (unchanged) or null (not a colour yet). */
  function colour(name) {
    var value = word(name, '');
    if (value === 'custom') {
      return hex((control(name + '_custom') || {}).value);
    }
    return value;
  }

  function css(value) {
    return recipe.colors[value] || hex(value) || recipe.colors.primary;
  }

  /** ButtonStyleCss::textColor(): a colour as the label, not a fill or a border. */
  function textCss(value) {
    return recipe.textColors[value] || css(value);
  }

  /** ButtonStyleCss::declarations(), for the form's current values. */
  function declarations() {
    var appearance = word('appearance', 'filled');
    var isText = appearance === 'text';
    var size = recipe.sizes[word('size', 'normal')] || recipe.sizes.normal;
    var pad = isText ? recipe.textPadding : size;
    var out = {};

    var fill = colour('fill_color') || 'primary';
    if (appearance !== 'filled') {
      out['--btn-bg'] = 'transparent';
    } else if (!flag('fill_gradient')) {
      out['--btn-bg'] = css(fill);
    } else {
      out['--btn-bg'] = fill === 'primary' ? recipe.primaryGradient : recipe.sheen + ', ' + css(fill);
    }
    out['--btn-fg'] = textCss(colour('text_color') || 'text');

    var border = word('border_width', 'none');
    if (isText) {
      out['--btn-border-width'] = '0px';
      out['--btn-border-color'] = 'transparent';
    } else {
      if (appearance === 'outline' && border === 'none') {
        border = 'normal';
      }
      out['--btn-border-width'] = recipe.borders[border] || recipe.borders.none;
      out['--btn-border-color'] = border === 'none' ? 'transparent' : css(colour('border_color') || 'primary');
    }

    out['--btn-radius'] = recipe.shapes[word('shape', 'pill')] || recipe.shapes.pill;
    out['--btn-pad-y'] = pad[0];
    out['--btn-pad-x'] = pad[1];
    out['--btn-font-size'] = size[2];
    out['--btn-font'] = recipe.fonts[word('font_role', 'body')] || recipe.fonts.body;
    out['--btn-weight'] = recipe.weights[word('font_weight', 'bold')] || recipe.weights.bold;
    out['--btn-case'] = flag('uppercase') ? 'uppercase' : 'none';
    out['--btn-tracking'] = flag('uppercase') ? '0.08em' : '0.01em';
    out['--btn-decoration'] = isText && flag('underline') ? 'underline' : 'none';

    var shadow = isText ? 'none' : (recipe.shadows[word('shadow', 'none')] || 'none');
    out['--btn-shadow'] = shadow;
    out['--btn-gap'] = recipe.gaps[word('icon_gap', 'normal')] || recipe.gaps.normal;

    var hover = recipe.hovers[word('hover_effect', 'none')] || recipe.hovers.none;
    out['--btn-hover-lift'] = hover[0];
    out['--btn-hover-shadow'] = hover[1] || shadow;
    out['--btn-hover-filter'] = hover[2];
    var hoverFill = colour('hover_fill_color');
    var hoverText = colour('hover_text_color');
    var hoverBorder = colour('hover_border_color');
    out['--btn-hover-bg'] = hoverFill ? css(hoverFill) : 'var(--btn-bg)';
    out['--btn-hover-fg'] = hoverText ? textCss(hoverText) : 'var(--btn-fg)';
    out['--btn-hover-border-color'] = !isText && hoverBorder ? css(hoverBorder) : 'var(--btn-border-color)';

    var icon = recipe.icons[word('icon', 'none')] || recipe.icons.none;
    var before = icon.mask && word('icon_position', 'after') === 'before';
    out['--btn-icon'] = icon.mask || 'none';
    out['--btn-icon-before'] = icon.mask && before ? '""' : 'none';
    out['--btn-icon-after'] = icon.mask && !before ? '""' : 'none';
    var direction = icon.mask && flag('icon_motion') ? icon.direction : 0;
    out['--btn-icon-shift'] = direction === 0 ? '0px' : (direction < 0 ? '-' : '') + recipe.iconShift;

    return out;
  }

  function samples() {
    try {
      var doc = frame && frame.contentDocument;
      return doc ? doc.querySelectorAll('[data-button-sample]') : [];
    } catch (e) {
      return [];
    }
  }

  function paint() {
    var list = samples();
    if (!list.length) {
      return;
    }
    var props = declarations();
    Array.prototype.forEach.call(list, function (sample) {
      Object.keys(props).forEach(function (property) {
        sample.style.setProperty(property, props[property]);
      });
    });
  }

  /** Only the fields that do something for this appearance; a fixed colour's input only with "Vaste kleur". */
  function showRelevant() {
    var appearance = word('appearance', 'filled');
    form.querySelectorAll('[data-button-when]').forEach(function (group) {
      group.hidden = group.getAttribute('data-button-when').split(' ').indexOf(appearance) === -1;
    });
    form.querySelectorAll('[data-button-color]').forEach(function (field) {
      var select = field.querySelector('select');
      var custom = field.querySelector('[data-button-color-custom]');
      if (select && custom) {
        custom.hidden = select.value !== 'custom';
      }
    });
  }

  function update() {
    showRelevant();
    paint();
  }

  form.addEventListener('input', update);
  form.addEventListener('change', update);
  if (frame) {
    frame.addEventListener('load', paint);
  }
  update();
})();
