/* =========================================================================
   Content block: Formulier (partials/form.php)
   Asked for by App\Service\Blocks\FormBlock::scripts() and
   App\Service\Blocks\ContactFormBlock::scripts().

   PROGRESSIVE ENHANCEMENT ONLY. Every form on this site already works with
   JavaScript switched off: it is a plain POST to /api/form-submit.php, which
   answers a browser with a redirect back to the page carrying the result.
   All this file does is submit the same form with fetch() so the page does
   not reload, and show the same messages the server would have rendered.
   Nothing here validates anything on the server's behalf — the server is the
   authority (App\Service\Forms\FormValidator) and re-checks whatever arrives.

   ONE SCRIPT, ANY NUMBER OF FORMS. It walks [data-form-block] and works
   inside each one, because the same form may be placed on a page twice and
   two different forms may sit on one page. Nothing is looked up with a bare
   document.querySelector.

   NO LANGUAGE CODE. Every sentence arrives from the server already in the
   language of the page: the answers of /api/form-submit.php are single
   strings, and the few sentences of its own this script shows (sending,
   failure, the summary heading, the success message) sit on the status
   element as data-form-* attributes. It writes them with textContent, or
   escaped where it builds markup.
   ========================================================================= */
(function () {
  "use strict";

  /* One sentence the server put on the status element. */
  function said(status, name) {
    return (status && status.getAttribute("data-form-" + name)) || "";
  }

  function initForm(form) {
    var token = form.getAttribute("data-form-token") || "";
    var summary = form.querySelector(".form-error-summary");
    var status = document.getElementById(token + "-status");
    var submitButton = form.querySelector('button[type="submit"]');
    var submitting = false;

    if (!summary || !status) return;

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (submitting) return;

      setSubmitting(true);

      fetch(form.getAttribute("action"), {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" },
      })
        .then(function (response) {
          return response
            .json()
            .catch(function () {
              return null;
            })
            .then(function (body) {
              return { ok: response.ok, body: body };
            });
        })
        .then(function (result) {
          setSubmitting(false);

          if (result.ok && result.body && result.body.ok) {
            showSuccess();
            return;
          }

          var body = result.body || {};
          showFieldErrors(body.errors || {});
          showMessage(body.message, false);
        })
        .catch(function () {
          setSubmitting(false);
          /* The network failed, so nothing is known about the submission.
             Say so rather than claiming either outcome. */
          showMessage(null, false);
        });
    });

    function setSubmitting(value) {
      submitting = value;
      if (submitButton) submitButton.disabled = value;

      if (value) {
        status.classList.remove("form-status--ok", "form-status--error");
        status.classList.add("is-visible");
        status.textContent = said(status, "sending");
      }
    }

    /* A successful submission replaces the form with the success message the
       server configured, exactly as the no-JS redirect would have done. */
    function showSuccess() {
      var message = said(status, "success");

      clearFieldErrors();
      summary.classList.remove("is-visible");
      form.hidden = true;

      status.classList.remove("form-status--error");
      status.classList.add("is-visible", "form-status--ok");
      status.textContent = message;
      status.setAttribute("tabindex", "-1");
      status.focus();
    }

    function showMessage(message, ok) {
      var text = typeof message === "string" && message !== "" ? message : said(status, "failure");

      status.classList.remove("form-status--ok", "form-status--error");
      status.classList.add("is-visible", ok ? "form-status--ok" : "form-status--error");
      status.textContent = text;
    }

    function clearFieldErrors() {
      form.querySelectorAll(".form-field").forEach(function (wrapper) {
        wrapper.classList.remove("has-error");
      });
      form.querySelectorAll(".form-error").forEach(function (el) {
        el.classList.remove("is-visible");
        el.textContent = "";
      });
      form.querySelectorAll("[aria-invalid]").forEach(function (el) {
        el.removeAttribute("aria-invalid");
      });
      summary.innerHTML = "";
      summary.classList.remove("is-visible");
    }

    /* errors is { fieldKey: "message" } — the same messages the server
       renders into the page on the no-JS path, in the same language. */
    function showFieldErrors(errors) {
      clearFieldErrors();

      var keys = Object.keys(errors);
      if (!keys.length) return;

      var items = [];

      keys.forEach(function (key) {
        var id = token + "-" + key;
        var errorEl = document.getElementById(id + "-error");
        var control = document.getElementById(id);
        var message = String(errors[key]);

        if (errorEl) {
          errorEl.textContent = message;
          errorEl.classList.add("is-visible");
          var wrapper = errorEl.closest(".form-field");
          if (wrapper) wrapper.classList.add("has-error");
        }

        if (control) control.setAttribute("aria-invalid", "true");

        items.push(
          '<li><a href="#' + id + '">' + escapeHtml(message) + "</a></li>"
        );
      });

      summary.innerHTML =
        "<p>" +
        escapeHtml(said(status, "summary")) +
        "</p><ul>" +
        items.join("") +
        "</ul>";
      summary.classList.add("is-visible");
      summary.setAttribute("tabindex", "-1");
      summary.focus();
    }

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-form-block]").forEach(initForm);
  });
})();
