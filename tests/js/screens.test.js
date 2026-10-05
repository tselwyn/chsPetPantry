// public/station/js/views/screens.js (S3 spec §3.3): the controllers of the lock screen (form, picker, PIN pad), the
// agreement, the forced password change, setting a PIN and home, over fake-session.js and fake-dom.js. Each test
// mounts the views into a fake document the way app.js's mountFor() does, and presses what a person would press.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeDocument, text } from './support/fake-dom.js';
import { fakeSession } from './support/fake-session.js';
import { flush } from './support/fake-env.js';
import { COPY } from '../../public/station/js/copy.js';
import { mount as domMount, remount as domRemount, useDocument } from '../../public/station/js/dom.js';
import {
  createAckScreen, createHomeScreen, createLoginScreen, createPasswordScreen, createPinSetScreen, pinRange,
} from '../../public/station/js/views/screens.js';

const PEOPLE = [{ user_id: 12, display_name: 'Jo Doe', username: 'jdoe', has_pin: true }, { user_id: 13, display_name: 'Al Bee', username: 'abee', has_pin: false }];
const DOC1 = { document_id: 5, version: '2', language: 'en', fingerprint: 'a'.repeat(64), body: 'The first wording.\n\nIts second part.' };
const DOC2 = { document_id: 6, version: '3', language: 'en', fingerprint: 'b'.repeat(64), body: 'The new wording.' };

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}

/**
 * A fake document and a session; mount() draws a section into #app as app.js's mountFor() does (afresh, or with
 * keepFocus through dom.js remount()), and counts it.
 */
function harness(over = {}) {
  const doc = fakeDocument();
  useDocument(doc);
  const root = doc.getElementById('app');
  const session = fakeSession(over);
  const mounts = [];
  const tablet = { orgName: 'CHS Pet Pantry', site: 'Dev Site North', label: 'E2E 1', build: '0.1.0-dev+abc' };
  const h = {
    doc, root, session, mounts, tablet, checking: false,
    mount: (section, options) => { mounts.push(section); (options?.keepFocus === true ? domRemount : domMount)(root, section); },
    info: () => ({ ...tablet }),
    q: (sel) => root.querySelector(sel),
    qa: (sel) => root.querySelectorAll(sel),
    button: (label) => root.querySelectorAll('button').find((b) => text(b) === label) ?? null,
  };
  return h;
}
const loginOf = (h) => createLoginScreen({ session: h.session, info: h.info, mount: h.mount, checking: () => h.checking });
const type = (h, values) => { for (const [id, v] of Object.entries(values)) h.q('#' + id).value = v; };
const submit = (h) => h.q('button[type="submit"]').dispatch('click');
const tile = (h, name) => h.qa('ul.tiles button').find((b) => text(b) === name);
const press = (h, ...keys) => { for (const k of keys) h.q(`[data-key="${k}"]`).dispatch('click'); };
const dots = (h) => text(h.q('p.pin-dots'));

test('the login screen shows the form when LOCKED and the picker when PICKER or IDLE, with the notice once', () => {
  const h = harness({ state: 'LOCKED', notice: { key: 'shift_ended' }, people: PEOPLE });
  const s = loginOf(h);
  s.show();
  assert.equal(h.q('section').getAttribute('class'), 'view view-login');
  assert.ok(h.q('form'));
  assert.equal(h.q('ul.tiles'), null, 'no picker without keys');
  assert.equal(text(h.q('h1')), 'CHS Pet Pantry');
  assert.equal(text(h.q('p.tablet')), 'E2E 1 at Dev Site North');
  assert.equal(text(h.q('p.notice')), COPY.shift_ended);
  assert.equal(s.model().mode, 'form');
  s.show();
  assert.equal(h.q('p.notice'), null, 'the notice shows once');
  for (const state of ['PICKER', 'IDLE']) {
    h.session.set('state', state);
    h.session.set('notice', { key: 'notice_idle' });
    s.show();
    assert.equal(h.q('section').getAttribute('class'), 'view view-login view-picker', state);
    assert.equal(text(h.q('h1')), COPY.picker_title);
    assert.deepEqual(h.qa('ul.tiles button').map(text), [COPY.someone_else, 'Jo Doe', 'Al Bee']);
    assert.equal(h.q('form'), null);
    assert.equal(text(h.q('p.notice')), COPY.notice_idle);
    s.show();
    assert.equal(h.q('p.notice'), null, `${state}: once`);
  }
  assert.equal(h.mounts.length, 6, 'one draw per show');
});

test('a sign-in in flight disables the form; a failure shows its message and keeps the identifier', async () => {
  const h = harness();
  const s = loginOf(h);
  s.show();
  const answer = deferred();
  let seen = null;
  h.session.queue.signIn.push((identifier, password) => { seen = [identifier, password]; return answer.promise; });
  type(h, { identifier: 'JDoe ', password: 'Correct-Horse-Battery-9' });
  submit(h);
  assert.deepEqual(h.session.calls, [['signIn', 'JDoe ']], 'the identifier as typed; the password is never recorded');
  assert.deepEqual(seen, ['JDoe ', 'Correct-Horse-Battery-9'], 'the session gets both');
  assert.equal(s.model().busy, true);
  assert.equal(text(h.q('button[type="submit"]')), COPY.signing_in);
  for (const sel of ['#identifier', '#password']) assert.equal(h.q(sel).disabled, true, sel);
  assert.equal(h.q('button[type="submit"]').getAttribute('aria-disabled'), 'true', 'Sign in is unavailable but keeps the focus');
  assert.equal(h.q('#identifier').value, 'JDoe ', 'the identifier stays in the field');
  assert.equal(h.q('#password').value, '');
  h.q('form').dispatch('submit');
  assert.equal(h.session.calls.length, 1, 'one sign-in at a time');
  answer.resolve({ ok: false, message: { key: 'login_failed' } });
  await flush();
  assert.equal(s.model().busy, false);
  assert.equal(text(h.q('p.message.message-error[role="alert"]')), COPY.login_failed);
  assert.equal(h.q('#identifier').value, 'JDoe ', 'kept');
  assert.equal(h.q('#identifier').disabled, false);
  assert.equal(h.q('#password').value, '');
  assert.equal(h.doc.activeElement, h.q('#password'), 'the password has the focus');
  // The next attempt clears the message while it runs; a success leaves the routing to app.js.
  const second = deferred();
  h.session.queue.signIn.push(second.promise);
  type(h, { password: 'again' });
  submit(h);
  assert.equal(h.q('p.message'), null, 'the old message goes while the new attempt runs');
  second.resolve({ ok: true });
  await flush();
  assert.equal(h.q('p.message'), null);
  assert.equal(s.model().busy, false);
  // A server sentence and a problem number.
  h.session.queue.signIn.push({ ok: false, message: { key: 'server_error', params: { incident: 'X7K2' } } });
  type(h, { password: 'x' });
  submit(h);
  await flush();
  assert.equal(text(h.q('p.message-error')), COPY.server_error);
  assert.equal(text(h.q('p.hint')), 'Problem number: X7K2');
});

