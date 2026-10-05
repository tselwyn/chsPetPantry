// Two windows (S2 spec §4.2, X-2): this window is not the tablet's primary one. "elsewhere" offers Use this window
// here (it steals the primary lock); "replaced" is the window that lost it, and has no buttons. A pure renderer.
import { el } from '../dom.js';
import { t } from '../copy.js';

/**
 * @param {{phase: 'elsewhere'|'replaced'}} model
 * @param {{onUseHere?: () => void}} [actions]
 * @returns {HTMLElement} section.view.view-elsewhere
 */
export function render({ phase }, { onUseHere } = {}) {
  if (phase === 'replaced') return el('section', { class: 'view view-elsewhere' }, el('h1', {}, t('replaced')));
  return el('section', { class: 'view view-elsewhere' },
    el('h1', {}, t('elsewhere')),
    el('button', { class: 'button button-primary', type: 'button', on: { click: () => onUseHere?.() } }, t('use_here')));
}
