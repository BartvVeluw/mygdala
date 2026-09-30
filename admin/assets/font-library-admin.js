/**
 * The Font Library's family editor (admin/font-family.php): one row per
 * chosen font file, each with its own variant choice and a line of text in
 * that very file, before anything is uploaded. See THEMING.md, "Font
 * Library".
 *
 * WHAT IT DOES, per file input marked [data-font-files]:
 *   - replaces the single fallback variant choice (one file, one variant;
 *     what the form does without this script) by a row per file, in the
 *     order the browser sends the files, so variants[] lines up with
 *     font_files[] on the server;
 *   - guesses the variant from the file name ("Roboto-SemiBoldItalic.ttf"
 *     is Halfvet cursief); the administrator can change every guess;
 *   - loads the file into the browser as a FontFace (from its bytes, no
 *     URL, no request) and shows a sample in it. A file the browser cannot
 *     read as a font says so right there. The server still checks every
 *     file; this is only an early, friendly answer;
 *   - on a new family: fills an empty name from the first file name, and
 *     shows the whole preview in the Regular (or the first) file.
 *
 * Without this script the screen still works: one file, one variant.
 */
(function () {
  'use strict';

  var editor = document.querySelector('[data-font-editor]');
  if (!editor || typeof window.FontFace !== 'function') {
    return;
  }

  var maxBytes = parseInt(editor.getAttribute('data-font-max-bytes'), 10) || 0;
  var EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'];

  // Longest words first, so "ExtraBold" is not read as "Bold".
  var WEIGHT_WORDS = [
    ['extralight', 200], ['ultralight', 200], ['semibold', 600], ['demibold', 600],
    ['extrabold', 800], ['ultrabold', 800], ['hairline', 100], ['regular', 400],
    ['medium', 500], ['normal', 400], ['light', 300], ['black', 900], ['heavy', 900],
    ['thin', 100], ['bold', 700], ['book', 400]
  ];

  var loaded = 0;

  function guessVariant(fileName) {
    var base = fileName.replace(/\.[^.]+$/, '').toLowerCase().replace(/[\s_-]+/g, '');
    var weight = 400;
    for (var i = 0; i < WEIGHT_WORDS.length; i++) {
      if (base.indexOf(WEIGHT_WORDS[i][0]) !== -1) {
        weight = WEIGHT_WORDS[i][1];
        break;
      }
    }
    var italic = /italic|oblique/.test(base);
    return String(weight) + (italic ? '-italic' : '');
  }

  function guessName(fileName) {
    var base = fileName.replace(/\.[^.]+$/, '').split(/[-_]/)[0] || '';
    // "OpenSans" reads as "Open Sans".
    return base.replace(/([a-z])([A-Z])/g, '$1 $2').trim().slice(0, 80);
  }

  function extensionOf(fileName) {
    var match = /\.([a-z0-9]+)$/i.exec(fileName);
    return match ? match[1].toLowerCase() : '';
  }

  function note(row, text) {
    var message = row.querySelector('[data-font-upload-note]');
    message.textContent = text;
    message.hidden = text === '';
    row.classList.toggle('is-refused', text !== '');
  }

  function applyVariant(sample, key) {
    var parts = /^(\d00)(-italic)?$/.exec(key || '');
    sample.style.fontWeight = parts ? parts[1] : '';
    sample.style.fontStyle = parts && parts[2] ? 'italic' : 'normal';
  }

  function preview(file, row, onReady) {
    var sample = row.querySelector('[data-font-upload-sample]');
    var name = 'mygdala-local-' + (++loaded);

    file.arrayBuffer().then(function (buffer) {
      var face = new FontFace(name, buffer);
      return face.load();
    }).then(function (face) {
      document.fonts.add(face);
      sample.style.fontFamily = '"' + name + '", sans-serif';
      if (onReady) {
        onReady(name);
      }
    }).catch(function () {
      note(row, editor.getAttribute('data-font-text-unreadable') || '');
    });
  }

  function enhance(container) {
    var input = container.querySelector('[data-font-files]');
    var rows = container.querySelector('[data-font-upload-rows]');
    var fallback = container.querySelector('[data-font-variant-fallback]');
    var options = container.querySelector('template[data-font-variant-options]');
    if (!input || !rows || !fallback || !options) {
      return;
    }

    var form = input.form;
    var nameField = form ? form.querySelector('[data-font-name]') : null;
    var formPreview = form ? form.querySelector('[data-font-preview]') : null;

    input.addEventListener('change', function () {
      rows.innerHTML = '';
      var files = Array.prototype.slice.call(input.files || []);

      // Without a file the fallback choice stays; with files every file
      // gets its own, and the fallback is not sent.
      fallback.hidden = files.length > 0;
      fallback.querySelector('select').disabled = files.length > 0;
      rows.hidden = files.length === 0;

      if (files.length > 0 && nameField && nameField.value.trim() === '') {
        nameField.value = guessName(files[0].name);
      }

      var previewDone = false;
      var regularIndex = -1;
      files.forEach(function (file, index) {
        if (regularIndex === -1 && guessVariant(file.name) === '400') {
          regularIndex = index;
        }
      });

      files.forEach(function (file, index) {
        var row = document.createElement('li');
        row.className = 'admin-font-upload';
        row.setAttribute('data-font-upload-row', '');

        var label = document.createElement('label');
        label.className = 'admin-font-upload__file';
        label.setAttribute('for', input.id + '-variant-' + index);
        label.textContent = file.name;

        var select = document.createElement('select');
        select.className = 'admin-select';
        select.name = 'variants[]';
        select.id = input.id + '-variant-' + index;
        select.innerHTML = options.innerHTML;
        select.value = guessVariant(file.name);

        var sample = document.createElement('p');
        sample.className = 'admin-font-upload__sample';
        sample.setAttribute('data-font-upload-sample', '');
        sample.textContent = editor.getAttribute('data-font-text-sample') || 'Aa Bb Cc 123';
        applyVariant(sample, select.value);
        select.addEventListener('change', function () {
          applyVariant(sample, select.value);
        });

        var message = document.createElement('p');
        message.className = 'admin-font-upload__note';
        message.setAttribute('data-font-upload-note', '');
        message.setAttribute('role', 'status');
        message.hidden = true;

        row.appendChild(label);
        row.appendChild(select);
        row.appendChild(sample);
        row.appendChild(message);
        rows.appendChild(row);

        if (EXTENSIONS.indexOf(extensionOf(file.name)) === -1) {
          note(row, editor.getAttribute('data-font-text-extension') || '');
          return;
        }

        if (maxBytes > 0 && file.size > maxBytes) {
          note(row, editor.getAttribute('data-font-text-too-large') || '');
          return;
        }

        var drivesPreview = !previewDone && formPreview && (regularIndex === -1 || regularIndex === index);
        if (drivesPreview) {
          previewDone = true;
        }

        preview(file, row, drivesPreview ? function (family) {
          formPreview.style.fontFamily = '"' + family + '", sans-serif';
          var hint = editor.querySelector('[data-font-preview-hint]');
          if (hint) {
            hint.hidden = true;
          }
        } : null);
      });
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-font-uploads]'), enhance);
})();