test('a tile with a PIN opens the PIN pad; one without opens the form with the username', () => {
  const h = harness({ state: 'PICKER', people: PEOPLE });
  const s = loginOf(h);
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  assert.equal(s.model().mode, 'pin');
  assert.equal(h.q('section').getAttribute('class'), 'view view-login view-pin');
  assert.equal(text(h.q('h1')), 'Jo Doe');
  assert.equal(text(h.q('p.lead')), COPY.pin_title);
  assert.equal(dots(h), '○○○○○○', 'six empty dots');
  h.button(COPY.pin_cancel).dispatch('click');
  assert.equal(s.model().mode, 'picker');
  tile(h, 'Al Bee').dispatch('click');
  assert.equal(s.model().mode, 'form');
  assert.equal(h.q('#identifier').value, 'abee');
  assert.equal(h.doc.activeElement, h.q('#password'), 'the password field has the focus');
  assert.ok(h.button(COPY.back), 'Back to the picker');
  h.button(COPY.back).dispatch('click');
  assert.equal(s.model().mode, 'picker');
  h.button(COPY.someone_else).dispatch('click');
  assert.equal(s.model().mode, 'form');
  assert.equal(h.q('#identifier').value, '');
  assert.equal(h.doc.activeElement, h.q('#identifier'));
  assert.ok(h.button(COPY.back));
  assert.deepEqual(h.session.calls, [], 'picking calls nothing');
});

test('the PIN pad submits by itself at pin_max_digits and from pin_min_digits on OK', async () => {
  const h = harness({ state: 'PICKER', people: PEOPLE });
  const s = loginOf(h);
  const sent = [];
  const switchTo = (r) => (userId, pin) => { sent.push([userId, pin]); return r; };
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  press(h, '1', '2', '3');
  assert.equal(dots(h), '●●●○○○');
  assert.equal(h.q('[data-key="ok"]').getAttribute('aria-disabled'), 'true', 'OK waits for pin_min_digits');
  press(h, '4');
  assert.equal(h.q('[data-key="ok"]').getAttribute('aria-disabled'), null);
  assert.deepEqual(h.session.calls, [], 'four digits do not send by themselves');
  h.session.queue.pinSwitch.push(switchTo({ ok: true }));
  press(h, 'ok');
  await flush();
  assert.deepEqual(h.session.calls, [['pinSwitch', 12]]);
  assert.deepEqual(sent, [[12, '1234']]);
  // Six digits send by themselves; a seventh is never added.
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  const held = deferred();
  h.session.queue.pinSwitch.push(switchTo(held.promise));
  press(h, '9', '8', '7', '6', '5');
  assert.equal(sent.length, 1);
  h.q('section').dispatch('keydown', { key: '4' }); // the keyboard too
  assert.deepEqual(sent, [[12, '1234'], [12, '987654']]);
  assert.equal(dots(h), '●●●●●●');
  assert.ok(h.qa('div.pinpad button').every((b) => b.getAttribute('aria-disabled') === 'true'), 'the keys wait for the answer');
  h.q('section').dispatch('keydown', { key: '3' });
  assert.equal(sent.length, 2, 'nothing more while it runs');
  held.resolve({ ok: true });
  await flush();
  assert.equal(sent.length, 2);
  assert.equal(s.model().digits, '');
});

test('a failed PIN clears the digits; next password opens the form prefilled', async () => {
  const h = harness({ state: 'IDLE', people: PEOPLE });
  const s = loginOf(h);
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  h.session.queue.pinSwitch.push({ ok: false, message: { key: 'pin_wrong_many', params: { n: 2 } } });
  press(h, '1', '1', '1', '1', 'ok');
  await flush();
  assert.equal(s.model().mode, 'pin', 'a wrong PIN stays on the pad');
  assert.equal(s.model().digits, '');
  assert.equal(dots(h), '○○○○○○', 'the digits are cleared');
  assert.equal(text(h.q('p.message-error')), 'Wrong PIN. 2 tries left before a password is needed.');
  press(h, '4');
  assert.equal(text(h.q('p.message-error')), 'Wrong PIN. 2 tries left before a password is needed.', 'the tries left stay while typing');
  h.session.queue.pinSwitch.push({ ok: false, message: { key: 'pin_locked' }, next: 'password', identifier: 'jdoe' });
  press(h, '8', '2', '0', 'ok');
  await flush();
  assert.equal(s.model().mode, 'form');
  assert.equal(h.q('#identifier').value, 'jdoe');
  assert.equal(h.q('#password').value, '');
  assert.equal(h.doc.activeElement, h.q('#password'));
  assert.equal(text(h.q('p.message-error')), COPY.pin_locked);
  // Without an identifier in the answer: the tile's username.
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  h.session.queue.pinSwitch.push({ ok: false, message: { key: 'pin_no_answer' }, next: 'password', identifier: '' });
  press(h, '1', '2', '4', '8', 'ok');
  await flush();
  assert.equal(h.q('#identifier').value, 'jdoe');
  assert.equal(text(h.q('p.message-error')), COPY.pin_no_answer);
});

test('Use my password and a failed PIN that needs the password show Back, which returns to the picker', async () => {
  const h = harness({ state: 'PICKER', people: PEOPLE });
  const s = loginOf(h);
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  h.button(COPY.use_password).dispatch('click');
  assert.equal(s.model().mode, 'form');
  assert.equal(s.model().canGoBack, true);
  assert.equal(h.q('#identifier').value, 'jdoe');
  h.button(COPY.back).dispatch('click');
  assert.equal(s.model().mode, 'picker');
  assert.deepEqual(h.qa('ul.tiles button').map(text), [COPY.someone_else, 'Jo Doe', 'Al Bee']);
  // A refused PIN that needs the password, keys still in memory: Back.
  tile(h, 'Jo Doe').dispatch('click');
  h.session.queue.pinSwitch.push({ ok: false, message: { key: 'pin_unavailable' }, next: 'password', identifier: 'jdoe' });
  press(h, '4', '8', '2', '1', 'ok');
  await flush();
  assert.equal(s.model().mode, 'form');
  assert.ok(h.button(COPY.back));
  h.button(COPY.back).dispatch('click');
  assert.equal(s.model().mode, 'picker');
  assert.equal(h.q('p.message'), null, 'Back clears the message');
  // The same answer after the tablet locked meanwhile (no keys): no picker to go back to.
  tile(h, 'Jo Doe').dispatch('click');
  h.session.queue.pinSwitch.push(() => { h.session.set('state', 'LOCKED'); return { ok: false, message: { key: 'pin_locked' }, next: 'password', identifier: 'jdoe' }; });
  press(h, '4', '8', '2', '1', 'ok');
  await flush();
  assert.equal(s.model().mode, 'form');
  assert.equal(s.model().canGoBack, false);
  assert.equal(h.button(COPY.back), null);
});

