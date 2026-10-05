// About this tablet (S2 spec §4.2): what the tablet knows about itself, Repair this app, and Update now when an
// update waits. app.js gathers the facts (and formats Last contact with env.formatTime()); this view only renders them.
import { el } from '../dom.js';
import { t } from '../copy.js';

/**
 * @typedef {object} AboutModel
 * @property {string|null} label
 * @property {string|null} site
 * @property {string} build
 * @property {string|null} lastContact already formatted; null when there was no contact yet
 * @property {true|false|null|'unregistered'|'unknown'} keyReceived ping's authorization_received; null while the check
 *   runs; 'unknown' when it failed (offline, 5xx) or the answer did not say
 * @property {number} unsynced records not uploaded yet
 * @property {number|null} offsetMs the trusted clock offset (positive: the tablet is behind, "slow")
 * @property {number|null} storageKb
 * @property {boolean} persisted
 * @property {boolean} canCheck S5 enables Check this tablet
 * @property {boolean} updateWaiting
 * @property {null|'repairing'|'offline'|'pending'} repairState 'pending': refused while a registration body is kept
 * @property {boolean} captivePortal the last offline answer was not JSON
 */
/**
 * @typedef {object} AboutActions
 * @property {() => void} onRepair
 * @property {() => void} onUpdateNow
 * @property {() => void} onBack
 */

/**
 * The clock fact: right under 2 s, then seconds under 2 minutes, then minutes; positive offsets are "slow".
 * @param {number|null} offsetMs
 * @returns {string}
 */
export function clockText(offsetMs) {
  if (typeof offsetMs !== 'number' || !Number.isFinite(offsetMs)) return t('clock_unknown');
  const abs = Math.abs(offsetMs);
  if (abs < 2000) return t('clock_right');
  const slow = offsetMs > 0;
  if (abs < 120000) return t(slow ? 'clock_slow' : 'clock_fast', { n: Math.round(abs / 1000) });
  return t(slow ? 'clock_slow_min' : 'clock_fast_min', { n: Math.round(abs / 60000) });
}

function keyText(keyReceived) {
  if (keyReceived === 'unregistered') return t('not_registered');
  if (keyReceived === 'unknown') return t('key_unknown');
  if (keyReceived === true) return t('yes');
  if (keyReceived === false) return t('no');
  return t('checking_short');
}

/** repairState → the status line's copy key. */
const REPAIR_STATUS = Object.freeze({ repairing: 'repairing', offline: 'repair_offline', pending: 'repair_registering' });

function storageText(kb) {
  return typeof kb === 'number' && Number.isFinite(kb) ? t('storage_mb', { mb: (kb / 1024).toFixed(1) }) : t('storage_unknown');
}

const fact = (term, value) => [el('dt', {}, t(term)), el('dd', {}, value)];
const button = (cls, key, onClick, extra = {}) => el('button', { class: cls, type: 'button', on: { click: () => onClick?.() }, ...extra }, t(key));

/**
 * @param {AboutModel} model
 * @param {AboutActions} actions
 * @returns {HTMLElement} section.view.view-about
 */
export function render(model, actions) {
  return el('section', { class: 'view view-about' },
    el('h1', {}, t('about_title')),
    el('dl', { class: 'facts' },
      fact('about_name', model.label ?? t('not_registered')),
      fact('about_site', model.site ?? t('not_registered')),
      fact('about_version', model.build),
      fact('about_last_contact', model.lastContact ?? t('about_never')),
      fact('about_key', keyText(model.keyReceived)),
      fact('about_unsynced', String(model.unsynced ?? 0)),
      fact('about_clock', clockText(model.offsetMs)),
      fact('about_storage', storageText(model.storageKb)),
      fact('about_storage_kept', t(model.persisted ? 'yes' : 'no'))),
    model.captivePortal ? el('p', { class: 'message message-warning' }, t('captive_portal')) : null,
    el('div', { class: 'actions' },
      button('button', 'check_tablet', null, { disabled: model.canCheck !== true }),
      el('p', { class: 'hint' }, t('check_needs_person')),
      button('button', 'repair_this_app', actions.onRepair, { disabled: model.repairState === 'repairing' }),
      model.repairState ? el('p', { class: 'status', role: 'status' }, t(REPAIR_STATUS[model.repairState] ?? 'repair_offline')) : null,
      model.updateWaiting ? button('button button-primary', 'update_now', actions.onUpdateNow) : null,
      button('button button-secondary', 'back', actions.onBack)));
}
