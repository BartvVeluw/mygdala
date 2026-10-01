/**
 * Dragging in the menu tree of Header & navigatie (admin/navigation.php,
 * [data-nav-tree]; HEADER-FOOTER.md, "Verplaatsen").
 *
 * A row is picked up by its handle and can be dropped in three ways, each
 * with its own mark, so it is never a guess where it lands:
 *
 *   - BEFORE a row: a line above it, starting where that row starts, so the
 *     line shows the level too;
 *   - AFTER a row: a line below it. Below the last row of a submenu the
 *     line follows the pointer to the left, so the same spot can mean "last
 *     in this submenu" or "after its parent, one level up";
 *   - INTO a menu item: the item itself lights up, and the row joins the end
 *     of its submenu. Below an item whose submenu is open, "after" means the
 *     top of that submenu, so the line is drawn there, one level in.
 *
 * Only drops the server accepts are offered: never into the row itself or
 * anything below it, never where its own submenu would pass the deepest
 * level (data-max-depth, NavigationRepository::MAX_DEPTH), never a heading
 * without destination below the top level. The levels come from the server
 * (data-nav-level, data-nav-height, App\Service\NavigationTree). The server
 * judges again: api/admin/place-nav-item.php gets ONE request per drop — the
 * item, its new parent and its place — and the screen reloads on success,
 * so what it shows is what is stored. A refusal is shown above the menu and
 * nothing moves.
 *
 * A folded submenu (admin/assets/navigation-tree.js) goes along with its row
 * and is no drop target. The header buttons are a flat list of their own:
 * admin.js drags those. Without this script nothing here happens; ↑/↓ and
 * the editor's "Bovenliggend item" do the same work.
 */
