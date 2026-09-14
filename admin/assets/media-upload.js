/*
 * Media Library: the upload queue on admin/media.php.
 *
 * OWNER. admin/media.php, the only screen that loads it. The picker modal
 * (admin/_media_picker.php) keeps its own one-file upload in media-picker.js.
 *
 * WHAT IT DOES. Files chosen with the file input (admin_file_input(), the
 * shared primitive) or dropped on the zone around it go into the queue of
 * new files first: a preview, the name, the type and the size. Nothing has
 * left the browser yet, so an editor can still change a name, add an alt
 * text or take a file out again. Submitting the form then sends the files
 * ONE PER REQUEST to api/admin/media-upload.php, the endpoint the picker
 * already uses. One request per file keeps a batch of large photos
 * under post_max_size (the Portfolio lesson in api/admin/add-portfolio-item-
 * images.php), gives every file its own answer, and means one refused file
 * never costs the others their place in the library. A file that made it
 * leaves the queue; a file that did not stays, with the server's reason
 * beside it.
 *
 * WHAT IS NOT HERE. No rule the server does not enforce again: the checks
 * below only spare an editor a pointless round trip, and
 * App\Service\Media\MediaUploader decides. No sentence an editor reads: the
 * words come from the JSON config and the <template> admin/media.php rendered
 * from the catalog. No markup built from a string: a filename goes in through
 * textContent or value. And no object URL outlives its row.
 *
 * Without this script the same form posts every chosen file at once to
 * api/admin/create-media.php, which answers with a redirect like every other
 * admin form.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-media-upload]");
  var configElement = document.querySelector("[data-media-upload-config]");
  var template = document.querySelector("[data-media-queue-template]");

  if (
    !form ||
    !configElement ||
    !template ||
    !("content" in template) ||
    typeof window.fetch !== "function" ||
    typeof window.FormData !== "function" ||
    typeof window.URL.createObjectURL !== "function"
  ) {
    return;
  }

  var config;
  try {
    config = JSON.parse(configElement.textContent || "{}");
  } catch (e) {
    return;
  }

  var input = form.querySelector("[data-media-upload-input]");
  var dropzone = form.querySelector("[data-media-dropzone]");
  var queue = form.querySelector("[data-media-queue]");
  var list = form.querySelector("[data-media-queue-list]");
  var counter = form.querySelector("[data-media-queue-count]");
  var status = form.querySelector("[data-media-upload-status]");
  var submit = form.querySelector("[data-media-upload-submit]");
  var clear = form.querySelector("[data-media-queue-clear]");
  var token = form.querySelector('input[name="csrf_token"]');

  if (!input || !dropzone || !queue || !list || !submit || !token) {
    return;
  }

  var messages = config.messages || {};
  /** Extension -> the image type it promises: { jpg: "jpeg", png: "png", ... } */
  var extensions = config.extensions || {};
  /** Image type -> the extension the server stores it under: { jpeg: "jpg", ... } */
  var storedAs = config.storedAs || {};
  var maxBytes = Number(config.maxBytes) || 0;
  var maxBaseLength = Number(config.maxBaseLength) || 200;
  var locale = document.documentElement.lang || "nl";

  /** Every file in the queue, in the order it was added. */
  var entries = [];
  var sequence = 0;
  var busy = false;
  var dragDepth = 0;

  // --- Words and numbers ---------------------------------------------------

  function say(key, replacements) {
    var text = String(messages[key] || "");

    Object.keys(replacements || {}).forEach(function (name) {
      text = text.split(":" + name).join(String(replacements[name]));
    });

    return text;
  }

  /** The same rounding admin/media.php uses for a stored item. */
  function formatBytes(bytes) {
    if (bytes < 1024) {
      return bytes + " B";
    }

    if (bytes < 1024 * 1024) {
      return Math.round(bytes / 1024).toLocaleString(locale) + " kB";
    }

    return (bytes / (1024 * 1024)).toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " MB";
  }

  function announce(text) {
    if (status) {
      status.textContent = text;
    }
  }

  // --- Names -----------------------------------------------------------------

  /** The same split App\Service\Media\MediaFilename makes: ".htaccess" has no extension. */
  function extensionOf(name) {
    var dot = name.lastIndexOf(".");
    return dot > 0 ? name.slice(dot + 1).toLowerCase() : "";
  }

  function baseOf(name) {
    var dot = name.lastIndexOf(".");
    return dot > 0 ? name.slice(0, dot) : name;
  }

  function typeLabel(extension) {
    var type = extensions[extension];
    return (type ? storedAs[type] || extension : extension || "?").toUpperCase();
  }

  /** MediaFilename::problemWith(), for the name field before anything is sent. */
  function nameProblem(value) {
    var name = value.trim();

    if (name === "") {
      return say("name_empty");
    }

    if (/[\x00-\x1f\x7f]/.test(name)) {
      return say("name_characters");
    }

    if (/[\/\\:*?"<>|]/.test(name)) {
      return say("name_forbidden");
    }

    if (name.charAt(0) === "." || name.charAt(name.length - 1) === ".") {
      return say("name_dot");
    }

    if (Array.from(name).length > maxBaseLength) {
      return say("name_long");
    }

    return "";
  }

  // --- What a file is --------------------------------------------------------

  /** A problem with the file itself, which no change of name can fix. */
  function fileProblemOf(file, extension) {
    if (extension === "svg") {
      return say("svg");
    }

    if (!Object.prototype.hasOwnProperty.call(extensions, extension)) {
      return say("bad_type");
    }

    if (maxBytes > 0 && file.size > maxBytes) {
      return say("too_large");
    }

    if (file.size === 0) {
      return say("not_image");
    }

    return "";
  }

  /** The image type the first bytes prove, or "" — the header the server reads too. */
  function typeOfBytes(bytes) {
    function starts(signature, offset) {
      for (var i = 0; i < signature.length; i++) {
        if (bytes[(offset || 0) + i] !== signature[i]) {
          return false;
        }
      }
      return true;
    }

    if (starts([0xff, 0xd8, 0xff])) {
      return "jpeg";
    }

    if (starts([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) {
      return "png";
    }

    if (starts([0x47, 0x49, 0x46, 0x38]) && (bytes[4] === 0x37 || bytes[4] === 0x39) && bytes[5] === 0x61) {
      return "gif";
    }

    if (starts([0x52, 0x49, 0x46, 0x46]) && starts([0x57, 0x45, 0x42, 0x50], 8)) {
      return "webp";
    }

    return "";
  }

  /**
   * Reads the first bytes of a file the browser already let through by name.
   * A text file renamed to .png is refused here instead of after its upload;
   * a PNG saved as .jpg keeps its place, under the extension the server will
   * give it.
   */
  function checkContent(entry) {
    var head = entry.file.slice(0, 16);

    if (typeof head.arrayBuffer !== "function") {
      return;
    }

    head.arrayBuffer().then(function (buffer) {
      if (entries.indexOf(entry) === -1) {
        return;
      }

      var type = typeOfBytes(new Uint8Array(buffer));

      if (type === "") {
        entry.fileProblem = say("not_image");
        dropPreview(entry);
      } else if (extensions[entry.extension] !== type && storedAs[type]) {
        entry.extension = storedAs[type];
        entry.ext.textContent = "." + entry.extension;
        entry.meta.textContent = typeLabel(entry.extension) + " · " + formatBytes(entry.file.size);
      }

      refresh(entry);
    }, function () {
      // Unreadable here; the server will say what is wrong with it.
    });
  }

  // --- One row in the queue --------------------------------------------------

  function createEntry(file) {
    sequence += 1;

    var id = "media-queue-" + sequence;
    var element = template.content.firstElementChild.cloneNode(true);
    var extension = extensionOf(file.name);

    var entry = {
      file: file,
      element: element,
      extension: extension,
      url: "",
      fileProblem: "",
      serverProblem: "",
      sending: false,
      name: element.querySelector("[data-queue-name]"),
      alt: element.querySelector("[data-queue-alt]"),
      error: element.querySelector("[data-queue-error]"),
      ext: element.querySelector("[data-queue-ext]"),
      meta: element.querySelector("[data-queue-meta]"),
      preview: element.querySelector("[data-queue-preview]"),
      remove: element.querySelector("[data-queue-remove]")
    };

    // Unique ids per row, so every label points at its own field and every
    // error is read out with the field it belongs to.
    entry.name.id = id + "-name";
    entry.alt.id = id + "-alt";
    entry.error.id = id + "-error";

    Array.prototype.forEach.call(element.querySelectorAll("[data-queue-label-for]"), function (label) {
      label.htmlFor = id + "-" + label.getAttribute("data-queue-label-for");
    });

    entry.name.setAttribute("aria-describedby", entry.error.id);
    entry.remove.setAttribute("aria-label", say("remove_named", { name: file.name }));

    entry.name.value = baseOf(file.name);
    entry.name.maxLength = maxBaseLength;
    entry.ext.textContent = extension ? "." + extension : "";
    entry.meta.textContent = typeLabel(extension) + " · " + formatBytes(file.size);

    entry.fileProblem = fileProblemOf(file, extension);

    if (entry.fileProblem === "") {
      showPreview(entry);
      checkContent(entry);
    }

    entry.name.addEventListener("input", function () {
      entry.serverProblem = "";
      refresh(entry);
    });

    entry.remove.addEventListener("click", function () {
      removeEntry(entry, true);
    });

    refresh(entry);

    return entry;
  }

  function showPreview(entry) {
    entry.url = window.URL.createObjectURL(entry.file);

    var image = document.createElement("img");
    image.alt = "";
    image.decoding = "async";
    // A file the browser cannot draw gets no broken picture; whether it is a
    // real image is still the server's call.
    image.addEventListener("error", function () {
      dropPreview(entry);
    });
    image.src = entry.url;

    entry.preview.appendChild(image);
    entry.element.classList.add("has-preview");
  }

  function dropPreview(entry) {
    if (entry.url !== "") {
      window.URL.revokeObjectURL(entry.url);
      entry.url = "";
    }

    var image = entry.preview.querySelector("img");
    if (image) {
      image.remove();
    }

    entry.element.classList.remove("has-preview");
  }

  function problemOf(entry) {
    return entry.fileProblem || nameProblem(entry.name.value) || entry.serverProblem;
  }

  function isReady(entry) {
    return !entry.sending && entry.fileProblem === "" && nameProblem(entry.name.value) === "";
  }

  function refresh(entry) {
    var problem = problemOf(entry);

    entry.error.textContent = problem;
    entry.error.hidden = problem === "";
    entry.element.classList.toggle("has-error", problem !== "");
    entry.name.setAttribute("aria-invalid", nameProblem(entry.name.value) !== "" ? "true" : "false");

    updateSummary();
  }

  function removeEntry(entry, moveFocus) {
    var index = entries.indexOf(entry);

    if (index === -1) {
      return;
    }

    entries.splice(index, 1);
    dropPreview(entry);
    entry.element.remove();

    if (moveFocus) {
      var neighbour = entries[index] || entries[index - 1];
      (neighbour ? neighbour.remove : input).focus();
    }

    updateSummary();
  }

  // --- The queue as a whole --------------------------------------------------

  function updateSummary() {
    var total = entries.length;

    queue.hidden = total === 0;

    if (clear) {
      clear.hidden = total === 0;
      clear.disabled = busy;
    }

    if (counter) {
      counter.textContent = total === 1 ? say("queued_one") : say("queued", { count: total });
    }

    submit.disabled = busy || !entries.some(isReady);
  }

  function addFiles(files) {
    if (busy || files.length === 0) {
      return;
    }

    files.forEach(function (file) {
      var entry = createEntry(file);
      entries.push(entry);
      list.appendChild(entry.element);
    });

    announce(files.length === 1 ? say("added_one") : say("added", { count: files.length }));
    updateSummary();
  }

  function setBusy(state) {
    busy = state;

    form.classList.toggle("is-busy", state);
    form.setAttribute("aria-busy", state ? "true" : "false");
    input.disabled = state;

    entries.forEach(function (entry) {
      entry.name.readOnly = state;
      entry.alt.readOnly = state;
      entry.remove.disabled = state;
    });

    updateSummary();
  }

  // --- Sending ---------------------------------------------------------------

  /** @return a promise of "added", "reused" or "kept" */
  function send(entry) {
    entry.sending = true;
    entry.serverProblem = "";
    entry.element.classList.add("is-sending");
    refresh(entry);

    var body = new FormData();
    body.append("csrf_token", token.value);
    body.append("file", entry.file, entry.file.name);
    body.append("name", entry.name.value.trim());
    body.append("alt_text", entry.alt.value.trim());

    return window
      .fetch(config.uploadUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: body
      })
      .then(
        function (response) {
          return response.json().then(
            function (data) {
              return { status: response.status, ok: response.ok, data: data || {} };
            },
            function () {
              return { status: response.status, ok: false, data: {} };
            }
          );
        },
        function () {
          return { status: 0, ok: false, data: {} };
        }
      )
      .then(function (answer) {
        entry.sending = false;
        entry.element.classList.remove("is-sending");

        if (answer.ok && answer.data.item) {
          removeEntry(entry, false);
          return answer.data.reused ? "reused" : "added";
        }

        // The login guard answers in English; an editor reads ours instead.
        if (answer.status === 401) {
          entry.serverProblem = say("session");
        } else if (typeof answer.data.error === "string" && answer.data.error !== "") {
          entry.serverProblem = answer.data.error;
        } else {
          entry.serverProblem = say("failed");
        }

        refresh(entry);
        return "kept";
      });
  }

  function finish(tally) {
    var sentences = [];

    if (tally.added > 0) {
      sentences.push(tally.added === 1 ? say("done_one") : say("done", { count: tally.added }));
    }

    if (tally.reused > 0) {
      sentences.push(tally.reused === 1 ? say("reused_one") : say("reused", { count: tally.reused }));
    }

    if (tally.kept > 0) {
      sentences.push(tally.kept === 1 ? say("kept_one") : say("kept", { count: tally.kept }));
    }

    announce(sentences.join(" "));

    if (tally.added + tally.reused > 0) {
      // media-library.js redraws the grid, so the new items are there at once.
      document.dispatchEvent(new CustomEvent("media-library:changed"));
    }

    var firstKept = entries.filter(function (entry) {
      return problemOf(entry) !== "";
    })[0];

    if (firstKept) {
      firstKept.name.focus();
    }
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    if (busy) {
      return;
    }

    var ready = entries.filter(isReady);

    if (ready.length === 0) {
      var firstProblem = entries.filter(function (entry) {
        return problemOf(entry) !== "";
      })[0];

      (firstProblem ? firstProblem.name : input).focus();
      return;
    }

    var tally = { added: 0, reused: 0, kept: 0, done: 0 };

    setBusy(true);
    announce(say("progress", { done: 0, total: ready.length }));

    ready
      .reduce(function (chain, entry) {
        return chain.then(function () {
          return send(entry).then(function (outcome) {
            tally[outcome] += 1;
            tally.done += 1;
            announce(say("progress", { done: tally.done, total: ready.length }));
          });
        });
      }, Promise.resolve())
      .then(function () {
        setBusy(false);
        finish(tally);
      });
  });

  // --- Choosing and dropping -------------------------------------------------

  input.addEventListener("change", function () {
    if (!input.files || input.files.length === 0) {
      return;
    }

    var chosen = Array.prototype.slice.call(input.files);

    // Emptied straight away: the queue holds the files now, the same file
    // can be chosen again, and the no-script form never posts them twice.
    input.value = "";
    addFiles(chosen);
  });

  function carriesFiles(event) {
    var types = event.dataTransfer ? event.dataTransfer.types : null;
    return !!types && Array.prototype.indexOf.call(types, "Files") !== -1;
  }

  function setDragging(state) {
    dropzone.classList.toggle("is-dragover", state);

    // The state is said in words, not only in colour.
    Array.prototype.forEach.call(dropzone.querySelectorAll("[data-media-drop-idle]"), function (element) {
      element.hidden = state;
    });
    Array.prototype.forEach.call(dropzone.querySelectorAll("[data-media-drop-active]"), function (element) {
      element.hidden = !state;
    });
  }

  dropzone.addEventListener("dragenter", function (event) {
    if (!carriesFiles(event) || busy) {
      return;
    }

    event.preventDefault();
    dragDepth += 1;
    setDragging(true);
  });

  dropzone.addEventListener("dragover", function (event) {
    if (!carriesFiles(event) || busy) {
      return;
    }

    event.preventDefault();
    event.dataTransfer.dropEffect = "copy";
  });

  dropzone.addEventListener("dragleave", function (event) {
    if (!carriesFiles(event)) {
      return;
    }

    dragDepth = Math.max(0, dragDepth - 1);

    if (dragDepth === 0) {
      setDragging(false);
    }
  });

  dropzone.addEventListener("drop", function (event) {
    if (!carriesFiles(event)) {
      return;
    }

    // Also stops the file input underneath from taking the drop as its own
    // selection: the queue is where dropped files go.
    event.preventDefault();
    dragDepth = 0;
    setDragging(false);
    addFiles(Array.prototype.slice.call(event.dataTransfer.files || []));
  });

  // A file dropped beside the zone must not make the browser leave the CMS to
  // show it.
  ["dragover", "drop"].forEach(function (type) {
    window.addEventListener(type, function (event) {
      if (carriesFiles(event) && !dropzone.contains(event.target)) {
        event.preventDefault();
      }
    });
  });

  // A click anywhere in the zone does what a click on its button does. The
  // button itself is the file input, so the keyboard needs nothing extra.
  dropzone.addEventListener("click", function (event) {
    if (busy || event.target.closest("label, input, button, a")) {
      return;
    }

    input.click();
  });

  if (clear) {
    clear.addEventListener("click", function () {
      if (busy) {
        return;
      }

      entries.slice().forEach(function (entry) {
        removeEntry(entry, false);
      });

      announce(say("cleared"));
      input.focus();
    });
  }

  // Files waiting in the queue exist only in this tab.
  window.addEventListener("beforeunload", function (event) {
    if (entries.length > 0) {
      event.preventDefault();
      event.returnValue = "";
    }
  });

  // --- Start -----------------------------------------------------------------

  // The queue carries the files from here on, and an input emptied after
  // every choice must not stop the form from being sent.
  input.required = false;
  form.classList.add("is-enhanced");
  setDragging(false);
  updateSummary();
})();
