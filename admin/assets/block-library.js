/**
 * The Contentblokken library's preview dialog (admin/_block_library.php):
 * open it from a card, show that block, switch the width, close it again.
 *
 * IT SHOWS AND NOTHING MORE. The block itself is admin/block-preview.php,
 * loaded into a sandboxed iframe that may run the block's scripts and cannot
 * send a form, open a window or navigate this screen. This file only points
 * the frame at the address the server printed on the card
 * (data-block-preview-src). It never builds one, never posts and holds no
 * text of its own: the heading, the description and the category are the
 * card's own words, copied as text, and every label is in the markup.
 *
 * A native modal <dialog>, like the confirmation dialog of the CMS
 * (ADMIN-UI.md): showModal() makes the page behind it inert, keeps Tab
 * inside and closes on Escape. On close the frame goes back to about:blank,
 * so a carousel or a marquee stops, and the focus returns to the button
 * that opened it. A click on the dimmed page around the dialog closes it
 * too. Nothing appears on hover alone. The frame's address is set on every
 * opening, so reopening a block loads it afresh.
 *
 * The width buttons only change the frame's width (data-viewport on the
 * stage, drawn in admin.css). The site's own media queries do the rest,
 * because the frame is a viewport of its own. The choice holds while this
 * screen is open and is not remembered anywhere.
 */
(function () {
  "use strict";

  var dialog = document.querySelector("[data-block-preview]");
  if (!dialog || typeof dialog.showModal !== "function") return;

  var titleEl = dialog.querySelector("[data-block-preview-title]");
  var descriptionEl = dialog.querySelector("[data-block-preview-description]");
  var categoryEl = dialog.querySelector("[data-block-preview-category]");
  var closeButton = dialog.querySelector("[data-block-preview-close]");
  var live = dialog.querySelector("[data-block-preview-live]");
  var stage = dialog.querySelector("[data-block-preview-stage]");
  var frame = dialog.querySelector("[data-block-preview-frame]");
  var fallback = dialog.querySelector("[data-block-preview-fallback]");
  var drawing = dialog.querySelector("[data-block-preview-drawing]");
  var viewportButtons = dialog.querySelectorAll("[data-block-preview-viewport]");

  var lastFocused = null;

  function textOf(card, selector) {
    var element = card ? card.querySelector(selector) : null;
    return element ? element.textContent.trim() : "";
  }

  function showViewport(name) {
    stage.setAttribute("data-viewport", name);
    Array.prototype.forEach.call(viewportButtons, function (button) {
      button.setAttribute("aria-pressed", button.getAttribute("data-block-preview-viewport") === name ? "true" : "false");
    });
  }

  function open(button) {
    var card = button.closest("[data-block-library-card]");
    var src = button.getAttribute("data-block-preview-src");

    titleEl.textContent = textOf(card, "[data-block-library-name]");
    descriptionEl.textContent = textOf(card, "[data-block-library-description]");
    categoryEl.textContent = textOf(card, "[data-block-library-category]");

    // With a sample: the real block in the frame. Without one: the card's
    // own drawing, larger, with the sentence that says why.
    live.hidden = !src;
    stage.hidden = !src;
    fallback.hidden = !!src;

    while (drawing.firstChild) drawing.removeChild(drawing.firstChild);

    if (src) {
      frame.setAttribute("title", button.getAttribute("data-block-preview-frame-title") || "");
      frame.setAttribute("src", src);
    } else {
      var visual = card ? card.querySelector(".admin-block-visual") : null;
      if (visual) drawing.appendChild(visual.cloneNode(true));
    }

    lastFocused = button;
    dialog.showModal();
    closeButton.focus();
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-block-preview-open]"), function (button) {
    button.addEventListener("click", function () {
      open(button);
    });
  });

  Array.prototype.forEach.call(viewportButtons, function (button) {
    button.addEventListener("click", function () {
      showViewport(button.getAttribute("data-block-preview-viewport"));
    });
  });

  /**
   * Stops the block and gives the focus back. Called right away by every way
   * of closing rather than only from the dialog's close event, which a
   * browser may deliver late; the close event still calls it, for any way
   * of closing this file does not know about. Doing it twice changes nothing.
   */
  function finish() {
    if (frame.getAttribute("src") !== "about:blank") {
      frame.setAttribute("src", "about:blank");
    }

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }

    lastFocused = null;
  }

  function close() {
    if (dialog.open) dialog.close();
    finish();
  }

  closeButton.addEventListener("click", close);

  // The panel fills the dialog, so a click that lands on the dialog element
  // itself landed on the dimmed page around it.
  dialog.addEventListener("click", function (event) {
    if (event.target === dialog) close();
  });

  // Escape: the browser closes the dialog itself, right after this.
  dialog.addEventListener("cancel", finish);

  // With the focus inside the preview (a question of a FAQ, a field of a
  // form) Escape reaches the frame's document and never this dialog, so the
  // frame is taught the same key. It is on this CMS's own origin, and the
  // preview document itself stays free of anything about this dialog.
  frame.addEventListener("load", function () {
    var framed;
    try {
      framed = frame.contentDocument;
    } catch (error) {
      return;
    }

    if (!framed || frame.getAttribute("src") === "about:blank") return;

    framed.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && dialog.open) close();
    });
  });

  // A close event that arrives after the dialog was already opened again
  // belongs to the previous preview, and must not blank this one.
  dialog.addEventListener("close", function () {
    if (!dialog.open) finish();
  });
})();
