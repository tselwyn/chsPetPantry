// Hash routes for the Station (S2 spec §3.12): '#/<view>' → a view, gated by the tablet state. A view that the
// state does not allow falls back to the state's home view (the first of its list). Imports nothing; the hash and
// its hashchange event come through env (js/env.js).

/** Every view the router knows. */
export const ROUTES = Object.freeze(['starting', 'device', 'login', 'about', 'wipe', 'elsewhere']);

/** The views each tablet state allows; the first is the state's home view (the fallback). S3 adds ack, password, pin, home to REGISTERED. */
export const ALLOWED = Object.freeze({
  STARTING: ['starting'], UNREGISTERED: ['device', 'about'], REGISTERED: ['login', 'about'], WIPING: ['wipe'], ERASED: ['wipe'],
  ELSEWHERE: ['elsewhere'], REPLACED: ['elsewhere'], NO_STORAGE: ['starting'],
});

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
 * @param {(name: string) => boolean} options.allowed may this view show in the current tablet state?
 * @param {() => string} options.fallback the view to show instead (the state's home view)
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
