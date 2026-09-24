/**
 * Lists of child rows inside ONE block-editor form (the Kaarten-carrousel's
 * cards and a card's tags, and every list admin/_editor_rows.php prints: a
 * FAQ's questions, a Detailsectie's points and images, ...): ↑, ↓ and × work
 * on screen, and "toevoegen" adds
 * an empty row, without a request. The one "Opslaan" of the form then stores
 * the rows in the order they are in. See App\Service\Blocks\EditorRows for
 * what the server does with them.
 *
 * The buttons are submit buttons of the form (`editor_action`), so without
 * this file every one of them still works: it sends the whole form, the
 * server stores everything that was typed and then moves or removes the row.
 * With this file the click is taken over here and nothing is sent — no save
 * per click, and nothing typed elsewhere on the screen is lost.
 *
 * Markup (data attributes only; no text of its own):
 *
 *   [data-row-list]                  one list; its rows are its direct children
 *     [data-row-list-row]            one row
 *       [data-row-list-move="up"]    ↑ / ↓ — disabled at the ends, redone here
 *       [data-row-list-remove]       takes the row off the screen
 *       [data-row-list-number]       its place, 1-based; rewritten after every change
 *       [data-row-list-removing]     a removal MARK (a checkbox): the row stays
 *                                    on screen and is removed by the save
 *   [data-row-list-add="<list id>"]  appends a copy of the list's
 *   <template data-row-list-template="<list id>">, with __KEY__ replaced by
 *                                    "new<n>" (a key no row has yet)
 *   [data-row-list-max="<n>"]        on the list: the add button is disabled
 *                                    while n rows are not marked for removal
 *
 * Every change ends with a bubbling "change" event, which is how the save
 * bar (admin/assets/save-bar.js) hears that something is unsaved. An added
 * row first gets a bubbling "row-list:added" event of its own, so a script
 * that enhances fields (the rich-text editor in admin/assets/admin.js) can
 * do that for the new row too. A status
 * line [data-row-list-status] next to the list says where a moved row went,
 * in words the server wrote into data-row-list-moved (":n" = its position).
 */
(function () {
  "use strict";

  Array.prototype.forEach.call(document.querySelectorAll("[data-row-list]"), function (list) {
    var id = list.getAttribute("data-row-list") || "";
    var template = id ? document.querySelector('template[data-row-list-template="' + id + '"]') : null;
    var add = id ? document.querySelector('[data-row-list-add="' + id + '"]') : null;
    var status = id ? document.querySelector('[data-row-list-status="' + id + '"]') : null;

    function rows() {
      return Array.prototype.filter.call(list.children, function (child) {
        return child.hasAttribute("data-row-list-row");
      });
    }

    var max = parseInt(list.getAttribute("data-row-list-max") || "", 10);

    function refresh() {
      var all = rows();
      all.forEach(function (row, position) {
        var up = row.querySelector('[data-row-list-move="up"]');
        var down = row.querySelector('[data-row-list-move="down"]');
        var number = row.querySelector("[data-row-list-number]");
        if (up) up.disabled = position === 0;
        if (down) down.disabled = position === all.length - 1;
        if (number) number.textContent = String(position + 1);
      });

      if (add && max > 0) {
        var kept = all.filter(function (row) {
          var mark = row.querySelector("[data-row-list-removing]");
          return !(mark && mark.checked);
        });
        add.disabled = kept.length >= max;
      }
    }

    // A removal mark changes how many rows are kept.
    list.addEventListener("change", function (event) {
      if (event.target && event.target.hasAttribute && event.target.hasAttribute("data-row-list-removing")) {
        refresh();
      }
    });

    function changed() {
      list.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function firstField(row) {
      return row.querySelector("input:not([type=hidden]), select, textarea, button");
    }

    list.addEventListener("click", function (event) {
      var button = event.target.closest("[data-row-list-move], [data-row-list-remove]");
      if (!button || !list.contains(button)) return;

      var row = button.closest("[data-row-list-row]");
      if (!row || row.parentNode !== list) return;

      event.preventDefault();

      // Neighbours are ROWS: a list may hold other children too (the
      // <noscript> row for adding without this file), which never count.
      var all = rows();
      var index = all.indexOf(row);

      if (button.hasAttribute("data-row-list-remove")) {
        var neighbour = all[index + 1] || all[index - 1] || null;
        list.removeChild(row);
        refresh();
        changed();
        var target = neighbour ? firstField(neighbour) : add;
        if (target) target.focus();
        return;
      }

      var up = button.getAttribute("data-row-list-move") === "up";
      var sibling = all[up ? index - 1 : index + 1];
      if (!sibling) return;

      // The NEIGHBOUR moves past this row, so the focused button stays in the
      // document and keeps the focus.
      list.insertBefore(sibling, up ? row.nextSibling : row);
      refresh();
      changed();

      if (button.disabled) {
        var other = row.querySelector('[data-row-list-move="' + (up ? "down" : "up") + '"]');
        if (other && !other.disabled) other.focus();
      } else {
        button.focus();
      }

      if (status) {
        var position = rows().indexOf(row) + 1;
        status.textContent = (status.getAttribute("data-row-list-moved") || "").replace(":n", String(position));
      }
    });

    if (add && template) {
      var counter = 0;
      add.hidden = false;
      add.addEventListener("click", function (event) {
        event.preventDefault();

        var key;
        do {
          key = "new" + String(counter++);
        } while (list.querySelector('[name*="[' + key + ']"]'));

        var holder = document.createElement("div");
        holder.innerHTML = template.innerHTML.replace(/__KEY__/g, key);
        var row = holder.firstElementChild;
        if (!row) return;

        list.appendChild(row);
        row.dispatchEvent(new CustomEvent("row-list:added", { bubbles: true }));
        refresh();
        changed();

        var field = firstField(row);
        if (field) field.focus();
      });
    }

    refresh();
  });
})();