test('the PIN pad\'s short-entry message carries the digit range, or the exact count when min equals max', () => {
  for (const [config, want, message] of [
    [{ pin_min_digits: 4, pin_max_digits: 6 }, 'Use 4 to 6 digits.', { key: 'pin_rule_digits', params: { min: 4, max: 6 } }],
    [{ pin_min_digits: 5, pin_max_digits: 5 }, 'Use 5 digits.', { key: 'pin_rule_digits_exact', params: { n: 5 } }],
    [{ pin_min_digits: 2, pin_max_digits: 9 }, 'Use 4 to 6 digits.', { key: 'pin_rule_digits', params: { min: 4, max: 6 } }],
  ]) {
    const h = harness({ state: 'PICKER', people: PEOPLE, config });
    const s = loginOf(h);
    s.show();
    tile(h, 'Jo Doe').dispatch('click');
    press(h, '1', '2');
    h.q('section').dispatch('keydown', { key: 'Enter' });
    assert.deepEqual(s.model().message, message, JSON.stringify(config));
    assert.equal(text(h.q('p.message-error')), want);
    assert.deepEqual(h.session.calls, [], 'nothing is sent');
    assert.equal(dots(h).length, message.params.max ?? message.params.n);
  }
  assert.deepEqual(pinRange({ pin_min_digits: 6, pin_max_digits: 4 }), { min: 6, max: 6 }, 'as the server: max is at least min');
  assert.deepEqual(pinRange({ pin_min_digits: '5' }), { min: 4, max: 6 }, 'not an integer: the default');
  assert.deepEqual(pinRange(undefined), { min: 4, max: 6 });
});

test('refresh() keeps the PIN digits, the notice and the typed identifier, and clears a half-typed password', () => {
  const h = harness({ state: 'IDLE', notice: { key: 'notice_idle' }, people: PEOPLE });
  const s = loginOf(h);
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  press(h, '4', '8');
  h.tablet.label = 'E2E 2';
  h.session.set('people', [PEOPLE[0]]);
  s.refresh();
  assert.equal(s.model().mode, 'pin');
  assert.equal(dots(h), '●●○○○○', 'the digits stay');
  assert.equal(text(h.q('p.notice')), COPY.notice_idle, 'the notice stays');
  assert.equal(text(h.q('p.tablet')), 'E2E 2 at Dev Site North', 'the header facts are read again');
  h.button(COPY.pin_cancel).dispatch('click');
  assert.deepEqual(h.qa('ul.tiles button').map(text), [COPY.someone_else, 'Jo Doe'], 'the people are read again');
  // The form: the typed identifier is carried over; a half-typed password is not.
  h.button(COPY.someone_else).dispatch('click');
  type(h, { identifier: 'jd', password: 'half' });
  h.tablet.orgName = 'CHS';
  s.refresh();
  assert.equal(s.model().mode, 'form');
  assert.equal(h.q('#identifier').value, 'jd');
  assert.equal(h.q('#password').value, '');
  assert.equal(text(h.q('h1')), 'CHS');
  assert.equal(text(h.q('p.notice')), COPY.notice_idle);
  assert.ok(h.button(COPY.back), 'canGoBack stays');
  assert.equal(JSON.stringify(s.model()).includes('half'), false, 'the password is never kept');
  // A message stays too.
  h.session.queue.signIn.push({ ok: false, message: { key: 'login_failed' } });
  return (async () => {
    type(h, { password: 'pw' });
    submit(h);
    await flush();
    s.refresh();
    assert.equal(text(h.q('p.message-error')), COPY.login_failed);
    assert.equal(h.q('#identifier').value, 'jd');
  })();
});

test('the form stays disabled while checking() is true', () => {
  const h = harness();
  h.checking = true;
  const s = loginOf(h);
  s.show();
  for (const sel of ['#identifier', '#password', 'button[type="submit"]']) assert.equal(h.q(sel).disabled, true, sel);
  assert.equal(text(h.q('p.status')), COPY.checking);
  type(h, { identifier: 'jdoe', password: 'pw' });
  h.q('form').dispatch('submit');
  submit(h);
  assert.deepEqual(h.session.calls, []);
  // The check ends: app.js calls refresh().
  h.checking = false;
  s.refresh();
  assert.equal(h.q('#identifier').disabled, false);
  assert.equal(h.q('#identifier').value, 'jdoe', 'what was typed stays');
  assert.equal(text(h.q('p.status')), '');
  // A form drawn before a new check started is refused by the controller as well.
  h.checking = true;
  type(h, { password: 'pw' });
  submit(h);
  assert.deepEqual(h.session.calls, [], 'the controller asks checking() itself');
  h.checking = false;
  submit(h);
  assert.deepEqual(h.session.calls, [['signIn', 'jdoe']]);
});

test('the ack screen loads, retries after an error, and shows policy_changed with the reloaded text', async () => {
  const h = harness({ state: 'GATE', gate: 'policy_ack' });
  const s = createAckScreen({ session: h.session, mount: h.mount });
  const first = deferred();
  h.session.queue.loadPolicy.push(first.promise, { ok: true, required: true, document: DOC1 });
  const shown = s.show();
  assert.equal(text(h.q('p.status')), COPY.ack_loading);
  assert.equal(h.q('button'), null);
  first.resolve({ ok: false, message: { key: 'ack_failed' } });
  await shown;
  assert.equal(s.model().phase, 'error');
  assert.equal(text(h.q('p.message-error')), COPY.ack_failed);
  h.button(COPY.try_again).dispatch('click');
  await flush();
  assert.equal(s.model().phase, 'ready');
  assert.deepEqual(h.qa('div.policy-text p').map(text), ['The first wording.', 'Its second part.']);
  assert.equal(text(h.q('p.hint')), 'Version 2');
  // The wording changed while it was read: the new text, with the message; nothing accepted.
  const changed = deferred();
  h.session.queue.acceptPolicy.push(changed.promise);
  h.session.queue.loadPolicy.push({ ok: true, required: true, document: DOC2 });
  h.button(COPY.ack_accept).dispatch('click');
  assert.equal(s.model().phase, 'sending');
  assert.equal(h.button(COPY.sending).getAttribute('aria-disabled'), 'true');
  assert.equal(h.button(COPY.ack_decline).getAttribute('aria-disabled'), 'true');
  h.q('div.actions button').dispatch('click');
  changed.resolve({ ok: false, message: { key: 'policy_changed' }, changed: true });
  await flush();
  assert.equal(s.model().phase, 'ready');
  assert.equal(text(h.q('p.message-error[role="alert"]')), COPY.policy_changed);
  assert.deepEqual(h.qa('div.policy-text p').map(text), ['The new wording.']);
  assert.equal(text(h.q('p.hint')), 'Version 3');
  assert.deepEqual(h.session.calls, [['loadPolicy'], ['loadPolicy'], ['acceptPolicy', 5], ['loadPolicy']]);
  // Accepting the new text; another failure shows its message on the same text.
  h.session.queue.acceptPolicy.push({ ok: false, message: { key: 'ack_failed' } });
  h.button(COPY.ack_accept).dispatch('click');
  await flush();
  assert.deepEqual(h.session.calls.at(-1), ['acceptPolicy', 6]);
  assert.equal(text(h.q('p.message-error')), COPY.ack_failed);
  assert.equal(s.model().phase, 'ready');
  // A reload that fails after policy_changed: the error and Try again.
  h.session.queue.acceptPolicy.push({ ok: false, message: { key: 'policy_changed' }, changed: true });
  h.session.queue.loadPolicy.push({ ok: false });
  h.button(COPY.ack_accept).dispatch('click');
  await flush();
  assert.equal(s.model().phase, 'error');
  assert.ok(h.button(COPY.try_again));
  // Decline: once, whatever is pressed meanwhile.
  h.session.queue.loadPolicy.push({ ok: true, required: true, document: DOC2 });
  await s.show();
  const declined = deferred();
  h.session.queue.declinePolicy.push(declined.promise);
  h.button(COPY.ack_decline).dispatch('click');
  h.q('#ack-decline').dispatch('click');
  h.q('#ack-accept').dispatch('click');
  declined.resolve({ ok: true });
  await flush();
  assert.deepEqual(h.session.calls.slice(-2), [['loadPolicy'], ['declinePolicy', 6]]);
  // A session that throws (it never should): the error, not a hang.
  h.session.loadPolicy = async () => { throw new Error('boom'); };
  await s.show();
  assert.equal(s.model().phase, 'error');
  assert.equal(text(h.q('p.message-error')), COPY.ack_failed);
});

