/**
 * "Bovenliggende pagina" in the page editor and on Nieuwe pagina
 * (admin/_page_placement.php, docs/pages/NESTING.md).
 *
 * Two things, both only on screen — nothing is saved to show them:
 *
 *   - the address line follows the chosen parent and the slug field: the
 *     parent's path in the language being edited (data-page-path on its
 *     option), then the slug, under that language's base URL. A parent with
 *     no address in this language means the page has none either, and the
 *     line says so instead of inventing one;
 *   - the admin group is offered for a root page only; under a parent the
 *     line "volgt de beheergroep van" names that tree's group.
 *
 * The slug is cleaned the way App\Service\PageService::sanitizeSlug() cleans
 * it on the server, so the preview shows the address the save will make.
 * Without this script the form works the same; the line then shows the
 * stored address.
 */
(function () {
  'use strict';

  function sanitize(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 170);
  }

  function init(root) {
    var select = root.querySelector('[data-page-parent]');
    if (!select) {
      return;
    }

    var form = select.form;
    var base = root.getAttribute('data-url-base') || '/';
    var preview = root.querySelector('[data-page-path-preview]');
    var none = root.querySelector('[data-page-path-none]');
    var groupChoice = root.querySelector('[data-page-group-choice]');
    var groupFollows = root.querySelector('[data-page-group-follows]');
    var groupName = root.querySelector('[data-page-group-follows-name]');
    var slugField = form ? form.querySelector('input[name="slug"]') : null;
    var foreignBase = form ? form.querySelector('[data-page-path-base]') : null;
    var initialSlug = slugField ? slugField.value : '';

    function update() {
      var option = select.options[select.selectedIndex];
      var isRoot = !option || option.value === '0';

      if (groupChoice) {
        groupChoice.hidden = !isRoot;
      }
      if (groupFollows) {
        groupFollows.hidden = isRoot;
      }
      if (groupName && option) {
        groupName.textContent = option.getAttribute('data-page-group') || '';
      }

      var slug = sanitize(slugField ? slugField.value : initialSlug);
      var missing = option && option.hasAttribute('data-page-path-missing');
      var parentPath = option ? (option.getAttribute('data-page-path') || '') : '';

      // Nieuwe pagina's own preview line: only its base moves along.
      if (foreignBase) {
        foreignBase.textContent = base + (parentPath !== '' ? parentPath + '/' : '');
      }

      if (!preview || !none) {
        return;
      }

      if (slug === '' || missing) {
        preview.hidden = true;
        none.hidden = false;
        return;
      }

      preview.textContent = base + (parentPath !== '' ? parentPath + '/' : '') + slug;
      preview.hidden = false;
      none.hidden = true;
    }

    select.addEventListener('change', update);
    // On the whole form: Nieuwe pagina fills the slug in from the title
    // (admin.js), and that change arrives as the title's input event.
    if (form) {
      form.addEventListener('input', update);
    }
    update();
  }

  document.querySelectorAll('[data-page-placement]').forEach(init);
})();
