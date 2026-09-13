/**
 * The page editor's save/status bar (admin/_save_bar.php).
 *
 * WHAT IT DRIVES. Nothing of its own. These screens are several ordinary POST
 * forms, each with its own api/admin/ endpoint, its own server-side
 * validation and its own PRG redirect. This script watches those forms for
 * edits and, when the bar's "Opslaan" is pressed, submits the ones that were
 * edited THROUGH THAT SAME MECHANISM. There is no page-wide endpoint, no
 * second copy of any validation rule here, and every form's own Opslaan
 * button keeps working untouched.
 *
 * ONE DIRTY FORM IS THE NORMAL CASE, and it is handled by handing the form
 * back to the browser: requestSubmit() does the real submit, so the request,
 * the server's validation and the error page an editor sees on failure are
 * byte for byte what the form's own button produces. Nothing is simulated.
 *
 * SEVERAL DIRTY FORMS can only happen on the repeater editors (the section's
 * own fields plus one of its items, say), and there the browser cannot
 * navigate more than once. Those are posted in order with fetch(), stopping
 * at the first one the server did not accept. A save that fails leaves its
 * form — and every form after it — dirty and the typed values untouched on
 * screen, and the bar says which one failed; it never reports success it did
 * not see.
 *
 * "OPGESLAGEN" IS NEVER A GUESS. The bar goes clean only after the server
 * redirected to its own success address (?saved=1 / ?updated=1 / ?created=1,
 * the marker every endpoint in this project already appends). A failed save
 * redirects back WITHOUT that marker, which is exactly how this tells the two
 * apart.
 *
 * DIRTY IS A FLAG, NOT A DIFF. Any edit inside a watched form marks it dirty
 * until it is saved; typing a character and deleting it again does not clear
 * the flag. That is deliberate: the rich-text editor rewrites its field's
 * HTML the moment it loads, so a value comparison would call an untouched
 * page dirty — the opposite mistake, and the dangerous one.
 */