test('the password screen refuses a mismatch without calling the session and shows each error under its own input', async () => {
  const h = harness({ state: 'GATE', gate: 'password_change', config: { password_min_length: 14 } });
  const s = createPasswordScreen({ session: h.session, mount: h.mount });
  s.show();
  assert.equal(text(h.q('p.hint')), 'Use at least 14 characters. A short sentence you can remember works well.');
  type(h, { current_password: 'Temp-Password-1', new_password: 'A new long sentence', repeat_password: 'A new long sentense' });
  submit(h);
  assert.deepEqual(h.session.calls, [], 'a mismatch never reaches the session');
  assert.deepEqual(s.model().errors, { repeat_password: { key: 'password_mismatch' } });
  assert.equal(text(h.q('#err-repeat_password')), COPY.password_mismatch);
  assert.equal(h.q('#repeat_password').getAttribute('aria-describedby'), 'err-repeat_password');
  assert.equal(h.q('#current_password').value, 'Temp-Password-1', 'what was right stays');
  assert.equal(h.q('#new_password').value, 'A new long sentence');
  assert.equal(h.q('#repeat_password').value, '', 'the repeat is typed again');
  assert.equal(h.doc.activeElement, h.q('#repeat_password'));
  // Fixed: the session gets both, and a wrong current password lands under its field.
  const sent = [];
  const held = deferred();
  h.session.queue.changePassword.push((current, next) => { sent.push([current, next]); return held.promise; });
  type(h, { repeat_password: 'A new long sentence' });
  submit(h);
  assert.deepEqual(sent, [['Temp-Password-1', 'A new long sentence']]);
  assert.deepEqual(h.session.calls, [['changePassword']], 'no password in the record');
  assert.equal(text(h.q('button[type="submit"]')), COPY.saving);
  assert.ok(h.qa('input').every((i) => i.disabled === true));
  assert.equal(h.q('#new_password').value, 'A new long sentence', 'the fields keep their values while it runs');
  assert.equal(h.q('#err-repeat_password'), null, 'the old error goes');
  submit(h);
  assert.equal(sent.length, 1, 'one change at a time');
  held.resolve({ ok: false, errors: { current_password: { key: 'current_password_wrong' } } });
  await flush();
  assert.equal(text(h.q('#err-current_password')), COPY.current_password_wrong);
  assert.equal(h.q('#current_password').value, '');
  assert.equal(h.q('#new_password').value, 'A new long sentence');
  assert.equal(h.q('#repeat_password').value, 'A new long sentence');
  assert.equal(h.doc.activeElement, h.q('#current_password'));
  // The server's rule on the new password: under it, and both new fields typed again.
  h.session.queue.changePassword.push({ ok: false, errors: { new_password: { text: 'Choose a password different from your current one.' } } });
  type(h, { current_password: 'Temp-Password-1' });
  submit(h);
  await flush();
  assert.equal(text(h.q('#err-new_password')), 'Choose a password different from your current one.');
  assert.equal(h.q('#err-current_password'), null);
  assert.deepEqual(['current_password', 'new_password', 'repeat_password'].map((id) => h.q('#' + id).value), ['Temp-Password-1', '', '']);
  // A message for the whole form keeps every field.
  h.session.queue.changePassword.push({ ok: false, message: { key: 'password_offline' } });
  type(h, { new_password: 'Another long sentence', repeat_password: 'Another long sentence' });
  submit(h);
  await flush();
  assert.equal(text(h.q('p.message[role="alert"]')), COPY.password_offline);
  assert.equal(h.qa('p.message-error[id]').length, 0);
  assert.deepEqual(['current_password', 'new_password', 'repeat_password'].map((id) => h.q('#' + id).value),
    ['Temp-Password-1', 'Another long sentence', 'Another long sentence']);
  h.button(COPY.cancel).dispatch('click');
  assert.deepEqual(h.session.calls.at(-1), ['cancelGate']);
});

test('the pin_set screen shows each error under its own input, the success, and Continue calls onDone', async () => {
  const h = harness({ state: 'ACTIVE', config: { pin_min_digits: 4, pin_max_digits: 6 } });
  let done = 0;
  const s = createPinSetScreen({ session: h.session, mount: h.mount, onDone: () => { done += 1; } });
  s.show();
  assert.equal(text(h.q('p.hint')), 'Use 4 to 6 digits.');
  const sent = [];
  const fill = () => type(h, { pin_password: 'Correct-Horse-Battery-9', pin_new: '4821', pin_repeat: '4821' });
  h.session.queue.setPin.push((password, pin, confirm) => { sent.push([password, pin, confirm]); return { ok: false, errors: { pin_password: { key: 'pin_set_wrong_password' } } }; },
    { ok: false, errors: { pin_new: { key: 'pin_rule_guessable' } } },
    { ok: false, errors: { pin_repeat: { key: 'pin_rule_mismatch' } } },
    { ok: false, message: { key: 'pin_set_offline' } });
  fill();
  submit(h);
  await flush();
  assert.deepEqual(sent, [['Correct-Horse-Battery-9', '4821', '4821']]);
  assert.deepEqual(h.session.calls, [['setPin']], 'no password or PIN in the record');
  assert.equal(text(h.q('#err-pin_password')), COPY.pin_set_wrong_password);
  assert.equal(h.q('#pin_password').value, '', 'the wrong password is typed again');
  assert.equal(h.q('#pin_new').value, '4821');
  assert.equal(h.doc.activeElement, h.q('#pin_password'));
  fill();
  submit(h);
  await flush();
  assert.equal(text(h.q('#err-pin_new')), COPY.pin_rule_guessable);
  assert.equal(h.q('#err-pin_password'), null);
  assert.deepEqual(['pin_password', 'pin_new', 'pin_repeat'].map((id) => h.q('#' + id).value), ['Correct-Horse-Battery-9', '', '']);
  fill();
  submit(h);
  await flush();
  assert.equal(text(h.q('#err-pin_repeat')), COPY.pin_rule_mismatch);
  assert.equal(h.q('#pin_repeat').getAttribute('aria-describedby'), 'err-pin_repeat');
  fill();
  submit(h);
  await flush();
  assert.equal(text(h.q('p.message[role="alert"]')), COPY.pin_set_offline);
  // The success stays until Continue (setPin emits nothing).
  const held = deferred();
  h.session.queue.setPin.push(held.promise);
  fill();
  submit(h);
  assert.equal(text(h.q('button[type="submit"]')), COPY.saving);
  held.resolve({ ok: true });
  await flush();
  assert.equal(s.model().done, true);
  assert.equal(text(h.q('p.message.message-ok')), COPY.pin_set_ok);
  assert.equal(h.q('form'), null);
  assert.equal(done, 0);
  h.button(COPY.continue).dispatch('click');
  assert.equal(done, 1);
  // Back, and the exact digit count.
  h.session.set('config', { pin_min_digits: 5, pin_max_digits: 5 });
  s.show();
  assert.equal(text(h.q('p.hint')), 'Use 5 digits.');
  assert.equal(h.q('#pin_new').getAttribute('maxlength'), '5');
  h.button(COPY.back).dispatch('click');
  assert.equal(done, 2);
});

