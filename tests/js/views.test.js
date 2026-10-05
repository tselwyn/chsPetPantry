// The Station's views and dom.js (S2 spec §3.11, §4.1, §4.2; S3 spec §3.4) over fake-dom.js, where innerHTML, outerHTML,
// insertAdjacentHTML and document.write throw: every screen is built from text nodes only.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { fakeDocument, text } from './support/fake-dom.js';
import { readStation } from './support/fixtures.js';
import { COPY, t } from '../../public/station/js/copy.js';
import { el, mount, remount, keepFocus, setUnavailable, unavailable, useDocument, clear } from '../../public/station/js/dom.js';
import * as chrome from '../../public/station/js/views/chrome.js';
import * as starting from '../../public/station/js/views/starting.js';
import * as device from '../../public/station/js/views/device.js';
import * as login from '../../public/station/js/views/login.js';
import * as ack from '../../public/station/js/views/ack.js';
import * as password from '../../public/station/js/views/password.js';
import * as pinSet from '../../public/station/js/views/pin_set.js';
import * as home from '../../public/station/js/views/home.js';
import * as controllers from '../../public/station/js/views/screens.js';
import { fakeSession } from './support/fake-session.js';
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
const PEOPLE = [{ user_id: 12, display_name: 'Jo Doe', username: 'jdoe', has_pin: true }, { user_id: 13, display_name: 'Al Bee', username: 'abee', has_pin: false }];
const loginModel = (over = {}) => ({ orgName: 'CHS Pet Pantry', site: 'Dev Site North', label: 'E2E 1', build: '0.1.0-dev+abc', checking: false, ...over });
/** Every action of the lock screen, each pushing [name, ...args] to log. */
const loginActions = (log = []) => Object.fromEntries(['onSignIn', 'onBackToPicker', 'onPick', 'onSomeoneElse', 'onDigit', 'onBackspace', 'onPinSubmit',
  'onPinCancel', 'onUsePassword'].map((name) => [name, (...args) => log.push([name, ...args])]));
const DOC = { document_id: 5, version: '2', language: 'en', fingerprint: 'f'.repeat(64),
  body: 'You keep what you see here private.\nThat includes names.\n\nSecond <b>part</b> https://example.test/a-very-long-link\n\n\n  \nThird part.' };
const homeModel = (over = {}) => ({ name: 'Jo Doe', site: 'Dev Site North', label: 'E2E 1', connectivity: 'online', hasPin: false, pinSwitch: true, canRecord: true,
  releaseUnavailable: null, ...over });
/** The S3 message shapes a view can be given: a copy key, one with params, a server sentence, a problem number. */
const MESSAGES = [{ key: 'login_failed' }, { key: 'pin_wrong_many', params: { n: 2 } }, { key: 'no_station_access_site', params: { site: 'Dev Site North' } },
  { text: 'The server said this.' }, { key: 'server_error', params: { incident: 'ABC123' } }];

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
  for (const mode of ['form', 'picker', 'pin']) {
    for (const busy of [false, true]) {
      for (const [i, message] of [null, ...MESSAGES].entries()) {
        screens.push([`login ${mode} ${busy} ${i}`, () => login.render(loginModel({ mode, busy, message, notice: message ? { key: 'notice_idle' } : null,
          identifier: i % 2 ? 'jdoe' : '', canGoBack: i % 2 === 1, people: i === 3 ? [] : PEOPLE, pin: { name: 'Jo Doe', entered: i },
          pinMin: 4, pinMax: i === 2 ? 4 : 6 }), loginActions())]);
      }
    }
  }
  for (const phase of ['loading', 'error', 'ready', 'sending', 'declining']) {
    for (const message of [null, ...MESSAGES]) screens.push([`ack ${phase}`, () => ack.render({ phase, doc: DOC, message }, { onAccept: noop, onDecline: noop, onRetry: noop })]);
  }
  screens.push(['ack ready without a document', () => ack.render({ phase: 'ready', doc: null, message: null }, {})]);
  for (const busy of [false, true]) {
    for (const message of [null, ...MESSAGES]) {
      const errors = message ? { current_password: message, new_password: { key: 'password_rule', params: { n: 12 } }, repeat_password: { key: 'password_mismatch' } } : {};
      screens.push([`password ${busy}`, () => password.render({ busy, errors, message, minLength: 12 }, { onSubmit: noop, onCancel: noop })]);
      const pinErrors = message ? { pin_password: message, pin_new: { key: 'pin_rule_guessable' }, pin_repeat: { key: 'pin_rule_mismatch' } } : {};
      for (const [pinMin, pinMax] of [[4, 6], [5, 5]]) {
        for (const done of [false, true]) {
          screens.push([`pin_set ${busy} ${pinMin}-${pinMax} ${done}`, () => pinSet.render({ busy, errors: pinErrors, message, done, pinMin, pinMax },
            { onSubmit: noop, onBack: noop, onContinue: noop })]);
        }
      }
    }
  }
  for (const connectivity of ['online', 'offline', 'unknown', 'none']) {
    for (const releaseUnavailable of [null, 'no_vault_key', 'no_grant_possible', 'password_needed']) {
      for (const hasPin of [false, true]) {
        screens.push([`home ${connectivity} ${releaseUnavailable} ${hasPin}`, () => home.render(homeModel({ connectivity, releaseUnavailable, hasPin, label: hasPin ? null : 'E2E 1' }),
          { onSetPin: noop })]);
      }
    }
  }
  // The controllers' own first draws (views/screens.js over fake-session.js).
  const first = (make, over) => () => {
    let out = null;
    make({ session: fakeSession(over), mount: (section) => { out = section; }, info: () => ({ orgName: null, site: 'S', label: 'L', build: 'b' }),
      checking: () => false, connectivity: () => 'online', onSetPin: noop, onDone: noop }).show();
    return out;
  };
  for (const state of ['LOCKED', 'PICKER', 'IDLE']) {
    screens.push([`screens login ${state}`, first(controllers.createLoginScreen, { state, people: PEOPLE, notice: { key: 'notice_idle' } })]);
  }
  screens.push(['screens ack', first(controllers.createAckScreen, {})]);
  screens.push(['screens password', first(controllers.createPasswordScreen, {})]);
  screens.push(['screens pin_set', first(controllers.createPinSetScreen, { config: { pin_min_digits: 5, pin_max_digits: 5 } })]);
  for (const releaseUnavailable of [null, 'no_vault_key']) {
    screens.push([`screens home ${releaseUnavailable}`, first(controllers.createHomeScreen, { state: 'ACTIVE', canRecord: true, releaseUnavailable,
      user: { user_id: 12, display_name: 'Jo Doe', has_pin: false, pin_switch: true } })]);
  }
  for (const controls of ['none', 'end', 'both']) {
    for (const person of [null, 'Jo Doe']) {
      screens.push([`chrome ${controls} ${person}`, () => chrome.render(chromeModel({ controls, person, banners: new Set(['update_ready']) }), { onSwitchUser: noop, onEndShift: noop })]);
    }
  }
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
    // Keys chosen at run time live in key maps: every quoted snake_case literal of a view is a copy key or a known value
    // (S3: the session's gates and release reasons, and the six field ids, which are also their errors' keys).
    const literals = [...src.matchAll(/'([a-z]+(?:_[a-z]+)+)'/g)].map((m) => m[1]);
    const known = ['dev_relax', 'header_missing', 'close_other_window', 'update_ready', 'no_storage', 'not_uploaded',
      'policy_ack', 'password_change', 'no_vault_key', 'no_grant_possible',
      'current_password', 'new_password', 'repeat_password', 'pin_password', 'pin_new', 'pin_repeat'];
    for (const k of literals) assert.ok(Object.hasOwn(COPY, k) || known.includes(k), `${f}: '${k}'`);
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
  for (const id of ['identifier', 'password']) assert.equal(root.querySelector('#' + id).disabled, false, id);
  assert.equal(root.querySelector('#password').getAttribute('type'), 'password');
  assert.equal(root.querySelector('#identifier').getAttribute('autocomplete'), 'username');
  assert.equal(root.querySelector('button[type="submit"]').disabled, false);
  assert.equal(root.querySelector('form').getAttribute('aria-disabled'), null);
  assert.equal(text(root.querySelector('p.status')), '');
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

