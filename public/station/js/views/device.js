// The registration view, "Register this tablet" (S2 spec §4.2). A pure renderer: its behaviour (prepare, typing, the
// QR scan loop, the press) is app.js's createDeviceScreen. updateCheck() changes the check line and the Register
// button in place while someone types, so the input is never re-mounted and keeps its focus and caret.
import { el } from '../dom.js';
import { t } from '../copy.js';

/**
 * @typedef {object} DeviceModel
 * @property {'preparing'|'ready'|'scanning'|'sending'|'registered'|'blocked'} phase
 * @property {'empty'|'partial'|'ok'|'mistyped'} check device.js codeCheck() of the code
 * @property {{key: string, params?: object}|null} message a copy key (params.incident adds the problem number)
 * @property {boolean} canScan the browser has a QR BarcodeDetector
 * @property {boolean} storageRefused the registration succeeded without persistent storage
 * @property {{site: string, label: string}|null} result after a registration
 * @property {string} code what is in the input
 * @property {MediaStream|null} stream the camera while scanning
 * @property {'code'|'register'} focus where focus goes when mounted
 */
/**
 * @typedef {object} DeviceActions
 * @property {(text: string) => void} onInput
 * @property {() => void} onScan
 * @property {() => void} onStopScan
 * @property {() => void} onSubmit
 * @property {() => void} onContinue
 */

/** check → the check line's class and copy key. */
const CHECK = Object.freeze({
  empty: { cls: 'hint', key: 'code_hint' },
  partial: { cls: 'hint', key: 'code_hint' },
  ok: { cls: 'message message-ok', key: 'code_ok' },
  mistyped: { cls: 'message message-error', key: 'reg_mistyped' },
});
const checkOf = (check) => CHECK[check] ?? CHECK.empty;
const buttonKey = (phase) => (phase === 'sending' ? 'registering' : 'register_button');
const registerDisabled = (check, phase) => check !== 'ok' || phase === 'sending';

function storageWarning(model) {
  return model.storageRefused ? el('p', { class: 'message message-warning' }, t('reg_storage_refused')) : null;
}

function messageLines(message) {
  if (!message) return null;
  const incident = message.params?.incident;
  return [
    el('p', { class: 'message message-error', role: 'alert' }, t(message.key, message.params ?? {})),
    incident !== null && incident !== undefined && incident !== '' ? el('p', { class: 'hint' }, t('incident', { incident })) : null,
  ];
}

function form(model, actions) {
  const c = checkOf(model.check);
  return el('form', { class: 'form', on: { submit: (e) => { e.preventDefault(); actions.onSubmit(); } } },
    el('label', { for: 'code', class: 'label' }, t('code_label')),
    el('input', {
      id: 'code', class: 'input input-code', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false',
      inputmode: 'text', enterkeyhint: 'go', maxlength: 100, value: model.code ?? '', 'aria-describedby': 'code-check',
      'data-autofocus': model.focus !== 'register' ? true : null,
      on: { input: (e) => actions.onInput(e.target.value) },
    }),
    el('p', { id: 'code-check', class: c.cls, 'aria-live': 'polite' }, t(c.key)),
    el('button', {
      class: 'button button-primary', type: 'submit', 'data-autofocus': model.focus === 'register' ? true : null,
      disabled: registerDisabled(model.check, model.phase),
    }, t(buttonKey(model.phase))));
}

/**
 * @param {DeviceModel} model
 * @param {DeviceActions} actions
 * @returns {HTMLElement} section.view.view-device
 */
export function render(model, actions) {
  const head = [el('h1', {}, t('register_title')), el('p', { class: 'lead' }, t('register_intro'))];
  let body;
  switch (model.phase) {
    case 'preparing':
      body = [el('p', { class: 'status', role: 'status' }, t('getting_ready'))];
      break;
    case 'blocked':
      body = [el('p', { class: 'message message-error', role: 'alert' }, t('reg_selftest_failed'))];
      break;
    case 'registered':
      body = [
        el('p', { class: 'message message-ok', role: 'status' }, t('registered', { site: model.result?.site ?? '', label: model.result?.label ?? '' })),
        storageWarning(model),
        el('button', { class: 'button button-primary', type: 'button', 'data-autofocus': true, on: { click: () => actions.onContinue() } }, t('continue')),
      ];
      break;
    default: { // ready, scanning, sending
      const scanning = model.phase === 'scanning';
      body = [
        model.canScan
          ? el('button', { class: 'button button-secondary', type: 'button', 'data-scan': true, disabled: model.phase === 'sending',
            on: { click: () => (scanning ? actions.onStopScan() : actions.onScan()) } }, t(scanning ? 'scan_stop' : 'scan_button'))
          : null,
        scanning ? el('video', { class: 'scanner', autoplay: true, muted: true, playsInline: true, srcObject: model.stream }) : null,
        scanning ? el('p', { class: 'hint' }, t('scan_hint')) : null,
        form(model, actions),
        messageLines(model.message),
        storageWarning(model),
      ];
    }
  }
  return el('section', { class: 'view view-device' }, head, body);
}

/**
 * While someone types: only the check line (#code-check) and the Register button change (class, text, disabled).
 * @param {Element} root the element the view is mounted in (#app)
 * @param {{check: string, phase: string}} state
 */
export function updateCheck(root, { check, phase }) {
  const line = root.querySelector('#code-check');
  if (line) {
    const c = checkOf(check);
    line.setAttribute('class', c.cls);
    line.textContent = t(c.key);
  }
  const button = root.querySelector('button[type="submit"]');
  if (button) {
    button.disabled = registerDisabled(check, phase);
    button.textContent = t(buttonKey(phase));
  }
}