test('the home screen offers Set a PIN only when the person can record and has none', () => {
  const user = { user_id: 12, username: 'jdoe', display_name: 'Jo Doe', role: 'Volunteer', has_pin: false, pin_switch: true };
  let connectivity = 'online';
  let setPin = 0;
  const show = (over) => {
    const h = harness({ state: 'ACTIVE', user, canRecord: true, ...over });
    const s = createHomeScreen({ session: h.session, info: h.info, mount: h.mount, connectivity: () => connectivity, onSetPin: () => { setPin += 1; } });
    s.show();
    return { h, s };
  };
  let { h, s } = show();
  assert.equal(text(h.q('h1')), 'Signed in as Jo Doe');
  assert.equal(text(h.q('p.tablet')), 'E2E 1 at Dev Site North');
  assert.equal(text(h.q('p.status')), COPY.home_online);
  assert.equal(text(h.q('p.hint')), COPY.home_set_pin_hint);
  h.button(COPY.set_pin_button).dispatch('click');
  assert.equal(setPin, 1);
  assert.deepEqual(s.model(), { name: 'Jo Doe', site: 'Dev Site North', label: 'E2E 1', connectivity: 'online', hasPin: false, pinSwitch: true,
    canRecord: true, releaseUnavailable: null });
  s.stop();
  h.button(COPY.set_pin_button).dispatch('click');
  assert.equal(setPin, 1, 'nothing after stop()');
  for (const over of [{ user: { ...user, has_pin: true } }, { user: { ...user, pin_switch: false } }, { canRecord: false, releaseUnavailable: 'no_grant_possible' }]) {
    ({ h } = show(over));
    assert.equal(h.button(COPY.set_pin_button), null, JSON.stringify(over));
  }
  assert.equal(text(h.q('p.message-warning')), COPY.grant_unavailable);
  connectivity = 'offline';
  ({ h } = show({ canRecord: false, releaseUnavailable: 'no_vault_key' }));
  assert.equal(text(h.q('p.message-warning')), COPY.test_tablet);
  assert.equal(h.q('p.status'), null, 'not online');
  assert.equal(h.button(COPY.set_pin_button), null);
});

test('a late answer after stop() draws nothing', async () => {
  // The lock screen: a sign-in, and a PIN switch, answered after stop().
  const h = harness({ state: 'PICKER', people: PEOPLE });
  const s = loginOf(h);
  s.show();
  h.button(COPY.someone_else).dispatch('click');
  const signIn = deferred();
  h.session.queue.signIn.push(signIn.promise);
  type(h, { identifier: 'jdoe', password: 'pw' });
  submit(h);
  s.stop();
  let n = h.mounts.length;
  signIn.resolve({ ok: false, message: { key: 'login_failed' } });
  await flush();
  assert.equal(h.mounts.length, n, 'no draw after stop()');
  s.refresh();
  assert.equal(h.mounts.length, n, 'nor a refresh');
  s.show();
  h.button(COPY.someone_else).dispatch('click');
  const again = deferred();
  h.session.queue.signIn.push(again.promise);
  type(h, { identifier: 'jdoe', password: 'pw' });
  submit(h);
  s.show(); // shown again while the sign-in runs (a hard lock re-routes to the lock screen)
  n = h.mounts.length;
  again.resolve({ ok: false, message: { key: 'login_failed' } });
  await flush();
  assert.equal(h.mounts.length, n, 'the earlier attempt draws nothing on the new screen');
  assert.equal(s.model().message, null);
  assert.equal(s.model().busy, false);
  tile(h, 'Jo Doe').dispatch('click');
  const pin = deferred();
  h.session.queue.pinSwitch.push(pin.promise);
  press(h, '1', '2', '4', '8', 'ok');
  s.show(); // a new show(): the earlier answer belongs to the earlier screen
  n = h.mounts.length;
  pin.resolve({ ok: false, message: { key: 'pin_locked' }, next: 'password', identifier: 'jdoe' });
  await flush();
  assert.equal(h.mounts.length, n);
  assert.equal(s.model().mode, 'picker', 'the new screen is untouched');
  assert.equal(s.model().message, null);
  // The agreement: the load and an acceptance.
  const a = harness({ state: 'GATE', gate: 'policy_ack' });
  const ack = createAckScreen({ session: a.session, mount: a.mount });
  const load = deferred();
  a.session.queue.loadPolicy.push(load.promise);
  const shown = ack.show();
  ack.stop();
  n = a.mounts.length;
  load.resolve({ ok: true, required: true, document: DOC1 });
  await shown;
  assert.equal(a.mounts.length, n);
  a.session.queue.loadPolicy.push({ ok: true, required: true, document: DOC1 });
  await ack.show();
  const accept = deferred();
  a.session.queue.acceptPolicy.push(accept.promise);
  a.button(COPY.ack_accept).dispatch('click');
  ack.stop();
  n = a.mounts.length;
  accept.resolve({ ok: false, message: { key: 'policy_changed' }, changed: true });
  await flush();
  assert.equal(a.mounts.length, n);
  assert.deepEqual(a.session.calls, [['loadPolicy'], ['loadPolicy'], ['acceptPolicy', 5]], 'no reload for a stopped screen');
  // The password change and the PIN set.
  const p = harness({ state: 'GATE', gate: 'password_change' });
  const pw = createPasswordScreen({ session: p.session, mount: p.mount });
  pw.show();
  const change = deferred();
  p.session.queue.changePassword.push(change.promise);
  type(p, { current_password: 'a', new_password: 'b', repeat_password: 'b' });
  submit(p);
  pw.stop();
  n = p.mounts.length;
  change.resolve({ ok: false, errors: { current_password: { key: 'current_password_wrong' } } });
  await flush();
  assert.equal(p.mounts.length, n);
  const q = harness({ state: 'ACTIVE' });
  const ps = createPinSetScreen({ session: q.session, mount: q.mount, onDone: () => {} });
  ps.show();
  const set = deferred();
  q.session.queue.setPin.push(set.promise);
  type(q, { pin_password: 'pw', pin_new: '4821', pin_repeat: '4821' });
  submit(q);
  ps.stop();
  n = q.mounts.length;
  set.resolve({ ok: true });
  await flush();
  assert.equal(q.mounts.length, n);
  assert.equal(ps.model().done, false);
});