test('the lock screen form is enabled once the check is done', () => {
  // While the start-up heartbeat runs: everything disabled, and a submit (Enter) signs nobody in.
  const log = [];
  let { doc, root } = fresh();
  mount(root, login.render(loginModel({ checking: true }), loginActions(log)));
  for (const sel of ['#identifier', '#password', 'button[type="submit"]']) assert.equal(root.querySelector(sel).disabled, true, sel);
  assert.equal(root.querySelector('form').getAttribute('aria-disabled'), 'true');
  assert.equal(text(root.querySelector('p.status[role="status"]')), COPY.checking);
  assert.equal(root.querySelector('form').dispatch('submit').defaultPrevented, true);
  assert.deepEqual(log, [], 'no sign-in while checking');
  // Done: enabled, the status line empty (but present), focus in the identifier, no Back.
  ({ doc, root } = fresh());
  mount(root, login.render(loginModel({ checking: false }), loginActions(log)));
  const id = root.querySelector('#identifier');
  for (const sel of ['#identifier', '#password', 'button[type="submit"]']) assert.equal(root.querySelector(sel).disabled, false, sel);
  assert.equal(root.querySelector('form').getAttribute('aria-disabled'), null);
  assert.equal(text(root.querySelector('p.status[role="status"]')), '');
  assert.equal(text(root.querySelector('button[type="submit"]')), COPY.signin_button);
  assert.equal(id.getAttribute('autocapitalize'), 'none');
  assert.equal(id.getAttribute('spellcheck'), 'false');
  assert.equal(id.getAttribute('autocomplete'), 'username');
  assert.equal(root.querySelector('#password').getAttribute('autocomplete'), 'current-password');
  assert.equal(text(root.querySelector('label[for="identifier"]')), COPY.username_label);
  assert.equal(text(root.querySelector('label[for="password"]')), COPY.password_label);
  assert.equal(doc.activeElement, id, 'an empty identifier has the focus');
  assert.equal(root.querySelectorAll('button').length, 1, 'no Back');
  assert.equal(root.querySelector('section').getAttribute('class'), 'view view-login');
  // A prefilled identifier: the password has the focus; Back when asked for.
  ({ doc, root } = fresh());
  mount(root, login.render(loginModel({ identifier: 'jdoe', canGoBack: true }), loginActions(log)));
  assert.equal(root.querySelector('#identifier').value, 'jdoe');
  assert.equal(doc.activeElement, root.querySelector('#password'), 'the password has the focus');
  const back = root.querySelectorAll('button').find((b) => text(b) === COPY.back);
  assert.equal(back.getAttribute('type'), 'button');
  back.dispatch('click');
  assert.deepEqual(log, [['onBackToPicker']]);
  // A sign-in in flight: Signing in… on the button and in the status line, the fields disabled, the buttons
  // unavailable but focusable, the focus on Sign in, and a press does nothing.
  ({ doc, root } = fresh());
  log.splice(0);
  mount(root, login.render(loginModel({ busy: true, identifier: 'jdoe', canGoBack: true }), loginActions(log)));
  const busySubmit = root.querySelector('button[type="submit"]');
  assert.equal(text(busySubmit), COPY.signing_in);
  assert.equal(text(root.querySelector('p.status[role="status"]')), COPY.signing_in);
  for (const i of root.querySelectorAll('input')) assert.equal(i.disabled, true, i.getAttribute('id'));
  for (const b of root.querySelectorAll('button')) assert.ok(b.disabled === false && b.getAttribute('aria-disabled') === 'true', text(b));
  assert.equal(doc.activeElement, busySubmit, 'Sign in has the focus');
  busySubmit.dispatch('click');
  root.querySelectorAll('button').find((b) => text(b) === COPY.back).dispatch('click');
  assert.deepEqual(log, [], 'nothing while it runs');
  assert.deepEqual(doc.navigations, []);
});

test('submitting calls onSignIn with the values as typed and clears the password field', () => {
  const { doc, root } = fresh();
  const log = [];
  mount(root, login.render(loginModel(), loginActions(log)));
  root.querySelector('#identifier').value = ' JDoe ';
  root.querySelector('#password').value = 'Correct-Horse-Battery-9';
  root.querySelector('button[type="submit"]').dispatch('click');
  assert.deepEqual(log, [['onSignIn', ' JDoe ', 'Correct-Horse-Battery-9']], 'as typed: the server trims');
  assert.equal(root.querySelector('#password').value, '', 'the password field is cleared');
  assert.equal(root.querySelector('#identifier').value, ' JDoe ', 'the identifier stays');
  root.querySelector('#password').value = 'x';
  assert.equal(root.querySelector('form').dispatch('submit').defaultPrevented, true, 'Enter in a field');
  assert.deepEqual(log.at(-1), ['onSignIn', ' JDoe ', 'x']);
  assert.deepEqual(doc.navigations, [], 'the page never navigates');
  // Messages and notices: a copy key, a server sentence, a problem number.
  for (const [message, want] of [[{ key: 'login_failed' }, COPY.login_failed], [{ text: 'The server said this.' }, 'The server said this.'],
    [{ key: 'no_station_access_site', params: { site: 'Dev Site North' } }, "You don't have access to Dev Site North, where this tablet is used."]]) {
    const r = fresh();
    mount(r.root, login.render(loginModel({ message, notice: { key: 'shift_ended' } }), {}));
    assert.equal(text(r.root.querySelector('p.message.message-error[role="alert"]')), want);
    assert.equal(text(r.root.querySelector('p.notice[role="status"]')), COPY.shift_ended);
    assert.equal(r.root.querySelector('p.hint'), null, 'no problem number');
  }
  const r = fresh();
  mount(r.root, login.render(loginModel({ message: { key: 'server_error', params: { incident: 'X7K2' } } }), {}));
  assert.equal(text(r.root.querySelector('p.message-error')), COPY.server_error);
  assert.equal(text(r.root.querySelector('p.hint')), 'Problem number: X7K2');
  assert.equal(r.root.querySelector('p.notice'), null, 'no notice');
});

