/**
 * The "Paginathema" choice on the page editor (admin/_page_theme_field.php):
 * redraws the small swatch next to the select when another theme is chosen.
 * The colours are the option's own data-swatches, written by the server from
 * validated values; nothing is fetched and nothing is saved here — the page
 * form does that. Without this script the swatch shows the stored choice.
 */
(function () {
  'use strict';

  var HEX = /^#[0-9A-F]{6}$/;

  document.querySelectorAll('[data-page-theme-field]').forEach(function (field) {
    var select = field.querySelector('select');
    var swatches = field.querySelector('[data-page-theme-swatches]');
    if (!select || !swatches) {
      return;
    }

    select.addEventListener('change', function () {
      var option = select.options[select.selectedIndex];
      var colors = String((option && option.getAttribute('data-swatches')) || '').split(' ');

      swatches.textContent = '';
      colors.forEach(function (color) {
        if (!HEX.test(color)) {
          return;
        }
        var swatch = document.createElement('span');
        swatch.className = 'admin-page-theme-swatch';
        swatch.style.background = color;
        swatches.appendChild(swatch);
      });
    });
  });
})();
