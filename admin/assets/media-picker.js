/**
 * The Media picker's behaviour: open the shared modal, browse the library by
 * folder and search, as a grid or a list, select one item (or several, for a
 * collecting field), upload a new file into the library, and hand exactly
 * the confirmed selection back to whichever field asked for it.
 *
 * Loaded only by admin screens that render admin/_media_picker.php. It owns
 * nothing but the picker: no other admin interaction runs through here, and
 * every field it fills is an ordinary hidden input that the surrounding
 * <form> posts like any other value. Turn this script off and the form still
 * submits — with whatever was already selected.
 *
 * ONE USER ACTION, ONE FLOW. "Kies uit mediabibliotheek" opens this modal and
 * nothing else. The operating system's file dialog opens from exactly one
 * place: the modal's "Nieuw bestand uploaden" button, whose click handler is
 * the only code that calls click() on the file input (openFileDialog()). The
 * input is hidden and sits in no <label>, so no other click can reach it; the
 * open handler cancels the click's default action, so a picker placed inside
 * a <label> or a <form> can never also activate something else. The script
 * binds itself once per page (data-media-picker-ready), so loading it twice
 * cannot register a second handler for the same click.
 *
 * CHOOSE, THEN CONFIRM. A card toggles its place in the selection
 * (aria-pressed). The selection is kept by id, outside the grid, so opening
 * another folder, searching or "Meer laden" never drops it; a card for an
 * item that is selected is drawn selected again wherever it reappears.
 * "Selecteren" hands the selection over; "Annuleren", Escape, the × and the
 * backdrop close the modal and change nothing in the editor. A field takes
 * one item (choosing another replaces it); a COLLECTING field
 * (data-media-picker-collect: "Afbeelding toevoegen" of a list, such as a
 * product's pictures, admin/assets/product-gallery.js) takes several, and
 * each is handed over, in the order chosen, as a bubbling
 * `media-picker:choose` event whose detail is the library item.
 *
 * UPLOADING goes through the library: every file becomes an ordinary media
 * item (api/admin/media-upload.php), is filed under the folder the modal is
 * showing, appears at the top of the grid and is selected — still to be
 * confirmed like any other choice. A file the library already had comes back
 * as that item and is selected the same way.
 *
 * One modal serves every field on the page; `activeField` remembers which
 * one opened it, and that field's kind (data-media-picker-kind) decides what
 * the modal lists and what its upload accepts. The server applies the same
 * kind again (api/admin/media-list.php ?type=, api/admin/media-upload.php
 * kind=), and every endpoint behind a field checks the id once more.
 *
 * An alt-text input linked to the field (data-media-alt-for="<its name>",
 * media_alt_field() in admin/_media_picker.php) is filled with the chosen
 * image's library alt text, or emptied with a placeholder when that image
 * has none, so the editor sees the alt text that will really be used.
 *
 * Every word comes from the page (the config's messages); names and alt
 * texts are editor-supplied strings and only ever reach the page through
 * textContent.
 */
