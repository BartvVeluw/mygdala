/**
 * The product editor's Varianten section (admin/_product_variants.php): the
 * options with their values, and the variants made of them, edited on screen
 * and stored by the editor's one Opslaan (api/admin/update-product.php,
 * App\Service\ProductVariantEditor). Adding, removing and moving rows is
 * admin/assets/row-list.js; this file only keeps what depends on OTHER rows
 * in step:
 *
 *   - a new variant's choice per option lists the values on screen right
 *     now, a value typed a moment ago included, by row key ("12", "new0");
 *   - an option or value a variant uses cannot be removed: its × is disabled
 *     and says why (the server refuses it too, so this only spares a trip);
 *   - a "Kleur" option shows a colour per value, a "Standaard" one does not;
 *   - the section's summary line counts the variants.
 *
 * NOTHING HERE DECIDES WHAT IS VALID. The server checks every choice again
 * and names what it refuses; these are conveniences, not rules.
 */
(function () {
  "use strict";

  function root() {
    return document.querySelector("[data-product-variants]");
  }

  function words(section) {
    try {
      return JSON.parse(section.getAttribute("data-product-variants-words") || "{}");
    } catch (e) {
      return {};
    }
  }

  function rowsOf(list) {
    return list
      ? Array.prototype.filter.call(list.children, function (child) { return child.hasAttribute("data-row-list-row"); })
      : [];
  }

  /** The options on screen: [{key, name, values: [{key, text}]}], in order. */
  function optionsOnScreen(section) {
    return rowsOf(section.querySelector("[data-product-options]")).map(function (row) {
      var key = row.getAttribute("data-option-key") || "";
      var nameField = row.querySelector("[data-option-name]");
      return {
        key: key,
        row: row,
        name: nameField ? nameField.value.trim() : "",
        values: rowsOf(row.querySelector("[data-option-values]")).map(function (valueRow) {
          var text = valueRow.querySelector("[data-value-text]");
          return {
            key: valueRow.getAttribute("data-value-key") || "",
            row: valueRow,
            text: text ? text.value.trim() : ""
          };
        })
      };
    });
  }

  /** Every "option:value" pair a variant on screen uses. */
  function usedPairs(section) {
    var used = {};
    rowsOf(section.querySelector("[data-product-variant-list]")).forEach(function (row) {
      (row.getAttribute("data-variant-values") || "").split(" ").forEach(function (pair) {
        if (pair) used[pair] = true;
      });
      Array.prototype.forEach.call(row.querySelectorAll("select[data-variant-choice]"), function (select) {
        if (select.value) used[select.getAttribute("data-variant-choice") + ":" + select.value] = true;
      });
    });
    return used;
  }

  function setRemovable(button, note, removable) {
    if (!button) return;
    button.disabled = !removable;
    if (note) note.hidden = removable;
  }

  function refreshUse(section) {
    var used = usedPairs(section);

    optionsOnScreen(section).forEach(function (option) {
      var optionUsed = false;
      option.values.forEach(function (value) {
        var inUse = used[option.key + ":" + value.key] === true;
        optionUsed = optionUsed || inUse;
        setRemovable(
          value.row.querySelector("[data-value-remove]"),
          value.row.querySelector("[data-value-in-use]"),
          !inUse
        );
      });
      setRemovable(
        option.row.querySelector("[data-option-remove]"),
        option.row.querySelector("[data-option-in-use]"),
        !optionUsed
      );
    });
  }

  /**
   * A new variant's choices: one select per option on screen, keeping what
   * was chosen while that value is still there.
   */
  function refreshChoices(section) {
    var options = optionsOnScreen(section);
    var text = words(section);

    Array.prototype.forEach.call(section.querySelectorAll("[data-variant-choices]"), function (holder) {
      var row = holder.closest("[data-row-list-row]");
      var key = row ? row.getAttribute("data-variant-key") || "" : "";
      var chosen = {};
      Array.prototype.forEach.call(holder.querySelectorAll("select[data-variant-choice]"), function (select) {
        chosen[select.getAttribute("data-variant-choice")] = select.value;
      });

      var empty = holder.parentNode.querySelector("[data-variant-choices-empty]");
      if (empty) empty.hidden = options.length > 0;

      // Rebuilt only when what it lists changed, so an open select is never
      // pulled from under the pointer while somebody types elsewhere.
      var signature = JSON.stringify(options.map(function (option) {
        return [option.key, option.name, option.values.map(function (value) { return [value.key, value.text]; })];
      }));
      if (holder.getAttribute("data-signature") === signature) return;
      holder.setAttribute("data-signature", signature);
      holder.textContent = "";

      options.forEach(function (option, index) {
        var id = "variant-" + key + "-choice-" + option.key;
        var field = document.createElement("div");
        field.className = "admin-field";

        var label = document.createElement("label");
        label.setAttribute("for", id);
        label.textContent = option.name || (text.option || "") + " " + String(index + 1);

        var select = document.createElement("select");
        select.id = id;
        select.name = "variants[" + key + "][values][" + option.key + "]";
        select.setAttribute("data-variant-choice", option.key);

        var none = document.createElement("option");
        none.value = "";
        none.textContent = text.choose || "";
        select.appendChild(none);

        option.values.forEach(function (value) {
          var item = document.createElement("option");
          item.value = value.key;
          item.textContent = value.text || text.unnamed || "";
          if (chosen[option.key] === value.key) item.selected = true;
          select.appendChild(item);
        });

        field.appendChild(label);
        field.appendChild(select);
        holder.appendChild(field);
      });
    });
  }

  function refreshCount(section) {
    var options = rowsOf(section.querySelector("[data-product-options]")).length;
    var variants = rowsOf(section.querySelector("[data-product-variant-list]")).length;

    var count = document.querySelector("[data-product-variants-count]");
    if (count) count.textContent = String(variants);

    var noOptions = section.querySelector("[data-product-options-empty]");
    if (noOptions) noOptions.hidden = options > 0;
    var noVariants = section.querySelector("[data-product-variants-empty]");
    if (noVariants) noVariants.hidden = variants > 0;
  }

  function refresh() {
    var section = root();
    if (!section) return;
    refreshChoices(section);
    refreshUse(section);
    refreshCount(section);
  }

  // An option's display type decides whether its values carry a colour.
  document.addEventListener("change", function (event) {
    var select = event.target && event.target.closest ? event.target.closest("[data-option-display]") : null;
    if (!select) return;
    var row = select.closest("[data-option-key]");
    if (row) row.setAttribute("data-option-display-type", select.value);
  });

  // Names and values typed, rows added, moved and removed, a choice made:
  // everything that can change what the other rows show.
  ["input", "change", "row-list:added", "admin-editor:replaced"].forEach(function (type) {
    document.addEventListener(type, function (event) {
      var section = root();
      if (!section || !event.target || !event.target.closest) return;
      if (event.type === "admin-editor:replaced" || section.contains(event.target)) refresh();
    });
  });

  refresh();
})();
