// The Station's source rules (S2 spec §0.1, §6.2): every .js file under public/station/ is read as text. Rules 1-8 and
// 10 run on the text with comments removed, rule 9 on the raw text; every pattern is exact and case-sensitive. Each
// rule is also run on inline samples (one that must pass, and one or more that must fail), so a weakened pattern is
// noticed.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { STATION_DIR } from './support/fixtures.js';
import { BOOT_COPY } from '../../public/station/boot.js';
import { COPY } from '../../public/station/js/copy.js';

/** Every .js file under public/station/, as {path relative to public/station/: text}. */
function stationFiles() {
  const dir = fileURLToPath(STATION_DIR);
  const out = {};
  for (const entry of readdirSync(dir, { recursive: true, withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.endsWith('.js')) continue;
    const abs = join(entry.parentPath ?? entry.path, entry.name);
    out[relative(dir, abs).split(sep).join('/')] = readFileSync(abs, 'utf8');
  }
  return out;
}

/** Words after which a '/' starts a regular expression, not a division. */
const BEFORE_EXPRESSION = new Set(['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do',
  'else', 'yield', 'await']);

/**
 * The text with its comments removed (§6.2). A small scanner, not a regex, so that '//' or '/*' inside a string,
 * template or regular-expression literal stays (with the code after it), and a quote inside a regex starts nothing.
 * A block comment becomes its own line breaks (or one space), so lines keep their numbers; literals are kept as written.
 * A '/' starts a regex where an operand is expected: at the start, after an operator or an opening bracket, after a
 * keyword such as return, or after a closing brace.
 * @param {string} src
 * @returns {string}
 */
function strip(src) {
  const n = src.length;
  let out = '';
  let i = 0;
  let operand = false;   // the last token ends an operand, so a '/' here divides
  let depth = 0;         // open braces
  const templates = [];  // for each open '${' of a template literal, the brace depth it was opened at
  const template = () => { // from just inside a template literal to its closing backtick or its next '${'
    while (i < n) {
      const c = src[i];
      if (c === '\\') { out += src.slice(i, i + 2); i += 2; continue; }
      if (c === '`') { out += c; i += 1; operand = true; return; }
      if (c === '$' && src[i + 1] === '{') { out += '${'; i += 2; templates.push(depth); depth += 1; operand = false; return; }
      out += c;
      i += 1;
    }
  };
  while (i < n) {
    const c = src[i];
    const next = src[i + 1];
    if (c === '/' && next === '/') { while (i < n && src[i] !== '\n') i += 1; continue; }
    if (c === '/' && next === '*') {
      const close = src.indexOf('*/', i + 2);
      const end = close === -1 ? n : close + 2;
      const breaks = src.slice(i, end).replace(/[^\n]/g, '');
      out += breaks === '' ? ' ' : breaks;
      i = end;
      continue;
    }
    if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < n && src[j] !== c && src[j] !== '\n') j += src[j] === '\\' ? 2 : 1;
      out += src.slice(i, j + 1);
      i = j + 1;
      operand = true;
      continue;
    }
    if (c === '`') { out += c; i += 1; template(); continue; }
    if (c === '/' && !operand) {
      let j = i + 1;
      let inClass = false;
      while (j < n && src[j] !== '\n') {
        if (src[j] === '\\') { j += 2; continue; }
        if (src[j] === '[') inClass = true;
        else if (src[j] === ']') inClass = false;
        else if (src[j] === '/' && !inClass) break;
        j += 1;
      }
      j += 1;
      while (j < n && /[a-z]/.test(src[j])) j += 1; // the flags
      out += src.slice(i, j);
      i = j;
      operand = true;
      continue;
    }
    if (/[\w$]/.test(c)) {
      let j = i;
      while (j < n && /[\w$]/.test(src[j])) j += 1;
      const word = src.slice(i, j);
      out += word;
      i = j;
      operand = !BEFORE_EXPRESSION.has(word);
      continue;
    }
    out += c;
    i += 1;
    if (c === '{') { depth += 1; operand = false; continue; }
    if (c === '}') {
      depth -= 1;
      if (templates.length > 0 && templates[templates.length - 1] === depth) { templates.pop(); template(); continue; }
      operand = false;
      continue;
    }
    if (c === ')' || c === ']') operand = true;
    else if (!/\s/.test(c)) operand = false;
  }
  return out;
}
const base = (path) => path.split('/').pop();

