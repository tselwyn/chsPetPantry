// The S3 screen controllers (S3 spec §3.3): the lock screen (form, picker, PIN pad), the agreement, the forced password
// change, setting a PIN, and home. Like app.js's createDeviceScreen, each owns its model, calls the session (js/session.js,
// whose results carry copy keys, never sentences) and mounts a pure view through mount(section). A generation counter
// makes an answer that arrives after stop() (or after the next show()) draw nothing. No copy here: the views hold every
// string, and this file only names copy keys in the results it builds.
// Focus (S3 review): a new screen or mode is mounted afresh, mount(section): the focus goes to the view's
// [data-autofocus] or its h1. The same screen drawn again with nothing new to read from the top (the lock screen's
// refresh(), a new answer on the PIN pad or the agreement) is mounted with mount(section, {keepFocus: true}): the
// control that had the focus keeps it (dom.js remount()). A PIN digit, a sign-in, PIN switch, acceptance or save
// starting, and home after a connection or header change, change the mounted section in place through the view's
// update() (as S2's views/device.js updateCheck()), so the focus stays where it was and the status lines stay the
// live regions a screen reader already watches. A message is read out (role alert) when it is new: not when the same
// words are drawn again (refresh()), and again after an attempt took it away (patch()).
import * as loginView from './login.js';
import * as ackView from './ack.js';
import * as passwordView from './password.js';
import * as pinSetView from './pin_set.js';
import * as homeView from './home.js';

/**
 * The PIN length the tablet allows: session.config()'s digits clamped to 4..6, as the server's Pin::digitsRange().
 * @param {object} [config] session.config()
 * @returns {{min: number, max: number}}
 */
export function pinRange(config) {
  const int = (v, fallback) => (Number.isInteger(v) ? v : fallback);
  const min = Math.max(4, Math.min(6, int(config?.pin_min_digits, 4)));
  const max = Math.max(min, Math.min(6, int(config?.pin_max_digits, 6)));
  return { min, max };
}

/** The PIN pad's message for too few digits: the range, or the exact count when both ends are the same. */
const shortPin = ({ min, max }) => (min === max ? { key: 'pin_rule_digits_exact', params: { n: min } } : { key: 'pin_rule_digits', params: { min, max } });

/** A copy of a list (an empty one for anything else). */
const list = (v) => (Array.isArray(v) ? [...v] : []);

/**
 * What a session.js Message says, as one string (null for no message): its key or its text, and its params in key
 * order. Two messages with the same content read out the same words.
 * @param {object|null|undefined} m
 * @returns {string|null}
 */
function messageContent(m) {
  if (m === null || typeof m !== 'object') return null;
  const params = m.params !== null && typeof m.params === 'object' ? Object.keys(m.params).sort().map((k) => [k, m.params[k]]) : [];
  return JSON.stringify([m.key ?? null, m.text ?? null, params]);
}
const sameMessage = (a, b) => messageContent(a) === messageContent(b);

/**
 * Re-mounting a form makes new inputs: copy the values of `ids` from the previous section's inputs into the new
 * section's, so the person does not type again what was right. The values stay in the inputs; no model holds them.
 */
function carry(from, to, ids) {
  for (const id of ids) {
    const a = from?.querySelector?.('#' + id);
    const b = to?.querySelector?.('#' + id);
    if (a && b) b.value = a.value;
  }
}

/**
 * The fields to keep after an answer: those without an error, and never the repeat of a refused new password or PIN.
 * @param {string[]} ids the form's field ids, the repeat last
 * @param {Object<string, object>} errors by field id
 * @param {string} fresh the new password's or PIN's id
 */
function keepAfter(ids, errors, fresh) {
  const repeat = ids[ids.length - 1];
  return ids.filter((id) => !errors[id] && !(id === repeat && errors[fresh]));
}

/**
 * The lock screen: the sign-in form (LOCKED), the picker (PICKER, IDLE) and the PIN pad.
 * @param {object} options
 * @param {object} options.session js/session.js's Session (app.js merges it over its LOCKED_SESSION)
 * @param {() => {orgName: string|null, site: string|null, label: string|null, build: string}} options.info
 * @param {(section: HTMLElement, options?: {keepFocus?: boolean}) => void} options.mount draws the section with the
 *   chrome; keepFocus: the same screen drawn again, so the focused control keeps the focus
 * @param {() => boolean} options.checking the start-up heartbeat is still running
 * @returns {{show: () => void, stop: () => void, refresh: () => void, model: () => object|null}}
 */
