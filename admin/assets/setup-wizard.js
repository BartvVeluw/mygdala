/**
 * admin/setup.php only: shows the wizard one step at a time.
 *
 * PROGRESSIVE ENHANCEMENT, and it matters here more than usual. The page is
 * ONE form that posts once, at the end — that is what lets the server
 * validate everything before it writes anything. Without JavaScript every
 * step is simply visible at once and the same single submit finishes the
 * job; this file only hides the panels the reader is not on yet and moves
 * between them. It never submits, never stores anything, and never changes
 * a value.
 *
 * The module checkboxes get one extra courtesy: a module that depends on
 * another cannot be ticked while its dependency is not. The server enforces
 * that anyway (App\Install\SetupWizard) — this is the explanation, not the
 * rule.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-setup-form]');
  if (!form) {
    return;
  }

  var panels = Array.prototype.slice.call(form.querySelectorAll('[data-setup-panel]'));
  var labels = Array.prototype.slice.call(document.querySelectorAll('[data-setup-step-label]'));
  var previousButton = form.querySelector('[data-setup-prev]');
  var nextButton = form.querySelector('[data-setup-next]');
  var finishButton = form.querySelector('[data-setup-finish]');

  if (panels.length < 2 || !previousButton || !nextButton || !finishButton) {
    return;
  }

  var current = 0;

  function show(index) {
    current = Math.max(0, Math.min(index, panels.length - 1));

    panels.forEach(function (panel, i) {
      panel.hidden = i !== current;
    });

    labels.forEach(function (label, i) {
      label.classList.toggle('is-current', i === current);
      label.classList.toggle('is-done', i < current);
    });

    previousButton.hidden = current === 0;
    nextButton.hidden = current === panels.length - 1;
    finishButton.hidden = current !== panels.length - 1;

    // A step the reader has just been moved to should be at the top of the
    // window, not halfway down where the previous step ended.
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /**
   * Only the fields on the CURRENT panel, so step 1's required site name is
   * checked when the reader leaves step 1 rather than at the very end.
   */
  function currentPanelIsValid() {
    var panel = panels[current];
    var fields = Array.prototype.slice.call(panel.querySelectorAll('input, select, textarea'));

    for (var i = 0; i < fields.length; i++) {
      if (typeof fields[i].checkValidity === 'function' && !fields[i].checkValidity()) {
        if (typeof fields[i].reportValidity === 'function') {
          fields[i].reportValidity();
        }
        return false;
      }
    }

    return true;
  }

  nextButton.addEventListener('click', function () {
    if (currentPanelIsValid()) {
      show(current + 1);
    }
  });

  previousButton.addEventListener('click', function () {
    show(current - 1);
  });

  // Enter in a text field would otherwise submit the whole wizard from step
  // one. On the last step it still does, which is what the reader expects.
  form.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || current === panels.length - 1) {
      return;
    }

    var target = event.target;
    if (target && target.tagName === 'INPUT' && target.type !== 'checkbox') {
      event.preventDefault();
      nextButton.click();
    }
  });

  var moduleBoxes = Array.prototype.slice.call(form.querySelectorAll('[data-setup-module]'));

  function moduleBox(key) {
    for (var i = 0; i < moduleBoxes.length; i++) {
      if (moduleBoxes[i].getAttribute('data-setup-module') === key) {
        return moduleBoxes[i];
      }
    }
    return null;
  }

  function applyDependencies() {
    moduleBoxes.forEach(function (box) {
      var requires = (box.getAttribute('data-setup-module-requires') || '').split(' ').filter(Boolean);
      if (requires.length === 0 || box.disabled) {
        return;
      }

      var satisfied = requires.every(function (key) {
        var dependency = moduleBox(key);
        // A dependency the environment pins is rendered disabled with its
        // real state already checked, so `checked` is the right question
        // either way.
        return !dependency || dependency.checked;
      });

      box.disabled = !satisfied;
      if (!satisfied) {
        box.checked = false;
      }
    });
  }

  moduleBoxes.forEach(function (box) {
    box.addEventListener('change', applyDependencies);
  });

  applyDependencies();
  show(0);
})();
