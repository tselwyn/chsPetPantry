// The Station's header, its banners and its footer (S2 spec §4.2). A pure renderer: app.js's chrome controller holds
// the model and re-renders the header in place (it replaces the children of one host element with render()'s nodes).
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
 */

/**
 * The header and the banners that are on.
 * @param {ChromeModel} model
 * @returns {HTMLElement[]} [header.topbar, ...div.banner]
 */
export function render(model) {
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
  return [el('header', { class: 'topbar' }, brand, meta), ...banners];
}

/**
 * The footer after the view: the version and, where About may open, its link (About itself shows Back instead).
 * @param {{build: string, aboutLink: boolean}} options
 * @returns {HTMLElement} footer.footer
 */
export function footer({ build, aboutLink }) {
  return el('footer', { class: 'footer' },
    el('span', {}, t('version', { build })),
    aboutLink ? [' · ', el('a', { class: 'link', link: '#/about' }, t('about_link'))] : null);
}
