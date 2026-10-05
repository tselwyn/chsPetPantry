// Setting a PIN (S3 spec §3.4; D-22): the person's password, the new PIN twice, the digit rule, each error under the
// field it is about (the error's key is that field's id), and the success with Continue. A pure renderer:
// views/screens.js's createPinSetScreen calls the session. update() starts the wait in place, as the password view's.
// The PIN fields stay type=password (S3 review, security-1): masked on the screen, kept from the keyboard's learning,
// and not read out by a screen reader. A browser may still take this form for a password change and offer to save the
// PIN; no attribute stops that reliably (autocomplete="off" is ignored for passwords), so the managed-tablet rule that
// turns the browser's password manager off is the control (50-design-station.md, P5 runbook).
import { el, unavailable } from '../dom.js';
import { t, messageText, incidentText } from '../copy.js';
import { formUpdate } from './password.js';

/**
 * @typedef {object} PinSetModel
 * @property {boolean} [busy] the PIN set is in flight
 * @property {Object<string, object>} [errors] by field id: pin_password, pin_new, pin_repeat
 * @property {object|null} [message] a session.js Message about the whole form (params.incident adds the problem number)
 * @property {boolean} [done] the PIN is set
 * @property {number} [pinMin]
 * @property {number} [pinMax]
 */
/**
 * @typedef {object} PinSetActions
 * @property {(password: string, pin: string, confirm: string) => void} [onSubmit]
 * @property {() => void} [onBack]
 * @property {() => void} [onContinue]
 */

/** The three fields: id, label key, and whether it takes the PIN's digits. */
export const FIELDS = Object.freeze([
  Object.freeze({ id: 'pin_password', label: 'pin_set_password_label', digits: false }),
  Object.freeze({ id: 'pin_new', label: 'pin_new_label', digits: true }),
  Object.freeze({ id: 'pin_repeat', label: 'pin_repeat_label', digits: true }),
]);

/** The digit rule: "Use 4 to 6 digits." or, when both ends are the same, "Use 5 digits.". */
function rule(min, max) {
  return min === max ? t('pin_rule_digits_exact', { n: min }) : t('pin_rule_digits', { min, max });
}

/**
 * @param {PinSetModel} model
 * @param {PinSetActions} [actions]
 * @returns {HTMLElement} section.view.view-pin-set
 */
export function render(model, actions = {}) {
  const min = model.pinMin ?? 4;
  const max = model.pinMax ?? 6;
  const head = [el('h1', {}, t('pin_set_title')), el('p', { class: 'lead' }, t('pin_set_intro'))];
  if (model.done === true) {
    return el('section', { class: 'view view-pin-set' }, head,
      el('p', { class: 'message message-ok', role: 'status' }, t('pin_set_ok')),
      el('button', { class: 'button button-primary', type: 'button', 'data-autofocus': true, on: { click: () => actions.onContinue?.() } }, t('continue')));
  }
  const errors = model.errors ?? {};
  const inputs = {};
  const fields = FIELDS.map((f) => {
    const error = errors[f.id];
    inputs[f.id] = el('input', {
      id: f.id, class: 'input', type: 'password',
      autocomplete: f.digits ? 'off' : 'current-password', inputmode: f.digits ? 'numeric' : null, maxlength: f.digits ? max : null,
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
        if (form.getAttribute('aria-disabled') === 'true') return; // a PIN set in flight (update() keeps it)
        actions.onSubmit?.(inputs.pin_password.value, inputs.pin_new.value, inputs.pin_repeat.value);
      },
    },
  },
  fields,
  el('button', { id: 'pin-set-save', class: 'button button-primary', type: 'submit' }, t('pin_set_save')));
  const section = el('section', { class: 'view view-pin-set' }, head,
    el('p', { class: 'hint' }, rule(min, max)),
    model.message ? el('p', { class: 'message message-error', role: 'alert', 'data-message': true }, messageText(model.message)) : null,
    incident !== '' ? el('p', { class: 'hint', 'data-message': true }, incident) : null,
    form,
    el('p', { class: 'status', role: 'status' }),
    el('div', { class: 'actions' },
      el('button', { id: 'pin-set-back', class: 'button button-secondary', type: 'button',
        on: { click: (e) => { if (!unavailable(e?.currentTarget)) actions.onBack?.(); } } }, t('back'))));
  update(section, model);
  return section;
}

/**
 * Brings a section render() made to model's busy state in place, as the password view's update(): the fields
 * disabled, Save and Back unavailable with the focus on Save, Saving… in the status line; and the message and
 * errors the model no longer has taken away. A section showing the success has nothing to change.
 * @param {HTMLElement} section
 * @param {PinSetModel} model
 */
export function update(section, model) {
  formUpdate(section, model, { fields: FIELDS, submit: '#pin-set-save', back: '#pin-set-back', idle: 'pin_set_save' });
}