// ---- S3 review: focus, live regions and the answer's feedback (client-pinpad-remount, conformance-3, tests-pinpad-focus,
// client-ack-accept-feedback, client-error-copy) ----

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

/** Presses a key the way a keyboard or switch user does: the focus on it first, then its click. */
const focusPress = (h, key) => {
  const b = h.q(`[data-key="${key}"]`);
  b.focus();
  same(h.doc.activeElement, b, `key ${key} can take the focus`);
  b.dispatch('click');
  return b;
};

test('a pressed PIN key keeps the focus: the pad changes in place and its count line stays one live region', () => {
  const h = harness({ state: 'PICKER', people: PEOPLE });
  const s = loginOf(h);
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  const section = h.q('section');
  const count = h.q('p.pin-count[role="status"]');
  assert.ok(count, 'the count line is there from the first draw');
  assert.equal(text(count), '0 digits entered');
  assert.equal(h.q('p.pin-dots').getAttribute('aria-hidden'), 'true', 'the dots are for the eye only');
  assert.equal(h.q('p.pin-dots').getAttribute('role'), null);
  const drawn = h.mounts.length;
  for (const [key, want] of [['7', '1 digit entered'], ['2', '2 digits entered'], ['back', '1 digit entered'], ['0', '2 digits entered']]) {
    const b = focusPress(h, key);
    same(h.doc.activeElement, b, `key ${key} keeps the focus`);
    same(h.q(`[data-key="${key}"]`), b, `key ${key} is the same node`);
    same(h.q('section'), section, 'nothing is re-mounted');
    same(h.q('p.pin-count'), count, 'the same live region');
    assert.equal(text(count), want, `after ${key}`);
  }
  assert.equal(dots(h), '●●○○○○');
  // Enter on a focused key: the browser clicks it; the pad's keydown does not submit as well.
  const five = h.q('[data-key="5"]');
  five.focus();
  five.dispatch('keydown', { key: 'Enter' });
  five.dispatch('click');
  same(h.doc.activeElement, five);
  assert.equal(text(count), '3 digits entered');
  // A hardware keyboard: the focus stays where it is too.
  h.q('section').dispatch('keydown', { key: '9' });
  same(h.doc.activeElement, five);
  assert.equal(text(count), '4 digits entered');
  assert.equal(h.q('[data-key="ok"]').getAttribute('aria-disabled'), null, 'OK is available from pin_min_digits');
  assert.equal(h.mounts.length, drawn, 'no digit or Delete mounts anything');
  assert.deepEqual(h.session.calls, []);
});

test('a PIN switch in flight keeps the focus on what sent it and says Signing in…; a wrong PIN\'s alert is not repeated by typing', async () => {
  const h = harness({ state: 'PICKER', people: PEOPLE });
  const s = loginOf(h);
  s.show();
  tile(h, 'Jo Doe').dispatch('click');
  const section = h.q('section');
  const status = h.q('p.status[role="status"]');
  assert.equal(text(status), '', 'the status line is there, empty');
  press(h, '1', '2', '3', '4');
  const held = deferred();
  h.session.queue.pinSwitch.push(held.promise);
  const ok = focusPress(h, 'ok');
  assert.deepEqual(h.session.calls, [['pinSwitch', 12]]);
  same(h.q('section'), section, 'the wait is shown in place');
  same(h.doc.activeElement, ok, 'OK keeps the focus');
  same(h.q('p.status'), status, 'the same live region');
  assert.equal(text(status), COPY.signing_in);
  assert.ok(h.qa('section button').every((b) => b.getAttribute('aria-disabled') === 'true'), 'every button waits, none drops the focus');
  ok.dispatch('click');
  h.q('[data-key="1"]').dispatch('click');
  h.button(COPY.pin_cancel).dispatch('click');
  assert.equal(h.session.calls.length, 1, 'nothing more while it runs');
  held.resolve({ ok: false, message: { key: 'pin_wrong_many', params: { n: 2 } } });
  await flush();
  assert.ok(h.q('section') !== section, 'the answer draws the pad again');
  same(h.doc.activeElement, h.q('[data-key="ok"]'), 'the focus is on OK again (unavailable with no digit, but focusable)');
  const alert = h.q('p.message-error[role="alert"]');
  assert.equal(text(alert), 'Wrong PIN. 2 tries left before a password is needed.');
  assert.equal(text(h.q('p.status')), '');
  // Typing after the error: the alert node stays as it is (not inserted again, so not read out again).
  const drawn = h.mounts.length;
  const eight = focusPress(h, '8');
  focusPress(h, '2');
  same(h.q('p.message-error[role="alert"]'), alert, 'the same alert node');
  assert.equal(h.mounts.length, drawn);
  assert.equal(text(h.q('p.pin-count')), '2 digits entered');
  // The last digit sends it by itself: that key keeps the focus while it runs and after the answer.
  const again = deferred();
  h.session.queue.pinSwitch.push(again.promise);
  focusPress(h, '4');
  focusPress(h, '8');
  focusPress(h, '1');
  const six = focusPress(h, '6');
  assert.equal(h.session.calls.length, 2, 'the sixth digit sent it');
  same(h.doc.activeElement, six);
  same(h.q('p.message-error'), null, 'the old message goes while the new attempt runs');
  assert.equal(text(h.q('p.status')), COPY.signing_in);
  assert.equal(eight.getAttribute('aria-disabled'), 'true');
  again.resolve({ ok: false, message: { key: 'pin_wrong_one' } });
  await flush();
  same(h.doc.activeElement, h.q('[data-key="6"]'));
  assert.equal(text(h.q('p.message-error[role="alert"]')), COPY.pin_wrong_one, 'a new answer is a new alert');
});

test('a sign-in in flight keeps the focus on Sign in and says Signing in… in the status line already there', async () => {
  const h = harness();
  const s = loginOf(h);
  s.show();
  const section = h.q('section');
  const status = h.q('p.status[role="status"]');
  const held = deferred();
  h.session.queue.signIn.push(held.promise);
  type(h, { identifier: 'jdoe', password: 'pw' });
  h.q('#password').focus();
  h.q('form').dispatch('submit'); // Enter in the password field
  same(h.q('section'), section, 'in place');
  same(h.q('p.status'), status, 'the same live region');
  assert.equal(text(status), COPY.signing_in);
  const signIn = h.q('button[type="submit"]');
  same(h.doc.activeElement, signIn, 'from the field that is now disabled to Sign in');
  assert.equal(signIn.disabled, false, 'Sign in can hold the focus');
  assert.equal(signIn.getAttribute('aria-disabled'), 'true');
  assert.equal(h.q('form').getAttribute('aria-disabled'), 'true');
  signIn.dispatch('click');
  assert.equal(h.session.calls.length, 1, 'one sign-in at a time');
  assert.deepEqual(h.doc.navigations, []);
  held.resolve({ ok: false, message: { key: 'login_failed' } });
  await flush();
  same(h.doc.activeElement, h.q('#password'), 'the answer: afresh, the password to type again');
  assert.equal(text(h.q('p.status')), '');
  // Sign in pressed: it keeps the focus while the answer is out.
  h.session.queue.signIn.push(deferred().promise);
  type(h, { password: 'pw' });
  const button = h.q('button[type="submit"]');
  button.focus();
  button.dispatch('click');
  same(h.doc.activeElement, button);
  same(h.q('p.message'), null, 'the old message goes');
  assert.equal(text(h.q('p.status')), COPY.signing_in);
});

