// The confidentiality agreement gate (S3 spec §3.4; 50 §7.8 "ack"): the agreement as text paragraphs, its version,
// I accept and I do not accept. A pure renderer: views/screens.js's createAckScreen loads the document and decides.
// The answer's message and its status line sit just above the two buttons, where the person who read to the end is;
// update() shows Sending… there in place while an answer is out, and the pressed button keeps the focus.
import { el, setUnavailable, unavailable } from '../dom.js';
import { t, messageText, incidentText } from '../copy.js';

/**
 * @typedef {object} AckModel
 * @property {'loading'|'error'|'ready'|'sending'|'declining'} phase sending: I accept is out; declining: I do not
 *   accept is out
 * @property {{document_id: number, version: string, body: string, fingerprint: string}|null} [doc]
 * @property {object|null} [message] a session.js Message (params.incident adds the problem number)
 */
/**
 * @typedef {object} AckActions
 * @property {() => void} [onAccept]
 * @property {() => void} [onDecline]
 * @property {() => void} [onRetry]
 */

/**
 * The agreement's paragraphs: its text split at blank lines (line breaks inside a paragraph are kept by the CSS).
 * @param {unknown} body
 * @returns {string[]}
 */
export function paragraphs(body) {
  return String(body ?? '').replace(/\r\n?/g, '\n').split(/\n[ \t]*\n/).map((p) => p.replace(/^\n+|\n+$/g, '')).filter((p) => p.trim() !== '');
}

/** An error and its problem number; mark: update() may take them away (an answer's message). */
function messageLines(message, mark) {
  if (!message) return null;
  const incident = incidentText(message);
  return [
    el('p', { class: 'message message-error', role: 'alert', 'data-message': mark ? true : null }, messageText(message)),
    incident !== '' ? el('p', { class: 'hint', 'data-message': mark ? true : null }, incident) : null,
  ];
}

/** A button's click, unless the button is marked unavailable (an answer is out). */
const press = (fn) => (e) => { if (!unavailable(e?.currentTarget)) fn(); };

/**
 * @param {AckModel} model
 * @param {AckActions} [actions]
 * @returns {HTMLElement} section.view.view-ack
 */
export function render(model, actions = {}) {
  const head = [el('h1', {}, t('ack_title')), el('p', { class: 'lead' }, t('ack_intro'))];
  let body;
  if (model.phase === 'loading') {
    body = [el('p', { class: 'status', role: 'status' }, t('ack_loading'))];
  } else if (model.phase === 'error' || !model.doc) {
    body = [
      messageLines(model.message ?? { key: 'ack_failed' }, false),
      el('div', { class: 'actions' },
        el('button', { id: 'ack-retry', class: 'button button-primary', type: 'button', on: { click: () => actions.onRetry?.() } }, t('try_again'))),
    ];
  } else {
    body = [
      el('p', { class: 'hint' }, t('ack_version', { version: String(model.doc.version ?? '') })),
      el('div', { class: 'policy-text' }, paragraphs(model.doc.body).map((p) => el('p', {}, p))),
      messageLines(model.message, true),
      el('p', { id: 'ack-status', class: 'status', role: 'status' }),
      el('div', { class: 'actions' },
        el('button', { id: 'ack-accept', class: 'button button-primary', type: 'button', on: { click: press(() => actions.onAccept?.()) } },
          t('ack_accept')),
        el('button', { id: 'ack-decline', class: 'button button-secondary', type: 'button', on: { click: press(() => actions.onDecline?.()) } },
          t('ack_decline'))),
    ];
  }
  const section = el('section', { class: 'view view-ack' }, head, body);
  update(section, model);
  return section;
}

/** Sets a node's text only when it changes, so a live region is not read out again for the same words. */
function setText(node, text) {
  if (node && node.textContent !== text) node.textContent = text;
}

/**
 * Brings a ready, sending or declining section to model's phase in place (render() ends with it): while an answer is
 * out both buttons are unavailable but keep the focus where it was, the pressed one and the status line say
 * Sending…, and an earlier message the model no longer has goes. A new message or document needs render().
 * @param {HTMLElement} section
 * @param {AckModel} model
 */
export function update(section, model) {
  const accept = section.querySelector('#ack-accept');
  const decline = section.querySelector('#ack-decline');
  if (!accept || !decline) return; // loading or error: nothing changes in place
  const sending = model.phase === 'sending';
  const declining = model.phase === 'declining';
  if (!model.message) for (const node of section.querySelectorAll('[data-message]')) node.remove();
  setText(accept, t(sending ? 'sending' : 'ack_accept'));
  setText(decline, t(declining ? 'sending' : 'ack_decline'));
  setUnavailable(accept, sending || declining);
  setUnavailable(decline, sending || declining);
  setText(section.querySelector('#ack-status'), sending || declining ? t('sending') : '');
}
