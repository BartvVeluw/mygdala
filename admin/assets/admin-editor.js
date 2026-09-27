/**
 * The dynamic admin editor (admin/_admin_editor.php): ONE form, ONE Opslaan,
 * saved with fetch() and never with a page load (ADMIN-UI.md, "Een editor
 * die opslaat zonder te herladen").
 *
 * WHAT A SCREEN GIVES IT. A `<form data-admin-editor>` whose endpoint speaks
 * the editor contract (App\Service\AdminEditorResponse), the bar, the leave
 * dialog and this script. Everything else is read from the markup:
 *
 *   [data-admin-editor-section="<key>"]  a part of the form an error can name;
 *                                        a <details> one is opened for it
 *   [data-admin-editor-errors="<key>"]   where a section's own messages go
 *   [data-admin-editor-error-for="<n>"]  where a message for field name <n>
 *                                        goes when no control carries that name
 *   [data-admin-editor-region="<key>"]   drawn again from the server after a
 *                                        save (rows that got a database id)
 *   [data-admin-editor-summary]          every message of a refused save
 *
 * THE CONTRACT. The request is the form as FormData with `Accept:
 * application/json`. The answer is `{ok, message, data, errors}`: ok and 200
 * when everything was stored, not ok and 422 when nothing was, with `errors`
 * as `{<field name or section key>: [messages]}`. `data.redirect` sends the
 * browser on (a new item that now has an address of its own). Any other
 * answer — a login that expired, a token that is too old, a server error, no
 * network — is a failed save as well, said in the CMS's words. The editor
 * stays dirty after every failure and nothing typed is lost.
 *
 * AFTER A SAVE the page is asked for again and every region is swapped for
 * the server's own rendering of it, so a row typed on the screen ("new0")
 * carries its database id from then on and the next save updates it instead
 * of creating it twice. The page stays the only place that renders the
 * editor. Scripts that enhance a region hear `admin-editor:replaced` on it.
 * If that request fails, the page is loaded again: what is on screen would
 * no longer be what the next save must send.
 *
 * DIRTY IS A FLAG, NOT A DIFF (the same rule as admin/assets/save-bar.js).
 * Any `input` or `change` inside the form marks it; opening a section, a help
 * text or the media picker fires neither. A script that changes the form
 * without such an event dispatches `admin-editor:change`.
 *
 * LEAVING. A plain click on a link to another page of this site, or a form
 * outside the editor that navigates (the language switch, Uitloggen), asks
 * first in the CMS's own dialog: save and go on, go on without saving, or
 * stay. Everything the browser owns — reload, closing the tab, the address
 * bar, Back — can only get the browser's own question (beforeunload), which
 * no page may style. Both only while something is unsaved.
 */