test('refresh() keeps the focused control and does not read out again a message or notice already shown', async () => {
  const h = harness({ state: 'IDLE', notice: { key: 'notice_idle' }, people: PEOPLE });
  const s = loginOf(h);
  s.show();
  assert.equal(h.q('p.notice').getAttribute('role'), 'status', 'a new notice is read out');
  tile(h, 'Jo Doe').dispatch('click');
  h.session.queue.pinSwitch.push({ ok: false, message: { key: 'pin_wrong_many', params: { n: 2 } } });
  press(h, '1', '1', '1', '1', 'ok');
  await flush();
  assert.equal(h.q('p.message-error').getAttribute('role'), 'alert');
  press(h, '4');
  h.q('[data-key="3"]').focus();
  s.refresh();
  same(h.doc.activeElement, h.q('[data-key="3"]'), 'the same key has the focus in the pad drawn again');
  assert.equal(text(h.q('p.message-error')), 'Wrong PIN. 2 tries left before a password is needed.', 'the message stays');
  assert.equal(h.q('p.message-error').getAttribute('role'), null, 'but it is not an alert again');
  assert.equal(h.q('p.notice').getAttribute('role'), null, 'nor the notice a status again');
  assert.equal(text(h.q('p.pin-count')), '1 digit entered');
  // The picker: a focused tile keeps the focus when the people are read again.
  h.button(COPY.pin_cancel).dispatch('click');
  h.q('[data-user-id="13"]').focus();
  s.refresh();
  same(h.doc.activeElement, h.q('[data-user-id="13"]'));
  // A control that is gone: as a fresh mount.
  h.session.set('people', [PEOPLE[0]]);
  s.refresh();
  same(h.doc.activeElement, h.q('h1'));
  // show(): a new screen, whose notice is read out.
  h.session.set('notice', { key: 'notice_signed_out' });
  s.show();
  assert.equal(h.q('p.notice').getAttribute('role'), 'status');
});

test('an answer to the agreement shows Sending… by the buttons in place; a refused one shows its message there, with the focus kept', async () => {
  const long = { ...DOC1, body: Array.from({ length: 60 }, (_, i) => `Paragraph ${i + 1}.`).join('\n\n') };
  const h = harness({ state: 'GATE', gate: 'policy_ack' });
  const s = createAckScreen({ session: h.session, mount: h.mount });
  h.session.queue.loadPolicy.push({ ok: true, required: true, document: long });
  await s.show();
  const section = h.q('section');
  const accept = h.q('#ack-accept');
  const status = h.q('#ack-status');
  assert.equal(status.getAttribute('role'), 'status');
  assert.equal(text(status), '');
  const held = deferred();
  h.session.queue.acceptPolicy.push(held.promise);
  accept.focus();
  accept.dispatch('click');
  same(h.q('section'), section, 'in place');
  same(h.doc.activeElement, accept, 'I accept keeps the focus');
  assert.equal(text(accept), COPY.sending);
  same(h.q('#ack-status'), status, 'the same live region');
  assert.equal(text(status), COPY.sending);
  assert.equal(h.q('#ack-decline').getAttribute('aria-disabled'), 'true');
  held.resolve({ ok: false, message: { key: 'ack_send_failed' } });
  await flush();
  same(h.doc.activeElement, h.q('#ack-accept'), 'the focus stays on I accept');
  const alert = h.q('p.message-error[role="alert"]');
  assert.equal(text(alert), COPY.ack_send_failed);
  assert.equal(COPY.ack_send_failed, 'Your answer could not be sent. Check the Wi-Fi and try again.');
  const order = h.q('section').children.map((n) => n.getAttribute('id') ?? n.getAttribute('class'));
  assert.deepEqual(order.slice(-4), ['policy-text', 'message message-error', 'ack-status', 'actions'], 'the message just above the buttons, after the text');
  // A server problem: its number under the message.
  h.session.queue.acceptPolicy.push({ ok: false, message: { key: 'server_error', params: { incident: 'AB12CD34' } } });
  h.q('#ack-accept').dispatch('click');
  await flush();
  assert.equal(text(h.q('p.message-error')), COPY.server_error);
  assert.equal(text(h.qa('p.hint').at(-1)), 'Problem number: AB12CD34');
  // I do not accept: both wait, it says Sending…, the old message goes, the focus stays on it.
  const declined = deferred();
  h.session.queue.declinePolicy.push(declined.promise);
  const decline = h.q('#ack-decline');
  decline.focus();
  const before = h.q('section');
  decline.dispatch('click');
  same(h.q('section'), before, 'in place');
  assert.equal(s.model().phase, 'declining');
  assert.equal(text(decline), COPY.sending);
  assert.equal(text(h.q('#ack-accept')), COPY.ack_accept);
  assert.ok([h.q('#ack-accept'), decline].every((b) => b.getAttribute('aria-disabled') === 'true'));
  assert.equal(text(h.q('#ack-status')), COPY.sending);
  same(h.q('p.message'), null);
  assert.equal(h.q('p.hint').getAttribute('data-message'), null, 'the version line stays');
  assert.equal(h.qa('p.hint').length, 1, 'the problem number went with its message');
  same(h.doc.activeElement, decline);
  h.q('#ack-accept').dispatch('click');
  decline.dispatch('click');
  declined.resolve();
  await flush();
  assert.deepEqual(h.session.calls.filter((c) => c[0] === 'declinePolicy'), [['declinePolicy', 5]], 'once');
  assert.equal(h.session.calls.filter((c) => c[0] === 'acceptPolicy').length, 2, 'no acceptance while declining');
  // Still shown (the session would have locked the tablet): ready again.
  assert.equal(s.model().phase, 'ready');
  assert.equal(text(h.q('#ack-decline')), COPY.ack_decline);
  assert.equal(h.q('#ack-decline').getAttribute('aria-disabled'), null);
});

