// The forced password change (S3 spec §3.4; D-54): current, new and repeat, the rule with the minimum length, and
// each error under the field it is about (the error's key is that field's id). A pure renderer: views/screens.js's
// createPasswordScreen checks the repeat and calls the session. update() starts the wait in place: the fields are
// disabled, Save keeps the focus and says Saving…, and so does the status line under the form.
import { el, setUnavailable, unavailable } from '../dom.js';
import { t, messageText, incidentText } from '../copy.js';

/**
 * @typedef {object} PasswordModel
 * @property {boolean} [busy] the change is in flight
 * @property {Object<string, object>} [errors] by field id: current_password, new_password, repeat_password
 * @property {object|null} [message] a session.js Message about the whole form (params.incident adds the problem number)
 * @property {number} [minLength] the password minimum
 */
/**
 * @typedef {object} PasswordActions
 * @property {(current: string, next: string, repeat: string) => void} [onSubmit]
 * @property {() => void} [onCancel]
 */

/** The three fields: id, label key, autocomplete. */
export const FIELDS = Object.freeze([
  Object.freeze({ id: 'current_password', label: 'current_password_label', autocomplete: 'current-password' }),
  Object.freeze({ id: 'new_password', label: 'new_password_label', autocomplete: 'new-password' }),
  Object.freeze({ id: 'repeat_password', label: 'repeat_password_label', autocomplete: 'new-password' }),
]);

/**
 * @param {PasswordModel} model
 * @param {PasswordActions} [actions]
 * @returns {HTMLElement} section.view.view-password
 */
export function render(model, actions = {}) {
  const errors = model.errors ?? {};
  const inputs = {};
  const fields = FIELDS.map((f) => {
    const error = errors[f.id];
    inputs[f.id] = el('input', {
      id: f.id, class: 'input', type: 'password', autocomplete: f.autocomplete,
      'aria-describedby': error ? 'err-' + f.id : null, 'aria-invalid': error ? 'true' : null,
    });
    return [
      el('label', { class: 'label', for: f.id }, t(f.label)),
      inputs[f.id],
      error ? el('p', { class: 'message message-error', id: 'err-' + f.id }, messageText(error)) : null,
    ];
  });
  const incident = model.message ? incidentText(model.message) : '';
  const form = el('form', {
    class: 'form',
    on: {
      submit: (e) => {
        e.preventDefault();
        if (form.getAttribute('aria-disabled') === 'true') return; // a change in flight (update() keeps it)
        actions.onSubmit?.(inputs.current_password.value, inputs.new_password.value, inputs.repeat_password.value);
      },
    },
  },
  fields,
  el('button', { id: 'password-save', class: 'button button-primary', type: 'submit' }, t('password_save')));
  const section = el('section', { class: 'view view-password' },
    el('h1', {}, t('password_title')),
    el('p', { class: 'lead' }, t('password_intro')),
    el('p', { class: 'hint' }, t('password_rule', { n: model.minLength ?? 12 })),
    model.message ? el('p', { class: 'message message-error', role: 'alert', 'data-message': true }, messageText(model.message)) : null,
    incident !== '' ? el('p', { class: 'hint', 'data-message': true }, incident) : null,
    form,
    el('p', { class: 'status', role: 'status' }),
    el('div', { class: 'actions' },
      el('button', { id: 'password-cancel', class: 'button button-secondary', type: 'button',
        on: { click: (e) => { if (!unavailable(e?.currentTarget)) actions.onCancel?.(); } } }, t('cancel'))));
  update(section, model);
  return section;
}

/**
 * Brings a section render() made to model's busy state in place (render() ends with it), and takes away the message
 * and the errors the model no longer has (a new attempt starting); a new message or error needs render(). While busy
 * the fields are disabled, Save and Cancel are unavailable but keep their place in the focus order, Save says
 * Saving… and gets the focus from a field that is now disabled, and the status line says Saving….
 * @param {HTMLElement} section
 * @param {PasswordModel} model
 */
export function update(section, model) {
  formUpdate(section, model, { fields: FIELDS, submit: '#password-save', back: '#password-cancel', idle: 'password_save' });
}

/**
 * The shared in-place update of the password and PIN-set forms (views/pin_set.js uses it too).
 * @param {HTMLElement} section
 * @param {{busy?: boolean, errors?: Object<string, object>, message?: object|null}} model
 * @param {{fields: ReadonlyArray<{id: string}>, submit: string, back: string, idle: string}} parts the field ids, the
 *   submit and back buttons' selectors and the submit's copy key when not busy
 */
export function formUpdate(section, model, { fields, submit: submitSel, back: backSel, idle }) {
  const busy = model.busy === true;
  const errors = model.errors ?? {};
  const form = section.querySelector('form');
  if (!form) return;
  if (!model.message) for (const node of section.querySelectorAll('[data-message]')) node.remove();
  for (const f of fields) {
    if (errors[f.id]) continue;
    section.querySelector('#err-' + f.id)?.remove();
    const input = section.querySelector('#' + f.id);
    input?.removeAttribute('aria-describedby');
    input?.removeAttribute('aria-invalid');
  }
  if (busy) form.setAttribute('aria-disabled', 'true'); else form.removeAttribute('aria-disabled');
  const inputs = fields.map((f) => section.querySelector('#' + f.id)).filter(Boolean);
  for (const input of inputs) input.disabled = busy;
  const submit = section.querySelector(submitSel);
  setUnavailable(submit, busy);
  setUnavailable(section.querySelector(backSel), busy);
  const label = t(busy ? 'saving' : idle);
  if (submit && submit.textContent !== label) submit.textContent = label;
  const status = section.querySelector('p.status');
  const said = busy ? t('saving') : '';
  if (status && status.textContent !== said) status.textContent = said;
  const autofocus = busy ? submit : (inputs.find((i) => errors[i.getAttribute('id')]) ?? inputs[0]);
  for (const node of [...inputs, submit]) {
    if (!node) continue;
    if (node === autofocus) node.setAttribute('data-autofocus', ''); else node.removeAttribute('data-autofocus');
  }
  const doc = section.ownerDocument;
  const active = doc?.activeElement;
  if (busy && submit && doc?.documentElement?.contains(section) && (!active || active === doc.body || (form.contains(active) && active !== submit))) {
    submit.focus?.({ preventScroll: true });
  }
}
