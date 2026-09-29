/**
 * Folding the menu tree of Header & navigatie (admin/navigation.php).
 *
 * A menu item with sub-items has a button before its name (aria-expanded,
 * aria-controls = its submenu zone). Closing it hides that zone, and with it
 * every level below; each sub-item keeps its own open or closed state, so
 * opening a parent again shows the tree as it was.
 *
 * ONLY A VIEW. Nothing is posted and nothing changes on the site: the items,
 * their order and their nesting are what they were. A hidden zone is still in
 * the page, so dragging its parent takes it along (admin.js) and ↑/↓ work as
 * before.
 *
 * WHAT IS REMEMBERED, and where: which items are closed, in this browser's
 * localStorage under one key for this screen, by item id (localStorage is per
 * installation already: one origin each). Storage that is unavailable (a
 * private window, blocked site data) just means everything open, every time.
 *
 * A LINK TO ONE ITEM (#nav-item-<id>, where ↑/↓ lands) opens the items above
 * it for this visit, without changing what is remembered, so the row it
 * names is never folded away.
 *
 * Without this script every item is visible and the buttons do nothing.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'mygdalaNavigationTree';

  function readClosed() {
    try {
      var parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null');
      if (parsed && typeof parsed === 'object' && parsed.closed && typeof parsed.closed === 'object') {
        return parsed.closed;
      }
    } catch (e) {
      // Unavailable or unreadable storage: everything open.
    }
    return {};
  }

  function writeClosed(closed) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ closed: closed }));
    } catch (e) {
      // Nothing to do: the state simply is not remembered.
    }
  }

  var closed = readClosed();
  var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-nav-tree-toggle]'));

  if (buttons.length === 0) {
    return;
  }

  // Opened for this visit only: the items above a linked row.
  var openedForVisit = {};
  var target = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
  if (target) {
    var zone = target.closest('.admin-nav-children');
    while (zone) {
      openedForVisit[zone.getAttribute('data-parent-id')] = true;
      zone = zone.parentElement ? zone.parentElement.closest('.admin-nav-children') : null;
    }
  }

  function isOpen(id) {
    return closed[id] !== true || openedForVisit[id] === true;
  }

  function render(button) {
    var id = button.getAttribute('data-nav-tree-toggle');
    var children = document.getElementById(button.getAttribute('aria-controls'));
    var open = isOpen(id);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (children) {
      children.hidden = !open;
    }
  }

  buttons.forEach(function (button) {
    render(button);

    button.addEventListener('click', function () {
      var id = button.getAttribute('data-nav-tree-toggle');
      if (isOpen(id)) {
        closed[id] = true;
      } else {
        delete closed[id];
      }
      delete openedForVisit[id];
      render(button);
      writeClosed(closed);
    });
  });
})();
