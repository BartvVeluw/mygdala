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
 * first kind, which is all a form with one button needs. A list of rows with
 * a button each (the items of Tekst met afbeelding) is the same thing: a
 * group per row, including rows added on screen after this file ran.
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
 *
 * "BOVENLIGGEND ITEM" (header items only, [data-nav-parent]): a button and a
 * heading without destination only exist on the top level, so while either
 * is chosen the list is off, and while a parent is chosen neither is
 * offered. The list itself only holds places the server accepts
 * (App\Service\NavigationTree); the endpoint judges again.
 *
 * "GEBRUIK TITEL VAN BESTEMMING" (header items only, [data-nav-label-follows]):
 * while a page link follows its page, the item's own text is hidden and no
 * longer required, and a line says what the menu will show — the chosen
 * page's title in the language being edited (its option's data-title). Any
 * other kind needs its own text, so the text is back the moment it is chosen.
 * The one field the server already hides, so it does not flash: without this
 * file, switching the follow off and saving brings it back, and the endpoint
 * asks for the words.
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
    return (group && group.querySelector("[data-nav-link-type]")) || form.querySelector("[data-nav-link-type]");
  }

  function syncDestination() {
    form.querySelectorAll("[data-nav-link-field]").forEach(function (field) {
      var kinds = (field.getAttribute("data-nav-link-field") || "").split(" ");
      var select = kindFor(field);
      if (select) field.hidden = kinds.indexOf(select.value) === -1;
    });
  }

  var parent = form.querySelector("[data-nav-parent]");
  var headingNote = form.querySelector("[data-nav-parent-heading-note]");

  function hasParent() {
    return !!(parent && !parent.disabled && parent.value !== "0" && parent.value !== "");
  }

  /**
   * "Bovenliggend item" against the two choices that only exist on the top
   * level: a header button has no place in the menu tree, and a heading
   * without destination never sits in a submenu. While either is chosen the
   * list is switched off (a disabled select is not posted, so the stored
   * place stays); while a parent is chosen, "Knop" is not offered.
   */
  function syncParent() {
    if (!parent) return;

    var isButton = !!(presentation && presentation.value === "button");
    var isHeading = !!(kind && kind.value === "none");
    parent.disabled = isButton || isHeading;
    if (headingNote) headingNote.hidden = !isHeading;

    if (presentation) {
      presentation.querySelectorAll('option[value="button"]').forEach(function (option) {
        option.disabled = hasParent();
      });
    }
  }

  function syncPresentation() {
    var isButton = !!(presentation && presentation.value === "button");

    form.querySelectorAll("[data-nav-button-field]").forEach(function (field) {
      field.hidden = !isButton;
    });

    if (!kind) return;

    // Also not for an item inside a submenu: a heading lives on top.
    var blocked = isButton || hasParent();

    kind.querySelectorAll("[data-nav-link-only]").forEach(function (option) {
      option.disabled = blocked;
      if (blocked && option.selected) {
        kind.value = "page";
        // The kind really changed, so the save bar and anything else
        // listening hear about it like any other edit.
        kind.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
  }

  var follows = form.querySelector("[data-nav-label-follows]");
  var own = form.querySelector("[data-nav-label-own]");
  var preview = form.querySelector("[data-nav-label-preview]");
  var previewTitle = form.querySelector("[data-nav-label-preview-title]");
  var pageSelect = form.querySelector('select[name="target_page_id"]');
  var labelInput = own ? own.querySelector('input[name="label"]') : null;

  function syncLabel() {
    if (!follows || !own) return;

    var following = !!(kind && kind.value === "page" && follows.checked);
    own.hidden = following;
    if (preview) preview.hidden = !following;

    if (labelInput) {
      if (following) {
        labelInput.removeAttribute("required");
      } else if (labelInput.hasAttribute("data-nav-label-required")) {
        labelInput.setAttribute("required", "");
      }
    }

    if (previewTitle && pageSelect) {
      var option = pageSelect.selectedOptions[0];
      previewTitle.textContent = option && option.value !== ""
        ? (option.getAttribute("data-title") || "")
        : (previewTitle.getAttribute("data-none") || "");
    }
  }

  // Listened for on the form, so a button in a row added later (a Tekst met
  // afbeelding item, admin/assets/row-list.js) follows its kind as well; an
  // added row is sorted out the moment it arrives.
  form.addEventListener("change", function (event) {
    var target = event.target;
    if (target && target.hasAttribute && target.hasAttribute("data-nav-link-type")) {
      syncDestination();
      syncParent();
    }
    if (target === parent) {
      syncPresentation();
      syncParent();
    }
    if (target === follows || target === pageSelect || (target && target.hasAttribute && target.hasAttribute("data-nav-link-type"))) syncLabel();
  });
  form.addEventListener("row-list:added", syncDestination);
  if (presentation) {
    presentation.addEventListener("change", function () {
      syncPresentation();
      syncParent();
    });
  }

  syncParent();
  syncPresentation();
  syncDestination();
  syncLabel();
})();
