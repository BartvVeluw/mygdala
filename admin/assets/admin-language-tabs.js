/* =========================================================================
   Language tabs on a content editor (Multilingual V1, see MULTILINGUAL.md)

   Switches every localized field on a screen at once. There is exactly one
   tab strip per form, not one per field: a screen carrying eight tab strips
   is harder to read than the two columns this replaced.

   Two things here are not decoration.

   1. `required` MOVES WITH THE VISIBLE PANE. A control that is `required`,
      empty and inside a `hidden` element makes the browser refuse to submit
      and then fail to focus the offending field — the editor gets "an
      invalid form control is not focusable" in the console and a form that
      silently will not save. So a pane that is hidden has its `required`
      attributes lifted, and gets them back when it is shown again. Server-
      side validation is unchanged and is still the real boundary.

   2. THE UNTRANSLATED DOT is computed from the fields themselves. A tab
      whose pane holds an empty field that has content in the primary
      language shows a dot, so an editor can see there is work behind a tab
      they have not opened.

   Vanilla JS, no build step, same as the rest of admin/assets.
   ========================================================================= */
(function () {
  "use strict";

  var STORAGE_KEY = "mygdala-admin-lang";

  function panesFor(strip) {
    var scope = strip.closest("form") || document;
    return Array.prototype.slice.call(scope.querySelectorAll("[data-lang-pane]"));
  }

  /* A pane the site does not publish at all stays hidden whatever the tabs
     do — it only exists so its stored value keeps being submitted. */
  function isSelectable(pane) {
    return pane.getAttribute("data-lang-disabled") !== "1";
  }

  function controlsIn(pane) {
    return Array.prototype.slice.call(pane.querySelectorAll("input, select, textarea"));
  }

  function suspendRequired(pane) {
    controlsIn(pane).forEach(function (control) {
      if (control.required) {
        control.required = false;
        control.setAttribute("data-lang-was-required", "1");
      }
    });
  }

  function restoreRequired(pane) {
    controlsIn(pane).forEach(function (control) {
      if (control.getAttribute("data-lang-was-required") === "1") {
        control.required = true;
        control.removeAttribute("data-lang-was-required");
      }
    });
  }

  function show(pane) {
    pane.hidden = false;
    restoreRequired(pane);
  }

  function hide(pane) {
    suspendRequired(pane);
    pane.hidden = true;
  }

  function activate(strip, code, remember) {
    var panes = panesFor(strip);

    panes.forEach(function (pane) {
      if (!isSelectable(pane)) {
        hide(pane);
        return;
      }

      if (pane.getAttribute("data-lang-pane") === code) {
        show(pane);
      } else {
        hide(pane);
      }
    });

    Array.prototype.slice.call(strip.querySelectorAll("[data-lang-tab]")).forEach(function (tab) {
      var isActive = tab.getAttribute("data-lang-tab") === code;
      tab.classList.toggle("is-active", isActive);
      tab.setAttribute("aria-selected", isActive ? "true" : "false");
      tab.tabIndex = isActive ? 0 : -1;
    });

    if (remember) {
      try { sessionStorage.setItem(STORAGE_KEY, code); } catch (e) {}
    }
  }

  /* A dot on a tab whose language is missing something the primary language
     has. Purely informational — nothing depends on it being exact. */
  function refreshBadges(strip) {
    var panes = panesFor(strip);
    var primary = strip.getAttribute("data-lang-primary") || "";

    var filled = {};
    panes.forEach(function (pane) {
      var code = pane.getAttribute("data-lang-pane");
      filled[code] = filled[code] || {};
      controlsIn(pane).forEach(function (control) {
        if (!control.name) return;
        var base = control.name.replace(/_(nl|en)$/, "");
        filled[code][base] = String(control.value || "").trim() !== "";
      });
    });

    Array.prototype.slice.call(strip.querySelectorAll("[data-lang-tab]")).forEach(function (tab) {
      var code = tab.getAttribute("data-lang-tab");
      var badge = tab.querySelector("[data-lang-untranslated]");
      if (!badge || code === primary) return;

      var missing = Object.keys(filled[primary] || {}).some(function (base) {
        return filled[primary][base] && !(filled[code] || {})[base];
      });

      badge.hidden = !missing;
    });
  }

  function initStrip(strip) {
    var tabs = Array.prototype.slice.call(strip.querySelectorAll("[data-lang-tab]"));
    if (tabs.length < 2) return;

    var codes = tabs.map(function (tab) { return tab.getAttribute("data-lang-tab"); });
    strip.setAttribute("data-lang-primary", codes[0]);

    var remembered = null;
    try { remembered = sessionStorage.getItem(STORAGE_KEY); } catch (e) {}

    activate(strip, codes.indexOf(remembered) > -1 ? remembered : codes[0], false);
    refreshBadges(strip);

    strip.addEventListener("click", function (event) {
      var tab = event.target.closest("[data-lang-tab]");
      if (!tab) return;
      activate(strip, tab.getAttribute("data-lang-tab"), true);
    });

    /* Arrow keys, Home and End — the same keyboard contract as
       admin/assets/admin-tabs.js, so the two feel like one CMS. */
    strip.addEventListener("keydown", function (event) {
      var current = codes.indexOf(document.activeElement.getAttribute && document.activeElement.getAttribute("data-lang-tab"));
      if (current < 0) return;

      var next = null;
      if (event.key === "ArrowRight") next = (current + 1) % codes.length;
      else if (event.key === "ArrowLeft") next = (current - 1 + codes.length) % codes.length;
      else if (event.key === "Home") next = 0;
      else if (event.key === "End") next = codes.length - 1;
      if (next === null) return;

      event.preventDefault();
      activate(strip, codes[next], true);
      tabs[next].focus();
    });

    var form = strip.closest("form");
    if (form) {
      form.addEventListener("input", function () { refreshBadges(strip); });
    }
  }

  /* -----------------------------------------------------------------------
     Automatic translation (Multilingual V1, MULTILINGUAL.md)

     Fills the secondary language's fields from the primary one's and stops
     there. It does NOT save: the editor reads what came back, changes what
     they want, and presses their form's own Save button. There is one write
     path into a content table in this CMS and this is not it.

     Two rules the server also enforces, mirrored here so the editor learns
     about them before they wait for a round trip rather than after:

       - a field a PERSON translated is not replaced without an explicit
         confirmation. The server refuses it too (TranslationState::
         mayOverwrite), so a tampered request gains nothing.
       - only fields belonging to this form are sent.
     ----------------------------------------------------------------------- */

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

  function collectFields(form, primary, target) {
    var sources = {};
    var targets = {};

    Array.prototype.slice.call(form.querySelectorAll("[data-lang-pane] [name]")).forEach(function (control) {
      var pane = control.closest("[data-lang-pane]");
      var lang = pane.getAttribute("data-lang-pane");
      var base = baseName(control.name);

      if (lang === primary) sources[base] = control;
      else if (lang === target) targets[base] = control;
    });

    return { sources: sources, targets: targets };
  }

  function setValue(control, value) {
    control.value = value;
    /* The same event the media picker and the rich-text editor fire, so the
       save bar notices there are unsaved changes. */
    control.dispatchEvent(new Event("input", { bubbles: true }));
    control.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function initTranslate(bar) {
    var form = bar.closest("form");
    if (!form) return;

    var status = bar.querySelector(".admin-lang-translate__status");
    var strip = form.querySelector(".admin-lang-tabs");
    var primary = strip ? strip.getAttribute("data-lang-primary") : null;

    bar.addEventListener("click", function (event) {
      var button = event.target.closest("[data-translate-target]");
      if (!button) return;

      var target = button.getAttribute("data-translate-target");
      if (!primary) primary = strip && strip.getAttribute("data-lang-primary");
      if (!primary || target === primary) return;

      var pairs = collectFields(form, primary, target);
      var body = new URLSearchParams();
      var csrf = form.querySelector('[name="csrf_token"]');

      body.set("csrf_token", csrf ? csrf.value : "");
      body.set("target_language", target);
      body.set("entity_type", bar.getAttribute("data-entity-type") || "");
      body.set("entity_key", bar.getAttribute("data-entity-key") || "");

      var sent = 0;
      Object.keys(pairs.sources).forEach(function (base) {
        var source = pairs.sources[base];
        var existing = pairs.targets[base];
        if (!existing) return;
        if (String(source.value || "").trim() === "") return;

        body.set("fields[" + base + "][source]", source.value);
        body.set("fields[" + base + "][existing]", existing.value || "");
        body.set("fields[" + base + "][html]", isHtmlField(source) ? "1" : "0");
        sent++;
      });

      if (!sent) return;

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
            message += " (" + manual.length + "\u00d7 " + (bar.getAttribute("data-manual-label") || "manual") + ")";
          }
          if (status) status.textContent = filled ? message : (bar.getAttribute("data-nothing-label") || message);

          /* Show the editor what just changed rather than leaving the new
             text behind a tab they are not looking at. */
          if (filled && strip) activate(strip, target, true);
          if (strip) refreshBadges(strip);
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
    Array.prototype.slice.call(document.querySelectorAll(".admin-lang-tabs")).forEach(initStrip);
    Array.prototype.slice.call(document.querySelectorAll("[data-lang-translate]")).forEach(initTranslate);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