test('the picker shows a tile per person and Someone else', () => {
  const { root } = fresh();
  const log = [];
  mount(root, login.render(loginModel({ mode: 'picker', people: PEOPLE, notice: { key: 'notice_idle' } }), loginActions(log)));
  assert.equal(root.querySelector('section').getAttribute('class'), 'view view-login view-picker');
  assert.equal(text(root.querySelector('h1')), COPY.picker_title);
  assert.equal(text(root.querySelector('p.tablet')), 'E2E 1 at Dev Site North');
  assert.equal(text(root.querySelector('p.notice')), COPY.notice_idle);
  assert.equal(root.querySelector('form'), null);
  const items = root.querySelector('ul.tiles').children;
  assert.equal(items.length, 3);
  const tiles = root.querySelectorAll('ul.tiles li button.button.tile');
  assert.deepEqual(tiles.map((b) => [text(b), b.getAttribute('data-user-id'), b.getAttribute('type')]),
    [[COPY.someone_else, null, 'button'], ['Jo Doe', '12', 'button'], ['Al Bee', '13', 'button']]);
  assert.equal(tiles[0].getAttribute('class'), 'button tile tile-other', 'Someone else is first: a new volunteer finds it without scrolling');
  assert.ok(tiles.every((b) => b.querySelector('span') === null), 'different names: one line each');
  tiles[2].dispatch('click');
  tiles[1].dispatch('click');
  tiles[0].dispatch('click');
  assert.deepEqual(log, [['onPick', 13], ['onPick', 12], ['onSomeoneElse']]);
  // Nobody listed: only Someone else.
  const e = fresh();
  mount(e.root, login.render(loginModel({ mode: 'picker', people: [] }), {}));
  assert.deepEqual(e.root.querySelectorAll('button').map(text), [COPY.someone_else]);
});

test('the PIN pad shows one filled dot per digit and its keys and the keyboard call the actions', () => {
  const log = [];
  let { root } = fresh();
  mount(root, login.render(loginModel({ mode: 'pin', pin: { name: 'Jo Doe', entered: 2 }, pinMin: 4, pinMax: 6, message: { key: 'pin_wrong_many', params: { n: 2 } } }),
    loginActions(log)));
  const section = root.querySelector('section');
  assert.equal(section.getAttribute('class'), 'view view-login view-pin');
  assert.equal(text(root.querySelector('h1')), 'Jo Doe');
  assert.equal(text(root.querySelector('p.lead')), COPY.pin_title);
  assert.equal(text(root.querySelector('p.message-error')), 'Wrong PIN. 2 tries left before a password is needed.');
  const dots = root.querySelector('p.pin-dots');
  assert.equal(text(dots), '●●○○○○');
  assert.equal(dots.getAttribute('aria-hidden'), 'true', 'the dots are for the eye');
  assert.equal(dots.getAttribute('role'), null);
  const count = root.querySelector('p.pin-count.visually-hidden[role="status"]');
  assert.equal(text(count), '2 digits entered', 'the count is read out from its own status line');
  assert.equal(text(root.querySelector('p.status[role="status"]')), '', 'the progress line is there, empty');
  const keys = root.querySelectorAll('div.pinpad button.button.key');
  assert.deepEqual(keys.map((k) => k.getAttribute('data-key')), ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'back', '0', 'ok']);
  assert.deepEqual(keys.map(text), ['1', '2', '3', '4', '5', '6', '7', '8', '9', COPY.pin_delete, '0', COPY.pin_ok]);
  assert.ok(keys.every((k) => k.getAttribute('type') === 'button'));
  const key = (k) => keys.find((b) => b.getAttribute('data-key') === k);
  assert.equal(key('ok').getAttribute('aria-disabled'), 'true', 'OK waits for pin_min_digits (and can keep the focus)');
  assert.ok(keys.every((k) => k.disabled === false));
  assert.ok(keys.filter((k) => k.getAttribute('data-key') !== 'ok').every((k) => k.getAttribute('aria-disabled') === null));
  key('ok').dispatch('click');
  key('7').dispatch('click');
  key('0').dispatch('click');
  key('back').dispatch('click');
  const actions = root.querySelectorAll('div.actions button');
  assert.deepEqual(actions.map(text), [COPY.use_password, COPY.pin_cancel]);
  actions[0].dispatch('click');
  actions[1].dispatch('click');
  assert.deepEqual(log.splice(0), [['onDigit', '7'], ['onDigit', '0'], ['onBackspace'], ['onUsePassword'], ['onPinCancel']]);
  // The keyboard: digits, Backspace, Enter, Escape; anything else, or with a modifier, does nothing.
  for (const k of ['5', 'Backspace', 'Enter', 'Escape', 'a', 'Tab']) section.dispatch('keydown', { key: k });
  section.dispatch('keydown', { key: '1', ctrlKey: true });
  root.querySelector('h1').dispatch('keydown', { key: '9' }); // focus is on the h1 after a mount: it bubbles
  key('3').dispatch('keydown', { key: 'Enter' }); // Enter on a focused key clicks that key; it does not also submit
  assert.deepEqual(log.splice(0), [['onDigit', '5'], ['onBackspace'], ['onPinSubmit'], ['onPinCancel'], ['onDigit', '9']]);
  const ev = section.dispatch('keydown', { key: '4' });
  assert.equal(ev.defaultPrevented, true);
  log.splice(0);
  // Enough digits: OK is enabled; the dots follow pinMax.
  ({ root } = fresh());
  mount(root, login.render(loginModel({ mode: 'pin', pin: { name: 'Jo Doe', entered: 4 }, pinMin: 4, pinMax: 4 }), loginActions(log)));
  assert.equal(text(root.querySelector('p.pin-dots')), '●●●●');
  assert.equal(root.querySelector('[data-key="ok"]').getAttribute('aria-disabled'), null);
  root.querySelector('[data-key="ok"]').dispatch('click');
  assert.deepEqual(log.splice(0), [['onPinSubmit']]);
  ({ root } = fresh());
  mount(root, login.render(loginModel({ mode: 'pin', pin: { name: 'Jo Doe', entered: 0 } }), {}));
  assert.equal(text(root.querySelector('p.pin-dots')), '○○○○○○', 'six empty dots by default');
  // While a switch is in flight: every key and action unavailable (still focusable), Signing in… in the status line,
  // and neither a press nor the keyboard does anything.
  ({ root } = fresh());
  mount(root, login.render(loginModel({ mode: 'pin', busy: true, pin: { name: 'Jo Doe', entered: 6 } }), loginActions(log)));
  assert.ok(root.querySelectorAll('button').every((b) => b.disabled === false && b.getAttribute('aria-disabled') === 'true'));
  assert.equal(text(root.querySelector('p.status')), COPY.signing_in);
  for (const b of root.querySelectorAll('button')) b.dispatch('click');
  root.querySelector('section').dispatch('keydown', { key: '1' });
  root.querySelector('section').dispatch('keydown', { key: 'Escape' });
  assert.deepEqual(log, []);
  // The count's words: one digit, none, several.
  for (const [entered, want] of [[1, '1 digit entered'], [0, '0 digits entered'], [5, '5 digits entered']]) {
    ({ root } = fresh());
    mount(root, login.render(loginModel({ mode: 'pin', pin: { name: 'Jo Doe', entered } }), {}));
    assert.equal(text(root.querySelector('p.pin-count')), want);
  }
});

