/*
 * The Destination Picker's searchable list (admin/_link_target_field.php,
 * CONTENT-BLOCKS.md "Waar een knop heen gaat"): for a kind of destination
 * that can have many items — products, collections, Portfolio projects, blog
 * posts — a search field and a list of results with their picture and status,
 * instead of one long <select>. Pages keep their <select> in tree order.
 *
 * THE <select> STAYS THE FIELD. It is what the form posts, what the server
 * printed the stored choice into, and — without this script — the whole
 * picker. This file hides it behind a list built from its own options
 * (value, data-name, data-note, data-thumbnail) and writes a choice back
 * into it with a change event, like any other edit, so the save bar and
 * admin/assets/navigation-item.js hear it as they always did.
 *
 * WHAT THE STORED CHOICE IS NOW. A panel may carry a warning about the
 * destination as stored ([data-destination-warning="<id>"]: a concept, an
 * item that is gone). It speaks about that one item, so it is hidden the
 * moment another is chosen — also for the page list, which is not enhanced.
 *
 * Every sentence comes from the panel's data-destination-texts: this file
 * holds no word of its own (ADMIN-UI.md). Searching changes nothing that is
 * saved, so its events never reach the save bar, and Enter in the search
 * field never submits the form.
 *
 * Keyboard: the results are buttons (Tab, Enter, Space), aria-pressed on the
 * chosen one; ArrowDown and ArrowUp move between the search field and the
 * results. After a choice the focus stays on the result that was chosen.
 */
