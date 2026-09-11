/* =========================================================================
   Automatic translation on a content editor (MULTILINGUAL.md)

   WHAT THIS FILE IS NOT ANY MORE. It used to switch per-form language tabs.
   There are no tabs: which language version of the content an administrator
   is editing is one global piece of editor state, chosen once in the CMS
   shell and resolved on the SERVER, so every screen renders the right
   language's fields already visible and the other language's fields hidden
   but still submitting. Nothing on this page needs JavaScript to show the
   editor the language they asked for.

   WHAT IS LEFT is the one genuinely interactive thing: filling the language
   you are looking at from the one you already wrote.

   Three rules it mirrors from the server, so an editor learns about them
   before a round trip rather than after:

     - TRANSLATION IS ALWAYS AN EXPLICIT ACTION. Switching editing language
       translates nothing. This runs on a click and on nothing else.
     - A FIELD A PERSON TRANSLATED IS NOT REPLACED without an explicit
       confirmation. The server refuses it too (TranslationState::
       mayOverwrite), so a tampered request gains nothing.
     - ONLY FIELDS BELONGING TO THIS FORM ARE SENT. No ids, no settings, no
       other row.

   IT DOES NOT SAVE. The editor reads what came back, changes what they want,
   and presses their form's own Save button. There is one write path into a
   content table in this CMS and this is not it.

   Vanilla JS, no build step, same as the rest of admin/assets.
   ========================================================================= */
(function () {
  "use strict";

  function baseName(name) {
    return name.replace(/_(nl|en)$/, "");
  }

  /* Rich text is edited by Quill over a hidden <textarea>; the textarea is
     still the field that submits, and it holds HTML. The provider has to be
     told, or it translates the tags as words. */
  function isHtmlField(control) {
    return control.tagName === "TEXTAREA"
      && !!control.closest(".admin-richtext-field");
  }

  /* The source pane is hidden (the editor is looking at the target language),
     which is exactly why its values still have to be readable here: hidden
     panes keep submitting their stored value, so the words to translate FROM
     are sitting right there in the same form. */
  function collectFields(form, source, target) {
    var sources = {};
    var targets = {};

    Array.prototype.slice.call(form.querySelectorAll("[data-lang-pane] [name]")).forEach(function (control) {
      var pane = control.closest("[data-lang-pane]");
      var lang = pane.getAttribute("data-lang-pane");
      var base = baseName(control.name);

      if (lang === source) sources[base] = control;
      else if (lang === target) targets[base] = control;
    });

    return { sources: sources, targets: targets };
  }

  function setValue(control, value) {
    control.value = value;
    /* The same events the media picker and the rich-text editor fire, so the
       save bar notices there are unsaved changes. */
    control.dispatchEvent(new Event("input", { bubbles: true }));
    control.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function initTranslate(bar) {
    var form = bar.closest("form");
    if (!form) return;

    var status = bar.querySelector(".admin-lang-translate__status");
    var source = bar.getAttribute("data-source-language");
    var target = bar.getAttribute("data-target-language");

    bar.addEventListener("click", function (event) {
      var button = event.target.closest("[data-translate-target]");
      if (!button) return;
      if (!source || !target || source === target) return;

      var pairs = collectFields(form, source, target);
      var body = new URLSearchParams();
      var csrf = form.querySelector('[name="csrf_token"]');

      body.set("csrf_token", csrf ? csrf.value : "");
      body.set("source_language", source);
      body.set("target_language", target);
      body.set("entity_type", bar.getAttribute("data-entity-type") || "");
      body.set("entity_key", bar.getAttribute("data-entity-key") || "");

      var sent = 0;
      Object.keys(pairs.sources).forEach(function (base) {
        var from = pairs.sources[base];
        var existing = pairs.targets[base];
        if (!existing) return;
        if (String(from.value || "").trim() === "") return;

        body.set("fields[" + base + "][source]", from.value);
        body.set("fields[" + base + "][existing]", existing.value || "");
        body.set("fields[" + base + "][html]", isHtmlField(from) ? "1" : "0");
        sent++;
      });

      if (!sent) {
        if (status) status.textContent = bar.getAttribute("data-nothing-label") || "";
        return;
      }

      button.disabled = true;
      if (status) status.textContent = bar.getAttribute("data-busy-label") || "";

      fetch(bar.getAttribute("data-endpoint"), {
        method: "POST",
        body: body,
        credentials: "same-origin",
        headers: { "X-Requested-With": "fetch" },
      })
        .then(function (response) {
          return response.json().then(function (payload) {
            return { ok: response.ok, payload: payload };
          });
        })
        .then(function (result) {
          if (!result.ok) throw new Error(result.payload && result.payload.error);

          var translations = (result.payload && result.payload.translations) || {};
          var filled = 0;
          Object.keys(translations).forEach(function (base) {
            var control = pairs.targets[base];
            if (!control) return;
            setValue(control, translations[base]);
            filled++;
          });

          var skipped = (result.payload && result.payload.skipped) || {};
          var manual = Object.keys(skipped).filter(function (k) { return skipped[k] === "manual"; });

          var message = bar.getAttribute("data-done-label") || "";
          if (manual.length) {
            message += " (" + manual.length + "× " + (bar.getAttribute("data-manual-label") || "manual") + ")";
          }
          if (status) status.textContent = filled ? message : (bar.getAttribute("data-nothing-label") || message);
        })
        .catch(function (error) {
          if (status) status.textContent = (error && error.message) ? String(error.message) : "";
        })
        .then(function () {
          button.disabled = false;
        });
    });
  }

  function init() {
    Array.prototype.slice.call(document.querySelectorAll("[data-lang-translate]")).forEach(initTranslate);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
