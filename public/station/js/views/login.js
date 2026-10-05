// The lock screen (S3 spec §3.4): the sign-in form, the picker of the people whose keys this tablet holds, and the PIN
// pad. It shows the organisation name, "Pet Pantry Station" and the build (50 E2E A2), and "Checking this tablet…"
// with the form disabled while the start-up heartbeat runs. A pure renderer: views/screens.js's createLoginScreen
// holds the model and calls the session.
// update() changes a mounted section in place, as S2's views/device.js updateCheck() does: the PIN digits, and a
// sign-in or PIN switch starting. Nothing is re-mounted, so the focus stays on the key or button the person pressed and
// the status lines are the same live regions a screen reader already watches (render() itself ends with update()).
import { el, setUnavailable, unavailable } from '../dom.js';
import { t, messageText, incidentText, pinCount } from '../copy.js';

/**
 * @typedef {object} LoginModel
 * @property {string|null} orgName
 * @property {string|null} site
 * @property {string|null} label
 * @property {string} build
 * @property {boolean} checking the start-up heartbeat has not answered yet
 * @property {'form'|'picker'|'pin'} [mode]
 * @property {boolean} [busy] a sign-in or a PIN switch is in flight
 * @property {object|null} [message] a session.js Message (an error)
 * @property {boolean} [messageLive] false: the message was shown before, so it is drawn without role=alert (a screen
 *   reader does not read it out again when the screen is drawn again); default true
 * @property {object|null} [notice] a session.js Message (why the tablet locked)
 * @property {boolean} [noticeLive] as messageLive, for the notice's role=status
 * @property {string} [identifier] what the identifier field holds
 * @property {Array<{user_id: number, display_name: string, username?: string}>} [people] the picker's tiles
 * @property {{name: string, entered: number}|null} [pin] the PIN pad's person and how many digits are entered
 * @property {number} [pinMin]
 * @property {number} [pinMax]
 * @property {boolean} [canGoBack] the form shows Back (to the picker)
 */
/**
 * @typedef {object} LoginActions
 * @property {(identifier: string, password: string) => void} [onSignIn]
 * @property {() => void} [onBackToPicker]
 * @property {(userId: number) => void} [onPick]
 * @property {() => void} [onSomeoneElse]
 * @property {(digit: string) => void} [onDigit]
 * @property {() => void} [onBackspace]
 * @property {() => void} [onPinSubmit]
 * @property {() => void} [onPinCancel]
 * @property {() => void} [onUsePassword]
 */

/** The PIN pad's twelve keys, in reading order: [data-key, label key or digit]. */
const PAD = Object.freeze(['1', '2', '3', '4', '5', '6', '7', '8', '9', 'back', '0', 'ok']);
const FILLED = '●';
const EMPTY = '○';

/**
 * A message (role alert) or a notice (role status), and the problem number when it has one. A message's lines are
 * marked data-message, so update() can take them away in place when a new attempt starts.
 * @param {object|null} message
 * @param {{cls: string, role: string, live?: boolean, mark?: boolean}} options live false: drawn without its role
 */
function lines(message, { cls, role, live, mark = false }) {
  if (!message) return null;
  const incident = incidentText(message);
  const marked = mark ? true : null;
  return [
    el('p', { class: cls, role: live === false ? null : role, 'data-message': marked }, messageText(message)),
    incident !== '' ? el('p', { class: 'hint', 'data-message': marked }, incident) : null,
  ];
}

function tabletLine(model) {
  return model.label && model.site ? el('p', { class: 'tablet' }, t('tablet_at', { label: model.label, site: model.site })) : null;
}

/** A button's click, unless the button is marked unavailable (a sign-in or PIN switch is in flight). */
const press = (fn) => (e) => { if (!unavailable(e?.currentTarget)) fn(); };