export function createLoginScreen({ session, info, mount, checking }) {
  let model = null;
  let generation = 0;
  let stopped = true;
  let section = null; // the last section mounted: refresh() reads the typed identifier from it, update() changes it
  // The message and the notice on the screen now: drawn again with the same content (sameMessage()), they are not
  // read out again (no role then). patch() forgets the message when it takes it away, so the same words given by the
  // next answer are read out.
  let shown = { message: null, notice: null };

  const viewModel = () => {
    const i = info?.() ?? {};
    const range = pinRange(session.config?.());
    return {
      orgName: i.orgName ?? null, site: i.site ?? null, label: i.label ?? null, build: i.build ?? '', checking: checking?.() === true,
      mode: model.mode, busy: model.busy, message: model.message, notice: model.notice, identifier: model.identifier, people: model.people,
      messageLive: !sameMessage(model.message, shown.message), noticeLive: !sameMessage(model.notice, shown.notice),
      pin: model.pin ? { name: model.pin.name, entered: model.digits.length } : null, pinMin: range.min, pinMax: range.max,
      canGoBack: model.canGoBack,
    };
  };
  /** Mounts the screen afresh, or (keepFocus) as the same screen drawn again. */
  const draw = (options) => {
    if (model === null || stopped) return;
    section = loginView.render(viewModel(), actions);
    shown = { message: model.message, notice: model.notice };
    mount(section, options);
  };
  /**
   * The mounted section, changed in place: the PIN digits, or an attempt starting. update() takes away a message the
   * model no longer has, so it is no longer shown either.
   */
  const patch = () => {
    if (model === null || stopped || section === null) return;
    loginView.update(section, viewModel());
    if (!model.message) shown = { ...shown, message: null };
  };
  const ready = () => model !== null && !stopped && !model.busy;
  const toForm = (identifier, canGoBack) => {
    model.mode = 'form';
    model.identifier = identifier;
    model.canGoBack = canGoBack;
    model.pin = null;
    model.digits = '';
  };
  const toPicker = () => {
    model.mode = 'picker';
    model.message = null;
    model.canGoBack = false;
    model.pin = null;
    model.digits = '';
  };

  const actions = {
    async onSignIn(identifier, password) {
      if (!ready() || checking?.() === true) return;
      const gen = generation;
      model.busy = true;
      model.identifier = String(identifier ?? '');
      model.message = null;
      patch(); // in place: Signing in… in the status line, the focus on Sign in
      let r;
      try {
        r = await session.signIn(identifier, password);
      } catch {
        r = { ok: false, message: { key: 'signin_failed' } };
      }
      if (gen !== generation) return;
      model.busy = false;
      if (r?.ok !== true) model.message = r?.message ?? null;
      draw(); // afresh: the focus goes to the password; after a success app.js has usually routed away (stop() ran)
    },
    onPick(userId) {
      if (!ready()) return;
      const p = model.people.find((x) => x.user_id === userId);
      if (!p) return;
      model.message = null;
      if (p.has_pin === true) {
        model.mode = 'pin';
        model.pin = { user_id: p.user_id, name: String(p.display_name ?? ''), username: String(p.username ?? '') };
        model.digits = '';
      } else {
        toForm(String(p.username ?? ''), true);
      }
      draw();
    },
    onSomeoneElse() {
      if (!ready()) return;
      model.message = null;
      toForm('', true);
      draw();
    },
    onBackToPicker() {
      if (!ready()) return;
      toPicker();
      draw();
    },
    onDigit(d) {
      if (!ready() || model.mode !== 'pin' || !/^[0-9]$/.test(String(d))) return;
      const { max } = pinRange(session.config?.());
      if (model.digits.length >= max) return;
      model.digits += String(d);
      if (model.digits.length >= max) { void actions.onPinSubmit(); return; } // the last digit sends it
      patch(); // in place: the focus stays on the key, the count is read out, a message shown stays as it is
    },
    onBackspace() {
      if (!ready() || model.mode !== 'pin') return;
      model.digits = model.digits.slice(0, -1);
      patch();
    },
    onPinCancel() {
      if (!ready()) return;
      toPicker();
      draw();
    },
    onUsePassword() {
      if (!ready()) return;
      model.message = null;
      toForm(model.pin?.username ?? '', true);
      draw();
    },
    async onPinSubmit() {
      if (!ready() || model.mode !== 'pin' || model.pin === null) return;
      const range = pinRange(session.config?.());
      if (model.digits.length < range.min) {
        model.message = shortPin(range);
        shown = { ...shown, message: null }; // the answer to this press: read out, even when the words are those shown
        draw({ keepFocus: true });
        return;
      }
      const gen = generation;
      const who = model.pin;
      const digits = model.digits;
      model.busy = true;
      model.message = null;
      patch(); // in place: the last digit's dot, Signing in…, every key unavailable, the focus where it was
      let r;
      try {
        r = await session.pinSwitch(who.user_id, digits);
      } catch {
        r = { ok: false, message: { key: 'signin_failed' } };
      }
      if (gen !== generation) return;
      model.busy = false;
      model.digits = '';
      if (r?.ok !== true) {
        model.message = r?.message ?? null;
        if (r?.next === 'password') {
          // Keys in memory: Back returns to the picker. The form is another mode: mounted afresh.
          toForm(String(r.identifier || who.username || ''), session.state() !== 'LOCKED');
          draw();
          return;
        }
      }
      draw({ keepFocus: true }); // the same pad with its message: the focus stays on the key that sent it
    },
  };

  return {
    show() {
      generation += 1;
      stopped = false;
      model = {
        mode: session.state() === 'LOCKED' ? 'form' : 'picker', notice: session.takeNotice?.() ?? null, identifier: '', message: null,
        canGoBack: false, people: list(session.peopleForPicker?.()), pin: null, digits: '', busy: false,
      };
      shown = { message: null, notice: null };
      draw();
    },
    stop() {
      generation += 1;
      stopped = true;
      if (model !== null) { model.digits = ''; model.busy = false; }
    },
    refresh() {
      if (model === null || stopped) return;
      if (model.mode === 'form') {
        const typed = section?.querySelector?.('#identifier')?.value;
        if (typeof typed === 'string') model.identifier = typed;
      }
      model.people = list(session.peopleForPicker?.());
      draw({ keepFocus: true }); // the same screen: the focused control keeps the focus, a shown message is not read again
    },
    model: () => model,
  };
}

