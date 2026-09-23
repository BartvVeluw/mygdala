/**
 * The editor of one header item (admin/navigation-item.php): shows only the
 * destination field that belongs to the chosen kind of destination, and the
 * button style only while the item is shown as a button.
 *
 * The footer link editor (admin/footer-link.php) uses this same file through
 * the same data attributes, because a footer link has the same destination
 * picker plus one kind of its own ("action"). It has no presentation choice,
 * so only the destination part applies there.
 *
 * A content block's button (admin/_link_target_field.php: a carousel card,
 * the Homepage Hero's two buttons) is a destination picker too — nothing, a
 * page, a blog post, a product or a typed address — and uses the destination
 * part through the same attributes. A form with more than one button wraps
 * each in its own [data-nav-link-group]: the fields inside a group follow
 * that group's kind, and a field outside every group follows the form's
 * first kind, which is all a form with one button needs.
 *
 * Nothing here is needed to use the screen. Without this file every field is
 * on screen and api/admin/_nav_item_input.php stores only the one that
 * belongs to the chosen kind. The file holds no text of its own and never
 * posts; it replaces the inline script the screen used to carry.
 *
 * A hidden field keeps its value: switching the kind back shows what was
 * there. "Nergens heen" (a submenu heading) is not a destination a button can
 * have, so that option is disabled while "Knop" is chosen — and if it was
 * selected, the first real kind is selected instead, so the form never shows
 * a combination the server would refuse.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-nav-item-form]");
  if (!form) return;

  var kind = form.querySelector("[data-nav-link-type]");
  var presentation = form.querySelector("[data-nav-presentation]");

  /** The kind select a field follows: its group's, else the form's first. */
  function kindFor(field) {
    var group = field.closest("[data-nav-link-group]");
    return (group && group.querySelector("[data-nav-link-type]")) || kind;
  }

  function syncDestination() {
    if (!kind) return;

    form.querySelectorAll("[data-nav-link-field]").forEach(function (field) {
      var kinds = (field.getAttribute("data-nav-link-field") || "").split(" ");
      var select = kindFor(field);
      field.hidden = kinds.indexOf(select.value) === -1;
    });
  }

  function syncPresentation() {
    if (!presentation) return;

    var isButton = presentation.value === "button";

    form.querySelectorAll("[data-nav-button-field]").forEach(function (field) {
      field.hidden = !isButton;
    });

    if (!kind) return;

    kind.querySelectorAll("[data-nav-link-only]").forEach(function (option) {
      option.disabled = isButton;
      if (isButton && option.selected) {
        kind.value = "page";
        // The kind really changed, so the save bar and anything else
        // listening hear about it like any other edit.
        kind.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
  }

  form.querySelectorAll("[data-nav-link-type]").forEach(function (select) {
    select.addEventListener("change", syncDestination);
  });
  if (presentation) presentation.addEventListener("change", syncPresentation);

  syncPresentation();
  syncDestination();
})();