test('ack renders the agreement as text paragraphs, its version, I accept and I do not accept', () => {
  const log = [];
  const actions = { onAccept: () => log.push('accept'), onDecline: () => log.push('decline'), onRetry: () => log.push('retry') };
  let { root } = fresh();
  mount(root, ack.render({ phase: 'ready', doc: DOC, message: null }, actions));
  assert.equal(root.querySelector('section').getAttribute('class'), 'view view-ack');
  assert.equal(text(root.querySelector('h1')), COPY.ack_title);
  assert.equal(text(root.querySelector('p.lead')), COPY.ack_intro);
  assert.equal(text(root.querySelector('p.hint')), 'Version 2');
  const paras = root.querySelectorAll('div.policy-text p');
  assert.deepEqual(paras.map(text), ['You keep what you see here private.\nThat includes names.', 'Second <b>part</b> https://example.test/a-very-long-link', 'Third part.']);
  assert.ok(paras.every((p) => p.children.length === 0), 'text only: the markup stays text');
  assert.deepEqual(ack.paragraphs('a\r\n\r\nb\n \nc\n'), ['a', 'b', 'c']);
  assert.deepEqual(ack.paragraphs(null), []);
  const buttons = root.querySelectorAll('div.actions button');
  assert.deepEqual(buttons.map((b) => [text(b), b.getAttribute('class'), b.disabled]),
    [[COPY.ack_accept, 'button button-primary', false], [COPY.ack_decline, 'button button-secondary', false]]);
  buttons[0].dispatch('click');
  buttons[1].dispatch('click');
  assert.deepEqual(log.splice(0), ['accept', 'decline']);
  assert.equal(root.querySelector('[role="alert"]'), null);
  // policy_changed: the message with the (new) text.
  ({ root } = fresh());
  mount(root, ack.render({ phase: 'ready', doc: DOC, message: { key: 'policy_changed' } }, actions));
  assert.equal(text(root.querySelector('p.message.message-error[role="alert"]')), COPY.policy_changed);
  assert.equal(root.querySelectorAll('div.policy-text p').length, 3);
  // Sending: Sending… on I accept and in the status line by the buttons; both unavailable (focusable), a press does nothing.
  ({ root } = fresh());
  mount(root, ack.render({ phase: 'sending', doc: DOC, message: null }, actions));
  assert.deepEqual(root.querySelectorAll('div.actions button').map((b) => [text(b), b.getAttribute('aria-disabled')]),
    [[COPY.sending, 'true'], [COPY.ack_decline, 'true']]);
  assert.equal(text(root.querySelector('#ack-status[role="status"]')), COPY.sending);
  for (const b of root.querySelectorAll('div.actions button')) b.dispatch('click');
  assert.deepEqual(log, []);
  // Declining: the same, with Sending… on I do not accept.
  ({ root } = fresh());
  mount(root, ack.render({ phase: 'declining', doc: DOC, message: null }, actions));
  assert.deepEqual(root.querySelectorAll('div.actions button').map((b) => [text(b), b.getAttribute('aria-disabled')]),
    [[COPY.ack_accept, 'true'], [COPY.sending, 'true']]);
  assert.equal(text(root.querySelector('#ack-status')), COPY.sending);
  // Loading: no text, no buttons. Error: the message and Try again.
  ({ root } = fresh());
  mount(root, ack.render({ phase: 'loading', doc: null, message: null }, actions));
  assert.equal(text(root.querySelector('p.status')), COPY.ack_loading);
  assert.equal(root.querySelector('button'), null);
  assert.equal(root.querySelector('.policy-text'), null);
  ({ root } = fresh());
  mount(root, ack.render({ phase: 'error', doc: null, message: { key: 'ack_failed' } }, actions));
  assert.equal(text(root.querySelector('p.message.message-error')), COPY.ack_failed);
  const retry = root.querySelector('button');
  assert.equal(text(retry), COPY.try_again);
  retry.dispatch('click');
  assert.deepEqual(log, ['retry']);
});