(function () {
  "use strict";

  var bar = document.querySelector("[data-save-bar]");
  if (!bar) return;

  var statusText = bar.querySelector("[data-save-bar-text]");
  var saveButton = bar.querySelector("[data-save-bar-save]");

  var RELOAD_FLAG = "mygdalaSaveBarSaved";

  var EDITABLE =
    "input:not([type='hidden']):not([type='submit']):not([type='button']), select, textarea";

  /**
   * Every form the bar is responsible for: POST forms inside the page's own
   * <main> that carry at least one control an editor can actually change,
   * minus the one-button hide/move/delete forms and anything that opted out.
   *
   * Scoped to <main> on purpose — the sidebar's logout form is a POST form
   * too, and submitting that from a save button would sign the editor out
   * mid-edit. The "must contain something editable" rule keeps the
   * action-only forms (Verwijderen, and the confirm-guarded ones) out for the
   * same kind of reason: they can never be dirty, so a save must never reach
   * for one.
   */
  var forms = Array.prototype.filter.call(
    document.querySelectorAll("main.admin-main form[method='post'], main.admin-main form[method='POST']"),
    function (form) {
      return (
        !form.classList.contains("admin-inline-form") &&
        !form.hasAttribute("data-no-dirty-track") &&
        form.querySelector("[type='submit'], button:not([type])") !== null &&
        form.querySelector(EDITABLE) !== null
      );
    }
  );

  if (forms.length === 0) return;

  var dirty = [];
  var leavingOnPurpose = false;

  bar.hidden = false;

  /**
   * What the bar calls a form when it has to name one — "Opslaan mislukt bij
   * X" is only useful if X is something the editor can see on screen. In
   * order of how recognisable it is: a name the screen supplied, the form's
   * own heading, what is typed in its first field (which is how a repeated
   * item is told apart from its neighbours: the question, the title, the
   * label), and only then the card it sits in.
   */
  function formName(form) {
    var explicit = form.getAttribute("data-save-name");
    if (explicit) return explicit;

    var own = form.querySelector("h2, h3, h4");
    if (own && own.textContent.trim() !== "") return own.textContent.trim();

    var first = form.querySelector("input[type='text']:not([hidden])");
    if (first && first.value.trim() !== "") {
      var value = first.value.trim();
      return value.length > 48 ? value.slice(0, 48) + "…" : value;
    }

    var card = form.closest("section, article");
    while (card) {
      var heading = card.querySelector("h1, h2, h3, h4");
      if (heading && heading.textContent.trim() !== "") return heading.textContent.trim();
      card = card.parentElement ? card.parentElement.closest("section, article") : null;
    }

    return "dit onderdeel";
  }

  function isDirty(form) {
    return dirty.indexOf(form) !== -1;
  }

  function markDirty(form) {
    if (isDirty(form)) return;
    dirty.push(form);
    render("dirty");
  }

  function markClean(form) {
    var index = dirty.indexOf(form);
    if (index !== -1) dirty.splice(index, 1);
  }

  /* The bar's words, put on the element by admin/_save_bar.php in the CMS
     interface language of whoever is signed in. The Dutch fallback is what
     this file used to say outright, so a bar rendered by an older template
     still reads correctly. */
  function label(name, fallback) {
    return bar.getAttribute("data-label-" + name) || fallback;
  }

  function render(state, message) {
    bar.setAttribute("data-save-bar-state", state);
    saveButton.disabled = state === "saved" || state === "saving";

    if (message) {
      statusText.textContent = message;
      return;
    }

    if (state === "saving") {
      statusText.textContent = label("saving", "Opslaan\u2026");
    } else if (state === "dirty") {
      statusText.textContent = label("dirty", "Niet-opgeslagen wijzigingen");
    } else if (state === "error") {
      statusText.textContent = label("error", "Opslaan mislukt");
    } else {
      statusText.textContent = label("saved", "Alles opgeslagen");
    }
  }

  /**
   * The address an endpoint redirects to when it accepted the data. Every
   * write endpoint in this project appends one of these markers on success
   * and redirects back without it on failure, so this is the server's own
   * answer rather than a guess about what a 200 means.
   */
  function wasAccepted(response) {
    return response.ok && /[?&](saved|updated|created)=1(&|$)/.test(response.url);
  }

  function saveSequentially(queue, index) {
    if (index >= queue.length) {
      try {
        sessionStorage.setItem(RELOAD_FLAG, "1");
      } catch (e) {}
      leavingOnPurpose = true;
      window.location.reload();
      return;
    }

    var form = queue[index];

    fetch(form.action, {
      method: "POST",
      credentials: "same-origin",
      body: new FormData(form),
    })
      .then(function (response) {
        if (!wasAccepted(response)) {
          throw new Error("rejected");
        }

        markClean(form);
        saveSequentially(queue, index + 1);
      })
      .catch(function () {
        // This form and everything after it stay dirty, and the page is not
        // reloaded: whatever was typed is still on screen and still sendable
        // with that form's own Opslaan button, which shows the server's real
        // error message.
        render(
          "error",
          label("error-in", "Opslaan mislukt bij :form.").replace(":form", formName(form))
        );
      });
  }

  function saveAll() {
    var queue = forms.filter(isDirty);
    if (queue.length === 0) return;

    if (queue.length === 1) {
      // The ordinary case: let the browser submit the form exactly as its own
      // button would. render("saving") happens in the submit listener below,
      // so a form the browser refuses on its own required-field check leaves
      // the bar honestly on "Niet-opgeslagen wijzigingen".
      queue[0].requestSubmit();
      return;
    }

    for (var i = 0; i < queue.length; i++) {
      var valid = true;
      try {
        valid = queue[i].reportValidity();
      } catch (e) {
        // A form with a required field inside a hidden panel cannot be
        // focused to be reported on; let the server be the judge, as it
        // always is anyway.
        valid = true;
      }

      if (!valid) {
        render("error", "Controleer de gemarkeerde velden bij “" + formName(queue[i]) + "”.");
        return;
      }
    }

    render("saving");
    saveSequentially(queue, 0);
  }

  forms.forEach(function (form) {
    form.addEventListener("submit", function () {
      // Covers the bar's own button AND the form's own Opslaan. The
      // leave-warning stands down only when this form was the last unsaved
      // one: submitting one form while another still holds typed changes IS
      // a navigation worth warning about, and that is precisely the case the
      // per-form buttons used to lose silently.
      var othersDirty = dirty.filter(function (other) {
        return other !== form;
      });

      leavingOnPurpose = othersDirty.length === 0;
      render("saving");
    });
  });

  /**
   * Any edit inside a watched form marks it dirty. Both events are needed:
   * "input" for typing, "change" for selects, checkboxes, radios, file
   * inputs and the media picker, which dispatches a bubbling "change" when it
   * fills its hidden field. The rich-text editor dispatches "input" on its
   * own textarea for user edits only (admin/assets/admin.js).
   */
  ["input", "change"].forEach(function (type) {
    document.addEventListener(type, function (event) {
      var form = event.target && event.target.closest ? event.target.closest("form") : null;
      if (form && forms.indexOf(form) !== -1) {
        markDirty(form);
      }
    });
  });

  saveButton.addEventListener("click", saveAll);

  window.addEventListener("beforeunload", function (event) {
    if (leavingOnPurpose || dirty.length === 0) return;

    // Only while something is genuinely unsaved: a browser that is warned
    // about every navigation is a browser nobody reads the warning of.
    event.preventDefault();
    event.returnValue = "";
    return "";
  });

  try {
    if (sessionStorage.getItem(RELOAD_FLAG)) {
      sessionStorage.removeItem(RELOAD_FLAG);
      render("saved", label("just-saved", "Opgeslagen"));
      window.setTimeout(function () {
        if (dirty.length === 0) render("saved");
      }, 2500);
    }
  } catch (e) {}
})();
