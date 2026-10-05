// Test support (S3 spec §4.3): a stand-in for js/session.js's Session, over plain fields, for the screen controllers
// (views/screens.js) and anything else that only reads the session and calls it. Not a suite (no .test.js suffix).
//
// fakeSession(over) → every Session member of S3 spec §3.1:
// - the getters read plain fields: state, gate, gateUser, user, mode, capabilities, hasVaultKey, canRecord,
//   releaseUnavailable, config (merged over CONFIG), busy, notice (takeNotice() returns it once), people
//   (peopleForPicker()), unlockDelaySeconds; `over` sets any of them, and a function in `over` replaces that member;
// - the async methods resolve the next result queued for them (s.queue.signIn = [{ok: false, …}, …]); a queued promise
//   is returned as it is (an answer the test holds), a queued function is called with the method's arguments (the only
//   way a test sees a password or a PIN) and its value returned. Methods that give a Result default to {ok: true},
//   the others to undefined;
// - s.calls lists every action as [name, …its non-secret arguments]: ['signIn', identifier], ['pinSwitch', userId],
//   ['acceptPolicy', document_id], never a password or a PIN;
// - s.emit(event, ...args) calls the listeners on() registered; s.set(field, value) changes a field (config is merged
//   over CONFIG), without emitting anything.

/** session.config()'s defaults (S3 spec §3.1). */
export const CONFIG = Object.freeze({
  session_idle_minutes: 30, session_absolute_hours: 12, pin_min_digits: 4, pin_max_digits: 6, pin_max_failed: 3, pin_shift_hours: 12,
  offline_grant_hours: 72, password_min_length: 12,
});

/** The Session's fields and their defaults. */
const FIELDS = Object.freeze({
  state: 'LOCKED', gate: null, gateUser: null, user: null, mode: null, capabilities: [], hasVaultKey: false, canRecord: false,
  releaseUnavailable: null, config: CONFIG, busy: false, notice: null, people: [], unlockDelaySeconds: 0,
});

/** The async methods that give a Result ({ok: true} unless a test queues another), with what .calls keeps of their arguments. */
const RESULT_METHODS = Object.freeze({
  signIn: (identifier) => [identifier],
  loadPolicy: () => [],
  acceptPolicy: (doc) => [doc?.document_id],
  declinePolicy: (doc) => [doc?.document_id],
  changePassword: () => [],
  pinSwitch: (userId) => [userId],
  setPin: () => [],
});
/** The async methods that give nothing. */
const VOID_METHODS = Object.freeze({
  cancelGate: () => [],
  endShift: () => [],
  replayShiftEnd: () => [],
  onRevokedGrants: (ids) => [ids],
  onOfflineDisabled: () => [],
});
/** The plain (not async) actions. */
const SYNC_METHODS = Object.freeze({
  switchUser: () => [],
  lock: (kind, reason) => [kind, reason],
  touch: () => [],
  tick: () => [],
  onConfig: () => [],
});

const copy = (v) => (Array.isArray(v) ? v.map(copy) : v !== null && typeof v === 'object' ? { ...v } : v);

/**
 * @param {object} [over] field values ({state: 'PICKER', people: [...]}) and member replacements (functions)
 * @returns {object} the Session, plus queue, calls, emit(), set() and listeners
 */
export function fakeSession(over = {}) {
  const fields = { ...FIELDS };
  const replaced = {};
  for (const [k, v] of Object.entries(over)) {
    if (typeof v === 'function') replaced[k] = v;
    else fields[k] = k === 'config' ? { ...CONFIG, ...v } : v;
  }
  const listeners = new Map();
  const calls = [];
  const queue = {};
  for (const name of [...Object.keys(RESULT_METHODS), ...Object.keys(VOID_METHODS), ...Object.keys(SYNC_METHODS)]) queue[name] = [];

  const answer = (name, args, fallback) => {
    if (queue[name].length === 0) return fallback;
    const next = queue[name].shift();
    return typeof next === 'function' ? next(...args) : next;
  };

  const s = {
    // ---- getters ----
    state: () => fields.state,
    gate: () => fields.gate,
    gateUser: () => copy(fields.gateUser),
    user: () => copy(fields.user),
    mode: () => fields.mode,
    capabilities: () => copy(fields.capabilities),
    hasVaultKey: () => fields.hasVaultKey,
    canRecord: () => fields.canRecord,
    releaseUnavailable: () => fields.releaseUnavailable,
    config: () => ({ ...fields.config }),
    busy: () => fields.busy,
    takeNotice: () => { const n = fields.notice; fields.notice = null; return n; },
    peopleForPicker: () => copy(fields.people),
    unlockDelaySeconds: () => fields.unlockDelaySeconds,
    on(event, cb) {
      if (!listeners.has(event)) listeners.set(event, []);
      listeners.get(event).push(cb);
      return () => { listeners.set(event, (listeners.get(event) ?? []).filter((f) => f !== cb)); };
    },
    // ---- test controls ----
    queue,
    calls,
    listeners,
    emit(event, ...args) { for (const cb of [...(listeners.get(event) ?? [])]) cb(...args); },
    set(field, value) { fields[field] = field === 'config' ? { ...CONFIG, ...value } : value; },
  };
  for (const [name, keep] of Object.entries(RESULT_METHODS)) {
    s[name] = async (...args) => { calls.push([name, ...keep(...args)]); return answer(name, args, { ok: true }); };
  }
  for (const [name, keep] of Object.entries(VOID_METHODS)) {
    s[name] = async (...args) => { calls.push([name, ...keep(...args)]); return answer(name, args, undefined); };
  }
  for (const [name, keep] of Object.entries(SYNC_METHODS)) {
    s[name] = (...args) => { calls.push([name, ...keep(...args)]); return answer(name, args, undefined); };
  }
  Object.assign(s, replaced);
  return s;
}