test('password shows the rule with the minimum length and each field\'s error', () => {
  const log = [];
  const actions = { onSubmit: (...a) => log.push(['submit', ...a]), onCancel: () => log.push(['cancel']) };
  let { doc, root } = fresh();
  mount(root, password.render({ busy: false, errors: {}, message: null, minLength: 14 }, actions));
  assert.equal(root.querySelector('section').getAttribute('class'), 'view view-password');
  assert.equal(text(root.querySelector('h1')), COPY.password_title);
  assert.equal(text(root.querySelector('p.lead')), COPY.password_intro);
  assert.equal(text(root.querySelector('p.hint')), 'Use at least 14 characters. A short sentence you can remember works well.');
  const ids = ['current_password', 'new_password', 'repeat_password'];
  assert.deepEqual(root.querySelectorAll('input').map((i) => [i.getAttribute('id'), i.getAttribute('type'), i.getAttribute('autocomplete')]),
    [['current_password', 'password', 'current-password'], ['new_password', 'password', 'new-password'], ['repeat_password', 'password', 'new-password']]);
  assert.deepEqual(ids.map((id) => text(root.querySelector(`label[for="${id}"]`))), [COPY.current_password_label, COPY.new_password_label, COPY.repeat_password_label]);
  assert.equal(root.querySelector('.message-error'), null);
  assert.equal(doc.activeElement, root.querySelector('#current_password'));
  root.querySelector('#current_password').value = 'old';
  root.querySelector('#new_password').value = 'new one';
  root.querySelector('#repeat_password').value = 'new two';
  root.querySelector('button[type="submit"]').dispatch('click');
  assert.equal(text(root.querySelector('button[type="submit"]')), COPY.password_save);
  root.querySelectorAll('button').find((b) => text(b) === COPY.cancel).dispatch('click');
  assert.deepEqual(log, [['submit', 'old', 'new one', 'new two'], ['cancel']]);
  // Each error under its own field, its key being the field's id; the first one has the focus.
  ({ doc, root } = fresh());
  mount(root, password.render({ busy: false, minLength: 12, message: { key: 'password_offline' }, errors: {
    new_password: { text: 'Use at least 12 characters.' }, repeat_password: { key: 'password_mismatch' } } }, actions));
  assert.equal(text(root.querySelector('p.message[role="alert"]')), COPY.password_offline);
  assert.equal(root.querySelector('#err-current_password'), null);
  assert.equal(text(root.querySelector('p.message.message-error#err-new_password')), 'Use at least 12 characters.');
  assert.equal(text(root.querySelector('#err-repeat_password')), COPY.password_mismatch);
  assert.equal(root.querySelector('#new_password').getAttribute('aria-describedby'), 'err-new_password');
  assert.equal(root.querySelector('#current_password').getAttribute('aria-describedby'), null);
  assert.equal(doc.activeElement, root.querySelector('#new_password'));
  const inputs = root.querySelector('form').children.map((n) => n.getAttribute('id') ?? n.tagName);
  assert.deepEqual(inputs, ['LABEL', 'current_password', 'LABEL', 'new_password', 'err-new_password', 'LABEL', 'repeat_password', 'err-repeat_password', 'password-save'],
    'each error right after its input');
  ({ root } = fresh());
  mount(root, password.render({ busy: false, minLength: 12, errors: { current_password: { key: 'current_password_wrong' } } }, actions));
  assert.equal(text(root.querySelector('#err-current_password')), COPY.current_password_wrong);
  // Busy: Saving… on Save and in the status line, the fields disabled, the buttons unavailable, the focus on Save.
  ({ doc, root } = fresh());
  log.splice(0);
  mount(root, password.render({ busy: true, errors: {}, message: null, minLength: 12 }, actions));
  assert.equal(text(root.querySelector('button[type="submit"]')), COPY.saving);
  assert.equal(text(root.querySelector('p.status[role="status"]')), COPY.saving);
  assert.ok(root.querySelectorAll('input').every((n) => n.disabled === true));
  assert.ok(root.querySelectorAll('button').every((n) => n.getAttribute('aria-disabled') === 'true'));
  assert.equal(doc.activeElement, root.querySelector('button[type="submit"]'));
  root.querySelector('button[type="submit"]').dispatch('click');
  root.querySelectorAll('button').find((b) => text(b) === COPY.cancel).dispatch('click');
  assert.deepEqual(log, [], 'nothing while it runs');
  // A server problem's number, under the message (S3 review).
  ({ root } = fresh());
  mount(root, password.render({ busy: false, errors: {}, minLength: 12, message: { key: 'server_error', params: { incident: 'AB12CD35' } } }, actions));
  assert.equal(text(root.querySelector('p.message[role="alert"]')), COPY.server_error);
  assert.deepEqual(root.querySelectorAll('p.hint').map(text), [t('password_rule', { n: 12 }), 'Problem number: AB12CD35']);
});

test('pin_set shows the digit rule (range and exact) and the success', () => {
  const log = [];
  const actions = { onSubmit: (...a) => log.push(['submit', ...a]), onBack: () => log.push(['back']), onContinue: () => log.push(['continue']) };
  let { doc, root } = fresh();
  mount(root, pinSet.render({ busy: false, errors: {}, message: null, done: false, pinMin: 4, pinMax: 6 }, actions));
  assert.equal(text(root.querySelector('h1')), COPY.pin_set_title);
  assert.equal(text(root.querySelector('p.lead')), COPY.pin_set_intro);
  assert.equal(text(root.querySelector('p.hint')), 'Use 4 to 6 digits.');
  assert.deepEqual(root.querySelectorAll('input').map((i) => [i.getAttribute('id'), i.getAttribute('type'), i.getAttribute('autocomplete'),
    i.getAttribute('inputmode'), i.getAttribute('maxlength')]),
  [['pin_password', 'password', 'current-password', null, null], ['pin_new', 'password', 'off', 'numeric', '6'], ['pin_repeat', 'password', 'off', 'numeric', '6']]);
  assert.deepEqual(['pin_password', 'pin_new', 'pin_repeat'].map((id) => text(root.querySelector(`label[for="${id}"]`))),
    [COPY.pin_set_password_label, COPY.pin_new_label, COPY.pin_repeat_label]);
  assert.equal(doc.activeElement, root.querySelector('#pin_password'));
  assert.equal(text(root.querySelector('button[type="submit"]')), COPY.pin_set_save);
  root.querySelector('#pin_password').value = 'pw';
  root.querySelector('#pin_new').value = '4821';
  root.querySelector('#pin_repeat').value = '4812';
  root.querySelector('form').dispatch('submit');
  root.querySelectorAll('button').find((b) => text(b) === COPY.back).dispatch('click');
  assert.deepEqual(log.splice(0), [['submit', 'pw', '4821', '4812'], ['back']]);
  // The exact count when both ends are the same, and the errors under their fields.
  ({ root } = fresh());
  mount(root, pinSet.render({ busy: false, done: false, pinMin: 5, pinMax: 5, message: { key: 'pin_set_offline' },
    errors: { pin_password: { key: 'pin_set_wrong_password' }, pin_new: { text: 'Use 5 digits.' }, pin_repeat: { key: 'pin_rule_mismatch' } } }, actions));
  assert.equal(text(root.querySelector('p.hint')), 'Use 5 digits.');
  assert.equal(root.querySelector('#pin_new').getAttribute('maxlength'), '5');
  assert.equal(text(root.querySelector('p.message[role="alert"]')), COPY.pin_set_offline);
  assert.equal(text(root.querySelector('#err-pin_password')), COPY.pin_set_wrong_password);
  assert.equal(text(root.querySelector('#err-pin_new')), 'Use 5 digits.');
  assert.equal(text(root.querySelector('#err-pin_repeat')), COPY.pin_rule_mismatch);
  assert.equal(root.querySelector('#pin_repeat').getAttribute('aria-describedby'), 'err-pin_repeat');
  // Busy: Saving… on Save and in the status line, the fields disabled, the buttons unavailable, the focus on Save.
  ({ doc, root } = fresh());
  mount(root, pinSet.render({ busy: true, errors: {}, done: false, pinMin: 4, pinMax: 6 }, actions));
  assert.equal(text(root.querySelector('button[type="submit"]')), COPY.saving);
  assert.equal(text(root.querySelector('p.status[role="status"]')), COPY.saving);
  assert.ok(root.querySelectorAll('input').every((n) => n.disabled === true));
  assert.ok(root.querySelectorAll('button').every((n) => n.getAttribute('aria-disabled') === 'true'));
  assert.equal(doc.activeElement, root.querySelector('button[type="submit"]'));
  // A server problem's number, under the message (S3 review).
  ({ root } = fresh());
  mount(root, pinSet.render({ busy: false, errors: {}, done: false, pinMin: 4, pinMax: 6, message: { key: 'server_error', params: { incident: 'AB12CD36' } } },
    actions));
  assert.equal(text(root.querySelector('p.message[role="alert"]')), COPY.server_error);
  assert.deepEqual(root.querySelectorAll('p.hint').map(text), ['Use 4 to 6 digits.', 'Problem number: AB12CD36']);
  // Done: the success and Continue, no form.
  ({ doc, root } = fresh());
  mount(root, pinSet.render({ busy: false, errors: {}, done: true, pinMin: 4, pinMax: 6 }, actions));
  assert.equal(text(root.querySelector('p.message.message-ok')), COPY.pin_set_ok);
  assert.equal(root.querySelector('form'), null);
  const cont = root.querySelector('button');
  assert.equal(text(cont), COPY.continue);
  assert.equal(doc.activeElement, cont);
  cont.dispatch('click');
  assert.deepEqual(log, [['continue']]);
});