function form(model, actions) {
  const idInput = el('input', {
    id: 'identifier', class: 'input', autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false', value: model.identifier ?? '',
  });
  const pwInput = el('input', { id: 'password', class: 'input', type: 'password', autocomplete: 'current-password' });
  const node = el('form', {
    class: 'form',
    on: {
      submit: (e) => {
        e.preventDefault();
        if (node.getAttribute('aria-disabled') === 'true') return; // checking, or a sign-in in flight (update() keeps it)
        actions.onSignIn?.(idInput.value, pwInput.value);
        pwInput.value = '';
      },
    },
  },
  el('label', { class: 'label', for: 'identifier' }, t('username_label')),
  idInput,
  el('label', { class: 'label', for: 'password' }, t('password_label')),
  pwInput,
  el('button', { id: 'signin', class: 'button button-primary', type: 'submit' }, t('signin_button')));
  return [
    node,
    el('p', { class: 'status', role: 'status' }),
    model.canGoBack === true
      ? el('button', { id: 'back-to-picker', class: 'button button-secondary', type: 'button', on: { click: press(() => actions.onBackToPicker?.()) } }, t('back'))
      : null,
  ];
}

/** The display name the picker compares: two tiles whose names differ only in case or spaces look the same. */
const sameName = (name) => name.trim().replace(/\s+/g, ' ').toLocaleLowerCase();

/**
 * Someone else first, where a new volunteer finds it without scrolling past every tile, then one tile per person.
 * When two or more people share a display name, each of their tiles adds the username as a second line (in the
 * tile's text, so also in its accessible name).
 */
function picker(model, actions) {
  const people = model.people ?? [];
  const nameOf = (p) => String(p.display_name ?? '');
  const seen = new Map();
  for (const p of people) seen.set(sameName(nameOf(p)), (seen.get(sameName(nameOf(p))) ?? 0) + 1);
  const tiles = people.map((p) => {
    const name = nameOf(p);
    const username = String(p.username ?? '');
    const label = seen.get(sameName(name)) > 1 && username !== ''
      ? [el('span', { class: 'tile-name' }, name), ' ', el('span', { class: 'tile-detail' }, username)]
      : name;
    return el('li', {},
      el('button', { class: 'button tile', type: 'button', 'data-user-id': String(p.user_id), on: { click: () => actions.onPick?.(p.user_id) } }, label));
  });
  return el('ul', { class: 'tiles' },
    el('li', {}, el('button', { id: 'someone-else', class: 'button tile tile-other', type: 'button', on: { click: () => actions.onSomeoneElse?.() } },
      t('someone_else'))),
    tiles);
}

function pinPad(model, actions) {
  const max = model.pinMax ?? 6;
  const send = (key) => {
    if (key === 'back') actions.onBackspace?.();
    else if (key === 'ok') actions.onPinSubmit?.();
    else actions.onDigit?.(key);
  };
  const keys = PAD.map((key) => el('button', { class: 'button key', type: 'button', 'data-key': key, on: { click: press(() => send(key)) } },
    key === 'back' ? t('pin_delete') : key === 'ok' ? t('pin_ok') : key));
  return [
    // The dots are for the eye; the count is read out from the status line under them (update() keeps both).
    el('p', { class: 'pin-dots', 'aria-hidden': 'true' }, EMPTY.repeat(max)),
    el('p', { class: 'pin-count visually-hidden', role: 'status' }),
    el('div', { class: 'pinpad' }, keys),
    el('p', { class: 'status', role: 'status' }),
    el('div', { class: 'actions' },
      el('button', { id: 'use-password', class: 'button button-secondary', type: 'button', on: { click: press(() => actions.onUsePassword?.()) } },
        t('use_password')),
      el('button', { id: 'pin-cancel', class: 'button button-secondary', type: 'button', on: { click: press(() => actions.onPinCancel?.()) } },
        t('pin_cancel'))),
  ];
}

/** The PIN pad's keyboard: 0-9, Backspace, Enter and Escape, ignored while busy or with a modifier held. */
function pinKeys(actions) {
  return (e) => {
    if (e.currentTarget?.hasAttribute?.('data-busy') || e.ctrlKey || e.metaKey || e.altKey) return;
    const key = e.key;
    if (typeof key === 'string' && /^[0-9]$/.test(key)) actions.onDigit?.(key);
    else if (key === 'Backspace') actions.onBackspace?.();
    else if (key === 'Enter') { if (e.target?.tagName === 'BUTTON') return; actions.onPinSubmit?.(); } // a focused key clicks by itself
    else if (key === 'Escape') actions.onPinCancel?.();
    else return;
    e.preventDefault?.();
  };
}

/** Sets a node's text only when it changes, so a live region is not read out again for the same words. */
function setText(node, text) {
  if (node && node.textContent !== text) node.textContent = text;
}

