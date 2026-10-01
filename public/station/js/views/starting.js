// The Starting view (S2 spec §4.2): shown while the app starts, and with the storage message when the database
// cannot open, with Try again (an installed app has no reload control of its own). A pure renderer.
import { el } from '../dom.js';
import { t } from '../copy.js';

/** phase → copy key. */
const LEAD = Object.freeze({ starting: 'starting', checking: 'checking', no_storage: 'storage_unavailable' });

/**
 * @param {{phase?: 'starting'|'checking'|'no_storage'}} [model]
 * @param {{onRetry?: () => void}} [actions] no_storage: Try again (app.js reloads the page)
 * @returns {HTMLElement} section.view.view-starting
 */
export function render({ phase = 'starting' } = {}, { onRetry } = {}) {
  return el('section', { class: 'view view-starting' },
    el('h1', {}, t('app_name')),
    el('p', { class: 'lead', role: 'status' }, t(LEAD[phase] ?? 'starting')),
    phase === 'no_storage'
      ? el('div', { class: 'actions' },
        el('button', { class: 'button button-primary', type: 'button', on: { click: () => onRetry?.() } }, t('try_again')))
      : null);
}
