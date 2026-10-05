// The Station's header, the person bar (S3 spec §3.4), its banners and its footer (S2 spec §4.2). A pure renderer:
// app.js's chrome controller holds the model and re-renders the header in place (it replaces the children of one host
// element with render()'s nodes).
import { el } from '../dom.js';
import { t } from '../copy.js';

/** The connectivity chip: model.connectivity → its class suffix and its copy key. */
const CHIP = Object.freeze({ online: 'online', offline: 'offline', unknown: 'connecting' });

/** The banners, in the order they show: kind (also the copy key), class and role. */
export const BANNERS = Object.freeze([
  { kind: 'dev_relax', cls: 'banner-danger', role: 'status' },
  { kind: 'header_missing', cls: 'banner-warning', role: 'alert' },
  { kind: 'unknown_tablet', cls: 'banner-warning', role: 'alert' },
  { kind: 'close_other_window', cls: 'banner-warning', role: 'alert' },
  { kind: 'update_ready', cls: 'banner-info', role: 'status' },
]);

/**
 * @typedef {object} ChromeModel
 * @property {string|null} orgName the organisation name (api/session.php), null until known
 * @property {string|null} site the registered tablet's site name
 * @property {string|null} label the registered tablet's label
 * @property {string} build the shell's build
 * @property {'online'|'offline'|'unknown'|'none'} connectivity 'none': this window never asks the server (another
 *   window holds the tablet, or the storage failed), so there is no chip
 * @property {Set<'dev_relax'|'header_missing'|'unknown_tablet'|'close_other_window'|'update_ready'>} banners
 * @property {boolean} [minimal] the wipe screens: only the brand, no banners
 * @property {string|null} [person] the ACTIVE person's name (S3), shown in the person bar
 * @property {'none'|'end'|'both'} [controls] the person bar's buttons (S3): 'end' End shift only, 'both' Switch user
 *   and End shift; 'none' or absent: no person bar
 */
/**
 * @typedef {object} ChromeActions
 * @property {() => void} [onSwitchUser]
 * @property {() => void} [onEndShift]
 */

/**
 * The person bar under the header: the person's name, Switch user and End shift / Lock device. The buttons' ids name
 * them across a re-render, so app.js's header redrawn in place gives the focus back to the same one (dom.js keepFocus()).
 */
function sessionBar(model, actions) {
  if (model.controls !== 'end' && model.controls !== 'both') return null;
  return el('div', { class: 'session-bar' },
    typeof model.person === 'string' && model.person !== '' ? el('span', { class: 'person' }, model.person) : null,
    model.controls === 'both'
      ? el('button', { id: 'switch-user', class: 'button button-secondary', type: 'button', on: { click: () => actions.onSwitchUser?.() } }, t('switch_user'))
      : null,
    el('button', { id: 'end-shift', class: 'button button-secondary', type: 'button', on: { click: () => actions.onEndShift?.() } }, t('end_shift')));
}

/**
 * The header, the person bar (when model.controls asks for one) and the banners that are on.
 * @param {ChromeModel} model
 * @param {ChromeActions} [actions]
 * @returns {HTMLElement[]} [header.topbar, div.session-bar?, ...div.banner]
 */
export function render(model, actions = {}) {
  const brand = el('div', { class: 'brand' },
    el('img', { class: 'brand-icon', image: 'icons/icon-192.png', alt: '', width: 40, height: 40 }),
    el('span', { class: 'brand-name' }, t('app_name')));
  if (model.minimal === true) return [el('header', { class: 'topbar' }, brand)];
  const conn = model.connectivity === 'none' ? null : (Object.hasOwn(CHIP, model.connectivity) ? model.connectivity : 'unknown');
  const meta = el('div', { class: 'topbar-meta' },
    model.orgName ? el('span', { class: 'org' }, model.orgName) : null,
    model.label && model.site ? el('span', { class: 'tablet' }, t('tablet_at', { label: model.label, site: model.site })) : null,
    conn === null ? null : el('span', { class: `chip chip-${conn}`, role: 'status', 'aria-live': 'polite' }, t(CHIP[conn])));
  const banners = BANNERS.filter((b) => model.banners?.has(b.kind))
    .map((b) => el('div', { class: `banner ${b.cls}`, role: b.role }, t(b.kind)));
  const bar = sessionBar(model, actions);
  return [el('header', { class: 'topbar' }, brand, meta), ...(bar ? [bar] : []), ...banners];
}

/**
 * The footer after the view: the version and, where About may open, its link (About itself shows Back instead). The
 * link's id names it across a re-render, so a screen drawn again in place of itself (dom.js remount()) gives it the
 * focus back.
 * @param {{build: string, aboutLink: boolean}} options
 * @returns {HTMLElement} footer.footer
 */
export function footer({ build, aboutLink }) {
  return el('footer', { class: 'footer' },
    el('span', {}, t('version', { build })),
    aboutLink ? [' · ', el('a', { id: 'about-link', class: 'link', link: '#/about' }, t('about_link'))] : null);
}
