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
 * one opened it.
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

  var activeField = null;
  var currentPage = 1;
  var currentTerm = "";
  var loading = false;
  var lastFocused = null;
  var searchTimer = null;

  function setStatus(message, isError) {
    statusEl.textContent = message || "";
    statusEl.classList.toggle("is-error", !!isError);
  }

  function openModal(field) {
    activeField = field;
    lastFocused = document.activeElement;

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
      encodeURIComponent(page);

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
              ? "De mediabibliotheek is nog leeg. Kies hierboven een bestand om er een aan toe te voegen."
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

  function buildCard(item) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "admin-media-card";
    if (item.missing) {
      button.classList.add("is-missing");
    }

    var figure = document.createElement("span");
    figure.className = "admin-media-card__media";

    if (item.missing) {
      var warn = document.createElement("span");
      warn.className = "admin-media-card__warning";
      warn.textContent = "Bestand ontbreekt";
      figure.appendChild(warn);
    } else {
      var img = document.createElement("img");
      img.src = item.thumbnail;
      img.alt = "";
      img.loading = "lazy";
      figure.appendChild(img);
    }

    var name = document.createElement("span");
    name.className = "admin-media-card__name";
    name.textContent = item.name;

    // textContent throughout: a filename and an alt text are editor-supplied
    // strings and never markup.
    var meta = document.createElement("span");
    meta.className = "admin-media-card__meta";
    meta.textContent = item.width && item.height ? item.width + " × " + item.height : "";

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

    if (item.missing) {
      var warn = document.createElement("span");
      warn.className = "admin-media-picker__missing";
      warn.textContent = "Bestand ontbreekt";
      preview.appendChild(warn);
    } else {
      var img = document.createElement("img");
      img.src = item.thumbnail;
      img.alt = "";
      img.loading = "lazy";
      preview.appendChild(img);
    }

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
          setStatus("Deze afbeelding stond al in de bibliotheek en is opnieuw gebruikt.", false);
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
    empty.textContent = "Nog geen afbeelding gekozen.";
    preview.appendChild(empty);

    clearButton.hidden = true;
    input.dispatchEvent(new Event("change", { bubbles: true }));
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