/**
 * The agreement gate: loads the document, then I accept / I do not accept. While an answer is out the section is
 * changed in place (Sending… by the buttons, the focus where it was); a refused acceptance draws its message by the
 * buttons with the focus kept on I accept; a changed wording is a new text, mounted afresh.
 * @param {{session: object, mount: (section: HTMLElement, options?: {keepFocus?: boolean}) => void}} options
 * @returns {{show: () => Promise<void>, stop: () => void, model: () => object|null}}
 */
export function createAckScreen({ session, mount }) {
  let model = null;
  let generation = 0;
  let stopped = true;
  let section = null;
  const viewModel = () => ({ phase: model.phase, doc: model.doc, message: model.message });
  const draw = (options) => {
    if (model === null || stopped) return;
    section = ackView.render(viewModel(), actions);
    mount(section, options);
  };
  const patch = () => {
    if (model === null || stopped || section === null) return;
    ackView.update(section, viewModel());
  };
  const loadPolicy = async () => {
    try {
      return await session.loadPolicy();
    } catch {
      return { ok: false };
    }
  };
  const answerable = () => model !== null && !stopped && model.phase === 'ready' && Boolean(model.doc);

  const actions = {
    async onAccept() {
      if (!answerable()) return;
      const gen = generation;
      model.phase = 'sending';
      model.message = null;
      patch();
      let r;
      try {
        r = await session.acceptPolicy(model.doc);
      } catch {
        r = { ok: false, message: { key: 'signin_failed' } };
      }
      if (gen !== generation) return;
      if (r?.ok !== true && r?.changed === true) {
        // The wording changed while it was read: the new text, with the message, and nothing accepted.
        const l = await loadPolicy();
        if (gen !== generation) return;
        if (l?.ok === true && l.document) {
          model.doc = l.document;
          model.message = r.message ?? { key: 'policy_changed' };
          model.phase = 'ready';
        } else {
          model.message = l?.message ?? { key: 'ack_failed' };
          model.phase = 'error';
        }
        draw(); // a new text: afresh, from its title
        return;
      }
      model.message = r?.ok === true ? null : (r?.message ?? null);
      model.phase = 'ready';
      draw({ keepFocus: true }); // the message by the buttons, the focus on I accept; after a release app.js has routed away
    },
    async onDecline() {
      if (!answerable()) return;
      const gen = generation;
      model.phase = 'declining'; // the session locks the tablet when it is done; until then a press does nothing more
      model.message = null;
      patch();
      try {
        await session.declinePolicy(model.doc);
      } catch {
        // the session locks the tablet whatever the network does
      }
      if (gen !== generation || model === null || stopped) return;
      model.phase = 'ready';
      patch();
    },
    onRetry() { if (model !== null && !stopped && model.phase === 'error') void screen.show(); },
  };

  const screen = {
    async show() {
      const gen = ++generation;
      stopped = false;
      section = null;
      model = { phase: 'loading', doc: null, message: null };
      draw();
      const r = await loadPolicy();
      if (gen !== generation) return;
      if (r?.ok === true && r.document) {
        model.doc = r.document;
        model.phase = 'ready';
      } else {
        model.message = r?.message ?? { key: 'ack_failed' };
        model.phase = 'error';
      }
      draw();
    },
    stop() {
      generation += 1;
      stopped = true;
      section = null;
    },
    model: () => model,
  };
  return screen;
}