const SINKS = new RegExp([
  /\.(?:innerHTML|outerHTML)\b|\[\s*['"`](?:inner|outer)HTML['"`]\s*\]/.source, // node.innerHTML, node['innerHTML']
  /\binsertAdjacentHTML\b|\bdocument\.write(?:ln)?\b|\b(?:createContextualFragment|setHTMLUnsafe|parseHTMLUnsafe|DOMParser)\b/.source,
  /\beval\s*\(|(?<![\w.$])(?:(?:globalThis|window|self)\.)?Function\s*\(|\bnew\s+Function\b/.source, // with or without new
  /\bset(?:Timeout|Interval)\s*\(\s*['"`]/.source, // a string timer
  /\bsetAttribute(?:NS\s*\([^,()]*,|\s*\()\s*['"`](?:[oO][nN]|[sS][tT][yY][lL][eE]['"`])/.source, // on…= or style=
  /\bcreateElement(?:NS\s*\([^,()]*,|\s*\()\s*['"`](?:[sS][cC][rR][iI][pP][tT]|[sS][tT][yY][lL][eE])['"`]/.source, // <script>, <style>
].join('|'));
const GLOBAL_FETCH = /(?<![\w.])(?:(?:globalThis|window|self)\.)?fetch\s*\(/;
const GLOBAL_FETCH_REF = /(?<![\w.$])(?:globalThis|window|self)\.fetch\b/;
const OTHER_NETWORK = /\b(?:XMLHttpRequest|sendBeacon|WebSocket|EventSource|importScripts)\b/;
const ENV_FETCH = /\benv\.fetch\b/;
const GLOBAL_IDB = /(?<![\w.])(?:(?:globalThis|window|self)\.)?indexedDB\b/;
const ENV_IDB = /\benv\.indexedDB\b/;
const GLOBAL_CACHES = /(?<![\w.])(?:(?:globalThis|window|self)\.)?caches\b/;
const ENV_CACHES = /\benv\.caches\b/;
const DYNAMIC_IMPORT = /\bimport\s*\(/g;
const IMPORTS_BOOT = /\bfrom\s*['"][^'"]*boot\.js['"]/;
const IMPORT_STATEMENT = /^\s*import\b(?!\s*\()/m;
const FROM = /\bfrom\s*['"]/;
const DEV_HOOK = /__pfpms(?!Started)\b/;
const DEV_BEGIN = '// dev-only hooks (D-12): begin';
const DEV_END = '// dev-only hooks (D-12): end';
const DEV_BRANCH = /if\s*\([^)]*\bdev_relax\s*===\s*true[^)]*\)\s*\{\s*$/;
// Built from parts, so this file never holds the header name itself (PageContractTest scans for it).
const CORS_NAME = ['Access', 'Control', 'Allow'].join('-');
const CORS = new RegExp(CORS_NAME, 'i');
const SPECIFIERS = /^\s*(?:import|export)\b[^'"]*?\bfrom\s*['"]([^'"]+)['"]|^\s*import\s*['"]([^'"]+)['"]/gm;
const RELATIVE_JS = /^\.\.?\/[^'"]*\.js$/;

/** A pattern may match only in the files named (by base name); returns the violations. */
function onlyIn(files, pattern, allowed, label) {
  const bad = [];
  for (const [path, src] of Object.entries(files)) {
    if (!allowed.includes(base(path)) && pattern.test(strip(src))) bad.push(`${path}: ${label}`);
  }
  return bad;
}

/** rule → check(files) → the list of violations ([] passes). */
const RULES = {
  sinks: (files) => onlyIn(files, SINKS, [], 'an HTML sink, eval, Function, a string timer, an inline handler or style, or a script element'),
  storage: (files) => [
    ...onlyIn(files, /\blocalStorage\b/, [], 'localStorage'),
    ...onlyIn(files, /\bsessionStorage\b/, ['boot.js'], 'sessionStorage outside boot.js'),
  ],
  network: (files) => [
    ...onlyIn(files, GLOBAL_FETCH, ['env.js', 'boot.js', 'sw-core.js'], 'a global fetch('),
    ...onlyIn(files, GLOBAL_FETCH_REF, ['env.js', 'boot.js', 'sw-core.js'], 'a global fetch taken by reference'),
    ...onlyIn(files, ENV_FETCH, ['api.js'], 'env.fetch outside api.js'),
    ...onlyIn(files, OTHER_NETWORK, [], 'XMLHttpRequest, sendBeacon, WebSocket, EventSource or importScripts'),
  ],
  indexedDb: (files) => [
    ...onlyIn(files, GLOBAL_IDB, ['env.js'], 'a global indexedDB'),
    ...onlyIn(files, ENV_IDB, ['app.js'], 'env.indexedDB outside app.js'),
  ],
  cacheStorage: (files) => [
    ...onlyIn(files, GLOBAL_CACHES, ['env.js', 'boot.js', 'sw-core.js'], 'a global caches'),
    ...onlyIn(files, ENV_CACHES, ['device.js'], 'env.caches outside device.js'),
  ],
  dynamicImport: (files) => {
    const bad = [];
    for (const [path, src] of Object.entries(files)) {
      const s = strip(src);
      const n = (s.match(DYNAMIC_IMPORT) ?? []).length;
      if (n === 0) continue;
      if (base(path) !== 'boot.js') { bad.push(`${path}: import(`); continue; }
      const ok = (s.match(/\bimport\(\s*'\.\/js\/app\.js'\s*\)/g) ?? []).length;
      if (ok !== n) bad.push(`${path}: import( of something other than './js/app.js'`);
    }
    return bad;
  },
  bootImports: (files) => {
    const bad = [];
    for (const [path, src] of Object.entries(files)) {
      const s = strip(src);
      if (base(path) === 'app.js' && IMPORTS_BOOT.test(s)) bad.push(`${path}: imports boot.js`);
      if (base(path) === 'boot.js' && (IMPORT_STATEMENT.test(s) || FROM.test(s))) bad.push(`${path}: imports something`);
    }
    return bad;
  },
  devHooks: (files) => {
    const bad = [];
    for (const [path, src] of Object.entries(files)) {
      if (base(path) !== 'app.js') {
        if (DEV_HOOK.test(strip(src))) bad.push(`${path}: __pfpms outside app.js`);
        if (src.includes(DEV_BEGIN) || src.includes(DEV_END)) bad.push(`${path}: dev-only markers outside app.js`);
        continue;
      }
      const begin = src.indexOf(DEV_BEGIN);
      const end = src.indexOf(DEV_END);
      if (begin === -1 || end === -1 || end < begin || src.indexOf(DEV_BEGIN, begin + 1) !== -1 || src.indexOf(DEV_END, end + 1) !== -1) {
        if (DEV_HOOK.test(strip(src))) bad.push(`${path}: __pfpms without exactly one pair of dev-only markers`);
        continue;
      }
      const before = src.slice(0, begin);
      const after = src.slice(end + DEV_END.length);
      if (DEV_HOOK.test(strip(before)) || DEV_HOOK.test(strip(after))) bad.push(`${path}: __pfpms outside the dev-only markers`);
      if (!DEV_BRANCH.test(before)) bad.push(`${path}: the dev-only markers are not inside the dev_relax branch`);
    }
    return bad;
  },
  cors: (files) => Object.entries(files).filter(([, src]) => CORS.test(src)).map(([path]) => `${path}: a CORS header name`),
  relativeImports: (files) => {
    const bad = [];
    for (const [path, src] of Object.entries(files)) {
      for (const m of strip(src).matchAll(SPECIFIERS)) {
        const spec = m[1] ?? m[2];
        if (!RELATIVE_JS.test(spec)) bad.push(`${path}: import of ${spec}`);
      }
    }
    return bad;
  },
};

const DEV_OK = `if (info?.dev_relax === true) {\n  ${DEV_BEGIN}\n  globalThis.__pfpms = { debug: {} };\n  ${DEV_END}\n}\n`;
/** For every rule: a sample that must pass, then one or more that must fail ({path: text}). */
const SAMPLES = {
  sinks: [
    { 'js/dom.js': "const FORBIDDEN = /^(on.*|style|innerhtml|outerhtml|srcdoc)$/i;\nenv.setTimeout(() => x, 5);\n// node.innerHTML is forbidden\n"
      + "node.setAttribute('href', v);\nline.setAttribute('class', c);\nconst node = doc.createElement(tag);\n/** @type {Function} */\n"
      + "const f = asyncFunction(x);\nconst url = 'http://x/' + y; // a comment after code\n" },
    { 'js/x.js': 'node.innerHTML = x;\n' },
    { 'js/x.js': "node['innerHTML'] = s;\n" },
    { 'js/x.js': 'node[ "outerHTML" ] = s;\n' },
    { 'js/x.js': "const sep = ' //'; node.innerHTML = sep + s;\n" },
    { 'js/x.js': 'const sep = "/*"; node.innerHTML = sep; // */\n' },
    { 'js/x.js': "const r = /['`]\\/\\//g; node.innerHTML = r;\n" },
    { 'js/x.js': 'const t = `a ${b /* c */} // d`; node.innerHTML = t;\n' },
    { 'js/x.js': "node.setAttribute('onclick', 'x()');\n" },
    { 'js/x.js': 'make("div").setAttribute("ONCLICK", h);\n' },
    { 'js/x.js': "node.setAttribute('style', 'color: red');\n" },
    { 'js/x.js': "node.setAttributeNS(null, 'onload', 'x()');\n" },
    { 'js/x.js': "const s = doc.createElement('script'); s.textContent = code;\n" },
    { 'js/x.js': "doc.createElement('style');\n" },
    { 'js/x.js': 'const f = Function(s);\n' },
    { 'js/x.js': 'const f = globalThis.Function(s);\n' },
    { 'js/x.js': 'const f = new Function(s);\n' },
    { 'js/x.js': 'range.createContextualFragment(s);\n' },
    { 'js/x.js': 'node.setHTMLUnsafe(s);\n' },
    { 'js/x.js': 'new DOMParser().parseFromString(s, "text/html");\n' },
    { 'js/x.js': "setTimeout('x()', 5);\n" },
  ],
  storage: [
    { 'boot.js': 'try { storage = win.sessionStorage; } catch {}\n', 'js/x.js': '// localStorage is never used\n' },
    { 'js/x.js': 'const v = sessionStorage.getItem("k");\n' },
  ],
  network: [
    { 'js/env.js': 'fetch: (url, init) => win.fetch(url, init),\nconst f = globalThis.fetch;\n', 'js/api.js': 'res = await env.fetch(base + path, init);\n',
      'js/x.js': "repair({ fetch: b.fetch })\nself.addEventListener('fetch', h);\n" },
    { 'js/device.js': 'const r = await fetch(url);\n' },
    { 'js/device.js': 'const f = globalThis.fetch; await f(url);\n' },
    { 'js/device.js': 'const x = new XMLHttpRequest();\n' },
    { 'js/device.js': 'navigator.sendBeacon(url, body);\n' },
    { 'js/device.js': 'const ws = new WebSocket(url);\n' },
    { 'js/device.js': 'const es = new EventSource(url);\n' },
    { 'sw-core.js': "importScripts('x.js');\n" },
  ],
  indexedDb: [
    { 'js/env.js': 'indexedDB: read(() => win.indexedDB),\n', 'js/app.js': 'db = await openDb(env.indexedDB, {});\n' },
    { 'js/device.js': 'const r = env.indexedDB.deleteDatabase("pfpms");\n' },
  ],
  cacheStorage: [
    { 'js/device.js': 'const store = env.caches;\n', 'sw-core.js': 'var hit = await caches.match(r);\n' },
    { 'js/app.js': 'for (const k of await env.caches.keys()) {}\n' },
  ],
  dynamicImport: [
    { 'boot.js': "importApp: () => import('./js/app.js'),\n", 'js/x.js': "/** @type {import('./env.js').Env} */\nconst x = 1;\n" },
    { 'boot.js': "importApp: () => import('./js/evil.js'),\n" },
  ],
  bootImports: [
    { 'boot.js': "  importApp: () => import('./js/app.js'),\n", 'js/app.js': "import { x } from './env.js';\n" },
    { 'js/app.js': "import { boot } from '../boot.js';\n" },
  ],
  devHooks: [
    { 'js/app.js': DEV_OK, 'boot.js': 'b.global.__pfpmsStarted = () => true;\n' },
    { 'js/app.js': `globalThis.__pfpms = {};\n${DEV_OK}` },
  ],
  cors: [
    { 'js/x.js': '// no cross-origin header is ever sent\n' },
    { 'js/x.js': `// ${['Access', 'Control', 'Allow', 'Origin'].join('-')}: *\n` },
  ],
  relativeImports: [
    { 'js/x.js': "import { a,\n  b } from './x.js';\nexport { c } from \"./views/y.js\";\nimport './side.js';\nexport function f() { return 'from'; }\n" },
    { 'js/x.js': "import { a } from 'https://cdn.example/a.js';\n" },
  ],
};

const FILES = stationFiles();

function check(rule) {
  assert.deepEqual(RULES[rule](FILES), [], `${rule}: the Station's files`);
  const [pass, ...fails] = SAMPLES[rule];
  assert.deepEqual(RULES[rule](pass), [], `${rule}: the passing sample`);
  for (const fail of fails) assert.notDeepEqual(RULES[rule](fail), [], `${rule}: this sample must fail: ${JSON.stringify(fail)}`);
}

test('comments are removed, but never from inside a string, template or regex literal', () => {
  assert.equal(strip("a = '//' + b; // c\n"), "a = '//' + b; \n");
  assert.equal(strip('a = "/* x */"; /* y */ b\n'), 'a = "/* x */";   b\n');
  assert.equal(strip('/**\n * doc\n */\nf();\n'), '\n\n\nf();\n', 'a block comment keeps its line breaks');
  assert.equal(strip("const r = /['`]\\/\\//g; x(); // y\n"), "const r = /['`]\\/\\//g; x(); \n", 'a regex with quotes and slashes');
  assert.equal(strip('const r = /[/]/; z(); // y\n'), 'const r = /[/]/; z(); \n', 'a slash inside a character class');
  assert.equal(strip('const q = a / b; // y\nconst p = (c) / 2; // z\n'), 'const q = a / b; \nconst p = (c) / 2; \n', 'divisions');
  assert.equal(strip('return /x\\/\\//.test(s); // y\n'), 'return /x\\/\\//.test(s); \n', 'a regex after return');
  assert.equal(strip('t = `a // ${b /* c */ + `d // ${e}`} f`; // g\n'), 't = `a // ${b   + `d // ${e}`} f`; \n', 'nested templates');
  assert.equal(strip('if (x) { y(); } /re/.test(z); // w\n'), 'if (x) { y(); } /re/.test(z); \n', 'a regex after a block');
  assert.equal(strip("s = 'it\\'s // fine'; // gone\n"), "s = 'it\\'s // fine'; \n", 'an escaped quote');
  for (const [path, src] of Object.entries(FILES)) {
    assert.equal(strip(src).split('\n').length, src.split('\n').length, `${path}: line count kept`);
  }
});

test('the Station files are all read', () => {
  for (const f of ['boot.js', 'js/app.js', 'js/device.js', 'js/dom.js', 'js/copy.js', 'js/router.js', 'js/env.js', 'js/api.js', 'js/db.js',
    'js/sw-core.js', 'js/views/chrome.js', 'js/views/device.js', 'js/views/about.js', 'js/views/login.js', 'js/views/wipe.js',
    'js/views/elsewhere.js', 'js/views/starting.js']) {
    assert.ok(Object.hasOwn(FILES, f), f);
  }
});

test('no HTML sinks, eval, Function, string timers, inline handlers, style attributes or script elements', () => check('sinks'));
test('no localStorage; sessionStorage only in boot.js', () => check('storage'));
test('the network only through env.js, api.js, boot.js and sw-core.js; no XMLHttpRequest, beacons or sockets', () => check('network'));
test('IndexedDB only through env.js', () => check('indexedDb'));
test('Cache Storage only through env.js', () => check('cacheStorage'));
test('dynamic import only in boot.js, of ./js/app.js', () => check('dynamicImport'));
test('app.js never imports boot.js and boot.js imports nothing', () => check('bootImports'));
test('__pfpms (not __pfpmsStarted) only in app.js between the dev-only markers inside the dev_relax branch', () => {
  check('devHooks');
  assert.notDeepEqual(RULES.devHooks({ 'js/app.js': `if (ready) {\n  ${DEV_BEGIN}\n  globalThis.__pfpms = {};\n  ${DEV_END}\n}\n` }), [],
    'markers outside the dev_relax branch fail');
  assert.notDeepEqual(RULES.devHooks({ 'js/device.js': 'globalThis.__pfpms = {};\n' }), [], 'another file fails');
});
test(`no ${CORS_NAME} anywhere`, () => check('cors'));
test('every import is relative and ends in .js', () => {
  check('relativeImports');
  assert.notDeepEqual(RULES.relativeImports({ 'js/x.js': "import { a } from './a';\n" }), [], 'a missing .js fails');
});
test('BOOT_COPY equals the same keys of COPY', () => {
  assert.deepEqual(Object.keys(BOOT_COPY).sort(), ['boot_failed', 'repair_app', 'repair_offline', 'repairing', 'try_again']);
  for (const [k, v] of Object.entries(BOOT_COPY)) assert.equal(v, COPY[k], k);
  assert.ok(Object.isFrozen(BOOT_COPY));
});
