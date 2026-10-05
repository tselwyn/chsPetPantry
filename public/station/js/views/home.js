// Home (S3 spec §3.4; 50 §7.8 "home"): who is signed in, where, whether the tablet works online, the test-tablet or
// no-grant warning, and Set a PIN for someone who can switch with one and has none. A pure renderer:
// views/screens.js's createHomeScreen builds the model from the session.
// update() brings a mounted home to a new model in place (a connection or header change, S3 review): nothing that
// stays is re-made, so the focus stays where the person left it and the title is not read out again.
import { el } from '../dom.js';
import { t } from '../copy.js';

/**
 * @typedef {object} HomeModel
 * @property {string} name the ACTIVE person's display name
 * @property {string|null} site
 * @property {string|null} label
 * @property {'online'|'offline'|'unknown'|'none'} connectivity
 * @property {boolean} hasPin
 * @property {boolean} pinSwitch the person's role may switch with a PIN
 * @property {boolean} canRecord the tablet holds this person's grant and the vault keys
 * @property {null|'no_vault_key'|'no_grant_possible'} releaseUnavailable
 */

/** session.releaseUnavailable() → the warning's copy key. */
const WARNINGS = Object.freeze({ no_vault_key: 'test_tablet', no_grant_possible: 'grant_unavailable' });

/** Home's parts in their order; each is marked data-part, so update() finds it again. */
const PARTS = Object.freeze(['title', 'tablet', 'status', 'warning', 'offer', 'empty']);

/** Each part's node for model (null: that part does not show). */
function parts(model, actions) {
  const warning = Object.hasOwn(WARNINGS, model.releaseUnavailable ?? '') ? WARNINGS[model.releaseUnavailable] : null;
  const offerPin = model.pinSwitch === true && model.canRecord === true && model.hasPin !== true;
  return {
    title: el('h1', { 'data-part': 'title' }, t('home_signed_in', { name: String(model.name ?? '') })),
    tablet: model.label && model.site ? el('p', { class: 'tablet', 'data-part': 'tablet' }, t('tablet_at', { label: model.label, site: model.site })) : null,
    status: model.connectivity === 'online' ? el('p', { class: 'status', role: 'status', 'data-part': 'status' }, t('home_online')) : null,
    warning: warning ? el('p', { class: 'message message-warning', 'data-part': 'warning' }, t(warning)) : null,
    offer: offerPin
      ? el('div', { class: 'actions', 'data-part': 'offer' },
        el('p', { class: 'hint' }, t('home_set_pin_hint')),
        el('button', { id: 'set-pin', class: 'button button-primary', type: 'button', on: { click: () => actions.onSetPin?.() } }, t('set_pin_button')))
      : null,
    empty: el('p', { 'data-part': 'empty' }, t('home_empty')),
  };
}

/**
 * @param {HomeModel} model
 * @param {{onSetPin?: () => void}} [actions]
 * @returns {HTMLElement} section.view.view-home
 */
export function render(model, actions = {}) {
  const p = parts(model, actions);
  return el('section', { class: 'view view-home' }, PARTS.map((name) => p[name]));
}

/**
 * Brings a section render() made to model, in place: a line whose words change keeps its node and takes the new words
 * (the title, the tablet line, the warning), a part that comes or goes is put in or taken out at its place (Working
 * online., the warning, the Set a PIN offer), and a part that stays the same is left alone. So a focused Set a PIN,
 * the focused title and a focus outside the section stay where they are, and nothing is read out again. Only when the
 * part that held the focus goes does the focus move, to the title (as dom.js mount() would place it).
 * @param {HTMLElement} section
 * @param {HomeModel} model
 * @param {{onSetPin?: () => void}} [actions] for a Set a PIN offer put in now
 */
export function update(section, model, actions = {}) {
  const next = parts(model, actions);
  const doc = section.ownerDocument;
  const active = doc?.activeElement ?? null;
  const hadFocus = active !== null && active !== section && section.contains(active);
  const shown = (name) => [...section.children].find((n) => n.getAttribute('data-part') === name) ?? null;
  let following = null; // the node of the part after this one, walking from the last part to the first
  for (const name of [...PARTS].reverse()) {
    const old = shown(name);
    const fresh = next[name];
    if (fresh === null) {
      old?.remove();
      continue;
    }
    if (old === null) {
      section.insertBefore(fresh, following);
      following = fresh;
      continue;
    }
    if (old.textContent !== fresh.textContent) {
      if (old.children.length === 0 && fresh.children.length === 0) {
        old.textContent = fresh.textContent; // a line: the same node, the new words
      } else {
        section.insertBefore(fresh, old);
        old.remove();
        following = fresh;
        continue;
      }
    }
    following = old;
  }
  if (hadFocus && !section.contains(doc.activeElement)) {
    const title = shown('title');
    title?.setAttribute('tabindex', '-1');
    title?.focus?.({ preventScroll: true });
  }
}
