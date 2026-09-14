/*
 * Media Library on admin/media.php: search, filter and page through the
 * library without reloading; select items and delete the selection after a
 * confirmation; rename an item; and keep the grid current when the upload
 * queue adds files. The grid only: a single delete on the item view asks in
 * the CMS's shared dialog (admin_confirm_dialog(), ADMIN-UI.md), so that view
 * does not load this script.
 *
 * OWNER. admin/media.php, the only screen that loads it.
 *
 * WHAT IT DOES. The server renders the library — the summary line, the grid,
 * the selection bar and the paging — once, in PHP, for the screen with and
 * without this script, and every view of it has an address of its own (?q=,
 * ?type=, ?page=). This script only changes how such an address is reached:
 * it fetches the screen for it, swaps in the fresh [data-media-results]
 * block, keeps the address bar in step so Back and Forward work, and leaves
 * the rest of the page alone. Deleting and renaming are posted to their own
 * endpoints and followed by the same redraw, so what the grid shows is always
 * what the server says.
 *
 * WHAT IS NOT HERE. No card markup and no copy of a rule the server follows:
 * the fresh block is the server's own markup, parsed with DOMParser and moved
 * in as nodes; nothing is written into the page as a string. No sentence an
 * editor reads — the words are in data attributes the server filled from the
 * catalog. No decision about what may be deleted: the dialog warns about the
 * files the grid counted as used, and api/admin/delete-media-items.php asks
 * again, strictly, and keeps whatever is still used.
 */
