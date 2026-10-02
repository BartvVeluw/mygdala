/* =========================================================================
   Owner: admin/theme-preview.php, the start page in a Global Theme inside
   the preview frame on the tab Thema of Vormgeving. No public page loads it.

   The preview is there to look at. Every script of the page runs as it does
   on the site (the hero draws, a carousel turns, the menu opens), but
   nothing in it may take the editor anywhere or send anything:

   - a click on a link does nothing. The links are the site's real ones,
     and following one would leave the preview for a page in the website's
     own theme, which is exactly the confusion a preview must not cause;
   - a form is never sent. The document's Content-Security-Policy
     (form-action 'none') and the frame's sandbox already refuse a real
     submission; this stops the submit event before a block's own script
     (assets/js/blocks/form.js) can send the form with fetch() instead.

   Both listen in the capture phase on the document, so they run before any
   listener a block puts on its own elements. The same two rules as
   assets/js/block-preview.js, the block library's preview: a file of its
   own because each file has one owner.

   Not here: anything that changes how the page looks. A preview that needs
   a theme-specific branch is a preview that stopped being the real site.
   ========================================================================= */
(function () {
  "use strict";

  document.addEventListener(
    "click",
    function (event) {
      var target = event.target;
      if (target && target.closest && target.closest("a[href]")) {
        event.preventDefault();
      }
    },
    true
  );

  document.addEventListener(
    "submit",
    function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();
    },
    true
  );
})();