/**
 * @param {LoginModel} model
 * @param {LoginActions} [actions]
 * @returns {HTMLElement} section.view.view-login (+ view-picker or view-pin)
 */
export function render(model, actions = {}) {
  const mode = model.mode === 'picker' || (model.mode === 'pin' && model.pin) ? model.mode : 'form';
  const common = [
    tabletLine(model),
    lines(model.notice, { cls: 'notice', role: 'status', live: model.noticeLive }),
    lines(model.message, { cls: 'message message-error', role: 'alert', live: model.messageLive, mark: true }),
  ];
  let section;
  if (mode === 'picker') {
    section = el('section', { class: 'view view-login view-picker' },
      el('h1', {}, t('picker_title')), common, picker(model, actions));
  } else if (mode === 'pin') {
    section = el('section', { class: 'view view-login view-pin', on: { keydown: pinKeys(actions) } },
      el('h1', {}, String(model.pin.name ?? '')), el('p', { class: 'lead' }, t('pin_title')), common, pinPad(model, actions));
  } else {
    section = el('section', { class: 'view view-login' },
      el('h1', {}, model.orgName || t('app_name')),
      el('p', { class: 'lead' }, t('app_name'), ' · ', t('version', { build: model.build })),
      common, form(model, actions));
  }
  update(section, model);
  return section;
}

/**
 * Brings a section render() made to model's busy, checking and PIN digits, in place (render() ends with it). It never
 * changes the mode, the people, the header lines or a message's words (those need render()); a message the model no
 * longer has is taken away (a new attempt starting). While busy every button is marked unavailable but keeps its
 * place in the focus order, so the focus stays on the one the person pressed; from a field that is now disabled it
 * moves to Sign in. "Signing in…" goes to the status line, which is there from the first render.
 * @param {HTMLElement} section
 * @param {LoginModel} model
 */
export function update(section, model) {
  const busy = model.busy === true;
  const checking = model.checking === true;
  if (busy) section.setAttribute('data-busy', ''); else section.removeAttribute('data-busy');
  if (!model.message) for (const node of section.querySelectorAll('[data-message]')) node.remove();
  const status = section.querySelector('p.status');
  const formNode = section.querySelector('form');
  if (formNode) {
    if (checking || busy) formNode.setAttribute('aria-disabled', 'true'); else formNode.removeAttribute('aria-disabled');
    const inputs = formNode.querySelectorAll('input');
    for (const input of inputs) input.disabled = checking || busy;
    const submit = formNode.querySelector('button');
    submit.disabled = checking; // the start-up check: nobody pressed anything yet
    setUnavailable(submit, busy && !checking); // a sign-in in flight: the button keeps the focus
    setText(submit, t(busy ? 'signing_in' : 'signin_button'));
    setUnavailable(section.querySelector('#back-to-picker'), busy);
    setText(status, checking ? t('checking') : busy ? t('signing_in') : '');
    const identifier = formNode.querySelector('#identifier');
    const autofocus = busy ? submit : identifier.value === '' ? identifier : formNode.querySelector('#password');
    for (const node of [...inputs, submit]) {
      if (node === autofocus) node.setAttribute('data-autofocus', ''); else node.removeAttribute('data-autofocus');
    }
    const doc = section.ownerDocument;
    const active = doc?.activeElement;
    if (busy && !checking && doc?.documentElement?.contains(section) && (!active || active === doc.body || (formNode.contains(active) && active !== submit))) {
      submit.focus?.({ preventScroll: true });
    }
  }
  const pad = section.querySelector('div.pinpad');
  if (pad) {
    const max = model.pinMax ?? 6;
    const min = model.pinMin ?? 4;
    const n = Math.max(0, Math.min(max, model.pin?.entered ?? 0));
    setText(section.querySelector('p.pin-dots'), FILLED.repeat(n) + EMPTY.repeat(max - n));
    setText(section.querySelector('p.pin-count'), pinCount(n));
    setText(status, busy ? t('signing_in') : '');
    for (const key of pad.querySelectorAll('button')) setUnavailable(key, busy || (key.getAttribute('data-key') === 'ok' && n < min));
    for (const button of section.querySelectorAll('div.actions button')) setUnavailable(button, busy);
  }
}