/** The password change's field ids, the repeat last. */
const PASSWORD_FIELDS = Object.freeze(['current_password', 'new_password', 'repeat_password']);

/**
 * The forced password change: the repeat is checked here (the endpoint takes two fields); the rest by the session.
 * @param {{session: object, mount: (section: HTMLElement) => void}} options
 * @returns {{show: () => void, stop: () => void, model: () => object|null}}
 */
export function createPasswordScreen({ session, mount }) {
  let model = null;
  let generation = 0;
  let stopped = true;
  let section = null;
  const minLength = () => {
    const n = session.config?.()?.password_min_length;
    return Number.isInteger(n) ? n : 12;
  };
  const draw = (keep = []) => {
    if (model === null || stopped) return;
    const previous = section;
    section = passwordView.render({ busy: model.busy, errors: model.errors, message: model.message, minLength: model.minLength }, actions);
    carry(previous, section, keep);
    mount(section);
  };
  /** A change starting, in place: the values stay in the fields, Save keeps the focus and says Saving…. */
  const patch = () => {
    if (model === null || stopped || section === null) return;
    passwordView.update(section, { busy: model.busy, errors: model.errors, message: model.message, minLength: model.minLength });
  };

  const actions = {
    async onSubmit(current, next, repeat) {
      if (model === null || stopped || model.busy) return;
      if (next !== repeat) {
        model.errors = { repeat_password: { key: 'password_mismatch' } };
        model.message = null;
        draw(keepAfter(PASSWORD_FIELDS, model.errors, 'new_password'));
        return;
      }
      const gen = generation;
      model.busy = true;
      model.errors = {};
      model.message = null;
      patch();
      let r;
      try {
        r = await session.changePassword(current, next);
      } catch {
        r = { ok: false, message: { key: 'signin_failed' } };
      }
      if (gen !== generation) return;
      model.busy = false;
      if (r?.ok === true) {
        model.errors = {};
        model.message = null;
        draw(); // app.js has usually routed on to the agreement or home already
        return;
      }
      model.errors = { ...(r?.errors ?? {}) };
      model.message = r?.message ?? null;
      draw(keepAfter(PASSWORD_FIELDS, model.errors, 'new_password'));
    },
    onCancel() {
      if (model === null || stopped) return;
      void session.cancelGate();
    },
  };

  return {
    show() {
      generation += 1;
      stopped = false;
      section = null;
      model = { busy: false, errors: {}, message: null, minLength: minLength() };
      draw();
    },
    stop() {
      generation += 1;
      stopped = true;
      section = null;
    },
    model: () => model,
  };
}