(function () {
  "use strict";

  var library = document.querySelector("[data-media-library]");

  if (
    !library ||
    typeof window.fetch !== "function" ||
    typeof window.FormData !== "function" ||
    typeof window.DOMParser !== "function" ||
    typeof window.URLSearchParams !== "function" ||
    typeof window.history.pushState !== "function"
  ) {
    return;
  }

  var form = library.querySelector("[data-media-filter]");
  var search = library.querySelector("[data-media-search]");
  var type = library.querySelector("[data-media-type]");
  var status = library.querySelector("[data-media-results-status]");
  var notice = library.querySelector("[data-media-notice]");
  var deleteDialog = document.querySelector("[data-media-delete-dialog]");
  var renameDialog = document.querySelector("[data-media-rename-dialog]");

  /**
   * A browser without <dialog> keeps the plain forms: deleting a selection
   * asks the browser's own question, and renaming happens on the item view.
   */
  var dialogs = typeof window.HTMLDialogElement === "function";

  /** How long after the last key a search starts: smooth to type, few requests. */
  var SEARCH_DELAY = 350;

  var controller = null;
  var searchTimer = null;
  var dialogTrigger = null;

  // --- Words ---------------------------------------------------------------

  /** A counted sentence from two data attributes: the one for exactly one, and the one with :count. */
  function counted(element, count, one, many) {
    var template = element.getAttribute(count === 1 ? one || "data-text-one" : many || "data-text-many") || "";

    return template.split(":count").join(String(count));
  }

  // --- Addresses -------------------------------------------------------------

  /** The address of the view the toolbar describes, from its first page. */
  function addressOfForm() {
    var params = new URLSearchParams();
    var term = search ? search.value.trim() : "";

    if (term !== "") {
      params.set("q", term);
    }

    if (type && type.value !== "") {
      params.set("type", type.value);
    }

    var query = params.toString();

    return form.getAttribute("action") + (query === "" ? "" : "?" + query);
  }

  /** Puts the toolbar in step with an address reached another way: Back, or showing everything. */
  function syncForm(address) {
    var params = new URL(address, window.location.href).searchParams;

    if (search) {
      search.value = params.get("q") || "";
    }

    if (type) {
      type.value = params.get("type") || "";

      // A kind the list does not offer means everything, as it does on the server.
      if (type.selectedIndex === -1) {
        type.value = "";
      }
    }
  }

  // --- Showing a view --------------------------------------------------------

  /**
   * Fetches the view at `address` and swaps its results block in.
   *
   *   history   "push" adds an entry, "replace" rewrites the current one; a
   *             view an editor navigates to also clears an earlier notice
   *   focus     moves focus to the summary line, so a keyboard continues at
   *             the top of the new page
   *   fallback  goes to the address the ordinary way when the request fails
   *
   * Resolves to true when the block was swapped.
   */
  function show(address, options) {
    var settings = options || {};
    var current = typeof window.AbortController === "function" ? new window.AbortController() : null;
    var results = library.querySelector("[data-media-results]");

    if (controller) {
      controller.abort();
    }

    controller = current;

    if (settings.history) {
      hideNotice();
    }

    if (results) {
      results.setAttribute("aria-busy", "true");
    }

    return window
      .fetch(address, {
        credentials: "same-origin",
        headers: { Accept: "text/html" },
        signal: current ? current.signal : undefined
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("HTTP " + response.status);
        }

        return response.text();
      })
      .then(function (html) {
        var fresh = new window.DOMParser().parseFromString(html, "text/html").querySelector("[data-media-results]");
        var target = library.querySelector("[data-media-results]");

        if (!fresh || !target) {
          throw new Error("The response has no results block");
        }

        target.replaceWith(document.importNode(fresh, true));

        if (settings.history === "push") {
          window.history.pushState({ mediaLibrary: true }, "", address);
        } else if (settings.history === "replace") {
          window.history.replaceState({ mediaLibrary: true }, "", address);
        }

        announce();
        enhanceResults();

        if (settings.focus) {
          var summary = library.querySelector("[data-media-summary]");

          if (summary) {
            summary.focus();
          }
        }

        document.dispatchEvent(new CustomEvent("media-library:refreshed"));

        return true;
      })
      .catch(function (error) {
        if (error && error.name === "AbortError") {
          return false;
        }

        if (settings.fallback) {
          window.location.assign(address);
        }

        return false;
      })
      .then(function (shown) {
        if (controller === current) {
          controller = null;

          var now = library.querySelector("[data-media-results]");

          if (now) {
            now.removeAttribute("aria-busy");
          }
        }

        return shown;
      });
  }

  /** Says the new summary once, through the status line that stays in place. */
  function announce() {
    var text = library.querySelector("[data-media-summary-text]");

    if (status && text) {
      status.textContent = text.textContent.replace(/\s+/g, " ").trim();
    }
  }

  // --- Notices ---------------------------------------------------------------

  function showNotice(message, details, isError) {
    if (!notice) {
      return;
    }

    var text = notice.querySelector("[data-media-notice-message]");
    var list = notice.querySelector("[data-media-notice-details]");

    text.textContent = message;
    list.replaceChildren();

    details.forEach(function (detail) {
      var entry = document.createElement("li");
      entry.textContent = detail;
      list.appendChild(entry);
    });

    list.hidden = details.length === 0;
    notice.classList.toggle("admin-alert--success", !isError);
    notice.classList.toggle("admin-alert--error", isError);
    notice.hidden = false;

    if (status) {
      status.textContent = [message].concat(details).join(" ");
    }
  }

  function hideNotice() {
    if (notice) {
      notice.hidden = true;
    }
  }

  // --- Selecting -------------------------------------------------------------

  function selectBoxes() {
    return Array.prototype.slice.call(library.querySelectorAll("[data-media-select]"));
  }

  function chosenBoxes() {
    return selectBoxes().filter(function (box) {
      return box.checked;
    });
  }

  function updateSelection() {
    var bar = library.querySelector("[data-media-bulk]");

    if (!bar) {
      return;
    }

    var boxes = selectBoxes();
    var chosen = chosenBoxes();
    var all = bar.querySelector("[data-media-select-all]");
    var allLabel = bar.querySelector("[data-media-select-all-label]");
    var count = bar.querySelector("[data-media-selected-count]");
    var actions = bar.querySelector("[data-media-bulk-actions]");

    if (allLabel) {
      allLabel.hidden = boxes.length === 0;
    }

    if (all) {
      all.checked = boxes.length > 0 && chosen.length === boxes.length;
      all.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
    }

    if (count) {
      count.hidden = chosen.length === 0;
      count.textContent = chosen.length === 0 ? "" : counted(count, chosen.length);
    }

    // The action appears once there is something to act on.
    if (actions) {
      actions.hidden = chosen.length === 0;
    }

    bar.classList.toggle("has-selection", chosen.length > 0);

    boxes.forEach(function (box) {
      var card = box.closest("[data-media-card]");

      if (card) {
        card.classList.toggle("is-selected", box.checked);
      }
    });
  }

  library.addEventListener("change", function (event) {
    var target = event.target;

    if (target.matches("[data-media-select-all]")) {
      selectBoxes().forEach(function (box) {
        box.checked = target.checked;
      });
    }

    if (target.matches("[data-media-select], [data-media-select-all]")) {
      updateSelection();
    }
  });

  // --- Dialogs ---------------------------------------------------------------

  function openDialog(dialog, trigger) {
    dialogTrigger = trigger || null;
    dialogError(dialog, "");
    dialog.showModal();
  }

  function dialogError(dialog, message) {
    var error = dialog.querySelector("[data-media-dialog-error]");

    if (error) {
      error.textContent = message;
      error.hidden = message === "";
    }
  }

  /** The editor's reason for a failed request: our own words for an expired session. */
  function problemOf(answer, dialog) {
    if (answer.status === 401) {
      return dialog.getAttribute("data-session") || "";
    }

    if (typeof answer.data.error === "string" && answer.data.error !== "") {
      return answer.data.error;
    }

    return dialog.getAttribute("data-failed") || "";
  }

  [deleteDialog, renameDialog].forEach(function (dialog) {
    if (!dialog || !dialogs) {
      return;
    }

    dialog.addEventListener("click", function (event) {
      if (event.target.closest("[data-media-dialog-close]")) {
        dialog.close();
      }
    });

    // Closed by a button or by Escape: focus goes back to what opened the
    // dialog, when that is still on the page.
    dialog.addEventListener("close", function () {
      var trigger = dialogTrigger;
      dialogTrigger = null;

      if (trigger && document.contains(trigger)) {
        trigger.focus();
      }
    });
  });

  /** Posts a form as JSON-answering request; resolves to {status, ok, data}. */
  function post(target, extra) {
    var body = new FormData(target);

    Object.keys(extra).forEach(function (name) {
      body.append(name, extra[name]);
    });

    return window
      .fetch(target.getAttribute("action"), {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: body
      })
      .then(
        function (response) {
          return response.json().then(
            function (data) {
              return { status: response.status, ok: response.ok, data: data || {} };
            },
            function () {
              return { status: response.status, ok: false, data: {} };
            }
          );
        },
        function () {
          return { status: 0, ok: false, data: {} };
        }
      );
  }

  // --- Deleting a selection --------------------------------------------------

  function fillDeleteDialog(chosen) {
    var used = chosen.filter(function (box) {
      return Number(box.getAttribute("data-media-usage") || 0) > 0;
    });
    var deletable = chosen.length - used.length;

    var text = deleteDialog.querySelector("[data-media-delete-text]");
    var kept = deleteDialog.querySelector("[data-media-delete-kept]");
    var keptText = deleteDialog.querySelector("[data-media-delete-kept-text]");
    var keptList = deleteDialog.querySelector("[data-media-delete-kept-list]");
    var confirm = deleteDialog.querySelector("[data-media-delete-confirm]");

    text.textContent = deletable === 0
      ? deleteDialog.getAttribute("data-all-used") || ""
      : counted(deleteDialog, deletable);

    keptList.replaceChildren();

    used.forEach(function (box) {
      var entry = document.createElement("li");
      entry.textContent = box.getAttribute("data-media-name") || "";
      keptList.appendChild(entry);
    });

    keptText.textContent = used.length === 0 ? "" : counted(deleteDialog, used.length, "data-kept-one", "data-kept-many");
    kept.hidden = used.length === 0;

    // Nothing to delete: no destructive button to press.
    confirm.hidden = deletable === 0;
    confirm.disabled = deletable === 0;
  }

  library.addEventListener("submit", function (event) {
    var target = event.target;

    if (!target.matches("[data-media-bulk]")) {
      return;
    }

    var chosen = chosenBoxes();

    if (chosen.length === 0) {
      event.preventDefault();
      return;
    }

    if (!deleteDialog || !dialogs) {
      // No dialog: the plain question, and the server's rule behind it.
      fillDeleteDialogText(chosen, event);
      return;
    }

    event.preventDefault();
    fillDeleteDialog(chosen);
    openDialog(deleteDialog, target.querySelector("[data-media-bulk-delete]"));
  });

  function fillDeleteDialogText(chosen, event) {
    if (!deleteDialog) {
      return;
    }

    fillDeleteDialog(chosen);

    var question = deleteDialog.querySelector("[data-media-delete-text]").textContent;

    if (!window.confirm(question)) {
      event.preventDefault();
    }
  }

  if (deleteDialog && dialogs) {
    deleteDialog.querySelector("[data-media-delete-confirm]").addEventListener("click", function () {
      var bar = library.querySelector("[data-media-bulk]");
      var confirm = this;

      if (!bar) {
        deleteDialog.close();
        return;
      }

      confirm.disabled = true;
      dialogError(deleteDialog, "");

      post(bar, { ajax: "1" }).then(function (answer) {
        confirm.disabled = false;

        if (!answer.ok || !answer.data.ok) {
          dialogError(deleteDialog, problemOf(answer, deleteDialog));
          return;
        }

        // The button that opened the dialog is redrawn away; focus goes to
        // the outcome instead.
        dialogTrigger = null;
        deleteDialog.close();

        var deleted = (answer.data.deleted || []).length;
        var kept = (answer.data.kept || []).length;
        var message = String(answer.data.message || "");
        var details = answer.data.details || [];

        showNotice(message, details, deleted === 0 && kept > 0);

        show(window.location.href).then(function () {
          // The redraw announced its own summary line; the outcome is what an
          // editor needs to hear, so it is said again, last.
          if (status) {
            status.textContent = [message].concat(details).join(" ");
          }

          if (notice && !notice.hidden) {
            notice.setAttribute("tabindex", "-1");
            notice.focus();
          }
        });
      });
    });
  }

  // --- Renaming --------------------------------------------------------------

  function revealRenameButtons() {
    if (!renameDialog || !dialogs) {
      return;
    }

    Array.prototype.forEach.call(library.querySelectorAll("[data-media-rename]"), function (button) {
      button.hidden = false;
    });
  }

  if (renameDialog && dialogs) {
    var renameForm = renameDialog.querySelector("[data-media-rename-form]");
    var renameName = renameDialog.querySelector("[data-media-rename-name]");
    var renameId = renameDialog.querySelector("[data-media-rename-id]");
    var renameExtension = renameDialog.querySelector("[data-media-rename-extension]");
    var renameSubmit = renameDialog.querySelector("[data-media-rename-submit]");

    library.addEventListener("click", function (event) {
      var button = event.target.closest("[data-media-rename]");

      if (!button) {
        return;
      }

      var extension = button.getAttribute("data-media-name-extension") || "";

      renameId.value = button.getAttribute("data-media-id") || "";
      renameName.value = button.getAttribute("data-media-name-base") || "";
      renameName.removeAttribute("aria-invalid");
      renameExtension.textContent = extension === "" ? "" : "." + extension;

      openDialog(renameDialog, button);
      renameName.focus();
      renameName.select();
    });

    renameForm.addEventListener("submit", function (event) {
      event.preventDefault();

      var id = renameId.value.replace(/[^0-9]/g, "");

      renameSubmit.disabled = true;
      dialogError(renameDialog, "");

      post(renameForm, { ajax: "1" }).then(function (answer) {
        renameSubmit.disabled = false;

        if (!answer.ok || !answer.data.ok) {
          renameName.setAttribute("aria-invalid", "true");
          dialogError(renameDialog, problemOf(answer, renameDialog));
          renameName.focus();
          return;
        }

        dialogTrigger = null;
        renameDialog.close();

        var name = answer.data.item && answer.data.item.name ? String(answer.data.item.name) : "";

        show(window.location.href).then(function () {
          if (status) {
            status.textContent = (renameDialog.getAttribute("data-done") || "").split(":name").join(name);
          }

          var again = library.querySelector('[data-media-rename][data-media-id="' + id + '"]');

          if (again) {
            again.focus();
          }
        });
      });
    });
  }

  // --- What an editor does in the toolbar ------------------------------------

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      window.clearTimeout(searchTimer);
      show(addressOfForm(), { history: "push", fallback: true });
    });
  }

  if (form && type) {
    type.addEventListener("change", function () {
      window.clearTimeout(searchTimer);
      show(addressOfForm(), { history: "push", fallback: true });
    });
  }

  if (form && search) {
    search.addEventListener("input", function () {
      window.clearTimeout(searchTimer);

      // Typing rewrites the current history entry instead of adding one per
      // letter; Enter or the button adds one.
      searchTimer = window.setTimeout(function () {
        show(addressOfForm(), { history: "replace" });
      }, SEARCH_DELAY);
    });
  }

  library.addEventListener("click", function (event) {
    var link = event.target.closest("a[data-media-page], a[data-media-reset]");

    // A link opened in a new tab or window stays an ordinary link.
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    event.preventDefault();
    window.clearTimeout(searchTimer);

    if (link.hasAttribute("data-media-reset")) {
      syncForm(link.href);
    }

    show(link.href, { history: "push", focus: true, fallback: true });
  });

  window.addEventListener("popstate", function () {
    window.clearTimeout(searchTimer);
    syncForm(window.location.href);
    show(window.location.href);
  });

  document.addEventListener("media-library:changed", function () {
    // New items are the newest, so the unfiltered first page is where an
    // editor sees them straight away.
    var address = form ? form.getAttribute("action") : window.location.pathname;

    window.clearTimeout(searchTimer);
    syncForm(address);
    show(address, { history: window.location.search === "" ? null : "replace" });
  });

  // --- Start -----------------------------------------------------------------

  /** What a freshly drawn results block needs: its selection state and its rename buttons. */
  function enhanceResults() {
    updateSelection();
    revealRenameButtons();
  }

  enhanceResults();

  // The entry the page was loaded with gets a state as well, so going Back to
  // it is a view this script redraws too.
  window.history.replaceState({ mediaLibrary: true }, "", window.location.href);
})();