(function () {
  "use strict";

  var form = document.querySelector("form[data-admin-editor]");
  var bar = document.querySelector("[data-admin-editor-bar]");
  if (!form || !bar || typeof window.fetch !== "function") return;

  var statusText = bar.querySelector("[data-admin-editor-text]");
  var saveButton = bar.querySelector("[data-admin-editor-save]");
  var summary = document.querySelector("[data-admin-editor-summary]");
  var dialog = document.querySelector("[data-admin-editor-leave-dialog]");

  var dirty = false;
  var saving = null;
  var leavingOnPurpose = false;
  var quiet = 0;
  var savedTimer = null;

  /* The words, put on the bar by admin/_admin_editor.php in the CMS language
     of whoever is signed in. */
  function word(name) {
    return bar.getAttribute("data-label-" + name) || "";
  }

  /**
   * The bar in one of its states: saved, dirty, saving, error. The button
   * says what it does ("Opslaan"), what it is doing ("Opslaan…") and, for a
   * moment after a save, what it did ("Opgeslagen").
   */
  function render(state, message, buttonWord) {
    bar.setAttribute("data-save-bar-state", state);
    saveButton.disabled = state === "saved" || state === "saving";
    saveButton.textContent = word(buttonWord || (state === "saving" ? "saving" : "save"));
    statusText.textContent = message || word(state);
  }

  function markDirty() {
    if (quiet > 0 || saving) return;
    window.clearTimeout(savedTimer);
    dirty = true;
    render("dirty");
  }

  function markClean(message) {
    dirty = false;
    window.clearTimeout(savedTimer);
    render("saved", message || word("just-saved"), "just-saved");
    savedTimer = window.setTimeout(function () {
      if (!dirty && !saving) render("saved");
    }, 2500);
  }

  ["input", "change", "admin-editor:change"].forEach(function (type) {
    document.addEventListener(type, function (event) {
      var target = event.target;
      if (target && target.nodeType === 1 && form.contains(target)) markDirty();
    });
  });

  /* ---------------------------------------------------------------------- */
  /* Messages                                                                */
  /* ---------------------------------------------------------------------- */

  function sections() {
    return Array.prototype.slice.call(form.querySelectorAll("[data-admin-editor-section]"));
  }

  function openSection(node) {
    var section = node && node.closest ? node.closest("[data-admin-editor-section]") : null;
    while (section) {
      if (section.tagName === "DETAILS") section.open = true;
      section = section.parentElement ? section.parentElement.closest("[data-admin-editor-section]") : null;
    }
    // A <details> of its own inside the section (a folded row) as well.
    var folded = node && node.closest ? node.closest("details") : null;
    while (folded) {
      folded.open = true;
      folded = folded.parentElement ? folded.parentElement.closest("details") : null;
    }
  }

  function clearMessages() {
    Array.prototype.forEach.call(form.querySelectorAll("[data-admin-editor-message]"), function (node) {
      node.parentNode.removeChild(node);
    });
    Array.prototype.forEach.call(form.querySelectorAll("[aria-invalid='true'][data-admin-editor-invalid]"), function (field) {
      field.removeAttribute("aria-invalid");
      field.removeAttribute("data-admin-editor-invalid");
      var described = (field.getAttribute("aria-describedby") || "").split(" ").filter(function (id) {
        return id.indexOf("admin-editor-message-") !== 0;
      });
      if (described.length) field.setAttribute("aria-describedby", described.join(" "));
      else field.removeAttribute("aria-describedby");
    });
    Array.prototype.forEach.call(form.querySelectorAll("[data-admin-editor-errors]"), function (box) {
      box.hidden = true;
      box.textContent = "";
    });
    if (summary) {
      summary.hidden = true;
      var list = summary.querySelector("[data-admin-editor-summary-list]");
      if (list) list.textContent = "";
    }
  }

  var messageCount = 0;

  function messageElement(text) {
    var p = document.createElement("p");
    p.className = "admin-field-error";
    p.id = "admin-editor-message-" + String(++messageCount);
    p.setAttribute("data-admin-editor-message", "");
    p.textContent = text;
    return p;
  }

  function fieldNamed(name) {
    var escaped = window.CSS && CSS.escape ? CSS.escape(name) : name.replace(/(["\\\]\[])/g, "\\$1");
    return form.querySelector('[name="' + escaped + '"]');
  }

  function slotFor(name) {
    var found = null;
    Array.prototype.some.call(form.querySelectorAll("[data-admin-editor-error-for]"), function (slot) {
      if (slot.getAttribute("data-admin-editor-error-for") === name) found = slot;
      return found !== null;
    });
    return found;
  }

  /** Where a control's message goes: after the label or field block it sits in. */
  function anchorOf(field) {
    if (field.type === "hidden") {
      return field.closest("[data-media-picker], .admin-field, fieldset, [data-admin-editor-section]") || field;
    }
    return field.closest(".admin-field, label, .admin-richtext-field") || field;
  }

  /* A screen with tabs (admin/_admin_tabs.php, the product editor): the tab
     that holds a message is brought forward, or the message would be on a
     hidden panel. Only the FIRST message's tab, so several messages in
     several tabs never make the screen jump between them. */
  function revealTab(node) {
    if (node && window.AdminTabs && typeof window.AdminTabs.reveal === "function") {
      window.AdminTabs.reveal(node);
    }
  }

  function showErrors(errors, message) {
    clearMessages();

    var all = [];
    var first = null;
    var firstPlaced = null;

    Object.keys(errors || {}).forEach(function (key) {
      var messages = [].concat(errors[key]).filter(function (text) { return typeof text === "string" && text !== ""; });
      if (messages.length === 0) return;
      all = all.concat(messages);

      var field = key.charAt(0) === "_" ? null : fieldNamed(key);
      if (field && field.type !== "hidden") {
        var p = messageElement(messages.join(" "));
        anchorOf(field).insertAdjacentElement("afterend", p);
        field.setAttribute("aria-invalid", "true");
        field.setAttribute("data-admin-editor-invalid", "");
        field.setAttribute("aria-describedby", ((field.getAttribute("aria-describedby") || "") + " " + p.id).trim());
        openSection(field);
        if (!first) first = field;
        if (!firstPlaced) firstPlaced = field;
        return;
      }

      var slot = field ? anchorOf(field) : slotFor(key);
      if (slot) {
        var q = messageElement(messages.join(" "));
        slot.insertAdjacentElement(slot.hasAttribute("data-admin-editor-error-for") ? "beforeend" : "afterend", q);
        openSection(slot);
        if (!firstPlaced) firstPlaced = slot;
        return;
      }

      var section = form.querySelector('[data-admin-editor-section="' + key + '"]');
      var box = section ? section.querySelector('[data-admin-editor-errors="' + key + '"]') : null;
      if (box) {
        messages.forEach(function (text) {
          var line = document.createElement("p");
          line.textContent = text;
          box.appendChild(line);
        });
        box.hidden = false;
        openSection(box);
        if (!firstPlaced) firstPlaced = box;
      }
    });

    revealTab(firstPlaced);

    if (summary) {
      var title = summary.querySelector("[data-admin-editor-summary-title]");
      var list = summary.querySelector("[data-admin-editor-summary-list]");
      if (title) title.textContent = message || word("invalid");
      if (list) {
        list.textContent = "";
        all.forEach(function (text) {
          var li = document.createElement("li");
          li.textContent = text;
          list.appendChild(li);
        });
      }
      summary.hidden = false;
      summary.focus({ preventScroll: false });
    } else if (first) {
      first.focus();
    }
  }

  /* ---------------------------------------------------------------------- */
  /* Saving                                                                  */
  /* ---------------------------------------------------------------------- */

  /** A section a browser check fails in is opened first: a closed one cannot show its field. */
  function browserAccepts() {
    var invalid = Array.prototype.filter.call(form.elements, function (field) {
      return typeof field.checkValidity === "function" && !field.disabled && !field.checkValidity();
    });
    if (invalid.length === 0) return true;
    openSection(invalid[0]);
    revealTab(invalid[0]);
    try {
      return form.reportValidity();
    } catch (e) {
      return true;
    }
  }

  /** What went wrong, from an answer that is not the contract's. */
  function failureFor(status) {
    if (status === 401) return word("expired");
    if (status === 403) return word("forbidden");
    return word("failed");
  }

  function readAnswer(response) {
    return response.text().then(function (text) {
      var body = null;
      try {
        body = JSON.parse(text);
      } catch (e) {
        body = null;
      }
      if (!body || typeof body !== "object" || typeof body.ok !== "boolean") {
        return { ok: false, message: failureFor(response.status), errors: {}, data: {}, broken: true };
      }
      return body;
    });
  }

  function remember(region) {
    var open = {};
    Array.prototype.forEach.call(region.querySelectorAll("details[id], details[data-admin-collapse-id]"), function (item) {
      open[item.id || item.getAttribute("data-admin-collapse-id")] = item.open;
    });
    return open;
  }

  function restore(region, open) {
    Array.prototype.forEach.call(region.querySelectorAll("details[id], details[data-admin-collapse-id]"), function (item) {
      var key = item.id || item.getAttribute("data-admin-collapse-id");
      if (Object.prototype.hasOwnProperty.call(open, key)) item.open = open[key];
    });
  }

  /**
   * Draws every region again from the server, in place. The document is
   * asked for at its own address, which renders what is now stored.
   */
  function refreshRegions() {
    var regions = Array.prototype.slice.call(document.querySelectorAll("[data-admin-editor-region]"));
    if (regions.length === 0) return Promise.resolve();

    return fetch(window.location.href, { credentials: "same-origin", headers: { Accept: "text/html" } })
      .then(function (response) {
        if (!response.ok) throw new Error("page");
        return response.text();
      })
      .then(function (html) {
        var fresh = new DOMParser().parseFromString(html, "text/html");
        var pairs = regions.map(function (region) {
          var key = region.getAttribute("data-admin-editor-region");
          var next = fresh.querySelector('[data-admin-editor-region="' + key + '"]');
          if (!next) throw new Error("region " + key);
          return [region, next];
        });

        var scrollY = window.scrollY;
        quiet++;
        try {
          pairs.forEach(function (pair) {
            var open = remember(pair[0]);
            var node = document.importNode(pair[1], true);
            pair[0].parentNode.replaceChild(node, pair[0]);
            restore(node, open);
            node.dispatchEvent(new CustomEvent("admin-editor:replaced", { bubbles: true }));
          });
        } finally {
          quiet--;
        }
        window.scrollTo(window.scrollX, scrollY);
      });
  }

  /**
   * One save of the whole form. Resolves with true when the server stored
   * it, false otherwise; `options.refresh` false skips drawing the regions
   * again (the editor is about to be left).
   */
  function save(options) {
    if (saving) return saving;
    var refresh = !options || options.refresh !== false;
    var follow = !options || options.follow !== false;

    if (!browserAccepts()) {
      render("error", word("check"));
      return Promise.resolve(false);
    }

    var body = new FormData(form);
    var focused = document.activeElement;
    render("saving");
    form.setAttribute("aria-busy", "true");
    // Nothing is typed while the request is on its way: what is sent and
    // what is on screen stay one and the same.
    form.inert = true;

    saving = fetch(form.action, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      body: body
    })
      .then(readAnswer, function () {
        return { ok: false, message: word("offline"), errors: {}, data: {}, broken: true };
      })
      .then(function (answer) {
        if (!answer.ok) {
          if (answer.broken) {
            clearMessages();
            render("error", answer.message);
          } else {
            showErrors(answer.errors || {}, answer.message);
            render("error", answer.message || word("invalid"));
          }
          return false;
        }

        clearMessages();
        var data = answer.data || {};

        if (typeof data.redirect === "string" && data.redirect.charAt(0) === "/" && data.redirect.charAt(1) !== "/") {
          dirty = false;
          leavingOnPurpose = true;
          render("saved", answer.message || "", "just-saved");
          if (follow) window.location.assign(data.redirect);
          return true;
        }

        var done = refresh ? refreshRegions() : Promise.resolve();
        return done.then(function () {
          markClean(answer.message || word("just-saved"));
          form.dispatchEvent(new CustomEvent("admin-editor:saved", { bubbles: true, detail: data }));
          return true;
        }, function () {
          // Stored, but the screen still shows the rows under their old keys;
          // sending those again would create them twice. Load it again.
          dirty = false;
          leavingOnPurpose = true;
          window.location.reload();
          return true;
        });
      })
      .then(function (result) {
        saving = null;
        form.inert = false;
        form.removeAttribute("aria-busy");
        // Back to where the editor was, when that is still on the page and
        // nothing else (the summary of a refused save) took the focus.
        if (focused && focused !== document.body && document.contains(focused) &&
            (document.activeElement === document.body || document.activeElement === null)) {
          focused.focus({ preventScroll: true });
        }
        return result;
      });

    return saving;
  }

  saveButton.addEventListener("click", function () {
    save();
  });

  // Enter in a field, and the form's own button where the script is not
  // wanted: the same save, never the browser's page load.
  form.addEventListener("submit", function (event) {
    event.preventDefault();
    save();
  });

  Array.prototype.forEach.call(form.querySelectorAll("[data-admin-editor-fallback]"), function (button) {
    button.hidden = true;
  });

  /* ---------------------------------------------------------------------- */
  /* Leaving                                                                 */
  /* ---------------------------------------------------------------------- */

  var pendingLeave = null;
  var passThrough = null;

  function closeDialog() {
    if (dialog && dialog.open) dialog.close();
  }

  function setDialogBusy(busy) {
    if (!dialog) return;
    Array.prototype.forEach.call(dialog.querySelectorAll("button"), function (button) {
      button.disabled = busy;
    });
    var go = dialog.querySelector("[data-admin-editor-leave-save]");
    if (go) go.textContent = busy ? word("saving") : go.getAttribute("data-label");
  }

  function answer(value) {
    var request = pendingLeave;
    if (!request) return;

    if (value === "save") {
      setDialogBusy(true);
      // Only after the server said it stored everything; a refused save
      // closes the dialog and shows why, and nothing navigates.
      save({ refresh: false, follow: false }).then(function (stored) {
        setDialogBusy(false);
        if (stored) {
          pendingLeave = null;
          leavingOnPurpose = true;
          request.go();
          return;
        }
        pendingLeave = null;
        closeDialog();
        if (summary && !summary.hidden) summary.focus();
      });
      return;
    }

    pendingLeave = null;
    closeDialog();

    if (value === "discard") {
      leavingOnPurpose = true;
      request.go();
      return;
    }

    if (request.from && typeof request.from.focus === "function") request.from.focus();
  }

  function askToLeave(go, from) {
    if (!dialog || typeof dialog.showModal !== "function") {
      // No dialog on this screen: the browser's own question, still before leaving.
      if (window.confirm(word("leave-question"))) {
        leavingOnPurpose = true;
        go();
      }
      return;
    }

    if (dialog.open) return;
    pendingLeave = { go: go, from: from };
    dialog.showModal();
    var stay = dialog.querySelector("[data-admin-editor-leave-stay]");
    if (stay) stay.focus();
  }

  if (dialog) {
    var saveChoice = dialog.querySelector("[data-admin-editor-leave-save]");
    if (saveChoice) saveChoice.setAttribute("data-label", saveChoice.textContent);

    dialog.addEventListener("submit", function (event) {
      event.preventDefault();
      answer(event.submitter ? event.submitter.value : "stay");
    });

    // Escape, and whatever else closes it: stay.
    dialog.addEventListener("cancel", function (event) {
      if (saving) {
        event.preventDefault();
        return;
      }
      answer("stay");
    });

    var pressedOutside = false;
    dialog.addEventListener("pointerdown", function (event) {
      pressedOutside = event.target === dialog;
    });
    dialog.addEventListener("click", function (event) {
      if (pressedOutside && event.target === dialog && !saving) answer("stay");
      pressedOutside = false;
    });
  }

  /** A link that leaves this page for another one of this site, followed in this tab. */
  function leavingLink(event) {
    if (event.defaultPrevented || event.button !== 0) return null;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return null;

    var link = event.target && event.target.closest ? event.target.closest("a[href]") : null;
    if (!link || link.hasAttribute("download") || link.hasAttribute("data-admin-editor-leave")) return null;

    var target = (link.getAttribute("target") || "").toLowerCase();
    if (target !== "" && target !== "_self") return null;

    var url;
    try {
      url = new URL(link.href, window.location.href);
    } catch (e) {
      return null;
    }

    if (url.protocol !== "http:" && url.protocol !== "https:") return null;
    if (url.origin !== window.location.origin) return null;

    // A jump within this same page is no navigation.
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== "") return null;

    return { link: link, url: url };
  }

  document.addEventListener("click", function (event) {
    if (!dirty || leavingOnPurpose) return;

    // A link that says it discards on purpose ("Annuleren") needs no question.
    var discard = event.target && event.target.closest ? event.target.closest("a[href][data-admin-editor-leave]") : null;
    if (discard && !event.defaultPrevented && event.button === 0 && !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
      leavingOnPurpose = true;
      return;
    }

    var found = leavingLink(event);
    if (!found) return;

    event.preventDefault();
    askToLeave(function () {
      window.location.assign(found.url.href);
    }, found.link);
  });

  document.addEventListener("submit", function (event) {
    var other = event.target;
    if (!dirty || leavingOnPurpose || event.defaultPrevented) return;
    if (!(other instanceof HTMLFormElement) || other === form || other === passThrough) return;
    if ((other.getAttribute("method") || "").toLowerCase() === "dialog") return;
    var target = (other.getAttribute("target") || "").toLowerCase();
    if (target !== "" && target !== "_self") return;

    event.preventDefault();
    var submitter = event.submitter || null;
    askToLeave(function () {
      passThrough = other;
      try {
        if (typeof other.requestSubmit === "function") other.requestSubmit(submitter && submitter.form === other ? submitter : null);
        else other.submit();
      } finally {
        passThrough = null;
      }
    }, submitter);
  });

  window.addEventListener("beforeunload", function (event) {
    if (!dirty || leavingOnPurpose) return;
    event.preventDefault();
    event.returnValue = "";
    return "";
  });

  // Back to this page from the browser's page cache: it is this page again.
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) leavingOnPurpose = false;
  });

  bar.hidden = false;
  render("saved");

  if (form.hasAttribute("data-admin-editor-unsaved")) markDirty();

  // For a screen's own script and for tests: the one editor on this page.
  window.AdminEditor = {
    isDirty: function () { return dirty; },
    save: save
  };
})();