test('home shows the person, the test tablet note and Set a PIN', () => {
  const log = [];
  let { root } = fresh();
  mount(root, home.render(homeModel(), { onSetPin: () => log.push('pin') }));
  assert.equal(root.querySelector('section').getAttribute('class'), 'view view-home');
  assert.equal(text(root.querySelector('h1')), 'Signed in as Jo Doe');
  assert.equal(text(root.querySelector('p.tablet')), 'E2E 1 at Dev Site North');
  assert.equal(text(root.querySelector('p.status')), COPY.home_online);
  assert.equal(root.querySelector('.message-warning'), null);
  assert.equal(text(root.querySelector('p.hint')), COPY.home_set_pin_hint);
  const button = root.querySelector('button');
  assert.equal(text(button), COPY.set_pin_button);
  assert.equal(button.getAttribute('class'), 'button button-primary');
  button.dispatch('click');
  assert.deepEqual(log, ['pin']);
  assert.ok(text(root).includes(COPY.home_empty));
  for (const [releaseUnavailable, key] of [['no_vault_key', 'test_tablet'], ['no_grant_possible', 'grant_unavailable']]) {
    ({ root } = fresh());
    mount(root, home.render(homeModel({ releaseUnavailable, canRecord: false }), {}));
    assert.equal(text(root.querySelector('p.message.message-warning')), COPY[key], releaseUnavailable);
    assert.equal(root.querySelector('button'), null, 'no PIN without a grant');
  }
  // Set a PIN only for someone who can switch, can record here and has no PIN.
  for (const over of [{ hasPin: true }, { canRecord: false }, { pinSwitch: false }]) {
    ({ root } = fresh());
    mount(root, home.render(homeModel(over), {}));
    assert.equal(root.querySelector('button'), null, JSON.stringify(over));
    assert.equal(text(root).includes(COPY.home_set_pin_hint), false);
  }
  ({ root } = fresh());
  mount(root, home.render(homeModel({ connectivity: 'offline', label: null }), {}));
  assert.equal(root.querySelector('p.status'), null, 'no "Working online." offline');
  assert.equal(root.querySelector('p.tablet'), null);
});

test('home.update() changes home in place: the lines keep their nodes, parts come and go at their place, the focus stays', () => {
  const { doc, root } = fresh();
  const log = [];
  const actions = { onSetPin: () => log.push('pin') };
  mount(root, home.render(homeModel(), actions));
  const section = root.querySelector('section');
  const h1 = root.querySelector('h1');
  same(doc.activeElement, h1, 'a fresh home: its title');
  /** The section's parts, in order, with their words. */
  const shape = (s) => s.children.map((n) => [n.getAttribute('data-part'), text(n)]);
  /** What render() makes for the same model: update() must end there. */
  const want = (model) => { fresh(); const out = shape(home.render(model, {})); useDocument(doc); return out; };
  const check = (model, message) => {
    home.update(section, model, actions);
    same(root.querySelector('section'), section, `${message}: the same section`);
    same(root.querySelector('h1'), h1, `${message}: the same title node`);
    assert.deepEqual(shape(section), want(model), message);
  };
  // The focus on the title (where a fresh mount puts it): the connection goes, then comes back; the title stays focused.
  const tablet = root.querySelector('p.tablet');
  check(homeModel({ connectivity: 'offline' }), 'offline');
  same(root.querySelector('p.status'), null, 'Working online. goes');
  same(doc.activeElement, h1, 'the focused title is not focused again (not read out again)');
  check(homeModel(), 'online again');
  assert.equal(root.querySelector('p.status').getAttribute('role'), 'status');
  same(doc.activeElement, h1);
  // A header change: the tablet line takes the new words in its own node; so does the title.
  check(homeModel({ label: 'E2E 2' }), 'a new label');
  same(root.querySelector('p.tablet'), tablet, 'the same tablet line');
  assert.equal(text(tablet), 'E2E 2 at Dev Site North');
  check(homeModel({ label: 'E2E 2', name: 'Jo D.' }), 'a new name');
  assert.equal(text(h1), 'Signed in as Jo D.');
  // The focus on Set a PIN: it stays on that node through every change that keeps the offer.
  const setPin = root.querySelector('#set-pin');
  setPin.focus();
  for (const [over, message] of [[{ connectivity: 'offline' }, 'offline'], [{ connectivity: 'unknown', label: null }, 'no tablet line'], [{}, 'all back']]) {
    check(homeModel(over), message);
    same(root.querySelector('#set-pin'), setPin, `${message}: the same Set a PIN`);
    same(doc.activeElement, setPin, `${message}: Set a PIN keeps the focus`);
  }
  root.querySelector('#set-pin').dispatch('click');
  assert.deepEqual(log, ['pin']);
  // The page has the focus (a tap beside the controls): it stays there.
  doc.activeElement = doc.body;
  check(homeModel({ connectivity: 'offline', releaseUnavailable: 'no_grant_possible' }), 'a warning');
  same(doc.activeElement, doc.body, 'nothing takes the focus');
  const warning = root.querySelector('p.message-warning');
  check(homeModel({ connectivity: 'offline', releaseUnavailable: 'no_vault_key' }), 'another warning');
  same(root.querySelector('p.message-warning'), warning, 'the same warning line, new words');
  assert.equal(text(warning), COPY.test_tablet);
  // The offer goes while Set a PIN has the focus: the focus goes to the title, not to the page.
  check(homeModel(), 'no warning');
  root.querySelector('#set-pin').focus();
  check(homeModel({ hasPin: true }), 'the PIN is set');
  same(root.querySelector('#set-pin'), null);
  same(doc.activeElement, h1, 'the control went: the title');
  // The offer comes back in its place, and Set a PIN works.
  check(homeModel({ connectivity: 'offline' }), 'offered again');
  same(doc.activeElement, h1);
  root.querySelector('#set-pin').dispatch('click');
  assert.deepEqual(log, ['pin', 'pin']);
  // Focus outside the section (the header, the footer) is left alone.
  const outside = el('button', { id: 'outside', type: 'button' }, 'X');
  root.append(outside);
  outside.focus();
  check(homeModel({ hasPin: true, releaseUnavailable: 'no_vault_key', label: null }), 'many changes');
  same(doc.activeElement, outside);
});

