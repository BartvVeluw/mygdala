/* =========================================================================
   Owner: admin/block-preview.php, the sample of one content block inside
   the Contentblokken library's preview dialog. No public page loads it.

   A preview is there to look at. Every block script runs as it does on the
   site, a carousel turns and a gallery zooms, but nothing in it may take the
   editor anywhere or send anything:

   - a click on a link does nothing. Every sample link points at #voorbeeld,
     yet even that would scroll or navigate the frame;
   - a form is never sent. The document's Content-Security-Policy
     (form-action 'none') and the iframe's sandbox already refuse a real
     submission; this stops the submit event before a block's own script
     (assets/js/blocks/form.js) can send the form with fetch() instead.

   Both listen in the capture phase on the document, so they run before any
   listener a block puts on its own elements.

   And one thing goes out: Escape. The frame is sandboxed WITHOUT
   allow-same-origin, so the dialog around it cannot listen in here, and with
   the focus inside the preview the key would never reach it. So Escape is
   posted to the parent, as one fixed message, addressed to this CMS's own
   origin only (the preview may only be framed by it: frame-ancestors 'self').
   admin/assets/block-library.js accepts it from this frame alone.

   Not here: anything that changes how a block looks or behaves. A preview
   that needs a block-specific branch is a preview that stopped being the
   real block.
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

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape" || window.parent === window) return;

    // The address of this document, not its sandboxed (opaque) origin: the
    // CMS that framed it lives at exactly this scheme, host and port.
    window.parent.postMessage({ mygdalaBlockPreview: "escape" }, window.location.protocol + "//" + window.location.host);
  });
})();
