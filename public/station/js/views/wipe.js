// The wipe screens (S2 spec §4.2, 50 §7.6): retired and uploading, being erased, stuck, waiting for the server, a
// blocked delete, and erased with Register this tablet. A pure renderer; device.js drives the phases through app.js.
import { el } from '../dom.js';
import { t } from '../copy.js';

/**
 * @typedef {object} WipeModel
 * @property {'retiring'|'erasing'|'stuck'|'confirming'|'blocked'|'erased'} phase
 * @property {'Push Then Wipe'|'Wipe Now'} [mode]
 * @property {number} [n] records that could not be uploaded (stuck)
 * @property {'uploaded'|'not_uploaded'|'unknown'} [final] the erased screen
 * @property {string} [previous] blocked: the phase whose heading stays on screen (app.js keeps it)
 */

const FINAL = Object.freeze({ uploaded: 'erased_uploaded', not_uploaded: 'erased_not_uploaded', unknown: 'erased_unknown' });

function heading(phase, model) {
  switch (phase) {
    case 'retiring': return t('wipe_retiring');
    case 'stuck': return t('wipe_stuck', { n: model.n ?? 0 });
    case 'confirming': return t(model.mode === 'Wipe Now' ? 'wipe_erasing' : 'wipe_retiring');
    default: return t('wipe_erasing'); // erasing, and a blocked delete with no earlier heading
  }
}

/**
 * @param {WipeModel} model
 * @param {{onRegisterAgain?: () => void}} [actions]
 * @returns {HTMLElement} section.view.view-wipe
 */
export function render(model, actions = {}) {
  let content;
  switch (model.phase) {
    case 'erased':
      content = [
        el('h1', {}, t(FINAL[model.final] ?? 'erased_unknown')),
        el('button', { class: 'button button-primary', type: 'button', on: { click: () => actions.onRegisterAgain?.() } }, t('register_again')),
      ];
      break;
    case 'blocked':
      content = [
        el('h1', {}, heading(model.previous ?? 'erasing', model)),
        el('p', { class: 'message message-warning', role: 'alert' }, t('close_other_window')),
      ];
      break;
    case 'confirming':
      content = [el('h1', {}, heading('confirming', model)), el('p', { class: 'status', role: 'status' }, t('wipe_confirming'))];
      break;
    default:
      content = [el('h1', {}, heading(model.phase, model))];
  }
  return el('section', { class: 'view view-wipe' }, content);
}
