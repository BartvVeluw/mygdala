/**
 * Shop → Betalingen (admin/payments.php): the two actions that are not part
 * of the save.
 *
 * TEST A KEY. A button with data-payments-test posts to the connection test
 * (its formaction, api/admin/test-payment-connection.php) and shows the
 * answer in the live region data-payments-test-result of the same mode,
 * without leaving the page and without saving anything. For "test" or
 * "live" it sends the key typed in that field, if any — in the POST body,
 * like the form would — so a key can be tried before it is saved. The key
 * is never put anywhere else and never read back from the answer, which does
 * not contain it. Without this script the same button posts the form there
 * and the answer comes back as a message on the page.
 *
 * COPY the webhook address. The button stays hidden where the browser has no
 * clipboard API; the address is selectable text either way.
 *
 * Every word comes from the page (data-label-*) or from the server's answer:
 * nothing an administrator reads is written here (ADMIN-UI.md). Listeners sit
 * on the document, so they keep working after the dynamic editor draws a
 * region again (admin/assets/admin-editor.js).
 */
(function () {
  "use strict";

  var form = document.getElementById("payments-editor");
  if (!form || typeof window.fetch !== "function" || typeof window.FormData !== "function") return;

  function resultFor(mode) {
    return document.querySelector('[data-payments-test-result="' + mode + '"]');
  }

  function show(mode, ok, text) {
    var box = resultFor(mode);
    if (!box) return;
    box.hidden = false;
    box.textContent = text;
    box.classList.toggle("admin-payments-result--ok", ok);
    box.classList.toggle("admin-payments-result--error", !ok);
  }

  document.addEventListener("click", function (event) {
    var button = event.target && event.target.closest ? event.target.closest("[data-payments-test]") : null;
    if (!button) return;

    // The script's own request instead of the form post (which would also
    // be caught by the editor and saved).
    event.preventDefault();
    if (button.disabled) return;

    var mode = button.getAttribute("data-payments-test") || "active";
    var body = new FormData();
    body.append("csrf_token", form.elements.csrf_token ? form.elements.csrf_token.value : "");
    body.append("test_mode", mode);
    var typed = form.elements[mode + "_api_key"];
    if (typed && typed.value) body.append(mode + "_api_key", typed.value);

    var label = button.textContent;
    var failed = button.getAttribute("data-label-failed") || "";
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.textContent = button.getAttribute("data-label-busy") || label;

    fetch(button.getAttribute("formaction") || form.action, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      body: body
    })
      .then(function (response) {
        return response.json().then(function (answer) {
          if (!response.ok || !answer || typeof answer.ok !== "boolean") throw new Error("answer");
          show(mode, answer.ok, String(answer.message || ""));
        });
      })
      .catch(function () {
        show(mode, false, failed);
      })
      .then(function () {
        button.disabled = false;
        button.removeAttribute("aria-busy");
        button.textContent = label;
      });
  });

  var canCopy = !!(navigator.clipboard && navigator.clipboard.writeText);

  Array.prototype.forEach.call(document.querySelectorAll("[data-payments-copy]"), function (button) {
    if (canCopy) button.hidden = false;
  });

  document.addEventListener("click", function (event) {
    var button = event.target && event.target.closest ? event.target.closest("[data-payments-copy]") : null;
    if (!button || !canCopy) return;

    var source = document.getElementById(button.getAttribute("data-payments-copy"));
    if (!source) return;

    var label = button.textContent;
    navigator.clipboard.writeText(source.textContent.trim()).then(function () {
      button.textContent = button.getAttribute("data-label-copied") || label;
      window.setTimeout(function () {
        button.textContent = label;
      }, 2000);
    });
  });
})();
