/*
 * The Updates screen's step runner (admin/updates.php).
 *
 * Owner: admin/updates.php, and nothing else loads it.
 *
 * The server's state decides everything; this script only keeps asking for
 * the next step while an update runs: POST api/admin/updates-step.php with the
 * update id and the step the server last named, show the answer, repeat.
 * When the update is no longer running the page reloads and shows how it
 * ended. A lost connection or a closed tab loses nothing — the step that was
 * next is still next — so on an error the script stops and offers Doorgaan
 * instead of guessing.
 *
 * NOT in here: any decision about what to do next, any path or version, any
 * retry of a step that failed. Without JavaScript the Doorgaan button posts
 * the same form and the page shows the next step after each click.
 */
(function () {
  'use strict';

  var runner = document.querySelector('[data-update-runner]');
  if (!runner) {
    return;
  }

  var endpoint = runner.getAttribute('data-endpoint');
  var csrf = runner.getAttribute('data-csrf');
  var updateId = runner.getAttribute('data-update-id');
  var step = runner.getAttribute('data-step');
  var messageBox = runner.querySelector('[data-update-message]');
  var continueForm = runner.querySelector('[data-update-continue]');
  var interrupted = runner.querySelector('[data-update-interrupted]');
  var busy = false;

  function say(text, tone) {
    if (!messageBox) {
      return;
    }
    messageBox.textContent = text || '';
    messageBox.className = tone ? 'admin-alert admin-alert--' + tone : 'admin-text-muted';
  }

  function markSteps(current) {
    var items = runner.querySelectorAll('[data-step-name]');
    var passed = true;
    for (var i = 0; i < items.length; i++) {
      var name = items[i].getAttribute('data-step-name');
      var state = name === current ? 'current' : (passed ? 'done' : 'pending');
      if (name === current) {
        passed = false;
      }
      items[i].className = 'admin-update-steps__item is-' + state;
    }
  }

  function setBusy(value) {
    busy = value;
    var buttons = runner.querySelectorAll('button');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].disabled = value;
    }
    runner.setAttribute('aria-busy', value ? 'true' : 'false');
  }

  function stop(text) {
    setBusy(false);
    say(text, 'warning');
  }

  function next() {
    if (busy) {
      return;
    }
    setBusy(true);
    if (interrupted) {
      interrupted.hidden = true;
    }
    markSteps(step);
    say(runner.getAttribute('data-text-working'), '');

    var body = new FormData();
    body.append('csrf_token', csrf);
    body.append('update_id', updateId);
    body.append('step', step);

    fetch(endpoint, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().then(function (data) {
        return { status: response.status, data: data };
      }, function () {
        return { status: response.status, data: null };
      });
    }).then(function (result) {
      var data = result.data;

      if (!data) {
        stop(runner.getAttribute('data-text-connection'));
        return;
      }

      if (result.status === 423) {
        // Another request is running a step right now; ask again shortly.
        setBusy(false);
        say(runner.getAttribute('data-text-busy'), '');
        window.setTimeout(next, 3000);
        return;
      }

      if (!data.running) {
        window.location.href = '/admin/updates.php';
        return;
      }

      if (data.update_id !== updateId) {
        window.location.href = '/admin/updates.php';
        return;
      }

      // 409 means the server is further than this page knew (another tab,
      // a retried request): carry on from where the SERVER says it is.
      step = data.step;
      if (continueForm) {
        continueForm.querySelector('input[name="step"]').value = step;
      }
      markSteps(step);

      if (result.status >= 500 && data.error) {
        stop(data.error);
        return;
      }

      say(data.message || data.step_label, data.message ? 'warning' : '');
      setBusy(false);
      window.setTimeout(next, Math.max(300, data.delay_ms || 0));
    }).catch(function () {
      stop(runner.getAttribute('data-text-connection'));
    });
  }

  if (continueForm) {
    continueForm.addEventListener('submit', function (event) {
      event.preventDefault();
      next();
    });
  }

  if (runner.getAttribute('data-autorun') === '1') {
    next();
  }
})();
