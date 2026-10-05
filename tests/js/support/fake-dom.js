// A small DOM for the Node suites (S2 spec §6.1): enough for js/dom.js, the views and boot.js's failure screen, and
// nothing that parses HTML. innerHTML, outerHTML, insertAdjacentHTML and document.write throw, so any use fails the
// test that reaches it.

const HTML_FORBIDDEN = 'fake-dom: HTML parsing is forbidden';
const forbidden = () => { throw new Error(HTML_FORBIDDEN); };

/** Properties that reflect a boolean attribute (property name → attribute name). */
const REFLECTED = { disabled: 'disabled', hidden: 'hidden', readOnly: 'readonly', autoplay: 'autoplay', playsInline: 'playsinline' };
/** Elements that take focus without a tabindex (when not disabled; <a> only with an href). */
const FOCUSABLE = new Set(['INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'A']);
const DISABLEABLE = new Set(['INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'FIELDSET']);

class FakeText {
  constructor(doc, data) {
    this.ownerDocument = doc;
    this.nodeType = 3;
    this.nodeName = '#text';
    this.parentNode = null;
    this.data = String(data);
  }

  get textContent() { return this.data; }

  set textContent(v) { this.data = String(v ?? ''); }
}

class FakeElement {
  constructor(doc, tag) {
    this.ownerDocument = doc;
    this.nodeType = 1;
    this.tagName = String(tag).toUpperCase();
    this.parentNode = null;
    this.childNodes = [];
    this._attrs = new Map();
    this._listeners = new Map();
    this._props = { value: undefined, checked: undefined, muted: false, srcObject: null };
  }

  get nodeName() { return this.tagName; }

  /** Element children only (as in the DOM); childNodes holds the text nodes too. */
  get children() { return this.childNodes.filter((n) => n.nodeType === 1); }

  get textContent() { return this.childNodes.map((n) => n.textContent).join(''); }

  set textContent(v) {
    const s = String(v ?? '');
    this.replaceChildren(...(s === '' ? [] : [new FakeText(this.ownerDocument, s)]));
  }

  get innerHTML() { return forbidden(); }

  set innerHTML(v) { forbidden(); }

  get outerHTML() { return forbidden(); }

  set outerHTML(v) { forbidden(); }

  insertAdjacentHTML() { forbidden(); }

  get attributes() { return [...this._attrs].map(([name, value]) => ({ name, value })); }

  setAttribute(name, value) { this._attrs.set(String(name).toLowerCase(), String(value)); }

  getAttribute(name) { const v = this._attrs.get(String(name).toLowerCase()); return v === undefined ? null : v; }

  removeAttribute(name) { this._attrs.delete(String(name).toLowerCase()); }

  hasAttribute(name) { return this._attrs.has(String(name).toLowerCase()); }

  get value() { return this._props.value ?? this.getAttribute('value') ?? ''; }

  set value(v) { this._props.value = String(v ?? ''); }

  get checked() { return this._props.checked ?? this.hasAttribute('checked'); }

  set checked(v) { this._props.checked = Boolean(v); }

  get muted() { return this._props.muted; }

  set muted(v) { this._props.muted = Boolean(v); }

  get srcObject() { return this._props.srcObject; }

  set srcObject(v) { this._props.srcObject = v ?? null; }

  /** Strings become text nodes; a node that already has a parent moves. */
  append(...nodes) {
    for (const n of nodes) this._insert(n);
  }

  replaceChildren(...nodes) {
    for (const child of [...this.childNodes]) detach(child);
    this.append(...nodes);
  }

  /**
   * Puts node just before ref, one of this element's children (at the end when ref is null), as in the DOM; a node
   * that already has a parent moves. Returns node.
   */
  insertBefore(node, ref) {
    if (ref !== null && ref !== undefined && ref.parentNode !== this) throw new Error('fake-dom: insertBefore: the reference is not a child');
    this._insert(node, ref ?? null);
    return node;
  }

  /** Takes this element out of its parent (focus inside it goes back to the body, as in a browser). */
  remove() { detach(this); }

  _insert(n, before = null) {
    const node = n instanceof FakeElement || n instanceof FakeText ? n : new FakeText(this.ownerDocument, String(n));
    if (node === this || (node instanceof FakeElement && node.contains(this))) throw new Error('fake-dom: a node cannot contain itself');
    if (node === before) return;
    if (node.parentNode) detach(node);
    node.parentNode = this;
    if (before === null) this.childNodes.push(node);
    else this.childNodes.splice(this.childNodes.indexOf(before), 0, node);
  }

  contains(other) {
    for (let n = other; n; n = n.parentNode) if (n === this) return true;
    return false;
  }

  addEventListener(type, fn) {
    if (typeof fn !== 'function') return;
    if (!this._listeners.has(type)) this._listeners.set(type, []);
    const list = this._listeners.get(type);
    if (!list.includes(fn)) list.push(fn);
  }

  removeEventListener(type, fn) {
    const list = this._listeners.get(type);
    if (list) this._listeners.set(type, list.filter((f) => f !== fn));
  }

  /**
   * A person's action: fires `type` here and bubbles it up (unless init.bubbles is false). A click on a disabled control
   * runs nothing; a click on a submit button (a <button> without another type) inside a form then submits that form,
   * unless a listener called preventDefault(). Returns the event.
   */
  dispatch(type, init = {}) {
    let stopped = false;
    const event = Object.assign({ type, bubbles: true, cancelable: true }, init, {
      target: this,
      currentTarget: null,
      defaultPrevented: false,
      preventDefault() { if (event.cancelable) event.defaultPrevented = true; },
      stopPropagation() { stopped = true; },
    });
    if (type === 'click' && this._disabledControl()) return event;
    for (let node = this; node && !stopped; node = event.bubbles ? node.parentNode : null) {
      if (!(node instanceof FakeElement)) break;
      event.currentTarget = node;
      for (const fn of [...(node._listeners.get(type) ?? [])]) fn.call(node, event);
    }
    event.currentTarget = null;
    if (type === 'click' && !event.defaultPrevented && this.tagName === 'BUTTON' && (this.getAttribute('type') ?? 'submit') === 'submit') {
      const form = this._closest('FORM');
      if (form) form.dispatch('submit');
    }
    // A browser's default action for a submit nobody prevented: the form is sent and the page navigates away (the
    // Station's forms have no action, so it would reload itself mid-press). Recorded, so a test can see it happen.
    if (type === 'submit' && this.tagName === 'FORM' && !event.defaultPrevented) this.ownerDocument.navigations.push({ form: this });
    return event;
  }

  _disabledControl() {
    if (!DISABLEABLE.has(this.tagName)) return false;
    for (let n = this; n instanceof FakeElement; n = n.parentNode) if (DISABLEABLE.has(n.tagName) && n.hasAttribute('disabled')) return true;
    return false;
  }

  _closest(tag) {
    for (let n = this.parentNode; n instanceof FakeElement; n = n.parentNode) if (n.tagName === tag) return n;
    return null;
  }

  /** Focus moves here only when the element is in the document and can take focus (as in a browser). */
  focus() {
    const doc = this.ownerDocument;
    if (!doc.documentElement.contains(this)) return;
    const focusable = this.hasAttribute('tabindex') || (FOCUSABLE.has(this.tagName) && (this.tagName !== 'A' || this.hasAttribute('href')));
    if (!focusable || this._disabledControl()) return;
    doc.activeElement = this;
  }

  /** Selectors: tag, *, #id, .class, [attr], [attr="v"] and their compounds (tag.class), descendant chains, lists. */
  querySelectorAll(selector) {
    const groups = parseSelector(selector);
    const found = [];
    walk(this, (el) => { if (groups.some((chain) => matchesChain(el, chain))) found.push(el); });
    return found;
  }

  querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
}

for (const [prop, attr] of Object.entries(REFLECTED)) {
  Object.defineProperty(FakeElement.prototype, prop, {
    get() { return this.hasAttribute(attr); },
    set(v) { if (v) this.setAttribute(attr, ''); else this.removeAttribute(attr); },
  });
}

/** Removes node from its parent; focus inside it goes back to the body (as when a browser removes the focused element). */
function detach(node) {
  const parent = node.parentNode;
  if (!parent) return;
  parent.childNodes.splice(parent.childNodes.indexOf(node), 1);
  node.parentNode = null;
  const doc = node.ownerDocument;
  if (node instanceof FakeElement && doc.activeElement && node.contains(doc.activeElement)) doc.activeElement = doc.body;
}

/** Calls fn on every element under root (not root itself), in document order. */
function walk(root, fn) {
  for (const child of root.childNodes) {
    if (child instanceof FakeElement) { fn(child); walk(child, fn); }
  }
}

function parseSelector(input) {
  const s = String(input).trim();
  const fail = () => { throw new Error(`fake-dom: unsupported selector ${JSON.stringify(input)}`); };
  const groups = [];
  let chain = [];
  let cur = null;
  let i = 0;
  const ident = () => {
    const m = /^-?[A-Za-z_][\w-]*/.exec(s.slice(i));
    if (!m) fail();
    i += m[0].length;
    return m[0];
  };
  const compound = () => { if (!cur) { cur = { tag: null, id: null, classes: [], attrs: [] }; chain.push(cur); } return cur; };
  while (i < s.length) {
    const ch = s[i];
    if (/\s/.test(ch)) { cur = null; i += 1; continue; }
    if (ch === ',') { if (chain.length === 0) fail(); groups.push(chain); chain = []; cur = null; i += 1; continue; }
    if (ch === '*') { if (cur) fail(); compound(); i += 1; continue; }
    if (ch === '#') { i += 1; compound().id = ident(); continue; }
    if (ch === '.') { i += 1; compound().classes.push(ident()); continue; }
    if (ch === '[') {
      i += 1;
      const name = ident().toLowerCase();
      let value;
      if (s[i] === '=') {
        i += 1;
        const q = s[i];
        if (q === '"' || q === "'") {
          const end = s.indexOf(q, i + 1);
          if (end === -1) fail();
          value = s.slice(i + 1, end);
          i = end + 1;
        } else {
          value = ident();
        }
      }
      if (s[i] !== ']') fail();
      i += 1;
      compound().attrs.push({ name, value });
      continue;
    }
    if (/[A-Za-z]/.test(ch) && !cur) { compound().tag = ident().toUpperCase(); continue; }
    fail();
  }
  if (chain.length === 0) fail();
  groups.push(chain);
  return groups;
}

function matches(el, c) {
  if (c.tag !== null && el.tagName !== c.tag) return false;
  if (c.id !== null && el.getAttribute('id') !== c.id) return false;
  if (c.classes.length > 0) {
    const classes = (el.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);
    if (!c.classes.every((k) => classes.includes(k))) return false;
  }
  return c.attrs.every((a) => (a.value === undefined ? el.hasAttribute(a.name) : el.getAttribute(a.name) === a.value));
}

function matchesChain(el, chain) {
  if (!matches(el, chain[chain.length - 1])) return false;
  let k = chain.length - 2;
  for (let n = el.parentNode; k >= 0 && n instanceof FakeElement; n = n.parentNode) if (matches(n, chain[k])) k -= 1;
  return k < 0;
}

/**
 * A document whose body already holds the shell's root, <main id="app" class="app"> (empty).
 * Members: documentElement, body, createElement, createTextNode, getElementById (the whole tree), activeElement (the
 * body until something takes focus), querySelector/All (from documentElement). document.write throws.
 * Test control: navigations lists every form submit that no listener prevented ({form}), each of which would have
 * navigated the page in a browser.
 */
export function fakeDocument() {
  const doc = {
    activeElement: null,
    navigations: [],
    createElement(tag) { return new FakeElement(doc, tag); },
    createTextNode(text) { return new FakeText(doc, text); },
    getElementById(id) {
      let found = null;
      walk({ childNodes: [doc.documentElement] }, (el) => { if (found === null && el.getAttribute('id') === String(id)) found = el; });
      return found;
    },
    querySelector(selector) { return doc.documentElement.querySelector(selector); },
    querySelectorAll(selector) { return doc.documentElement.querySelectorAll(selector); },
    write: forbidden,
    writeln: forbidden,
  };
  doc.documentElement = new FakeElement(doc, 'html');
  doc.documentElement.setAttribute('lang', 'en');
  const head = new FakeElement(doc, 'head');
  doc.body = new FakeElement(doc, 'body');
  const app = new FakeElement(doc, 'main');
  app.setAttribute('id', 'app');
  app.setAttribute('class', 'app');
  doc.body.append(app);
  doc.documentElement.append(head, doc.body);
  doc.activeElement = doc.body;
  return doc;
}

/** The text content of a node (a text node's data, an element's descendants' text joined). */
export function text(node) {
  return node === null || node === undefined ? '' : node.textContent;
}
