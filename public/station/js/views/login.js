// The lock screen (S2 spec §4.2): in S2 a placeholder with the sign-in form disabled. It shows the organisation name,
// "Pet Pantry Station" and the build (50 E2E A2), and "Checking this tablet…" while the start-up heartbeat runs.
// S3 enables the form, adds the picker and replaces the note; the model stays. A pure renderer.
import { el } from '../dom.js';
import { t } from '../copy.js';

/**
 * @typedef {object} LoginModel
 * @property {string|null} orgName
 * @property {string|null} site
 * @property {string|null} label
 * @property {string} build
 * @property {boolean} checking the start-up heartbeat has not answered yet
 */

/**
 * @param {LoginModel} model
 * @param {object} [actions] none in S2 (S3 adds the sign-in and picker actions)
 * @returns {HTMLElement} section.view.view-login
 */
export function render(model, actions = {}) {
  return el('section', { class: 'view view-login' },
    el('h1', {}, model.orgName || t('app_name')),
    el('p', { class: 'lead' }, t('app_name'), ' · ', t('version', { build: model.build })),
    model.label && model.site ? el('p', { class: 'tablet' }, t('tablet_at', { label: model.label, site: model.site })) : null,
    el('form', { class: 'form', 'aria-disabled': 'true', on: { submit: (e) => e.preventDefault() } },
      el('label', { class: 'label', for: 'identifier' }, t('username_label')),
      el('input', { id: 'identifier', class: 'input', autocomplete: 'username', disabled: true }),
      el('label', { class: 'label', for: 'password' }, t('password_label')),
      el('input', { id: 'password', class: 'input', type: 'password', autocomplete: 'current-password', disabled: true }),
      el('button', { class: 'button button-primary', type: 'submit', disabled: true }, t('signin_button'))),
    el('p', { class: 'status', role: 'status' }, t(model.checking ? 'checking' : 'signin_not_ready')));
}