(function () {
  "use strict";

  /** The most results listed at once; the search narrows the rest. */
  var LIMIT = 40;

  function normalise(text) {
    return String(text || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
  }

  function textsOf(panel) {
    try {
      return JSON.parse(panel.getAttribute("data-destination-texts") || "{}");
    } catch (e) {
      return {};
    }
  }

  function fill(template, values) {
    return String(template || "").replace(/:([a-z_]+)/g, function (match, key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match;
    });
  }

  /** Show a panel's warning only while the item it speaks about is chosen. */
  function syncWarnings(panel, select) {
    panel.querySelectorAll("[data-destination-warning]").forEach(function (warning) {
      warning.hidden = warning.getAttribute("data-destination-warning") !== select.value;
    });
  }

  function enhance(panel) {
    if (panel.hasAttribute("data-destination-ready")) return;
    var select = panel.querySelector("select[data-destination-select]");
    if (!select) return;
    panel.setAttribute("data-destination-ready", "");

    var texts = textsOf(panel);
    var base = (select.id || "destination") + "-search";

    var items = Array.prototype.filter.call(select.options, function (option) {
      return option.value !== "" && !option.disabled;
    }).map(function (option) {
      return {
        value: option.value,
        name: option.getAttribute("data-name") || option.textContent.trim(),
        note: option.getAttribute("data-note") || "",
        thumbnail: option.getAttribute("data-thumbnail") || ""
      };
    });

    var box = document.createElement("div");
    box.className = "admin-destination-search";

    var current = document.createElement("p");
    current.className = "admin-destination-search__current";
    current.setAttribute("aria-live", "polite");

    var field = document.createElement("label");
    field.className = "admin-search admin-destination-search__field";
    var fieldName = document.createElement("span");
    fieldName.className = "admin-visually-hidden";
    fieldName.textContent = texts.search_label || "";
    var input = document.createElement("input");
    input.type = "search";
    input.id = base;
    input.placeholder = texts.search_placeholder || "";
    input.autocomplete = "off";
    input.setAttribute("aria-controls", base + "-results");
    field.appendChild(fieldName);
    field.appendChild(input);

    var list = document.createElement("ul");
    list.className = "admin-destination-search__results";
    list.id = base + "-results";
    list.setAttribute("aria-label", texts.results_label || "");

    var status = document.createElement("p");
    status.className = "admin-destination-search__status";
    status.setAttribute("aria-live", "polite");

    box.appendChild(current);
    box.appendChild(field);
    box.appendChild(list);
    box.appendChild(status);

    select.hidden = true;
    select.insertAdjacentElement("afterend", box);

    // The kind's visible label now names the search field, which is where
    // a click on it and a screen reader should land.
    var label = panel.querySelector('label[for="' + select.id + '"]');
    if (label) label.setAttribute("for", input.id);

    function badge(note) {
      var text = texts["note_" + note];
      if (!note || !text) return null;
      var span = document.createElement("span");
      span.className = "admin-badge admin-badge--" + (note === "draft" ? "draft" : "warning");
      span.textContent = text;
      return span;
    }

    function picture(src) {
      var frame = document.createElement("span");
      frame.className = "admin-destination-search__thumb";
      frame.setAttribute("aria-hidden", "true");
      if (src) {
        var image = document.createElement("img");
        image.src = src;
        image.alt = "";
        image.loading = "lazy";
        frame.appendChild(image);
      }
      return frame;
    }

    function chosenItem() {
      if (select.value === "") return null;
      for (var i = 0; i < items.length; i++) {
        if (items[i].value === select.value) return items[i];
      }
      // A stored destination that can no longer be chosen is only in the
      // <select> (disabled or not): named from its option.
      var option = select.selectedOptions[0];
      return option ? { value: option.value, name: option.textContent.trim(), note: option.getAttribute("data-note") || "gone", thumbnail: "" } : null;
    }

    function renderCurrent() {
      current.textContent = "";
      var item = chosenItem();
      if (!item) {
        current.textContent = texts.nothing_chosen || "";
        return;
      }
      var lead = document.createElement("span");
      lead.className = "admin-destination-search__lead";
      lead.textContent = texts.chosen || "";
      var name = document.createElement("strong");
      name.textContent = item.name;
      current.appendChild(lead);
      current.appendChild(picture(item.thumbnail));
      current.appendChild(name);
      var mark = badge(item.note);
      if (mark) current.appendChild(mark);
    }

    function render() {
      var term = normalise(input.value.trim());
      var matches = items.filter(function (item) {
        return term === "" || normalise(item.name).indexOf(term) !== -1;
      });
      var shown = matches.slice(0, LIMIT);

      list.textContent = "";
      shown.forEach(function (item) {
        var row = document.createElement("li");
        var button = document.createElement("button");
        button.type = "button";
        button.className = "admin-destination-result";
        button.setAttribute("data-value", item.value);
        button.setAttribute("aria-pressed", item.value === select.value ? "true" : "false");
        button.appendChild(picture(item.thumbnail));
        var name = document.createElement("span");
        name.className = "admin-destination-result__name";
        name.textContent = item.name;
        button.appendChild(name);
        var mark = badge(item.note);
        if (mark) button.appendChild(mark);
        row.appendChild(button);
        list.appendChild(row);
      });

      if (matches.length === 0) {
        status.textContent = texts.no_results || "";
      } else if (matches.length > shown.length) {
        status.textContent = fill(texts.count_more, { shown: shown.length, count: matches.length });
      } else {
        status.textContent = fill(texts.count, { count: matches.length });
      }
    }

    function choose(value) {
      if (select.value !== value) {
        select.value = value;
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
      list.querySelectorAll("button[data-value]").forEach(function (button) {
        button.setAttribute("aria-pressed", button.getAttribute("data-value") === value ? "true" : "false");
      });
      renderCurrent();
      syncWarnings(panel, select);
    }

    function results() {
      return Array.prototype.slice.call(list.querySelectorAll("button[data-value]"));
    }

    list.addEventListener("click", function (event) {
      var button = event.target.closest ? event.target.closest("button[data-value]") : null;
      if (!button) return;
      choose(button.getAttribute("data-value"));
      button.focus();
    });

    list.addEventListener("keydown", function (event) {
      if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
      var buttons = results();
      var at = buttons.indexOf(document.activeElement);
      if (at === -1) return;
      event.preventDefault();
      if (event.key === "ArrowDown" && at < buttons.length - 1) buttons[at + 1].focus();
      if (event.key === "ArrowUp") (at === 0 ? input : buttons[at - 1]).focus();
    });

    // Searching changes nothing that is saved: the save bar
    // (admin/assets/save-bar.js, listening on the document) never hears it.
    ["input", "change"].forEach(function (type) {
      input.addEventListener(type, function (event) {
        event.stopPropagation();
        if (type === "input") render();
      });
    });

    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter") event.preventDefault();
      if (event.key === "ArrowDown") {
        var buttons = results();
        if (buttons.length) {
          event.preventDefault();
          buttons[0].focus();
        }
      }
    });

    render();
    renderCurrent();
  }

  function start(root) {
    root.querySelectorAll("[data-destination-search]").forEach(enhance);
    root.querySelectorAll("select[data-destination-select]").forEach(function (select) {
      var panel = select.closest("[data-nav-link-field]");
      if (panel) syncWarnings(panel, select);
    });
  }

  // A choice in a plain list (the pages) hides the stored item's warning too.
  document.addEventListener("change", function (event) {
    var select = event.target;
    if (!select || !select.matches || !select.matches("select[data-destination-select]")) return;
    var panel = select.closest("[data-nav-link-field]");
    if (panel) syncWarnings(panel, select);
  });

  // A row added on screen (Tekst met afbeelding, Hover-kaarten) brings its
  // own button; so does a region the dynamic editor drew again.
  ["row-list:added", "admin-editor:replaced"].forEach(function (type) {
    document.addEventListener(type, function (event) {
      if (event.target && event.target.querySelectorAll) start(event.target);
    });
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { start(document); });
  } else {
    start(document);
  }
})();