(function () {
  'use strict';

  var tree = document.querySelector('[data-nav-tree]');
  if (!tree) {
    return;
  }

  var maxDepth = parseInt(tree.getAttribute('data-max-depth'), 10) || 3;
  var errorBox = document.querySelector('[data-nav-tree-error]');
  var dragged = null;
  var target = null;

  var line = document.createElement('div');
  line.className = 'admin-nav-drop-line';
  line.setAttribute('aria-hidden', 'true');
  line.hidden = true;
  tree.appendChild(line);

  function level(row) { return parseInt(row.getAttribute('data-nav-level'), 10); }
  function height(row) { return parseInt(row.getAttribute('data-nav-height'), 10) || 1; }
  function idOf(row) { return row.getAttribute('data-nav-item-id'); }

  function isRow(el) {
    return el.classList.contains('admin-nav-item-row');
  }

  /** The submenu zone right after a row, or null. */
  function childZone(row) {
    var next = row.nextElementSibling;
    return next && next.classList.contains('admin-nav-children') && next.getAttribute('data-parent-id') === idOf(row) ? next : null;
  }

  /** The row whose submenu holds this row, or null on the top level. */
  function parentRow(row) {
    var zone = row.parentElement;
    if (!zone || !zone.classList.contains('admin-nav-children')) return null;
    return document.getElementById('nav-item-' + zone.getAttribute('data-parent-id'));
  }

  function parentIdOf(row) {
    var parent = parentRow(row);
    return parent ? idOf(parent) : '';
  }

  /** The rows of one list, the dragged row left out: positions are counted without it. */
  function listOf(container) {
    return Array.prototype.filter.call(container.children, function (el) {
      return isRow(el) && el !== dragged;
    });
  }

  /** The dragged row itself, or anything in its own submenu. */
  function isOwn(row) {
    if (row === dragged) return true;
    var zone = childZone(dragged);
    return zone !== null && zone.contains(row);
  }

  /** Can the dragged row, with its own submenu, live on this level (1 = top)? */
  function fitsOn(targetLevel) {
    if (dragged.hasAttribute('data-nav-heading') && targetLevel > 1) return false;
    return targetLevel + height(dragged) - 1 <= maxDepth;
  }

  function clear() {
    line.hidden = true;
    tree.querySelectorAll('.is-drop-inside').forEach(function (row) { row.classList.remove('is-drop-inside'); });
    target = null;
  }

  function showLine(y, left) {
    var box = tree.getBoundingClientRect();
    line.style.top = (y - box.top) + 'px';
    line.style.left = Math.max(0, left - box.left) + 'px';
    line.hidden = false;
  }

  /** The drop for the pointer over one row, or null when there is none there. */
  function dropFor(row, event) {
    var rect = row.getBoundingClientRect();
    var part = (event.clientY - rect.top) / rect.height;
    var canGoInside = fitsOn(level(row) + 1);
    var zone = childZone(row);
    var openZone = zone && !zone.hidden && listOf(zone).length > 0 ? zone : null;

    if (part < 0.25 || (!canGoInside && part < 0.5)) {
      if (!fitsOn(level(row))) return null;
      return { parent: parentIdOf(row), position: listOf(row.parentElement).indexOf(row), y: rect.top - 4, left: rect.left };
    }

    if (part <= 0.75 && canGoInside) {
      return { parent: idOf(row), position: '', inside: row };
    }

    // After a row whose submenu is open: the top of that submenu.
    if (openZone) {
      if (!canGoInside) return null;
      return { parent: idOf(row), position: 0, y: rect.bottom + 4, left: listOf(openZone)[0].getBoundingClientRect().left };
    }

    // After the last row of a list the pointer's distance from the left
    // picks the level: this list, or the one of a parent it closes.
    var chain = [row];
    var current = row;
    var parent = parentRow(current);
    while (parent) {
      var list = listOf(current.parentElement);
      if (list[list.length - 1] !== current) break;
      chain.push(parent);
      current = parent;
      parent = parentRow(current);
    }

    var choice = null;
    for (var i = 0; i < chain.length; i++) {
      if (!fitsOn(level(chain[i]))) continue;
      choice = chain[i];
      if (event.clientX >= chain[i].getBoundingClientRect().left) break;
    }
    if (choice === null) return null;

    return { parent: parentIdOf(choice), position: listOf(choice.parentElement).indexOf(choice) + 1, y: rect.bottom + 4, left: choice.getBoundingClientRect().left };
  }

  /** A drop where the row already stands sends nothing. */
  function isNoMove(drop) {
    if (drop.parent !== parentIdOf(dragged)) return false;
    var list = Array.prototype.filter.call(dragged.parentElement.children, isRow);
    var current = list.indexOf(dragged);
    return drop.position === '' ? current === list.length - 1 : drop.position === current;
  }

  function showError(message) {
    if (!errorBox) return;
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  tree.querySelectorAll('.admin-nav-item-row[data-nav-level] > .admin-drag-handle').forEach(function (handle) {
    var row = handle.parentElement;

    handle.addEventListener('dragstart', function (event) {
      dragged = row;
      row.classList.add('is-dragging');
      var zone = childZone(row);
      if (zone) zone.classList.add('is-dragging');
      if (errorBox) errorBox.hidden = true;
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', idOf(row));
      }
    });

    handle.addEventListener('dragend', function () {
      row.classList.remove('is-dragging');
      var zone = childZone(row);
      if (zone) zone.classList.remove('is-dragging');
      dragged = null;
      clear();
    });
  });

  tree.addEventListener('dragover', function (event) {
    if (!dragged) return;
    var row = event.target.closest ? event.target.closest('.admin-nav-item-row[data-nav-level]') : null;
    if (!row || !tree.contains(row)) {
      // In the gap between two rows: the mark that is showing still counts.
      if (target) event.preventDefault();
      return;
    }

    clear();
    if (isOwn(row)) return;

    var drop = dropFor(row, event);
    if (!drop) return;

    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    target = drop;
    if (drop.inside) {
      drop.inside.classList.add('is-drop-inside');
    } else {
      showLine(drop.y, drop.left);
    }
  });

  tree.addEventListener('dragleave', function (event) {
    if (!event.relatedTarget || !tree.contains(event.relatedTarget)) clear();
  });

  tree.addEventListener('drop', function (event) {
    if (!dragged || !target) return;
    event.preventDefault();
    var drop = target;
    var id = idOf(dragged);
    clear();
    if (isNoMove(drop)) return;

    var body = new URLSearchParams();
    body.set('csrf_token', tree.getAttribute('data-csrf-token'));
    body.set('id', id);
    body.set('parent_id', drop.parent);
    body.set('position', String(drop.position));

    fetch(tree.getAttribute('data-place-url'), { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (data && data.ok) {
          // The same item moved twice is the same address, and going to an
          // address that differs only in its #fragment does not load the
          // page again: reload explicitly then.
          var page = '/admin/navigation.php?moved=' + encodeURIComponent(id);
          if (window.location.pathname + window.location.search === page) {
            window.history.replaceState(null, '', page + '#nav-item-' + encodeURIComponent(id));
            window.location.reload();
          } else {
            window.location.assign(page + '#nav-item-' + encodeURIComponent(id));
          }
        } else if (data && data.error) {
          showError(data.error);
        } else {
          window.location.reload();
        }
      })
      .catch(function () { window.location.reload(); });
  });
})();
