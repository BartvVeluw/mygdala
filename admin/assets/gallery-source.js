/*
 * Where a gallery item's picture comes from (admin/_gallery_source_field.php):
 * the focus frame of the row (admin/_responsive_image_field.php) shows the
 * picture the row shows NOW, while it is being chosen, not after a save.
 *
 *   a library picture   what its Media picker holds
 *   an item of the site  asked of api/admin/linked-image-preview.php, the
 *                        same live resolution the website uses
 *                        (App\Service\Media\LinkedImages): no kind is known
 *                        here, and no path is stored anywhere
 *
 * The answer reaches the frame as an "rm:picture" event from the row
 * (admin/assets/responsive-image.js). An item a visitor cannot see answers no
 * picture: the frame hides and the row's [data-linked-image-missing] line
 * says why. Only a list that names the endpoint takes part
 * ([data-linked-image-preview] on the row list); the form's own section and
 * CSRF token go along, so the endpoint checks who may edit that list.
 *
 * The focus sliders stay what they are whatever is shown, so a point set
 * before the first save is saved with it. A slower answer to an earlier
 * choice never overwrites a later one. This file holds no text of its own.
 *
 * Detailsectie 2.1: a public item WITHOUT a main picture yet answers
 * {available: true, picture: false}; the row's [data-linked-image-no-picture]
 * warning says so (nothing to choose here, the website shows its name). And
 * a folded row's summary line ([data-row-list-title], admin/_editor_rows.php)
 * follows the source chosen on screen: " — <source>: <name>", from the
 * source option's own text and the chosen item's data-name, or the library
 * picture's name in its picker.
 */
(function () {
  "use strict";

  var sequence = 0;

  function show(row, src, available, picture) {
    var missing = row.querySelector("[data-linked-image-missing]");
    if (missing) missing.hidden = available;
    var blank = row.querySelector("[data-linked-image-no-picture]");
    if (blank) blank.hidden = !(available && picture === false);
    row.dispatchEvent(new CustomEvent("rm:picture", { bubbles: true, detail: { src: src } }));
  }

  function retitle(row) {
    var title = row.querySelector("[data-row-list-title]");
    var type = row.querySelector("[data-nav-link-type]");
    if (!title || !type) return;

    var option = type.selectedOptions[0];
    var source = option ? option.textContent.trim() : "";
    var name = "";

    if (type.value === "media") {
      var input = row.querySelector('[data-nav-link-field="media"] [data-media-picker-input]');
      var chosen = row.querySelector('[data-nav-link-field="media"] .admin-media-picker__name');
      name = input && input.value !== "" && chosen ? chosen.textContent.trim() : "";
    } else {
      var select = row.querySelector('[data-destination-select="' + type.value + '"]');
      var item = select ? select.selectedOptions[0] : null;
      name = item && item.value !== "" ? (item.getAttribute("data-name") || item.textContent).trim() : "";
    }

    title.textContent = name === "" ? "" : " \u2014 " + source + ": " + name;
  }

  function libraryPicture(row) {
    var input = row.querySelector('[data-nav-link-field="media"] [data-media-picker-input]');
    var image = row.querySelector('[data-nav-link-field="media"] [data-media-picker-preview] img');
    return input && input.value !== "" && image ? image.getAttribute("src") || "" : "";
  }

  function refresh(row, list) {
    var type = row.querySelector("[data-nav-link-type]");
    var kind = type ? type.value : "media";
    var ticket = String(++sequence);
    row.setAttribute("data-linked-image-ticket", ticket);

    if (kind === "media") {
      show(row, libraryPicture(row), true, true);
      return;
    }

    var select = row.querySelector('[data-destination-select="' + kind + '"]');
    var id = select ? select.value : "";
    if (id === "") {
      show(row, "", true, true);
      return;
    }

    var form = row.closest("form");
    var body = new FormData();
    body.set("csrf_token", form && form.querySelector('[name="csrf_token"]') ? form.querySelector('[name="csrf_token"]').value : "");
    body.set("section", form && form.querySelector('[name="section"]') ? form.querySelector('[name="section"]').value : "");
    body.set("kind", kind);
    body.set("id", id);

    fetch(list.getAttribute("data-linked-image-preview"), { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) { return response.ok ? response.json() : { src: "", available: false }; })
      .catch(function () { return { src: "", available: false }; })
      .then(function (answer) {
        if (row.getAttribute("data-linked-image-ticket") !== ticket) return;
        show(row, answer && typeof answer.src === "string" ? answer.src : "", !!(answer && answer.available), !(answer && answer.picture === false));
      });
  }

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    var list = target.closest("[data-linked-image-preview]");
    var row = target.closest("[data-row-list-row]");
    if (!list || !row) return;

    if (target.matches("[data-nav-link-type], [data-destination-select]")) {
      refresh(row, list);
      retitle(row);
    } else if (target.matches('[data-nav-link-field="media"] [data-media-picker-input]')) {
      retitle(row);
    }
  });
})();
