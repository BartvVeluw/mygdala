/*
 * Media Library: search, filter and page through the library on
 * admin/media.php without reloading, and keep the grid current when the
 * upload queue adds files.
 *
 * OWNER. admin/media.php, the only screen that loads it.
 *
 * WHAT IT DOES. The server renders the library — the summary line, the grid
 * and the paging — once, in PHP, for the screen with and without this
 * script, and every view of it has an address of its own (?q=, ?type=,
 * ?page=). This script only changes how such an address is reached: it
 * fetches the screen for it, swaps in the fresh [data-media-results] block,
 * keeps the address bar in step so Back and Forward work, and leaves the rest
 * of the page alone. The search field keeps its cursor while an editor types,
 * and files waiting in the upload queue stay where they are.
 *
 * WHAT IS NOT HERE. No card markup and no copy of a rule the grid follows: the
 * fresh block is the server's own markup, parsed with DOMParser and moved in
 * as nodes; nothing is written into the page as a string. No sentence an
 * editor reads. And no second way of filtering: when a request fails, the
 * browser goes to the address the way a plain link would.
 */
(function () {
  "use strict";

  var library = document.querySelector("[data-media-library]");

  if (
    !library ||
    typeof window.fetch !== "function" ||
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

  /** How long after the last key a search starts: smooth to type, few requests. */
  var SEARCH_DELAY = 350;

  var controller = null;
  var searchTimer = null;

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
   *   history   "push" adds an entry, "replace" rewrites the current one
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

  // --- What an editor does ---------------------------------------------------

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

  // The entry the page was loaded with gets a state as well, so going Back to
  // it is a view this script redraws too.
  window.history.replaceState({ mediaLibrary: true }, "", window.location.href);
})();