test('the header shows the person and Switch user and End shift by controls', () => {
  const log = [];
  const actions = { onSwitchUser: () => log.push('switch'), onEndShift: () => log.push('end') };
  // ACTIVE: the name and both buttons, right after the header and before the banners.
  let nodes = chrome.render(chromeModel({ person: 'Jo Doe', controls: 'both', banners: new Set(['update_ready']) }), actions);
  assert.deepEqual(nodes.map((n) => n.getAttribute('class')), ['topbar', 'session-bar', 'banner banner-info']);
  let { root } = fresh();
  mount(root, nodes);
  const bar = root.querySelector('div.session-bar');
  assert.equal(text(bar.querySelector('span.person')), 'Jo Doe');
  const buttons = bar.querySelectorAll('button');
  assert.deepEqual(buttons.map((b) => [text(b), b.getAttribute('class'), b.getAttribute('type')]),
    [[COPY.switch_user, 'button button-secondary', 'button'], [COPY.end_shift, 'button button-secondary', 'button']]);
  buttons[0].dispatch('click');
  buttons[1].dispatch('click');
  assert.deepEqual(log.splice(0), ['switch', 'end']);
  // PICKER, IDLE, GATE: End shift only, no name.
  nodes = chrome.render(chromeModel({ person: null, controls: 'end' }), actions);
  ({ root } = fresh());
  mount(root, nodes);
  assert.equal(root.querySelector('span.person'), null);
  assert.deepEqual(root.querySelectorAll('div.session-bar button').map(text), [COPY.end_shift]);
  root.querySelector('div.session-bar button').dispatch('click');
  assert.deepEqual(log, ['end']);
  // LOCKED, S2's call without controls, and the wipe screens: no bar.
  for (const model of [chromeModel({ controls: 'none', person: 'Jo Doe' }), chromeModel(), chromeModel({ minimal: true, controls: 'both', person: 'Jo Doe' })]) {
    nodes = chrome.render(model);
    assert.ok(nodes.every((n) => n.getAttribute('class') !== 'session-bar'));
    ({ root } = fresh());
    mount(root, nodes);
    assert.equal(root.querySelector('.session-bar'), null);
    assert.equal(text(root).includes(COPY.end_shift), false);
  }
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

// ---- S3 review: the picker at scale, focus kept across re-renders, in-place updates ----

/** The node, named shortly for a failure message. */
const nodeName = (n) => (n === null || n === undefined ? String(n)
  : `<${String(n.tagName ?? '#text').toLowerCase()}${['id', 'data-key', 'data-user-id'].map((a) => (n.getAttribute?.(a) ? ` ${a}="${n.getAttribute(a)}"` : '')).join('')}> "${String(n.textContent ?? '').slice(0, 40)}"`);
/**
 * actual === expected for fake-DOM nodes (or null). assert.equal would print and diff both nodes' whole object graphs
 * on a failure, which takes minutes; this fails at once with the two nodes named.
 */
function same(actual, expected, message = 'the same node') {
  if (actual !== expected) assert.fail(`${message}: got ${nodeName(actual)}, wanted ${nodeName(expected)}`);
}

test('the picker tells apart people who share a display name with their username on a second line', () => {
  const people = [
    { user_id: 21, display_name: 'Sam Lee', username: 'slee', has_pin: true },
    { user_id: 22, display_name: 'sam  lee ', username: 'slee2', has_pin: false },
    { user_id: 23, display_name: 'Jo Doe', username: 'jdoe', has_pin: false },
  ];
  const { root } = fresh();
  const log = [];
  mount(root, login.render(loginModel({ mode: 'picker', people }), loginActions(log)));
  const tiles = root.querySelectorAll('ul.tiles button');
  assert.equal(text(tiles[0]), COPY.someone_else, 'Someone else first');
  const twin = (id) => root.querySelector(`[data-user-id="${id}"]`);
  for (const [id, name, username] of [[21, 'Sam Lee', 'slee'], [22, 'sam  lee ', 'slee2']]) {
    const b = twin(id);
    assert.equal(text(b.querySelector('span.tile-name')), name);
    assert.equal(text(b.querySelector('span.tile-detail')), username, 'the username is the second line');
    assert.equal(text(b), `${name} ${username}`, 'and part of the accessible name (the button\'s text)');
    assert.equal(b.getAttribute('aria-label'), null, 'no label hides the text');
  }
  assert.equal(text(twin(23)), 'Jo Doe', 'a name nobody shares stays one line');
  same(twin(23).querySelector('span'), null);
  twin(22).dispatch('click');
  assert.deepEqual(log, [['onPick', 22]]);
  // A shared name without a username (never sent by session.js, but drawn safely): the name alone.
  const r = fresh();
  mount(r.root, login.render(loginModel({ mode: 'picker', people: [{ user_id: 1, display_name: 'A B' }, { user_id: 2, display_name: 'A B' }] }), {}));
  assert.deepEqual(r.root.querySelectorAll('ul.tiles button').map(text), [COPY.someone_else, 'A B', 'A B']);
});

test('dom.remount() and keepFocus() give the focus back to the same control; mount() skips a disabled autofocus', () => {
  const { doc, root } = fresh();
  const screen = (over = {}) => el('section', {},
    el('h1', {}, 'Title'),
    el('button', { id: 'a', type: 'button', 'data-autofocus': over.autofocusA === true ? true : null }, 'A'),
    over.withKey === false ? null : el('button', { type: 'button', 'data-key': '7' }, '7'),
    el('button', { type: 'button', 'data-user-id': '12' }, 'Jo'),
    el('input', { id: 'f', disabled: over.disabled === true }));
  mount(root, screen());
  root.querySelector('[data-key="7"]').focus();
  remount(root, screen());
  same(doc.activeElement, root.querySelector('[data-key="7"]'), 'the same data-key');
  root.querySelector('[data-user-id="12"]').focus();
  remount(root, screen());
  same(doc.activeElement, root.querySelector('[data-user-id="12"]'), 'the same data-user-id');
  root.querySelector('#a').focus();
  remount(root, screen());
  same(doc.activeElement, root.querySelector('#a'), 'the same id');
  // Gone, or not focusable any more: as mount().
  root.querySelector('[data-key="7"]').focus();
  remount(root, screen({ withKey: false, autofocusA: true }));
  same(doc.activeElement, root.querySelector('#a'), 'gone: the autofocus');
  root.querySelector('#f').focus();
  remount(root, screen({ disabled: true }));
  same(doc.activeElement, root.querySelector('h1'), 'disabled now: the h1');
  // mount() is always a fresh screen: the focus moves to its autofocus whatever had it.
  root.querySelector('[data-key="7"]').focus();
  mount(root, screen({ autofocusA: true }));
  same(doc.activeElement, root.querySelector('#a'), 'mount() is a fresh screen: the autofocus');
  // mount() passes over a disabled [data-autofocus] to the h1 (a form disabled while the tablet is checked).
  mount(root, el('section', {}, el('h1', {}, 'T'), el('input', { id: 'x', disabled: true, 'data-autofocus': true })));
  same(doc.activeElement, root.querySelector('h1'));
  // keepFocus(): a part re-rendered in place (app.js's header).
  const host = el('div', {});
  root.replaceChildren(host, el('p', {}, 'body'), el('button', { id: 'outside', type: 'button' }, 'X'));
  const bar = (withEnd = true) => [el('button', { id: 'switch-user', type: 'button' }, 'S'), withEnd ? el('button', { id: 'end-shift', type: 'button' }, 'E') : null];
  host.replaceChildren(...bar().filter(Boolean));
  host.querySelector('#end-shift').focus();
  keepFocus(host, () => host.replaceChildren(...bar().filter(Boolean)));
  same(doc.activeElement, host.querySelector('#end-shift'), 'the new End shift has the focus');
  keepFocus(host, () => host.replaceChildren(...bar(false).filter(Boolean)));
  same(doc.activeElement, doc.body, 'gone: nothing is invented');
  root.querySelector('#outside').focus();
  keepFocus(host, () => host.replaceChildren(...bar().filter(Boolean)));
  same(doc.activeElement, root.querySelector('#outside'), 'focus elsewhere is left alone');
  // setUnavailable(): aria-disabled, still focusable.
  const b = el('button', { type: 'button' }, 'B');
  root.replaceChildren(b);
  setUnavailable(b, true);
  same(unavailable(b), true);
  b.focus();
  same(doc.activeElement, b);
  setUnavailable(b, false);
  assert.equal(b.getAttribute('aria-disabled'), null);
  same(unavailable(b), false);
  same(unavailable(null), false);
});

test('login.update() changes the PIN pad and the form in place: the same nodes, the same live regions', () => {
  const { doc, root } = fresh();
  const log = [];
  const model = loginModel({ mode: 'pin', pin: { name: 'Jo Doe', entered: 0 }, pinMin: 4, pinMax: 6, message: { key: 'pin_wrong_many', params: { n: 2 } } });
  mount(root, login.render(model, loginActions(log)));
  const section = root.querySelector('section');
  const nodes = ['p.pin-dots', 'p.pin-count', 'p.status', 'p.message-error', '[data-key="ok"]'].map((sel) => root.querySelector(sel));
  root.querySelector('[data-key="4"]').focus();
  login.update(section, { ...model, pin: { name: 'Jo Doe', entered: 4 } });
  ['p.pin-dots', 'p.pin-count', 'p.status', 'p.message-error', '[data-key="ok"]'].forEach((sel, i) => same(root.querySelector(sel), nodes[i], `${sel} is not re-created`));
  assert.equal(text(nodes[0]), '●●●●○○');
  assert.equal(text(nodes[1]), '4 digits entered');
  assert.equal(nodes[4].getAttribute('aria-disabled'), null, 'OK from pin_min_digits');
  same(doc.activeElement, root.querySelector('[data-key="4"]'));
  // Busy, without the message: the message goes, Signing in…, every button unavailable, the focus where it was.
  login.update(section, { ...model, busy: true, message: null, pin: { name: 'Jo Doe', entered: 4 } });
  same(root.querySelector('p.message-error'), null);
  assert.equal(text(nodes[2]), COPY.signing_in);
  assert.ok(root.querySelectorAll('button').every((b) => b.getAttribute('aria-disabled') === 'true'));
  same(doc.activeElement, root.querySelector('[data-key="4"]'));
  section.dispatch('keydown', { key: '5' });
  assert.deepEqual(log, [], 'the keyboard waits too');
  // The form: a sign-in starting moves the focus from a field (now disabled) to Sign in.
  const form = fresh();
  const formModel = loginModel({ identifier: 'jdoe', message: { key: 'server_error', params: { incident: 'X1' } } });
  mount(form.root, login.render(formModel, {}));
  const status = form.root.querySelector('p.status');
  form.root.querySelector('#password').focus();
  login.update(form.root.querySelector('section'), { ...formModel, busy: true, message: null });
  same(form.doc.activeElement, form.root.querySelector('button[type="submit"]'));
  same(form.root.querySelector('p.status'), status);
  assert.equal(text(status), COPY.signing_in);
  same(form.root.querySelector('p.message'), null);
  same(form.root.querySelector('p.hint'), null, 'the problem number goes with its message');
  assert.equal(form.root.querySelector('#identifier').value, 'jdoe');
});

test('ack places an answer\'s message and its problem number just above the buttons, after the agreement', () => {
  const { root } = fresh();
  mount(root, ack.render({ phase: 'ready', doc: DOC, message: { key: 'server_error', params: { incident: 'AB12CD34' } } }, {}));
  const order = root.querySelector('section').children.map((n) => n.getAttribute('id') ?? n.getAttribute('class'));
  assert.deepEqual(order, [null, 'lead', 'hint', 'policy-text', 'message message-error', 'hint', 'ack-status', 'actions']);
  assert.equal(text(root.querySelector('p.message-error[role="alert"]')), COPY.server_error);
  assert.equal(text(root.querySelectorAll('p.hint')[1]), 'Problem number: AB12CD34');
  // ack.update() back to the answer starting: the message goes, the version line stays.
  ack.update(root.querySelector('section'), { phase: 'sending', doc: DOC, message: null });
  same(root.querySelector('p.message'), null);
  assert.deepEqual(root.querySelectorAll('p.hint').map(text), ['Version 2']);
  // A failed load: its message and number, and Try again.
  const r = fresh();
  mount(r.root, ack.render({ phase: 'error', doc: null, message: { key: 'server_error', params: { incident: 'Z9' } } }, {}));
  assert.equal(text(r.root.querySelector('p.hint')), 'Problem number: Z9');
  ack.update(r.root.querySelector('section'), { phase: 'error', doc: null, message: null });
  assert.equal(text(r.root.querySelector('p.message-error')), COPY.server_error, 'update() leaves the error screen alone');
});

test('the PIN-set PIN fields stay password fields (masked, not echoed by a screen reader)', () => {
  // security-1: no attribute stops a browser taking this form for a password change; the managed-tablet rule does.
  // The fields stay type=password so the PIN is never shown or read out, and the pair never asks for a saved password.
  const { root } = fresh();
  mount(root, pinSet.render({ busy: false, errors: {}, done: false, pinMin: 4, pinMax: 6 }, {}));
  for (const id of ['pin_new', 'pin_repeat']) {
    const input = root.querySelector('#' + id);
    assert.equal(input.getAttribute('type'), 'password', id);
    assert.equal(input.getAttribute('autocomplete'), 'off', `${id}: never new-password`);
    assert.equal(input.getAttribute('inputmode'), 'numeric', id);
  }
  assert.equal(root.querySelector('#pin_password').getAttribute('autocomplete'), 'current-password');
  assert.equal(root.querySelectorAll('[autocomplete="new-password"]').length, 0);
});