test('the password and PIN-set screens show Saving… in place with the focus on Save, and a problem number', async () => {
  const p = harness({ state: 'GATE', gate: 'password_change' });
  const pw = createPasswordScreen({ session: p.session, mount: p.mount });
  pw.show();
  const section = p.q('section');
  const status = p.q('p.status[role="status"]');
  assert.equal(text(status), '');
  const held = deferred();
  p.session.queue.changePassword.push(held.promise);
  type(p, { current_password: 'Temp-Password-1', new_password: 'A new long sentence', repeat_password: 'A new long sentence' });
  p.q('#repeat_password').focus();
  p.q('form').dispatch('submit');
  same(p.q('section'), section, 'in place');
  same(p.doc.activeElement, p.q('#password-save'), 'Save has the focus');
  assert.equal(p.q('#password-save').getAttribute('aria-disabled'), 'true');
  same(p.q('p.status'), status);
  assert.equal(text(status), COPY.saving);
  assert.equal(p.q('#new_password').value, 'A new long sentence', 'the values stay where they are');
  p.q('form').dispatch('submit');
  p.button(COPY.cancel).dispatch('click');
  assert.deepEqual(p.session.calls, [['changePassword']], 'nothing more while it runs');
  held.resolve({ ok: false, message: { key: 'server_error', params: { incident: 'AB12CD35' } } });
  await flush();
  assert.equal(text(p.q('p.message-error[role="alert"]')), COPY.server_error);
  assert.equal(text(p.qa('p.hint').at(-1)), 'Problem number: AB12CD35');

  const q = harness({ state: 'ACTIVE' });
  const ps = createPinSetScreen({ session: q.session, mount: q.mount, onDone: () => {} });
  ps.show();
  const set = deferred();
  q.session.queue.setPin.push(set.promise);
  type(q, { pin_password: 'pw', pin_new: '4821', pin_repeat: '4821' });
  const save = q.q('#pin-set-save');
  save.focus();
  save.dispatch('click');
  same(q.doc.activeElement, save, 'Save keeps the focus');
  assert.equal(text(q.q('p.status')), COPY.saving);
  assert.ok(q.qa('input').every((i) => i.disabled === true));
  set.resolve({ ok: false, message: { key: 'server_error', params: { incident: 'AB12CD36' } } });
  await flush();
  assert.equal(text(q.q('p.message-error[role="alert"]')), COPY.server_error);
  assert.equal(text(q.qa('p.hint').at(-1)), 'Problem number: AB12CD36');
  assert.equal(text(q.q('p.status')), '');
});

test('home drawn again after a connection change keeps the focus on Set a PIN', () => {
  const user = { user_id: 12, username: 'jdoe', display_name: 'Jo Doe', role: 'Volunteer', has_pin: false, pin_switch: true };
  let connectivity = 'online';
  const h = harness({ state: 'ACTIVE', user, canRecord: true });
  const s = createHomeScreen({ session: h.session, info: h.info, mount: h.mount, connectivity: () => connectivity, onSetPin: () => {} });
  s.show();
  same(h.doc.activeElement, h.q('h1'), 'a new screen: its title');
  h.q('#set-pin').focus();
  connectivity = 'offline';
  s.refresh();
  same(h.q('p.status'), null, 'the new connection is shown');
  same(h.doc.activeElement, h.q('#set-pin'), 'the focus stays on Set a PIN');
  s.stop();
  const n = h.mounts.length;
  s.refresh();
  assert.equal(h.mounts.length, n, 'nothing after stop()');
});

test('home read again in place keeps the focus on its title or on the page, and mounts nothing', () => {
  const user = { user_id: 12, username: 'jdoe', display_name: 'Jo Doe', role: 'Volunteer', has_pin: false, pin_switch: true };
  let connectivity = 'online';
  const h = harness({ state: 'ACTIVE', user, canRecord: true });
  const s = createHomeScreen({ session: h.session, info: h.info, mount: h.mount, connectivity: () => connectivity, onSetPin: () => {} });
  s.show();
  const section = h.q('section');
  const title = h.q('h1');
  same(h.doc.activeElement, title, 'a new screen: its title');
  // The chip flaps and the tablet is renamed: the same title node keeps the focus, so it is not read out again.
  for (const [conn, label] of [['offline', 'E2E 1'], ['online', 'E2E 1'], ['unknown', 'E2E 2']]) {
    connectivity = conn;
    h.tablet.label = label;
    s.refresh();
    same(h.q('section'), section, `${conn}: changed in place`);
    same(h.q('h1'), title, `${conn}: the same title`);
    same(h.doc.activeElement, title, `${conn}: the title keeps the focus`);
  }
  assert.equal(text(h.q('p.tablet')), 'E2E 2 at Dev Site North');
  assert.equal(s.model().connectivity, 'unknown');
  // The page has the focus: nothing takes it.
  h.doc.activeElement = h.doc.body;
  connectivity = 'online';
  h.session.set('releaseUnavailable', 'no_vault_key');
  s.refresh();
  same(h.doc.activeElement, h.doc.body, 'the focus stays on the page');
  assert.equal(text(h.q('p.status')), COPY.home_online);
  assert.equal(text(h.q('p.message-warning')), COPY.test_tablet);
  assert.equal(h.mounts.length, 1, 'only show() mounts');
});

test('a message is read out when it is new: again after the next attempt, not when the same words are drawn again', async () => {
  // The same answer object twice (a session may keep its messages): the second failure is an alert too.
  const h = harness();
  const s = loginOf(h);
  s.show();
  const failed = { key: 'login_failed' };
  h.session.queue.signIn.push({ ok: false, message: failed }, { ok: false, message: failed });
  for (const attempt of ['first', 'second']) {
    type(h, { identifier: 'jdoe', password: 'pw' });
    submit(h);
    same(h.q('p.message'), null, `${attempt}: the message goes while the attempt runs`);
    await flush();
    assert.equal(h.q('p.message-error').getAttribute('role'), 'alert', `${attempt} failure: read out`);
  }
  // The same words in a new object, drawn again: not read out again (messages are compared by what they say).
  s.model().message = { key: 'login_failed' };
  s.refresh();
  assert.equal(text(h.q('p.message-error')), COPY.login_failed);
  assert.equal(h.q('p.message-error').getAttribute('role'), null, 'the same content: no alert');
  s.model().message = { key: 'pin_wrong_many', params: { n: 1 } };
  s.refresh();
  assert.equal(h.q('p.message-error').getAttribute('role'), 'alert', 'other words: an alert');
  s.model().message = { params: { n: 1 }, key: 'pin_wrong_many' };
  s.refresh();
  assert.equal(h.q('p.message-error').getAttribute('role'), null, 'the same key and params: no alert');
  s.model().message = { key: 'server_error', params: { incident: 'ABC', n: 1 } };
  s.refresh();
  assert.equal(h.q('p.message-error').getAttribute('role'), 'alert', 'another key: an alert');
  s.model().message = { key: 'server_error', params: { n: 1, incident: 'ABC' } };
  s.refresh();
  assert.equal(h.q('p.message-error').getAttribute('role'), null, 'the same params in another order: no alert');
  s.model().message = { key: 'server_error', params: { n: 1, incident: 'XYZ' } };
  s.refresh();
  assert.equal(h.q('p.message-error').getAttribute('role'), 'alert', 'the same key with other params: an alert');
  // Enter with too few digits, pressed twice: each press is answered aloud, as a press of its own.
  const p = harness({ state: 'PICKER', people: PEOPLE });
  const pad = loginOf(p);
  pad.show();
  tile(p, 'Jo Doe').dispatch('click');
  press(p, '1', '2');
  p.q('section').dispatch('keydown', { key: 'Enter' });
  assert.equal(p.q('p.message-error').getAttribute('role'), 'alert');
  p.q('section').dispatch('keydown', { key: 'Enter' });
  assert.equal(text(p.q('p.message-error')), 'Use 4 to 6 digits.');
  assert.equal(p.q('p.message-error').getAttribute('role'), 'alert', 'the second press is answered aloud');
  press(p, '3');
  pad.refresh();
  assert.equal(p.q('p.message-error').getAttribute('role'), null, 'drawn again: not read out again');
});
