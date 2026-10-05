// textContent-only rendering for the Station (S2 spec §3.11): an allow-list of tags, attributes and properties.
// Strings always become text nodes; nothing is ever parsed as HTML. Links are only "#/<route>" and images only the
// Station's own icons or blob: URLs; href, src, style and every on* attribute are refused.

const TAGS = new Set(['div', 'span', 'p', 'h1', 'h2', 'h3', 'header', 'main', 'section', 'footer', 'nav', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
  'strong', 'em', 'small', 'button', 'form', 'label', 'input', 'output', 'a', 'img', 'video', 'fieldset', 'legend', 'details', 'summary', 'br', 'hr']);
const ATTRS = new Set(['id', 'class', 'role', 'type', 'name', 'for', 'placeholder', 'autocomplete', 'autocapitalize', 'spellcheck', 'inputmode',
  'enterkeyhint', 'maxlength', 'minlength', 'alt', 'width', 'height', 'tabindex', 'title', 'lang', 'dir', 'rows', 'cols']);
const PROPS = new Set(['value', 'disabled', 'hidden', 'checked', 'muted', 'autoplay', 'playsInline', 'readOnly', 'srcObject']);
const FORBIDDEN = /^(on.*|style|innerhtml|outerhtml|srcdoc|formaction|action|href|src)$/i;

let doc = globalThis.document;

/**
 * The document elements are made in. app.js passes env.document; the tests pass tests/js/support/fake-dom.js.
 * @param {Document} d
 */
export function useDocument(d) { doc = d; }

/**
 * el('button', {class: 'button', on: {click: fn}}, t('continue')): strings become text nodes; nothing is ever parsed
 * as HTML. Props: `on` (event listeners), `link` ('#/<route>' → href), `image` ('icons/<name>.png' or 'blob:…' → src),
 * the PROPS properties (set as properties), and the ATTRS, aria-* and data-* attributes (true → '', false → absent).
 * Null and undefined props are skipped; any other prop throws.
 * @param {string} tag an allowed tag name
 * @param {object|null} [props]
 * @param {...(Node|string|number|null|undefined|false|Array)} children nested arrays are flattened; null, undefined
 *   and false are skipped
 * @returns {HTMLElement}
 */
export function el(tag, props = {}, ...children) {
  if (!TAGS.has(tag)) throw new Error(`dom.el: <${tag}> is not allowed`);
  const node = doc.createElement(tag);
  for (const [k, v] of Object.entries(props ?? {})) {
    if (v === null || v === undefined) continue;
    if (k === 'on') { for (const [type, fn] of Object.entries(v)) node.addEventListener(type, fn); continue; }
    if (k === 'link') { if (!/^#\/[a-z_-]*$/.test(v)) throw new Error('dom.el: link must be "#/<route>"'); node.setAttribute('href', v); continue; }
    if (k === 'image') { if (!/^(icons\/[a-z0-9-]+\.png|blob:.+)$/.test(v)) throw new Error('dom.el: image must be a Station icon or a blob: URL'); node.setAttribute('src', v); continue; }
    if (PROPS.has(k)) { node[k] = v; continue; }
    if (FORBIDDEN.test(k)) throw new Error(`dom.el: attribute ${k} is not allowed`);
    if (ATTRS.has(k) || k.startsWith('aria-') || k.startsWith('data-')) { if (v !== false) node.setAttribute(k, v === true ? '' : String(v)); continue; }
    throw new Error(`dom.el: attribute ${k} is not allowed`);
  }
  append(node, children);
  return node;
}

function append(node, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    node.append(typeof c === 'string' || typeof c === 'number' ? doc.createTextNode(String(c)) : c);
  }
}

/**
 * Removes every child of node.
 * @param {Element} node
 */
export function clear(node) { node.replaceChildren(); }

/**
 * Replace root's content and move focus to the first [data-autofocus] that is not disabled, else the first h1 (made
 * focusable with tabindex -1).
 * @param {Element} root
 * @param {...(Node|string|null|undefined|false|Array)} nodes
 */
export function mount(root, ...nodes) {
  root.replaceChildren();
  append(root, nodes);
  autofocus(root);
}

function autofocus(root) {
  const target = [...root.querySelectorAll('[data-autofocus]')].find((n) => n.disabled !== true) ?? root.querySelector('h1');
  if (target) { if (target.tagName === 'H1') target.setAttribute('tabindex', '-1'); target.focus?.({ preventScroll: true }); }
}

/** The attributes that name a control across a re-render: an element with the same value is the same control. */
const NAMES = Object.freeze(['id', 'data-key', 'data-user-id']);

/** The focused control under root, as [attribute, value] (the first of NAMES it has), or null. */
function focusedName(root) {
  const active = (root.ownerDocument ?? doc)?.activeElement;
  if (!active || active === root || !root.contains(active)) return null;
  for (const name of NAMES) {
    const value = active.getAttribute?.(name);
    if (typeof value === 'string' && value !== '') return [name, value];
  }
  return null;
}

/** Focuses the control under root that focusedName() named, when it is there and can take focus. */
function refocus(root, [name, value]) {
  for (const node of root.querySelectorAll(`[${name}]`)) {
    if (node.getAttribute(name) !== value) continue;
    node.focus?.();
    return (root.ownerDocument ?? doc).activeElement === node;
  }
  return false;
}

/**
 * mount() for a view drawn again in place of itself (a header or connection change, a new answer on the same
 * screen): the control that had the focus keeps it when the new content has the same one (the same id, data-key or
 * data-user-id) and it can take focus; otherwise as mount().
 * @param {Element} root
 * @param {...(Node|string|null|undefined|false|Array)} nodes
 */
export function remount(root, ...nodes) {
  const name = focusedName(root);
  root.replaceChildren();
  append(root, nodes);
  if (name !== null && refocus(root, name)) return;
  autofocus(root);
}

/**
 * Runs change(), which re-renders part of root in place (app.js's header), and gives the focus back to the same
 * control (as remount()) when change() replaced the one that had it. Focus elsewhere is left alone.
 * @param {Element} root
 * @param {() => void} change
 */
export function keepFocus(root, change) {
  const d = root.ownerDocument ?? doc;
  const before = d?.activeElement ?? null;
  const name = focusedName(root);
  change();
  if (name === null || (d.activeElement === before && root.contains(before))) return;
  refocus(root, name);
}

/**
 * Marks a control unavailable while it keeps its place in the focus order (aria-disabled="true"), or available again.
 * A sign-in or a save in flight marks its buttons so: the person's focus stays on the button they pressed (a disabled
 * one would drop it), and the button's listener asks unavailable() and does nothing.
 * @param {Element} node
 * @param {boolean} on
 */
export function setUnavailable(node, on) {
  if (!node) return;
  if (on) node.setAttribute('aria-disabled', 'true'); else node.removeAttribute('aria-disabled');
}

/**
 * Is this control marked unavailable by setUnavailable()?
 * @param {Element|null|undefined} node
 * @returns {boolean}
 */
export function unavailable(node) {
  return node?.getAttribute?.('aria-disabled') === 'true';
}
