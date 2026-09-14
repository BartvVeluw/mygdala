/*
 * Media Library: keeps the library part of admin/media.php current without
 * reloading the page.
 *
 * OWNER. admin/media.php, the only screen that loads it.
 *
 * WHAT IT DOES. The server renders the library — the summary line, the grid
 * and the paging — once, in PHP, for the screen with and without this script.
 * When something changes the library (media-upload.js adds files and says so
 * with a "media-library:changed" event), this fetches the screen again and
 * swaps in the fresh [data-media-library] region. What an editor was doing
 * elsewhere on the page survives: a file the server refused stays in the
 * queue with its reason, and nothing scrolls away.
 *
 * WHAT IS NOT HERE. No card markup and no copy of a rule the grid follows: the
 * fresh region is the server's own markup, parsed with DOMParser and moved in
 * as nodes; nothing is written into the page as a string. No sentence an
 * editor reads.
 */
(function () {
  "use strict";

  if (
    !document.querySelector("[data-media-library]") ||
    typeof window.fetch !== "function" ||
    typeof window.DOMParser !== "function"
  ) {
    return;
  }

  var controller = null;

  /**
   * Replaces the library region with the one the server renders for `url`.
   * Resolves to true when the region was swapped, false when it was not (a
   * failed request leaves the current region as it was).
   */
  function refresh(url) {
    var region = document.querySelector("[data-media-library]");
    var current = typeof window.AbortController === "function" ? new window.AbortController() : null;

    if (controller) {
      controller.abort();
    }

    controller = current;
    region.setAttribute("aria-busy", "true");

    return window
      .fetch(url, {
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
        var fresh = new window.DOMParser().parseFromString(html, "text/html").querySelector("[data-media-library]");

        if (!fresh) {
          throw new Error("The response has no library region");
        }

        document.querySelector("[data-media-library]").replaceWith(document.importNode(fresh, true));
        document.dispatchEvent(new CustomEvent("media-library:refreshed"));

        return true;
      })
      .catch(function () {
        return false;
      })
      .then(function (swapped) {
        if (controller === current) {
          controller = null;

          var now = document.querySelector("[data-media-library]");
          if (now) {
            now.removeAttribute("aria-busy");
          }
        }

        return swapped;
      });
  }

  document.addEventListener("media-library:changed", function () {
    // New items are the newest, so they sit at the top of the whole library:
    // the unfiltered first page is where an editor sees them straight away.
    refresh(window.location.pathname).then(function (swapped) {
      if (swapped && window.location.search !== "") {
        window.history.replaceState(null, "", window.location.pathname);
      }
    });
  });
})();
