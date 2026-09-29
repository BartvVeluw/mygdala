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
 */
(function () {
  "use strict";

  var sequence = 0;

  function show(row, src, available) {
    var missing = row.querySelector("[data-linked-image-missing]");
    if (missing) missing.hidden = available;
    row.dispatchEvent(new CustomEvent("rm:picture", { bubbles: true, detail: { src: src } }));
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
      show(row, libraryPicture(row), true);
      return;
    }

    var select = row.querySelector('[data-destination-select="' + kind + '"]');
    var id = select ? select.value : "";
    if (id === "") {
      show(row, "", true);
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
        show(row, answer && typeof answer.src === "string" ? answer.src : "", !!(answer && answer.available));
      });
  }

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element) || !target.matches("[data-nav-link-type], [data-destination-select]")) return;

    var list = target.closest("[data-linked-image-preview]");
    var row = target.closest("[data-row-list-row]");
    if (list && row) refresh(row, list);
  });
})();
