/**
 * The Media picker's behaviour: open the shared modal, browse and search the
 * library, upload a new image, and hand the chosen media id back to whichever
 * field asked for it.
 *
 * Loaded only by admin screens that render admin/_media_picker.php. It owns
 * nothing but the picker: no other admin interaction runs through here, and
 * every field it fills is an ordinary hidden input that the surrounding
 * <form> posts like any other value. Turn this script off and the form still
 * submits — with whatever was already selected.
 *
 * One modal serves every field on the page; `activeField` remembers which
 * one opened it, and that field's kind (data-media-picker-kind: image or
 * video) decides what the modal lists and what its upload accepts. The
 * server applies the same kind again (api/admin/media-list.php ?type=,
 * api/admin/media-upload.php kind=).
 *
 * An alt-text input linked to the field (data-media-alt-for="<its name>",
 * media_alt_field() in admin/_media_picker.php) is filled with the chosen
 * image's library alt text, or emptied with a placeholder when that image
 * has none, so the editor sees the alt text that will really be used.
 */
(function () {
  "use strict";

  var modal = document.querySelector("[data-media-modal]");
  var configEl = document.querySelector("[data-media-picker-config]");

  if (!modal || !configEl) {
    return;
  }

  var config;
  try {
    config = JSON.parse(configEl.textContent || "{}");
  } catch (e) {
    return;
  }

  var grid = modal.querySelector("[data-media-modal-grid]");
  var searchInput = modal.querySelector("[data-media-modal-search]");
  var uploadInput = modal.querySelector("[data-media-modal-upload]");
  var statusEl = modal.querySelector("[data-media-modal-status]");
  var moreButton = modal.querySelector("[data-media-modal-more]");
  var uploadLabel = modal.querySelector("[data-media-modal-upload-label]");
  var kinds = config.kinds || {};
  var messages = config.messages || {};

  var activeField = null;
  var currentKind = "image";
  var currentPage = 1;
  var currentTerm = "";
  var loading = false;
  var lastFocused = null;
  var searchTimer = null;

  function setStatus(message, isError) {
    statusEl.textContent = message || "";
    statusEl.classList.toggle("is-error", !!isError);
  }

  function kindOf(field) {
    return field.getAttribute("data-media-picker-kind") === "video" ? "video" : "image";
  }

  function openModal(field) {
    activeField = field;
    lastFocused = document.activeElement;
    currentKind = kindOf(field);

    var words = kinds[currentKind] || {};
    if (words.accept) {
      uploadInput.accept = words.accept;
    }
    if (uploadLabel && words.upload) {
      uploadLabel.textContent = words.upload;
    }

    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("admin-media-modal-open");

    searchInput.value = "";
    currentTerm = "";
    load(1, true);

    searchInput.focus();
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("admin-media-modal-open");
    activeField = null;

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  /**
   * Renders one page of results. `replace` starts a fresh list (a new search,
   * or a freshly opened modal); otherwise the page is appended, which is what
   * "Meer laden" does.
   */
  function load(page, replace) {
    if (loading) {
      return;
    }

    loading = true;
    setStatus("Laden…", false);

    var url =
      config.listUrl +
      "?q=" +
      encodeURIComponent(currentTerm) +
      "&page=" +
      encodeURIComponent(page) +
      "&type=" +
      encodeURIComponent(currentKind);

    fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (replace) {
          grid.innerHTML = "";
        }

        (data.items || []).forEach(function (item) {
          grid.appendChild(buildCard(item));
        });

        currentPage = data.page || page;
        moreButton.hidden = !data.has_more;

        if ((data.total || 0) === 0) {
          setStatus(
            currentTerm === ""
              ? (kinds[currentKind] || {}).empty || ""
              : "Geen resultaten voor “" + currentTerm + "”.",
            false
          );
        } else {
          setStatus("", false);
        }
      })
      .catch(function () {
        setStatus("De mediabibliotheek kon niet worden geladen.", true);
      })
      .finally(function () {
        loading = false;
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
      warn.textContent = messages.missing || "Bestand ontbreekt";
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
    alt.placeholder = item && text === "" ? messages.noAlt || "" : "";
    alt.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function buildCard(item) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "admin-media-card";
    if (item.missing) {
      button.classList.add("is-missing");
    }

    var figure = document.createElement("span");
    figure.className = "admin-media-card__media";
    figure.appendChild(pictureOf(item, "admin-media-card__warning"));

    var name = document.createElement("span");
    name.className = "admin-media-card__name";
    name.textContent = item.name;

    // textContent throughout: a filename and an alt text are editor-supplied
    // strings and never markup.
    var meta = document.createElement("span");
    meta.className = "admin-media-card__meta";
    meta.textContent = item.width && item.height ? item.width + " × " + item.height : item.type || "";

    button.appendChild(figure);
    button.appendChild(name);
    button.appendChild(meta);

    button.addEventListener("click", function () {
      select(item);
    });

    return button;
  }

  function select(item) {
    if (!activeField) {
      return;
    }

    var input = activeField.querySelector("[data-media-picker-input]");
    var preview = activeField.querySelector("[data-media-picker-preview]");
    var clear = activeField.querySelector("[data-media-picker-clear]");

    input.value = item.id;

    preview.innerHTML = "";
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
      fillAlt(activeField, item);
    }

    closeModal();
  }

  function upload(file) {
    if (!file) {
      return;
    }

    setStatus("Uploaden…", false);

    var body = new FormData();
    body.append("file", file);
    body.append("csrf_token", config.csrfToken);
    body.append("kind", currentKind);

    fetch(config.uploadUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) {
            throw new Error(data.error || "Uploaden is mislukt.");
          }
          return data;
        });
      })
      .then(function (data) {
        if (data.reused) {
          setStatus(messages.reused || "", false);
        }
        select(data.item);
      })
      .catch(function (error) {
        setStatus(error.message || "Uploaden is mislukt.", true);
      })
      .finally(function () {
        uploadInput.value = "";
      });
  }

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

    if (openButton) {
      openModal(field);
      return;
    }

    var input = field.querySelector("[data-media-picker-input]");
    var preview = field.querySelector("[data-media-picker-preview]");

    input.value = "";
    preview.innerHTML = "";

    var empty = document.createElement("span");
    empty.className = "admin-media-picker__empty";
    empty.textContent = field.getAttribute("data-media-picker-empty") || "";
    preview.appendChild(empty);

    clearButton.hidden = true;
    input.dispatchEvent(new Event("change", { bubbles: true }));
    fillAlt(field, null);
  });

  modal.querySelectorAll("[data-media-modal-close]").forEach(function (button) {
    button.addEventListener("click", closeModal);
  });

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

  uploadInput.addEventListener("change", function () {
    upload(uploadInput.files && uploadInput.files[0]);
  });

  moreButton.addEventListener("click", function () {
    load(currentPage + 1, false);
  });
})();