(function () {
  "use strict";

  var modal = document.querySelector("[data-media-modal]");
  var configEl = document.querySelector("[data-media-picker-config]");

  if (!modal || !configEl || modal.hasAttribute("data-media-picker-ready")) {
    return;
  }

  modal.setAttribute("data-media-picker-ready", "");

  var config;
  try {
    config = JSON.parse(configEl.textContent || "{}");
  } catch (e) {
    return;
  }

  var grid = modal.querySelector("[data-media-modal-grid]");
  var searchInput = modal.querySelector("[data-media-modal-search]");
  var folderSelect = modal.querySelector("[data-media-modal-folder]");
  var uploadInput = modal.querySelector("[data-media-modal-upload]");
  var uploadButton = modal.querySelector("[data-media-modal-upload-button]");
  var statusEl = modal.querySelector("[data-media-modal-status]");
  var moreButton = modal.querySelector("[data-media-modal-more]");
  var confirmButton = modal.querySelector("[data-media-modal-confirm]");
  var countEl = modal.querySelector("[data-media-modal-count]");
  var kinds = config.kinds || {};
  var messages = config.messages || {};

  /** The library screen's grid-or-list choice, shared: one preference per browser. */
  var VIEW_STORAGE_KEY = "mygdala.media-library.view";

  var activeField = null;
  var currentKind = "image";
  var currentPage = 1;
  var currentTerm = "";
  var currentFolder = "";
  var requestNumber = 0;
  var lastFocused = null;
  var searchTimer = null;

  /** The selection: ids in the order chosen, and the item behind each id. */
  var selectedIds = [];
  var selectedItems = {};

  function word(key, values) {
    var text = String(messages[key] || "");
    Object.keys(values || {}).forEach(function (name) {
      text = text.split(":" + name).join(String(values[name]));
    });
    return text;
  }

  function setStatus(message, isError) {
    statusEl.textContent = message || "";
    statusEl.classList.toggle("is-error", !!isError);
  }

  /** image, video, or a filter on one (social_image: no SVG), as the field says. */
  function kindOf(field) {
    var kind = field.getAttribute("data-media-picker-kind") || "";
    return Object.prototype.hasOwnProperty.call(kinds, kind) ? kind : "image";
  }

  /** Whether the field collects items for a list rather than holding one. */
  function collects(field) {
    return !!field && field.hasAttribute("data-media-picker-collect");
  }

  // --- Grid or list ------------------------------------------------------------

  function storedView() {
    try {
      return window.localStorage.getItem(VIEW_STORAGE_KEY) === "list" ? "list" : "grid";
    } catch (e) {
      return "grid";
    }
  }

  function applyView(view) {
    modal.setAttribute("data-media-view", view);
    modal.querySelectorAll("[data-media-modal-view]").forEach(function (option) {
      option.setAttribute("aria-pressed", option.getAttribute("data-media-modal-view") === view ? "true" : "false");
    });
  }

  // --- Opening and closing -----------------------------------------------------

  function openModal(field) {
    activeField = field;
    lastFocused = document.activeElement;
    currentKind = kindOf(field);
    uploadInput.multiple = collects(field);
    selectedIds = [];
    selectedItems = {};

    var words = kinds[currentKind] || {};
    if (words.accept) {
      uploadInput.accept = words.accept;
    }
    if (uploadButton && words.upload) {
      uploadButton.textContent = words.upload;
    }

    applyView(storedView());

    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("admin-media-modal-open");

    searchInput.value = "";
    currentTerm = "";
    updateSelection();
    load(1, true);

    searchInput.focus();
  }

  /** Closes without touching the field: Annuleren, Escape, × and the backdrop. */
  function closeModal() {
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("admin-media-modal-open");
    activeField = null;
    selectedIds = [];
    selectedItems = {};
    requestNumber++;

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  // --- Listing -----------------------------------------------------------------

  /** The folder list the first page brought along: "Alle media", "Geen map", then each folder. */
  function fillFolders(overview) {
    if (!folderSelect || !overview || !Array.isArray(overview.folders)) {
      return;
    }

    var keep = currentFolder;

    while (folderSelect.options.length > 2) {
      folderSelect.remove(2);
    }

    overview.folders.forEach(function (folder) {
      var option = document.createElement("option");
      option.value = String(folder.id);
      option.textContent = String(folder.name);
      folderSelect.appendChild(option);
    });

    folderSelect.value = keep;
    if (folderSelect.selectedIndex === -1) {
      folderSelect.value = "";
      currentFolder = "";
    }
  }

  /**
   * Renders one page of results. `replace` starts a fresh list (a new search,
   * another folder, a freshly opened modal); otherwise the page is appended,
   * which is what "Meer laden" does. An answer to an older request than the
   * latest is dropped, so a quick change of folder never shows the wrong one.
   */
  function load(page, replace) {
    var mine = ++requestNumber;

    setStatus(word("loading"), false);

    var url =
      config.listUrl +
      "?q=" + encodeURIComponent(currentTerm) +
      "&page=" + encodeURIComponent(page) +
      "&type=" + encodeURIComponent(currentKind) +
      "&folder=" + encodeURIComponent(currentFolder);

    fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (mine !== requestNumber) {
          return;
        }

        if (data.folders) {
          fillFolders(data.folders);
        }

        if (replace) {
          grid.replaceChildren();
        }

        (data.items || []).forEach(function (item) {
          if (!grid.querySelector('[data-media-id="' + Number(item.id) + '"]')) {
            grid.appendChild(buildCard(item));
          }
        });

        currentPage = data.page || page;
        moreButton.hidden = !data.has_more;

        if ((data.total || 0) === 0) {
          setStatus(
            currentTerm !== ""
              ? word("noResults", { term: currentTerm })
              : currentFolder !== ""
                ? word("folderEmpty")
                : (kinds[currentKind] || {}).empty || "",
            false
          );
        } else {
          setStatus("", false);
        }
      })
      .catch(function () {
        if (mine === requestNumber) {
          setStatus(word("loadFailed"), true);
        }
      });
  }

  /** The same decorative picture media_video_icon() prints. */
  function videoIcon() {
    var ns = "http://www.w3.org/2000/svg";
    var wrap = document.createElement("span");
    wrap.className = "admin-media-video-icon";
    wrap.setAttribute("aria-hidden", "true");

    var svg = document.createElementNS(ns, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("width", "32");
    svg.setAttribute("height", "32");
    svg.setAttribute("focusable", "false");

    var frame = document.createElementNS(ns, "rect");
    [["x", "2.5"], ["y", "5"], ["width", "19"], ["height", "14"], ["rx", "2.5"], ["fill", "none"], ["stroke", "currentColor"], ["stroke-width", "1.6"]].forEach(function (pair) {
      frame.setAttribute(pair[0], pair[1]);
    });

    var play = document.createElementNS(ns, "path");
    play.setAttribute("d", "M10 9.2v5.6l4.8-2.8z");
    play.setAttribute("fill", "currentColor");

    svg.appendChild(frame);
    svg.appendChild(play);
    wrap.appendChild(svg);

    return wrap;
  }

  /** The picture of an item: its thumbnail, a video icon, or the missing-file warning. */
  function pictureOf(item, warningClass) {
    if (item.missing) {
      var warn = document.createElement("span");
      warn.className = warningClass;
      warn.textContent = word("missing");
      return warn;
    }

    if (item.kind === "video") {
      return videoIcon();
    }

    var img = document.createElement("img");
    img.src = item.thumbnail;
    img.alt = "";
    img.loading = "lazy";
    return img;
  }

  /** Bytes as a person reads them, the way the library screen prints them. */
  function sizeOf(bytes) {
    var n = Number(bytes || 0);
    if (n < 1) return "";
    if (n < 1024) return n + " B";
    if (n < 1024 * 1024) return Math.round(n / 1024) + " kB";
    return (n / (1024 * 1024)).toFixed(1).replace(".", ",") + " MB";
  }

  /**
   * One card: a toggle button (aria-pressed) with the picture, the name and
   * what kind of file it is. textContent throughout: a name is an editor's
   * string and never markup.
   */
  function buildCard(item) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "admin-media-card";
    button.setAttribute("data-media-id", String(Number(item.id)));
    if (item.missing) {
      button.classList.add("is-missing");
    }

    var figure = document.createElement("span");
    figure.className = "admin-media-card__media";
    figure.appendChild(pictureOf(item, "admin-media-card__warning"));

    var name = document.createElement("span");
    name.className = "admin-media-card__name";
    name.textContent = item.name;

    var meta = document.createElement("span");
    meta.className = "admin-media-card__meta";
    meta.textContent = [item.type || "", item.width && item.height ? item.width + " × " + item.height : "", sizeOf(item.size)]
      .filter(function (part) { return part !== ""; })
      .join(" · ");

    button.appendChild(figure);
    button.appendChild(name);
    button.appendChild(meta);
    // The item behind the card, for the selection: a property, never markup.
    button.mediaItem = item;
    markCard(button);

    return button;
  }

  function markCard(card) {
    var chosen = selectedIds.indexOf(Number(card.getAttribute("data-media-id"))) !== -1;
    card.setAttribute("aria-pressed", chosen ? "true" : "false");
    card.classList.toggle("is-selected", chosen);
  }

  // --- The selection -------------------------------------------------------------

  /** Adds an item, or takes it out again; a single field holds at most one. */
  function toggle(item, forceOn) {
    var id = Number(item.id);
    var at = selectedIds.indexOf(id);

    if (at !== -1 && !forceOn) {
      selectedIds.splice(at, 1);
      delete selectedItems[id];
    } else if (at === -1) {
      if (!collects(activeField)) {
        selectedIds = [];
        selectedItems = {};
      }
      selectedIds.push(id);
      selectedItems[id] = item;
    } else {
      selectedItems[id] = item;
    }

    updateSelection();
  }

  function updateSelection() {
    grid.querySelectorAll("[data-media-id]").forEach(markCard);

    var count = selectedIds.length;
    confirmButton.disabled = count === 0;
    countEl.textContent = count === 0 ? word("selectedNone") : count === 1 ? word("selectedOne") : word("selectedMany", { count: count });
  }

  /** "Selecteren": exactly the chosen items go to the field, and nothing else. */
  function confirmSelection() {
    if (!activeField || selectedIds.length === 0) {
      return;
    }

    var field = activeField;
    var items = selectedIds.map(function (id) {
      return selectedItems[id];
    });

    if (collects(field)) {
      items.forEach(function (item) {
        field.dispatchEvent(new CustomEvent("media-picker:choose", { bubbles: true, detail: item }));
      });
      closeModal();
      return;
    }

    applyToField(field, items[0]);
    closeModal();
  }

  /** The alt-text input that belongs to a field, when the screen linked one. */
  function linkedAlt(field) {
    var input = field.querySelector("[data-media-picker-input]");
    var scope = input && input.form ? input.form : document;

    if (!input || !input.name) {
      return null;
    }

    var name = window.CSS && typeof window.CSS.escape === "function" ? window.CSS.escape(input.name) : input.name.replace(/["\\]/g, "\\$&");

    return scope.querySelector('[data-media-alt-for="' + name + '"]');
  }

  /**
   * Shows the alt text a newly chosen image really gets: its library alt
   * text, or nothing plus the placeholder that says it has none. Fires input
   * so the save bar counts it as a change like any typed one.
   */
  function fillAlt(field, item) {
    var alt = linkedAlt(field);

    if (!alt) {
      return;
    }

    var text = item ? String(item.alt || "") : "";
    alt.value = text;
    alt.placeholder = item && text === "" ? word("noAlt") : "";
    alt.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function applyToField(field, item) {
    var input = field.querySelector("[data-media-picker-input]");
    var preview = field.querySelector("[data-media-picker-preview]");
    var clear = field.querySelector("[data-media-picker-clear]");

    input.value = item.id;

    preview.replaceChildren();
    preview.appendChild(pictureOf(item, "admin-media-picker__missing"));

    var name = document.createElement("span");
    name.className = "admin-media-picker__name";
    name.textContent = item.name;
    preview.appendChild(name);

    if (clear) {
      clear.hidden = false;
    }

    // The surrounding form may be watching for a change (an unsaved-changes
    // warning, a live preview); a programmatic value assignment fires nothing
    // on its own.
    input.dispatchEvent(new Event("change", { bubbles: true }));

    if (item.kind !== "video") {
      fillAlt(field, item);
    }
  }

  // --- Uploading into the library ---------------------------------------------------

  /** The one place the native file dialog is opened: the upload button's own click. */
  function openFileDialog() {
    uploadInput.value = "";
    uploadInput.click();
  }

  /** One file to the library; resolves with the endpoint's answer. */
  function send(file) {
    var body = new FormData();
    body.append("file", file);
    body.append("csrf_token", config.csrfToken);
    body.append("kind", currentKind);
    body.append("folder_id", /^[0-9]+$/.test(currentFolder) ? currentFolder : "");

    return fetch(config.uploadUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          throw new Error(data.error || word("uploadFailed"));
        }
        return data;
      });
    });
  }

  /**
   * Every chosen file in turn, each one a library item as soon as the library
   * has it: shown at the top of the grid and selected. One refused file is
   * said out loud and the rest still go. Nothing is handed to the field until
   * "Selecteren".
   */
  function uploadAll(files) {
    var field = activeField;
    var queue = Array.prototype.slice.call(files || []);

    function next() {
      if (queue.length === 0 || activeField !== field) {
        uploadInput.value = "";
        return;
      }

      var file = queue.shift();
      setStatus(word("uploading", { name: file.name }), false);

      send(file)
        .then(function (data) {
          if (activeField !== field) {
            return;
          }

          var item = data.item;
          var existing = grid.querySelector('[data-media-id="' + Number(item.id) + '"]');

          if (existing) {
            existing.remove();
          }
          grid.insertBefore(buildCard(item), grid.firstChild);
          toggle(item, true);
          setStatus(data.reused ? word("reused") : word("uploaded", { name: item.name }), false);
        })
        .catch(function (error) {
          setStatus(file.name + ": " + (error.message || word("uploadFailed")), true);
        })
        .then(next);
    }

    next();
  }

  // --- Wiring ------------------------------------------------------------------------

  // Delegated, so a field that arrives later works too: a row a block
  // editor adds on screen (admin/assets/row-list.js) carries its own picker.
  document.addEventListener("click", function (event) {
    var target = event.target && event.target.closest ? event.target : null;
    var openButton = target ? target.closest("[data-media-picker-open]") : null;
    var clearButton = target ? target.closest("[data-media-picker-clear]") : null;
    var field = (openButton || clearButton) ? (openButton || clearButton).closest("[data-media-picker]") : null;

    if (!field) {
      return;
    }

    // Nothing else may happen on this click: no label activation, no form
    // submit. The picker's button opens the library, and only that.
    event.preventDefault();

    if (openButton) {
      if (modal.hidden) {
        openModal(field);
      }
      return;
    }

    var input = field.querySelector("[data-media-picker-input]");
    var preview = field.querySelector("[data-media-picker-preview]");

    input.value = "";
    preview.replaceChildren();

    var empty = document.createElement("span");
    empty.className = "admin-media-picker__empty";
    empty.textContent = field.getAttribute("data-media-picker-empty") || "";
    preview.appendChild(empty);

    clearButton.hidden = true;
    input.dispatchEvent(new Event("change", { bubbles: true }));
    fillAlt(field, null);
  });

  grid.addEventListener("click", function (event) {
    var card = event.target.closest ? event.target.closest("[data-media-id]") : null;

    if (!card || !activeField) {
      return;
    }

    var id = Number(card.getAttribute("data-media-id"));
    var known = selectedItems[id];

    if (known) {
      toggle(known);
      return;
    }

    // The item as the list answered it, kept on the card when it was built.
    toggle(card.mediaItem || { id: id });
  });

  modal.querySelectorAll("[data-media-modal-close]").forEach(function (button) {
    button.addEventListener("click", closeModal);
  });

  confirmButton.addEventListener("click", confirmSelection);

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });

  searchInput.addEventListener("input", function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(function () {
      currentTerm = searchInput.value.trim();
      load(1, true);
    }, 250);
  });

  if (folderSelect) {
    folderSelect.addEventListener("change", function () {
      currentFolder = folderSelect.value;
      load(1, true);
    });
  }

  modal.querySelectorAll("[data-media-modal-view]").forEach(function (option) {
    option.addEventListener("click", function () {
      var view = option.getAttribute("data-media-modal-view") === "list" ? "list" : "grid";
      applyView(view);
      try {
        window.localStorage.setItem(VIEW_STORAGE_KEY, view);
      } catch (e) {
        // Not remembered for next time, but still shown now.
      }
    });
  });

  if (uploadButton) {
    uploadButton.addEventListener("click", openFileDialog);
  }

  uploadInput.addEventListener("change", function () {
    if (!activeField || !uploadInput.files || uploadInput.files.length === 0) {
      return;
    }
    uploadAll(collects(activeField) ? uploadInput.files : [uploadInput.files[0]]);
  });

  moreButton.addEventListener("click", function () {
    load(currentPage + 1, false);
  });
})();
