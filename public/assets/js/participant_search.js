// Find a participant (participant_search.php). Puts the cursor in the search box, ready to type. US-04: with nothing
// typed the page lists today's check-ins first; this keeps that list current by polling api/participant/checkins.php
// (which never extends the idle timer), and hides the lists as soon as something is typed so the volunteer is back
// in a normal search. Without script the page still works: the lists are as of loading, and the form searches on
// submit. Rows are built with textContent only.
'use strict';

(function () {
  var input = document.getElementById('q');
  var lists = document.getElementById('empty-search');
  var typed = function () { return input !== null && input.value.trim() !== ''; };

  if (input !== null) {
    // Ready to type on arrival: focused (autofocus can be skipped, e.g. after a redirect), with the cursor after any
    // search already there so more letters narrow it rather than land in front of it.
    if (document.activeElement !== input) {
      input.focus();
    }
    var end = input.value.length;
    input.setSelectionRange(end, end);
  }
  if (input !== null && lists !== null) {
    input.addEventListener('input', function () { lists.hidden = typed(); });
  }

  var section = document.getElementById('check-ins');
  if (section === null) {
    return;
  }
  var url = section.getAttribute('data-poll-url');
  var seconds = Math.max(5, Math.min(120, parseInt(section.getAttribute('data-poll-seconds') || '15', 10) || 15));
  var tbody = section.querySelector('tbody');
  var part = function (name) { return section.querySelector('[data-' + name + ']'); };
  var timer = null;

  function cell(tag, text) {
    var el = document.createElement(tag);
    el.textContent = text === null || text === undefined ? '' : String(text);
    return el;
  }

  function nameCell(r) {
    var th = document.createElement('th');
    th.scope = 'row';
    var a = document.createElement('a');
    a.href = r.url;
    a.textContent = r.name;
    th.appendChild(a);
    if (r.legal !== null) {
      th.appendChild(document.createElement('br'));
      var legal = cell('span', 'Legal name: ' + r.legal);
      legal.className = 'meta';
      th.appendChild(legal);
    }
    return th;
  }

  function render(data) {
    var rows = Array.isArray(data.rows) ? data.rows : [];
    var fresh = document.createDocumentFragment();
    rows.forEach(function (r) {
      var tr = document.createElement('tr');
      tr.appendChild(cell('td', r.checked_in));
      tr.appendChild(nameCell(r));
      tr.appendChild(cell('td', r.code));
      tr.appendChild(cell('td', r.outcome));
      var pets = cell('td', r.pets);
      pets.className = 'num';
      tr.appendChild(pets);
      var last = cell('td', r.last_distribution === null ? 'never' : r.last_distribution);
      if (r.last_distribution === null) {
        last.className = 'meta';
      }
      tr.appendChild(last);
      fresh.appendChild(tr);
    });
    tbody.replaceChildren(fresh);
    part('table').hidden = rows.length === 0;
    part('empty').hidden = rows.length !== 0;
    part('cap').hidden = data.truncated !== true;
  }

  function stop(message) {
    window.clearTimeout(timer);
    timer = null;
    if (message) {
      part('table').hidden = true;
      part('cap').hidden = true;
      var empty = part('empty');
      empty.textContent = message;
      empty.hidden = false;
    }
  }

  function poll() {
    timer = window.setTimeout(poll, seconds * 1000);
    if (document.hidden || typed()) {
      return; // nothing to refresh that anyone is looking at
    }
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (res) {
        if (res.status === 401 || res.status === 403) {
          stop('Your session has ended. Reload the page to sign in again.');
          return null;
        }
        return res.ok ? res.json() : null; // a passing server error: try again next time
      })
      .then(function (data) {
        if (data === null || timer === null) {
          return;
        }
        if (data.open !== true) {
          stop('Today\'s event has closed. Reload the page for the latest list.');
          return;
        }
        render(data);
      })
      .catch(function () { /* offline for a moment: keep the last list and try again */ });
  }

  timer = window.setTimeout(poll, seconds * 1000);
}());
