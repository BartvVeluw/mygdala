/**
 * Folding the Pages overview (admin/pages.php, docs/pages/NESTING.md).
 *
 * Two kinds of button, both with aria-expanded and aria-controls:
 *
 *   - on a page that has pages under it: hides or shows every row below it,
 *     at any depth. A row is visible only while every page above it is open,
 *     so opening a parent never reveals a grandchild whose own parent is
 *     still closed;
 *   - on each group ("Websitepagina's", "Service & juridisch"): hides or
 *     shows the group's list. Websitepagina's starts open and Service &
 *     juridisch closed, until the editor chooses otherwise.
 *
 * WHAT IS REMEMBERED, and where: which pages and groups are closed, in this
 * browser's localStorage. A view preference, so no request and no server
 * write; storage that is unavailable (a private window, blocked site data)
 * just means the defaults, every time.
 *
 * WHILE A SEARCH IS ACTIVE ([data-page-tree-search]) everything is open and
 * nothing is stored: the server already listed each match with the pages
 * above it, and a match must never be hidden because its parent was closed
 * before the search. Clearing the search brings the remembered state back.
 *
 * Without this script every row is visible and the buttons do nothing.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'mygdalaPageTree';

  function readState() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      var parsed = raw ? JSON.parse(raw) : null;
      if (parsed && typeof parsed === 'object') {
        return {
          pages: parsed.pages && typeof parsed.pages === 'object' ? parsed.pages : {},
          groups: parsed.groups && typeof parsed.groups === 'object' ? parsed.groups : {}
        };
      }
    } catch (e) {
      // Unavailable or unreadable storage: the defaults.
    }
    return { pages: {}, groups: {} };
  }

  function writeState(state) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
      // Nothing to do: the state simply is not remembered.
    }
  }

  function init(tree) {
    var searching = tree.hasAttribute('data-page-tree-search');
    var state = searching ? { pages: {}, groups: {} } : readState();

    var rows = Array.prototype.slice.call(tree.querySelectorAll('[data-page-row]'));
    var parentOf = {};
    rows.forEach(function (row) {
      parentOf[row.getAttribute('data-page-row')] = row.getAttribute('data-page-parent') || '0';
    });

    function isClosed(id) {
      return state.pages[id] === 'closed';
    }

    function hiddenByAncestor(id) {
      var seen = {};
      var current = parentOf[id];
      while (current && current !== '0' && !seen[current]) {
        seen[current] = true;
        if (isClosed(current)) {
          return true;
        }
        current = parentOf[current];
      }
      return false;
    }

    function renderRows() {
      rows.forEach(function (row) {
        var id = row.getAttribute('data-page-row');
        row.hidden = hiddenByAncestor(id);
      });
      tree.querySelectorAll('[data-page-tree-toggle]').forEach(function (button) {
        button.setAttribute('aria-expanded', isClosed(button.getAttribute('data-page-tree-toggle')) ? 'false' : 'true');
      });
    }

    function groupIsOpen(group) {
      var key = group.getAttribute('data-page-group');
      var stored = state.groups[key];
      if (stored === 'open' || stored === 'closed') {
        return stored === 'open';
      }
      // A search starts with every group open; a click still folds one.
      if (searching) {
        return true;
      }
      return group.getAttribute('data-page-group-default') !== 'closed';
    }

    function renderGroups() {
      tree.querySelectorAll('[data-page-group]').forEach(function (group) {
        var button = group.querySelector('[data-page-group-toggle]');
        var body = button ? document.getElementById(button.getAttribute('aria-controls')) : null;
        var open = groupIsOpen(group);
        if (button) {
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (body) {
          body.hidden = !open;
        }
      });
    }

    tree.addEventListener('click', function (event) {
      var pageButton = event.target.closest('[data-page-tree-toggle]');
      if (pageButton && tree.contains(pageButton)) {
        var id = pageButton.getAttribute('data-page-tree-toggle');
        if (isClosed(id)) {
          delete state.pages[id];
        } else {
          state.pages[id] = 'closed';
        }
        renderRows();
        if (!searching) {
          writeState(state);
        }
        return;
      }

      var groupButton = event.target.closest('[data-page-group-toggle]');
      if (groupButton && tree.contains(groupButton)) {
        var group = groupButton.closest('[data-page-group]');
        state.groups[group.getAttribute('data-page-group')] = groupIsOpen(group) ? 'closed' : 'open';
        renderGroups();
        if (!searching) {
          writeState(state);
        }
      }
    });

    renderRows();
    renderGroups();
  }

  function start() {
    document.querySelectorAll('[data-page-tree]').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