/** The PIN set's field ids, the repeat last. */
const PIN_FIELDS = Object.freeze(['pin_password', 'pin_new', 'pin_repeat']);

/**
 * Setting a PIN: the session checks the rules (as the server does) and stores the new verifier; the success stays
 * until Continue.
 * @param {{session: object, mount: (section: HTMLElement) => void, onDone: () => void}} options
 * @returns {{show: () => void, stop: () => void, model: () => object|null}}
 */
export function createPinSetScreen({ session, mount, onDone }) {
  let model = null;
  let generation = 0;
  let stopped = true;
  let section = null;
  const draw = (keep = []) => {
    if (model === null || stopped) return;
    const previous = section;
    const range = pinRange(session.config?.());
    section = pinSetView.render({ busy: model.busy, errors: model.errors, message: model.message, done: model.done, pinMin: range.min,
      pinMax: range.max }, actions);
    carry(previous, section, keep);
    mount(section);
  };
  /** A PIN set starting, in place, as the password screen's. */
  const patch = () => {
    if (model === null || stopped || section === null) return;
    pinSetView.update(section, { busy: model.busy, errors: model.errors, message: model.message, done: model.done });
  };

  const actions = {
    async onSubmit(password, pin, confirm) {
      if (model === null || stopped || model.busy || model.done) return;
      const gen = generation;
      model.busy = true;
      model.errors = {};
      model.message = null;
      patch();
      let r;
      try {
        r = await session.setPin(password, pin, confirm);
      } catch {
        r = { ok: false, message: { key: 'signin_failed' } };
      }
      if (gen !== generation) return;
      model.busy = false;
      if (r?.ok === true) {
        model.done = true; // setPin emits nothing, so app.js leaves this screen up until Continue
        draw();
        return;
      }
      model.errors = { ...(r?.errors ?? {}) };
      model.message = r?.message ?? null;
      draw(keepAfter(PIN_FIELDS, model.errors, 'pin_new'));
    },
    onContinue() { if (model !== null && !stopped) onDone?.(); },
    onBack() { if (model !== null && !stopped) onDone?.(); },
  };

  return {
    show() {
      generation += 1;
      stopped = false;
      section = null;
      model = { busy: false, errors: {}, message: null, done: false };
      draw();
    },
    stop() {
      generation += 1;
      stopped = true;
      section = null;
    },
    model: () => model,
  };
}

/**
 * Home: who is signed in, the tablet's warnings and Set a PIN.
 * @param {object} options
 * @param {object} options.session
 * @param {() => {site: string|null, label: string|null}} options.info
 * @param {(section: HTMLElement) => void} options.mount draws the section with the chrome (show() only)
 * @param {() => 'online'|'offline'|'unknown'|'none'} options.connectivity
 * @param {() => void} options.onSetPin
 * @returns {{show: () => void, stop: () => void, refresh: () => void, model: () => object|null}} refresh(): home read
 *   again after a connection or header change (app.js) and changed in place (views/home.js update()), never mounted
 *   again: the focus stays wherever it is (Set a PIN, the title, the footer's About link, the page) and the title is
 *   not read out again
 */
export function createHomeScreen({ session, info, mount, connectivity, onSetPin }) {
  let model = null;
  let stopped = true;
  let section = null; // the section show() mounted: refresh() changes it in place
  const actions = { onSetPin: () => { if (!stopped) onSetPin?.(); } };
  const read = () => {
    const user = session.user?.() ?? null;
    const i = info?.() ?? {};
    return {
      name: String(user?.display_name ?? ''), site: i.site ?? null, label: i.label ?? null, connectivity: connectivity?.() ?? 'unknown',
      hasPin: user?.has_pin === true, pinSwitch: user?.pin_switch === true, canRecord: session.canRecord?.() === true,
      releaseUnavailable: session.releaseUnavailable?.() ?? null,
    };
  };
  return {
    show() {
      stopped = false;
      model = read();
      section = homeView.render(model, actions);
      mount(section);
    },
    refresh() {
      if (stopped || section === null) return;
      model = read();
      homeView.update(section, model, actions);
    },
    stop() {
      stopped = true;
      section = null;
    },
    model: () => model,
  };
}
