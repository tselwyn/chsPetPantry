// The Station's views and dom.js (S2 spec §3.11, §4.1, §4.2) over fake-dom.js, where innerHTML, outerHTML,
// insertAdjacentHTML and document.write throw: every screen is built from text nodes only.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { fakeDocument, text } from './support/fake-dom.js';
import { readStation } from './support/fixtures.js';
import { COPY, t } from '../../public/station/js/copy.js';
import { el, mount, useDocument, clear } from '../../public/station/js/dom.js';
import * as chrome from '../../public/station/js/views/chrome.js';
import * as starting from '../../public/station/js/views/starting.js';
import * as device from '../../public/station/js/views/device.js';
import * as login from '../../public/station/js/views/login.js';
import * as about from '../../public/station/js/views/about.js';
import * as wipe from '../../public/station/js/views/wipe.js';
import * as elsewhere from '../../public/station/js/views/elsewhere.js';
import { registrationMessage } from '../../public/station/js/device.js';
import { ApiError } from '../../public/station/js/api.js';

const VIEW_FILES = readdirSync(new URL('../../public/station/js/views/', import.meta.url)).filter((f) => f.endsWith('.js')).sort();

/** A fresh fake document, used by dom.js, and its #app. */
function fresh() {
  const doc = fakeDocument();
  useDocument(doc);
  return { doc, root: doc.getElementById('app') };
}
const noop = () => {};
const deviceActions = (log = []) => ({
  onInput: (v) => log.push(['input', v]), onScan: () => log.push(['scan']), onStopScan: () => log.push(['stop']),
  onSubmit: () => log.push(['submit']), onContinue: () => log.push(['continue']),
});
const deviceModel = (over = {}) => ({ phase: 'ready', check: 'empty', message: null, canScan: false, storageRefused: false, result: null,
  code: '', stream: null, focus: 'code', ...over });
const aboutModel = (over = {}) => ({ label: 'E2E 1', site: 'Dev Site North', build: '0.1.0-dev+abc', lastContact: '2026-10-01T12:00:00.000Z',
  keyReceived: true, unsynced: 0, offsetMs: 0, storageKb: 812, persisted: true, canCheck: false, updateWaiting: false, repairState: null,
  captivePortal: false, ...over });
const chromeModel = (over = {}) => ({ orgName: 'CHS Pet Pantry', site: 'Dev Site North', label: 'E2E 1', build: '0.1.0-dev+abc',
  connectivity: 'online', banners: new Set(), ...over });

/** Every text node's text under node, in order. */
function texts(node, out = []) {
  for (const c of node.childNodes ?? []) {
    if (c.nodeType === 3) out.push(c.data); else texts(c, out);
  }
  return out;
}
/** A text that looks like a copy key t() returned unchanged (an unknown key). */
const looksLikeKey = (s) => /^[a-z]+(?:_[a-z]+)+$/.test(s.trim());

/** Every screen the views can draw (with their actions). */
function everyScreen() {
  const screens = [];
  for (const phase of ['starting', 'checking', 'no_storage']) screens.push(['starting ' + phase, () => starting.render({ phase }, { onRetry: noop })]);
  for (const phase of ['preparing', 'ready', 'scanning', 'sending', 'registered', 'blocked']) {
    for (const check of ['empty', 'partial', 'ok', 'mistyped']) {
      screens.push([`device ${phase} ${check}`, () => device.render(deviceModel({ phase, check, canScan: true, storageRefused: true,
        result: { site: 'Dev Site North', label: 'E2E 1' }, stream: { id: 's' }, message: { key: 'reg_busy', params: { incident: 'ABC123' } } }), deviceActions())]);
    }
  }
  for (const checking of [true, false]) screens.push([`login ${checking}`, () => login.render({ orgName: null, site: 'S', label: 'L', build: 'b', checking }, {})]);
  for (const keyReceived of [true, false, null, 'unregistered', 'unknown']) {
    for (const offsetMs of [null, 0, 5000, -5000, 600000, -600000]) {
      for (const repairState of ['offline', 'pending']) {
        screens.push([`about ${keyReceived} ${offsetMs} ${repairState}`, () => about.render(aboutModel({ keyReceived, offsetMs, updateWaiting: true, repairState,
          captivePortal: true, storageKb: null, lastContact: null, label: null, site: null }), { onRepair: noop, onUpdateNow: noop, onBack: noop })]);
      }
    }
  }
  for (const phase of ['retiring', 'erasing', 'stuck', 'confirming', 'blocked', 'erased']) {
    for (const mode of ['Push Then Wipe', 'Wipe Now']) {
      for (const final of ['uploaded', 'not_uploaded', 'unknown']) {
        screens.push([`wipe ${phase} ${mode} ${final}`, () => wipe.render({ phase, mode, n: 2, final, previous: 'confirming' }, { onRegisterAgain: noop })]);
      }
    }
  }
  for (const phase of ['elsewhere', 'replaced']) screens.push(['elsewhere ' + phase, () => elsewhere.render({ phase }, { onUseHere: noop })]);
  for (const connectivity of ['online', 'offline', 'unknown', 'none']) {
    screens.push(['chrome ' + connectivity, () => chrome.render(chromeModel({ connectivity, banners: new Set(chrome.BANNERS.map((b) => b.kind)) }))]);
  }
  screens.push(['chrome minimal', () => chrome.render(chromeModel({ minimal: true }))]);
  screens.push(['footer', () => chrome.footer({ build: 'b', aboutLink: true })]);
  return screens;
}

