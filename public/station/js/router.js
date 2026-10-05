// Hash routes for the Station (S2 spec §3.12, S3 spec §3.8): '#/<view>' → a view, gated by the tablet state and, on a
// registered tablet, by the session's state and gate. A view that is not allowed falls back to the home view (the
// first of its list). Imports nothing; the hash and its hashchange event come through env (js/env.js).

/** Every view the router knows. */
export const ROUTES = Object.freeze(['starting', 'device', 'login', 'home', 'ack', 'password', 'pin', 'about', 'wipe', 'elsewhere']);

/** The views each tablet state allows; the first is the state's home view (the fallback). */
export const ALLOWED = Object.freeze({
  STARTING: ['starting'], UNREGISTERED: ['device', 'about'], REGISTERED: ['login', 'home', 'ack', 'password', 'pin', 'about'], WIPING: ['wipe'],
  ERASED: ['wipe'], ELSEWHERE: ['elsewhere'], REPLACED: ['elsewhere'], NO_STORAGE: ['starting'],
});

/** Inside REGISTERED, the views each session state allows (about always); the first is its home. GATE: its gate's view only. */
export const SESSION_VIEWS = Object.freeze({ LOCKED: ['login'], PICKER: ['login'], IDLE: ['login'], ACTIVE: ['home', 'pin'], GATE: [] });

/** At a gate, the one view it shows. */
export const GATE_VIEWS = Object.freeze({ policy_ack: 'ack', password_change: 'password' });

/**
 * Whether a view may show in a tablet state.
 * @param {string} state
 * @param {string} name
 * @returns {boolean}
 */
export const allowedIn = (state, name) => (ALLOWED[state] ?? []).includes(name);

/**
 * The state's home view (its fallback); 'starting' for an unknown state.
 * @param {string} state
 * @returns {string}
 */
export const homeOf = (state) => (ALLOWED[state] ?? ['starting'])[0];

/**
 * On a registered tablet, the session's home view: its gate's view at a gate ('login' for an unknown gate), else the
 * first view its state allows ('login' for an unknown state).
 * @param {string} state session.state()
 * @param {string|null} gate session.gate()
 * @returns {string}
 */
export const sessionHome = (state, gate) => (state === 'GATE' ? (GATE_VIEWS[gate] ?? 'login') : (SESSION_VIEWS[state] ?? ['login'])[0]);

/**
 * On a registered tablet, whether the session's state and gate allow a view (About always).
 * @param {string} state
 * @param {string|null} gate
 * @param {string} name
 * @returns {boolean}
 */
export const sessionAllows = (state, gate, name) => name === 'about'
  || (state === 'GATE' ? GATE_VIEWS[gate] === name : (SESSION_VIEWS[state] ?? ['login']).includes(name));

/**
 * '#/about' → 'about'; anything else → null.
 * @param {string|null|undefined} hash
 * @returns {string|null}
 */
export function parseHash(hash) { const m = /^#\/([a-z_-]+)$/.exec(hash ?? ''); return m && ROUTES.includes(m[1]) ? m[1] : null; }

/**
 * @typedef {object} Router
 * @property {(name: string) => void} go set the hash to '#/<name>' and show the view (the hashchange that follows
 *   does not render again)
 * @property {(name: string|null) => void} show show the view, or the fallback when it is not allowed
 * @property {() => string|null} current the view shown last
 * @property {() => void} start listen for hashchange and show the view of the current hash
 * @property {() => void} stop stop listening
 */

/**
 * @param {object} options
 * @param {{hash: () => string, setHash: (h: string) => void, on: Function}} options.env the Env of js/env.js
 * @param {(name: string) => void} options.render draws that view now
 * @param {(name: string) => boolean} options.allowed may this view show now (the tablet state, and the session's)?
 * @param {() => string} options.fallback the view to show instead (the home view)
 * @returns {Router}
 */
export function createRouter({ env, render, allowed, fallback }) {
  let current = null;
  let off = null;
  const show = (name) => { const target = name !== null && allowed(name) ? name : fallback(); current = target; render(target); };
  return {
    go(name) { if (env.hash() !== '#/' + name) env.setHash('#/' + name); show(name); }, // the hashchange that follows is a no-op re-render guard
    show,
    current: () => current,
    start() { off = env.on('hashchange', () => { const n = parseHash(env.hash()); if (n !== current) show(n); }); show(parseHash(env.hash())); },
    stop() { off?.(); off = null; },
  };
}
