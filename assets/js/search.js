/* =========================================================================
   The site search's header control (partials/header-search.php, SEARCH.md).

   Asked for as a shell script by App\Service\PageAssets, only while the site
   switched search on. Without it everything still works: the magnifier is
   a link to the results page and the panel's form a GET form to it.

   What this adds:
   - the magnifier becomes a disclosure button (aria-expanded) that opens a
     compact panel under the header on a wide screen; Escape, the close
     button and a click outside close it, and focus goes back to the
     magnifier. It is not a modal, so there is no focus trap. Inside the
     phone menu the panel is always shown (search.css) and nothing opens;
   - live results under the field while typing: ONE request per pause in
     typing (debounced), an older answer never overwrites a newer one, at
     most the server's live limit, and "Alle resultaten bekijken" to the
     results page. Enter still submits the form to that page;
   - Arrow Down from the field moves into the results, Arrow Up/Down move
     between them.

   Every sentence comes from the partial's data-text-* attributes in the
   page's language; results are built with textContent only, and a result
   URL is used only when it is a root-relative address on this site.
   ========================================================================= */
(function () {
  "use strict";

  var root = document.querySelector("[data-site-search]");
  if (!root) return;

  var toggle = root.querySelector("[data-site-search-toggle]");
  var panel = root.querySelector("[data-site-search-panel]");
  var input = root.querySelector("[data-site-search-input]");
  var status = root.querySelector("[data-site-search-status]");
  var list = root.querySelector("[data-site-search-results]");
  var all = root.querySelector("[data-site-search-all]");
  var closeButton = root.querySelector("[data-site-search-close]");
  if (!toggle || !panel || !input || !status || !list || !all) return;

  var endpoint = root.getAttribute("data-search-endpoint") || "/api/search.php";
  var lang = root.getAttribute("data-search-lang") || "";
  var min = parseInt(root.getAttribute("data-search-min"), 10) || 2;
  var DEBOUNCE = 220;
  var desktop = window.matchMedia("(min-width: 901px)");

  function text(key) { return root.getAttribute("data-text-" + key) || ""; }
  function format(template, value) { return template.replace(/%[ds]/, String(value)); }

  function isSiteUrl(url) {
    return typeof url === "string" && url.charAt(0) === "/" && url.charAt(1) !== "/" && url.indexOf("\\") === -1;
  }

  /* ---- open / close (wide screens) -------------------------------- */

  toggle.setAttribute("role", "button");
  toggle.setAttribute("aria-expanded", "false");

  function isOpen() { return root.classList.contains("is-open"); }

  function open() {
    root.classList.add("is-open");
    toggle.setAttribute("aria-expanded", "true");
    input.focus();
  }

  function close(returnFocus) {
    if (!isOpen()) return;
    root.classList.remove("is-open");
    toggle.setAttribute("aria-expanded", "false");
    if (returnFocus) toggle.focus();
  }

  toggle.addEventListener("click", function (event) {
    // The link's own address is the no-JS route; with the script the same
    // control opens the panel instead of leaving the page.
    event.preventDefault();
    if (isOpen()) {
      close(true);
    } else {
      open();
    }
  });
  toggle.addEventListener("keydown", function (event) {
    // A link answers Enter only; a button answers Space too.
    if (event.key === " ") {
      event.preventDefault();
      toggle.click();
    }
  });

  if (closeButton) {
    closeButton.addEventListener("click", function () { close(true); });
  }

  root.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && isOpen()) {
      event.stopPropagation();
      close(true);
    }
  });

  document.addEventListener("click", function (event) {
    if (isOpen() && !root.contains(event.target)) close(false);
  });

  var onBreakpoint = function () { if (!desktop.matches) close(false); };
  if (desktop.addEventListener) desktop.addEventListener("change", onBreakpoint);
  else if (desktop.addListener) desktop.addListener(onBreakpoint);

  /* ---- live results ------------------------------------------------ */

  var timer = null;
  var sequence = 0;
  var controller = null;

  function clearResults() {
    list.textContent = "";
    list.hidden = true;
    all.hidden = true;
  }

  function say(message) { status.textContent = message; }

  function renderHit(hit) {
    var item = document.createElement("li");
    var link = document.createElement("a");
    link.className = "search-hit";
    link.href = hit.url;

    if (isSiteUrl(hit.thumbnail)) {
      var picture = document.createElement("img");
      picture.className = "search-hit__picture";
      picture.src = hit.thumbnail;
      picture.alt = "";
      picture.loading = "lazy";
      picture.width = 48;
      picture.height = 48;
      link.appendChild(picture);
    } else {
      link.classList.add("search-hit--no-picture");
    }

    var body = document.createElement("span");
    body.className = "search-hit__body";
    [["search-hit__type", hit.type_label], ["search-hit__title", hit.title], ["search-hit__excerpt", hit.excerpt]].forEach(function (part) {
      if (!part[1]) return;
      var span = document.createElement("span");
      span.className = part[0];
      span.textContent = part[1];
      body.appendChild(span);
    });
    link.appendChild(body);
    item.appendChild(link);
    return item;
  }

  function show(data, query) {
    clearResults();
    var hits = Array.isArray(data.results) ? data.results.filter(function (hit) { return hit && isSiteUrl(hit.url); }) : [];
    var total = typeof data.total === "number" ? data.total : hits.length;

    if (hits.length === 0) {
      say(format(text("none"), query));
      return;
    }

    hits.forEach(function (hit) { list.appendChild(renderHit(hit)); });
    list.hidden = false;
    say(total === 1 ? text("one") : format(text("many"), total));

    if (isSiteUrl(data.all_url)) {
      all.href = data.all_url;
      all.hidden = false;
    }
  }

  function search(query) {
    var mine = ++sequence;
    if (controller) controller.abort();
    controller = window.AbortController ? new AbortController() : null;

    say(text("loading"));
    var url = endpoint + "?q=" + encodeURIComponent(query) + (lang ? "&lang=" + encodeURIComponent(lang) : "");

    fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin", signal: controller ? controller.signal : undefined })
      .then(function (response) {
        if (!response.ok) throw new Error("HTTP " + response.status);
        return response.json();
      })
      .then(function (data) {
        if (mine !== sequence) return; // a newer query is on its way
        show(data || {}, query);
      })
      .catch(function (error) {
        if (error && error.name === "AbortError") return;
        if (mine !== sequence) return;
        clearResults();
        say(text("error"));
      });
  }

  input.addEventListener("input", function () {
    window.clearTimeout(timer);
    var query = input.value.trim();

    if (query.length === 0) {
      sequence++;
      if (controller) controller.abort();
      clearResults();
      say("");
      return;
    }
    if (query.length < min) {
      sequence++;
      if (controller) controller.abort();
      clearResults();
      say(format(text("short"), min));
      return;
    }

    timer = window.setTimeout(function () { search(query); }, DEBOUNCE);
  });

  /* ---- keyboard in the list --------------------------------------- */

  function links() { return Array.prototype.slice.call(list.querySelectorAll("a.search-hit")); }

  input.addEventListener("keydown", function (event) {
    if (event.key === "ArrowDown" && !list.hidden) {
      var first = links()[0];
      if (first) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  list.addEventListener("keydown", function (event) {
    if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
    var items = links();
    var at = items.indexOf(document.activeElement);
    if (at === -1) return;
    event.preventDefault();
    if (event.key === "ArrowDown") {
      // The next result, then "Alle resultaten bekijken", then stay put.
      var next = items[at + 1] || (all.hidden ? null : all);
      if (next) next.focus();
    } else if (at === 0) {
      input.focus();
    } else {
      items[at - 1].focus();
    }
  });
})();