test('every view renders through textContent only', () => {
  for (const [name, draw] of everyScreen()) {
    const { root } = fresh();
    mount(root, draw()); // fake-dom throws on any HTML parsing
    assert.ok(text(root).length > 0, name);
  }
  // And the sources never reach for an HTML sink (source_rules.test.js checks every Station file the same way).
  for (const f of ['dom.js', ...VIEW_FILES.map((v) => 'views/' + v)]) {
    const src = readStation('js/' + f);
    assert.doesNotMatch(src, /\.(?:innerHTML|outerHTML)\b|insertAdjacentHTML|document\.write/, f);
  }
});

test('every t() key used in the views exists in COPY', () => {
  for (const f of VIEW_FILES) {
    const src = readStation('js/views/' + f);
    const keys = [...src.matchAll(/\bt\(\s*'([a-z_]+)'/g)].map((m) => m[1]);
    for (const k of keys) assert.ok(Object.hasOwn(COPY, k), `${f}: t('${k}')`);
    // Keys chosen at run time live in key maps: every quoted snake_case literal of a view is a copy key or a known value.
    const literals = [...src.matchAll(/'([a-z]+(?:_[a-z]+)+)'/g)].map((m) => m[1]);
    for (const k of literals) assert.ok(Object.hasOwn(COPY, k) || ['dev_relax', 'header_missing', 'close_other_window', 'update_ready', 'no_storage', 'not_uploaded'].includes(k), `${f}: '${k}'`);
  }
  // Every rendered text is a real string, never a key t() did not know.
  for (const [name, draw] of everyScreen()) {
    const { root } = fresh();
    mount(root, draw());
    for (const s of texts(root)) assert.ok(!looksLikeKey(s), `${name}: "${s}" looks like an unknown copy key`);
  }
  // The registration messages device.js can give exist too.
  const errors = ['code_mistyped', 'not_installed', 'code_invalid', 'busy', 'rate_limited', 'bad_request', 'bad_json', 'unsupported_media_type', 'server_error', 'other']
    .map((code) => new ApiError({ kind: 'http', status: 400, code }));
  errors.push(new ApiError({ kind: 'offline', code: 'maintenance' }), new ApiError({ kind: 'offline', code: 'network' }), new Error('x'));
  for (const e of errors) assert.ok(Object.hasOwn(COPY, registrationMessage(e).key), registrationMessage(e).key);
  for (const k of ['reg_selftest_failed', 'camera_refused', 'reg_no_answer', 'reg_failed', 'incident']) assert.ok(Object.hasOwn(COPY, k), k);
  // t() fills placeholders as text and leaves unknown keys visible.
  assert.equal(t('registered', { site: '<b>S</b>', label: 'L' }), 'Registered to <b>S</b> as L.');
  assert.equal(t('nope_key'), 'nope_key');
  assert.equal(t('version', {}), 'Version {build}');
  assert.ok(Object.isFrozen(COPY));
});

test('the device view\'s check line and button follow empty, partial, ok and mistyped', () => {
  const want = {
    empty: ['hint', COPY.code_hint, true], partial: ['hint', COPY.code_hint, true],
    ok: ['message message-ok', COPY.code_ok, false], mistyped: ['message message-error', COPY.reg_mistyped, true],
  };
  for (const [check, [cls, line, disabled]] of Object.entries(want)) {
    const { root } = fresh();
    mount(root, device.render(deviceModel({ check }), deviceActions()));
    const p = root.querySelector('#code-check');
    assert.equal(p.getAttribute('class'), cls, check);
    assert.equal(text(p), line, check);
    assert.equal(p.getAttribute('aria-live'), 'polite');
    const button = root.querySelector('button[type="submit"]');
    assert.equal(button.disabled, disabled, check);
    assert.equal(text(button), COPY.register_button);
    const input = root.querySelector('#code');
    assert.equal(input.getAttribute('aria-describedby'), 'code-check');
    assert.equal(input.getAttribute('maxlength'), '100');
    assert.equal(input.getAttribute('autocapitalize'), 'characters');
    assert.equal(input.getAttribute('autocomplete'), 'off');
    assert.equal(input.getAttribute('spellcheck'), 'false');
    assert.equal(text(root.querySelector('label')), COPY.code_label);
  }
  // Without a BarcodeDetector there is no Scan button; with one there is.
  let { root } = fresh();
  mount(root, device.render(deviceModel(), deviceActions()));
  assert.equal(root.querySelector('[data-scan]'), null);
  ({ root } = fresh());
  mount(root, device.render(deviceModel({ canScan: true }), deviceActions()));
  assert.equal(text(root.querySelector('[data-scan]')), COPY.scan_button);
  // preparing and blocked have no form.
  ({ root } = fresh());
  mount(root, device.render(deviceModel({ phase: 'preparing' }), deviceActions()));
  assert.equal(root.querySelector('form'), null);
  assert.ok(text(root).includes(COPY.getting_ready));
  ({ root } = fresh());
  mount(root, device.render(deviceModel({ phase: 'blocked' }), deviceActions()));
  assert.equal(root.querySelector('form'), null);
  assert.equal(text(root.querySelector('[role="alert"]')), COPY.reg_selftest_failed);
});

test('updateCheck changes only #code-check and the Register button', () => {
  const { doc, root } = fresh();
  const log = [];
  mount(root, device.render(deviceModel({ code: 'AB' }), deviceActions(log)));
  const input = root.querySelector('#code');
  const line = root.querySelector('#code-check');
  const button = root.querySelector('button[type="submit"]');
  const before = root.querySelectorAll('*');
  assert.equal(doc.activeElement, input, 'the input has the focus');
  input.value = 'ABCD';
  input.dispatch('input');
  assert.deepEqual(log, [['input', 'ABCD']]);
  device.updateCheck(root, { check: 'ok', phase: 'ready' });
  assert.equal(root.querySelector('#code-check'), line, 'the same check line');
  assert.equal(root.querySelector('button[type="submit"]'), button, 'the same button');
  assert.equal(root.querySelector('#code'), input, 'the same input');
  assert.deepEqual(root.querySelectorAll('*'), before, 'nothing else was added or removed');
  assert.equal(line.getAttribute('class'), 'message message-ok');
  assert.equal(text(line), COPY.code_ok);
  assert.equal(button.disabled, false);
  assert.equal(input.value, 'ABCD');
  assert.equal(doc.activeElement, input, 'focus stays in the input');
  device.updateCheck(root, { check: 'mistyped', phase: 'ready' });
  assert.equal(text(line), COPY.reg_mistyped);
  assert.equal(button.disabled, true);
  device.updateCheck(root, { check: 'ok', phase: 'sending' });
  assert.equal(button.disabled, true);
  assert.equal(text(button), COPY.registering);
  // The submit button submits through the form, which calls onSubmit once.
  device.updateCheck(root, { check: 'ok', phase: 'ready' });
  button.dispatch('click');
  assert.deepEqual(log.at(-1), ['submit']);
});

test('the forms prevent the browser\'s own submit, so the page never navigates away mid-press', () => {
  // A <form> without an action submits to the page's own URL unless its submit handler calls preventDefault(): on the
  // device view that would reload the Station mid-press and lose the kept nonce and proof key (X-3).
  const { doc, root } = fresh();
  const log = [];
  mount(root, device.render(deviceModel({ check: 'ok' }), deviceActions(log)));
  root.querySelector('button[type="submit"]').dispatch('click');
  assert.deepEqual(log, [['submit']]);
  assert.deepEqual(doc.navigations, [], 'Register never navigates');
  assert.equal(root.querySelector('form').dispatch('submit').defaultPrevented, true, 'Enter in the code field neither');
  assert.deepEqual(doc.navigations, []);
  const l = fresh();
  mount(l.root, login.render({ orgName: null, site: 'S', label: 'L', build: 'b', checking: false }, {}));
  assert.equal(l.root.querySelector('form').dispatch('submit').defaultPrevented, true, 'the lock screen\'s form');
  assert.deepEqual(l.doc.navigations, []);
  // The fake itself: a submit nobody prevents is recorded as a navigation.
  const f = fresh();
  const form = el('form', {}, el('button', { type: 'submit' }, 'Go'));
  mount(f.root, el('section', {}, form));
  form.querySelector('button').dispatch('click');
  assert.equal(f.doc.navigations.length, 1);
});

test('scanning shows the video with the model\'s stream as srcObject', () => {
  const { root } = fresh();
  const stream = { id: 'camera' };
  const log = [];
  mount(root, device.render(deviceModel({ phase: 'scanning', canScan: true, stream }), deviceActions(log)));
  const video = root.querySelector('video.scanner');
  assert.ok(video);
  assert.equal(video.srcObject, stream);
  assert.equal(video.muted, true);
  assert.equal(video.autoplay, true);
  assert.equal(video.playsInline, true);
  assert.equal(video.getAttribute('src'), null, 'no src attribute');
  assert.ok(text(root).includes(COPY.scan_hint));
  const scan = root.querySelector('[data-scan]');
  assert.equal(text(scan), COPY.scan_stop);
  scan.dispatch('click');
  assert.deepEqual(log, [['stop']]);
  const { root: r2 } = fresh();
  mount(r2, device.render(deviceModel({ phase: 'ready', canScan: true }), deviceActions(log)));
  assert.equal(r2.querySelector('video'), null);
  r2.querySelector('[data-scan]').dispatch('click');
  assert.deepEqual(log.at(-1), ['scan']);
});

test('sending disables Register and shows Registering…', () => {
  const { root } = fresh();
  mount(root, device.render(deviceModel({ phase: 'sending', check: 'ok', canScan: true }), deviceActions()));
  const button = root.querySelector('button[type="submit"]');
  assert.equal(button.disabled, true);
  assert.equal(text(button), 'Registering…');
  assert.equal(root.querySelector('[data-scan]').disabled, true, 'no scan while sending');
});

test('the device view shows each registration message', () => {
  const keys = ['reg_mistyped', 'reg_not_installed', 'reg_invalid', 'reg_busy', 'reg_no_answer', 'reg_rate_limited', 'reg_bad_request',
    'reg_maintenance', 'reg_failed', 'reg_selftest_failed', 'server_error', 'camera_refused'];
  for (const key of keys) {
    const { root } = fresh();
    mount(root, device.render(deviceModel({ message: { key, params: { incident: null } } }), deviceActions()));
    const alert = root.querySelector('p.message.message-error[role="alert"]');
    assert.equal(text(alert), COPY[key], key);
    assert.ok(!text(root).includes('Problem number'), `${key}: no incident line`);
  }
  const { root } = fresh();
  mount(root, device.render(deviceModel({ message: { key: 'server_error', params: { incident: 'X7K2' } } }), deviceActions()));
  assert.ok(text(root).includes(COPY.server_error));
  assert.ok(text(root).includes('Problem number: X7K2'));
});

test('registered shows the site and label with Continue', () => {
  for (const storageRefused of [false, true]) {
    const { doc, root } = fresh();
    const log = [];
    mount(root, device.render(deviceModel({ phase: 'registered', result: { site: 'Dev Site North', label: 'E2E 1' }, storageRefused }), deviceActions(log)));
    assert.equal(text(root.querySelector('p.message-ok')), 'Registered to Dev Site North as E2E 1.');
    assert.equal(text(root).includes(COPY.reg_storage_refused), storageRefused);
    const button = root.querySelector('button');
    assert.equal(text(button), COPY.continue);
    assert.equal(doc.activeElement, button, 'Continue has the focus');
    assert.equal(root.querySelector('form'), null);
    button.dispatch('click');
    assert.deepEqual(log, [['continue']]);
  }
});

test('the lock screen shows the organisation name, Pet Pantry Station and the build', () => {
  const { root } = fresh();
  mount(root, login.render({ orgName: 'CHS Pet Pantry', site: 'Dev Site North', label: 'E2E 1', build: '0.1.0-dev+abc', checking: false }, {}));
  assert.equal(text(root.querySelector('h1')), 'CHS Pet Pantry');
  assert.equal(text(root.querySelector('p.lead')), 'Pet Pantry Station · Version 0.1.0-dev+abc');
  assert.equal(text(root.querySelector('p.tablet')), 'E2E 1 at Dev Site North');
  for (const id of ['identifier', 'password']) assert.equal(root.querySelector('#' + id).disabled, true, id);
  assert.equal(root.querySelector('#password').getAttribute('type'), 'password');
  assert.equal(root.querySelector('#identifier').getAttribute('autocomplete'), 'username');
  assert.equal(root.querySelector('button[type="submit"]').disabled, true);
  assert.equal(root.querySelector('form').getAttribute('aria-disabled'), 'true');
  assert.equal(text(root.querySelector('p.status')), COPY.signin_not_ready);
  // No organisation name yet: the app's name.
  const { root: r2 } = fresh();
  mount(r2, login.render({ orgName: null, site: 'S', label: 'L', build: 'b', checking: false }, {}));
  assert.equal(text(r2.querySelector('h1')), COPY.app_name);
});

test('the lock screen says Checking this tablet… while checking', () => {
  const { root } = fresh();
  mount(root, login.render({ orgName: 'CHS Pet Pantry', site: 'S', label: 'L', build: 'b', checking: true }, {}));
  assert.equal(text(root.querySelector('p.status')), 'Checking this tablet…');
  assert.equal(root.querySelector('button[type="submit"]').disabled, true);
});

test('about shows each fact and its fallback, Update now only when an update waits, and Check this tablet disabled', () => {
  const facts = (root) => {
    const dts = root.querySelectorAll('dt').map(text);
    const dds = root.querySelectorAll('dd').map(text);
    return Object.fromEntries(dts.map((k, i) => [k, dds[i]]));
  };
  const log = [];
  const actions = { onRepair: () => log.push('repair'), onUpdateNow: () => log.push('update'), onBack: () => log.push('back') };
  let { root } = fresh();
  mount(root, about.render(aboutModel({ lastContact: '2026-10-01T12:00:00.000Z', unsynced: 3, offsetMs: 1500, storageKb: 812 }), actions));
  assert.equal(text(root.querySelector('h1')), COPY.about_title);
  assert.deepEqual(facts(root), {
    [COPY.about_name]: 'E2E 1', [COPY.about_site]: 'Dev Site North', [COPY.about_version]: '0.1.0-dev+abc',
    [COPY.about_last_contact]: '2026-10-01T12:00:00.000Z', [COPY.about_key]: 'Yes', [COPY.about_unsynced]: '3',
    [COPY.about_clock]: 'Right', [COPY.about_storage]: '0.8 MB', [COPY.about_storage_kept]: 'Yes',
  });
  const buttons = () => root.querySelectorAll('button');
  const check = buttons().find((b) => text(b) === COPY.check_tablet);
  assert.equal(check.disabled, true);
  assert.ok(text(root).includes(COPY.check_needs_person));
  assert.equal(buttons().some((b) => text(b) === COPY.update_now), false, 'no update waits');
  assert.equal(root.querySelector('.message-warning'), null, 'no captive portal');
  buttons().find((b) => text(b) === COPY.repair_this_app).dispatch('click');
  buttons().find((b) => text(b) === COPY.back).dispatch('click');
  assert.deepEqual(log, ['repair', 'back']);

  // Fallbacks.
  ({ root } = fresh());
  mount(root, about.render(aboutModel({ label: null, site: null, lastContact: null, keyReceived: 'unregistered', offsetMs: null, storageKb: null,
    persisted: false, updateWaiting: true, captivePortal: true, repairState: 'repairing' }), actions));
  assert.deepEqual(facts(root), {
    [COPY.about_name]: COPY.not_registered, [COPY.about_site]: COPY.not_registered, [COPY.about_version]: '0.1.0-dev+abc',
    [COPY.about_last_contact]: COPY.about_never, [COPY.about_key]: COPY.not_registered, [COPY.about_unsynced]: '0',
    [COPY.about_clock]: COPY.clock_unknown, [COPY.about_storage]: COPY.storage_unknown, [COPY.about_storage_kept]: 'No',
  });
  assert.equal(text(root.querySelector('.message-warning')), COPY.captive_portal);
  assert.ok(text(root).includes(COPY.repairing));
  assert.equal(buttons().find((b) => text(b) === COPY.repair_this_app).disabled, true, 'one repair at a time');
  buttons().find((b) => text(b) === COPY.update_now).dispatch('click');
  assert.deepEqual(log.at(-1), 'update');
  ({ root } = fresh());
  mount(root, about.render(aboutModel({ repairState: 'offline' }), actions));
  assert.ok(text(root).includes(COPY.repair_offline));
  ({ root } = fresh());
  mount(root, about.render(aboutModel({ repairState: 'pending' }), actions));
  assert.equal(text(root.querySelector('p.status')), COPY.repair_registering, 'refused while a registration body is kept');
  assert.equal(buttons().find((b) => text(b) === COPY.repair_this_app).disabled, false, 'it can be pressed again later');

  // The key and the clock.
  for (const [k, want] of [[true, 'Yes'], [false, 'No'], [null, COPY.checking_short], ['unregistered', COPY.not_registered], ['unknown', COPY.key_unknown]]) {
    ({ root } = fresh());
    mount(root, about.render(aboutModel({ keyReceived: k }), actions));
    assert.equal(facts(root)[COPY.about_key], want, String(k));
  }
  for (const [ms, want] of [[0, 'Right'], [1999, 'Right'], [-1999, 'Right'], [2000, '2 seconds slow'], [-45000, '45 seconds fast'],
    [119499, '119 seconds slow'], [120000, '2 minutes slow'], [-600000, '10 minutes fast'], [null, COPY.clock_unknown]]) {
    assert.equal(about.clockText(ms), want, String(ms));
  }
  ({ root } = fresh());
  mount(root, about.render(aboutModel({ storageKb: 10 * 1024 + 512 }), actions));
  assert.equal(facts(root)[COPY.about_storage], '10.5 MB');
});

test('wipe shows each phase\'s text and Register this tablet when erased', () => {
  const show = (model) => { const { root } = fresh(); const log = []; mount(root, wipe.render(model, { onRegisterAgain: () => log.push('again') })); return { root, log }; };
  assert.equal(text(show({ phase: 'retiring' }).root.querySelector('h1')), COPY.wipe_retiring);
  assert.equal(text(show({ phase: 'erasing' }).root.querySelector('h1')), COPY.wipe_erasing);
  assert.equal(text(show({ phase: 'stuck', n: 2 }).root.querySelector('h1')),
    'Retired, but 2 record(s) could not be uploaded, so it has not erased itself. Ask a Coordinator.');
  let s = show({ phase: 'confirming', mode: 'Push Then Wipe' });
  assert.equal(text(s.root.querySelector('h1')), COPY.wipe_retiring);
  assert.equal(text(s.root.querySelector('p.status')), COPY.wipe_confirming);
  s = show({ phase: 'confirming', mode: 'Wipe Now' });
  assert.equal(text(s.root.querySelector('h1')), COPY.wipe_erasing);
  s = show({ phase: 'blocked', mode: 'Push Then Wipe', previous: 'confirming' });
  assert.equal(text(s.root.querySelector('h1')), COPY.wipe_retiring, 'the previous heading stays');
  assert.equal(text(s.root.querySelector('[role="alert"]')), COPY.close_other_window);
  s = show({ phase: 'blocked', previous: 'erasing' });
  assert.equal(text(s.root.querySelector('h1')), COPY.wipe_erasing);
  for (const [final, key] of [['uploaded', 'erased_uploaded'], ['not_uploaded', 'erased_not_uploaded'], ['unknown', 'erased_unknown']]) {
    s = show({ phase: 'erased', final });
    assert.equal(text(s.root.querySelector('h1')), COPY[key], final);
    const button = s.root.querySelector('button');
    assert.equal(text(button), COPY.register_again);
    button.dispatch('click');
    assert.deepEqual(s.log, ['again']);
  }
  assert.equal(show({ phase: 'retiring' }).root.querySelector('button'), null, 'no button before the end');
});

test('elsewhere and replaced', () => {
  const log = [];
  let { root } = fresh();
  mount(root, elsewhere.render({ phase: 'elsewhere' }, { onUseHere: () => log.push('here') }));
  assert.equal(text(root.querySelector('h1')), COPY.elsewhere);
  const button = root.querySelector('button');
  assert.equal(text(button), COPY.use_here);
  button.dispatch('click');
  assert.deepEqual(log, ['here']);
  ({ root } = fresh());
  mount(root, elsewhere.render({ phase: 'replaced' }, { onUseHere: () => log.push('here') }));
  assert.equal(text(root.querySelector('h1')), COPY.replaced);
  assert.equal(root.querySelector('button'), null, 'no buttons');
});

test('the header shows the chip states and each banner', () => {
  for (const [connectivity, cls, label] of [['online', 'chip-online', 'Online'], ['offline', 'chip-offline', 'Offline'], ['unknown', 'chip-unknown', 'Connecting…']]) {
    const { root } = fresh();
    mount(root, chrome.render(chromeModel({ connectivity })));
    const chip = root.querySelector('span.chip');
    assert.equal(chip.getAttribute('class'), 'chip ' + cls);
    assert.equal(chip.getAttribute('role'), 'status');
    assert.equal(chip.getAttribute('aria-live'), 'polite');
    assert.equal(text(chip), label);
    assert.equal(text(root.querySelector('.brand-name')), COPY.app_name);
    assert.equal(root.querySelector('img.brand-icon').getAttribute('src'), 'icons/icon-192.png');
    assert.equal(root.querySelector('img.brand-icon').getAttribute('alt'), '');
    assert.equal(text(root.querySelector('span.org')), 'CHS Pet Pantry');
    assert.equal(text(root.querySelector('span.tablet')), 'E2E 1 at Dev Site North');
    assert.equal(root.querySelectorAll('.banner').length, 0);
  }
  const want = [['dev_relax', 'banner banner-danger', 'status'], ['header_missing', 'banner banner-warning', 'alert'],
    ['unknown_tablet', 'banner banner-warning', 'alert'], ['close_other_window', 'banner banner-warning', 'alert'],
    ['update_ready', 'banner banner-info', 'status']];
  for (const [kind, cls, role] of want) {
    const { root } = fresh();
    mount(root, chrome.render(chromeModel({ banners: new Set([kind]) })));
    const banners = root.querySelectorAll('.banner');
    assert.equal(banners.length, 1, kind);
    assert.equal(banners[0].getAttribute('class'), cls);
    assert.equal(banners[0].getAttribute('role'), role);
    assert.equal(text(banners[0]), COPY[kind]);
  }
  // All of them, in order; unregistered and without an organisation name the header leaves both out.
  let { root } = fresh();
  mount(root, chrome.render(chromeModel({ orgName: null, site: null, label: null,
    banners: new Set(['update_ready', 'dev_relax', 'close_other_window', 'unknown_tablet', 'header_missing']) })));
  assert.deepEqual(root.querySelectorAll('.banner').map(text), want.map(([k]) => COPY[k]));
  assert.equal(root.querySelector('span.org'), null);
  assert.equal(root.querySelector('span.tablet'), null);
  assert.equal(text(root.querySelector('.banner-warning')), COPY.header_missing);
  // A window that never asks the server (another window holds the tablet, or no storage) shows no chip at all.
  ({ root } = fresh());
  mount(root, chrome.render(chromeModel({ connectivity: 'none' })));
  assert.equal(root.querySelector('.chip'), null);
  assert.equal(text(root.querySelector('span.org')), 'CHS Pet Pantry');
  // The wipe screens show only the brand.
  ({ root } = fresh());
  mount(root, chrome.render(chromeModel({ minimal: true, banners: new Set(['dev_relax']) })));
  assert.equal(root.querySelector('.chip'), null);
  assert.equal(root.querySelector('.banner'), null);
  assert.equal(text(root), COPY.app_name);
  // The footer.
  ({ root } = fresh());
  mount(root, chrome.footer({ build: '0.1.0-dev+abc', aboutLink: true }));
  assert.equal(text(root), 'Version 0.1.0-dev+abc · About this tablet');
  assert.equal(root.querySelector('a.link').getAttribute('href'), '#/about');
  ({ root } = fresh());
  mount(root, chrome.footer({ build: 'b', aboutLink: false }));
  assert.equal(root.querySelector('a'), null);
});

test('dom.el refuses script, style and iframe, on* and style attributes, and links other than #/', () => {
  const { root } = fresh();
  for (const tag of ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'template', 'SCRIPT']) {
    assert.throws(() => el(tag), /is not allowed/, tag);
  }
  for (const attr of ['onclick', 'onerror', 'ONLOAD', 'style', 'innerHTML', 'outerHTML', 'srcdoc', 'formaction', 'action', 'href', 'src', 'xlink', 'nonce']) {
    assert.throws(() => el('div', { [attr]: 'x' }), /not allowed/, attr);
  }
  for (const link of ['http://evil.example/', 'javascript:alert(1)', '//evil', '#about', '#/About', '#/a b', '../login.php']) {
    assert.throws(() => el('a', { link }), /link must be/, link);
  }
  for (const image of ['http://evil/x.png', 'icons/../x.png', 'data:image/png;base64,AA', 'icons/x.svg']) {
    assert.throws(() => el('img', { image }), /image must be/, image);
  }
  // What is allowed: #/ links, the Station's icons, blob: images, text children that are never parsed.
  const a = el('a', { link: '#/about', class: 'link', 'aria-label': 'About', 'data-x': 'y', hidden: false }, '<b>not bold</b>');
  assert.equal(a.getAttribute('href'), '#/about');
  assert.equal(a.getAttribute('data-x'), 'y');
  assert.equal(a.children.length, 0, 'the markup stays text');
  assert.equal(text(a), '<b>not bold</b>');
  assert.equal(el('img', { image: 'icons/icon-192.png' }).getAttribute('src'), 'icons/icon-192.png');
  assert.equal(el('img', { image: 'blob:http://localhost:8088/1234' }).getAttribute('src'), 'blob:http://localhost:8088/1234');
  // on: listeners; booleans; null children skipped; numbers become text.
  let clicked = 0;
  const b = el('button', { on: { click: () => { clicked += 1; } }, disabled: false, 'data-scan': true, type: 'button' }, null, false, undefined, 3, ['a', ['b']]);
  b.dispatch('click');
  assert.equal(clicked, 1);
  assert.equal(b.getAttribute('data-scan'), '');
  assert.equal(text(b), '3ab');
  // mount() focuses [data-autofocus], else the h1 (made focusable).
  mount(root, el('section', {}, el('h1', {}, 'Title')));
  const h1 = root.querySelector('h1');
  assert.equal(h1.getAttribute('tabindex'), '-1');
  assert.equal(root.ownerDocument.activeElement, h1);
  mount(root, el('section', {}, el('h1', {}, 'T'), el('input', { id: 'x', 'data-autofocus': true })));
  assert.equal(root.ownerDocument.activeElement, root.querySelector('#x'));
  clear(root);
  assert.equal(root.children.length, 0);
});

test('starting shows Starting… or Checking… alone, and the storage message with Try again', () => {
  const log = [];
  for (const [phase, lead] of [['starting', COPY.starting], ['checking', COPY.checking]]) {
    const { root } = fresh();
    mount(root, starting.render({ phase }, { onRetry: () => log.push(phase) }));
    assert.equal(text(root.querySelector('h1')), COPY.app_name);
    assert.equal(text(root.querySelector('p.lead')), lead);
    assert.equal(root.querySelector('button'), null, phase);
  }
  const { root } = fresh();
  mount(root, starting.render({ phase: 'no_storage' }, { onRetry: () => log.push('retry') }));
  assert.equal(text(root.querySelector('p.lead')), COPY.storage_unavailable);
  const button = root.querySelector('button');
  assert.equal(text(button), COPY.try_again);
  assert.equal(button.getAttribute('class'), 'button button-primary');
  button.dispatch('click');
  assert.deepEqual(log, ['retry']);
});
