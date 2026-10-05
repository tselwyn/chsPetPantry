// The copy keys session.js returns (S3 spec §3.5, §4.4): every MESSAGE_KEYS entry is in COPY, the sentences the server
// sends (src/Station/StationAuth.php, src/Station/StationGate.php, src/Auth/Pin.php) are word for word the tablet's
// where both exist, and the S2 placeholder signin_not_ready is gone. The PHP files are read as text: their string
// literals (outside comments) are compared, so a sentence changed on one side only fails here.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { COPY } from '../../public/station/js/copy.js';
import { MESSAGE_KEYS } from '../../public/station/js/session.js';
import { STATION_DIR } from './support/fixtures.js';

const REPO = new URL('../../', import.meta.url);
const php = (path) => readFileSync(new URL(path, REPO), 'utf8');

/**
 * The string literals of a PHP file, outside comments: [{quote: "'" | '"', raw, value}]. raw is the text between the
 * quotes as written (a double-quoted one keeps its $variables); value is the single-quoted literal unescaped (\' and
 * \\), or the double-quoted one with \" \\ \$ \n \t unescaped. Heredocs are not used by these files.
 * @param {string} src
 */
function phpLiterals(src) {
  const out = [];
  const n = src.length;
  let i = 0;
  while (i < n) {
    const c = src[i];
    const next = src[i + 1];
    if ((c === '/' && next === '/') || (c === '#' && next !== '[')) { while (i < n && src[i] !== '\n') i += 1; continue; } // #[ is an attribute
    if (c === '/' && next === '*') { const end = src.indexOf('*/', i + 2); i = end === -1 ? n : end + 2; continue; }
    if (c === "'" || c === '"') {
      let j = i + 1;
      let raw = '';
      while (j < n && src[j] !== c) {
        if (src[j] === '\\' && j + 1 < n) { raw += src.slice(j, j + 2); j += 2; continue; }
        raw += src[j];
        j += 1;
      }
      const value = c === "'"
        ? raw.replace(/\\(['\\])/g, '$1')
        : raw.replace(/\\(["\\$])/g, '$1').replace(/\\n/g, '\n').replace(/\\t/g, '\t');
      out.push({ quote: c, raw, value });
      i = j + 1;
      continue;
    }
    i += 1;
  }
  return out;
}

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Does a literal of these PHP files say exactly COPY[key]? */
function says(literals, key) {
  return literals.some((l) => l.value === COPY[key]);
}

/**
 * Does a double-quoted literal say COPY[key] with each {name} placeholder as a PHP variable ("Use $min to $max digits.")?
 */
function saysPattern(literals, key) {
  const parts = COPY[key].split(/\{[a-z_]+\}/);
  assert.ok(parts.length > 1, `${key} has a placeholder`);
  const re = new RegExp('^' + parts.map(escapeRe).join('\\$[A-Za-z_][A-Za-z0-9_]*') + '$');
  return literals.some((l) => l.quote === '"' && re.test(l.raw));
}

test('every MESSAGE_KEYS entry exists in COPY', () => {
  assert.ok(Object.isFrozen(MESSAGE_KEYS));
  assert.equal(MESSAGE_KEYS.length, 43, 'S3 spec §3.5: these 42, and ack_send_failed (S3 review)');
  assert.equal(new Set(MESSAGE_KEYS).size, MESSAGE_KEYS.length, 'no key twice');
  for (const key of MESSAGE_KEYS) {
    assert.ok(Object.hasOwn(COPY, key), `${key} is in COPY`);
    assert.equal(typeof COPY[key], 'string');
    assert.notEqual(COPY[key].trim(), '', `${key} says something`);
    assert.equal(COPY[key], COPY[key].trim(), `${key} has no stray spaces`);
  }
  // The keys the spec names, a sample of each family (a removed key fails here as well as in session_online).
  for (const key of ['signin_missing', 'signin_no_answer', 'server_error', 'pin_wrong_many', 'pin_no_answer', 'policy_changed', 'gate_expired',
    'password_rule', 'notice_idle', 'shift_ended', 'grant_revoked', 'ack_failed', 'ack_send_failed']) {
    assert.ok(MESSAGE_KEYS.includes(key), key);
  }
  // A refused answer to the agreement says it was not sent; ack_failed stays for a text that did not load (S3 review).
  assert.equal(COPY.ack_send_failed, 'Your answer could not be sent. Check the Wi-Fi and try again.');
  assert.equal(COPY.ack_failed, 'The agreement could not be loaded. Check the Wi-Fi and try again.');
});

test("the server's sentences equal their copy keys", () => {
  const auth = phpLiterals(php('src/Station/StationAuth.php'));
  const gate = phpLiterals(php('src/Station/StationGate.php'));
  const pin = phpLiterals(php('src/Auth/Pin.php'));
  assert.ok(auth.length > 20 && gate.length > 10 && pin.length > 10, 'the three files were read');

  // S3 spec §4.4's list, then the other sentences both sides hold (§2.9).
  const exact = {
    login_failed: auth, account_locked: auth, account_unusable: gate, no_station_access_role: gate, pin_unavailable: auth, pin_locked: auth,
    policy_changed: auth, pin_rule_guessable: pin, pin_rule_mismatch: pin, pin_set_wrong_password: pin, current_password_wrong: auth,
    signin_missing: auth, pin_wrong_plain: auth, device_site_inactive: gate, device_not_registered: gate,
  };
  for (const [key, literals] of Object.entries(exact)) {
    assert.ok(says(literals, key), `${key}: the server says ${JSON.stringify(COPY[key])} word for word`);
  }
  // The sentences with a number in them: the server writes them with a PHP variable where the copy has {n}.
  assert.ok(says(auth, 'pin_wrong_one'), 'pin_wrong_one: "1 try left" is a literal of its own');
  assert.ok(saysPattern(auth, 'pin_wrong_many'), `pin_wrong_many: ${COPY.pin_wrong_many}`);
  assert.ok(saysPattern(pin, 'pin_rule_digits'), `pin_rule_digits: ${COPY.pin_rule_digits}`);
  assert.ok(saysPattern(pin, 'pin_rule_digits_exact'), `pin_rule_digits_exact: ${COPY.pin_rule_digits_exact}`);
  // The site sentence is built around the site's name: its two halves are the copy's, around {site}.
  const [before, after] = COPY.no_station_access_site.split('{site}');
  assert.ok(gate.some((l) => l.value === before), `no_station_access_site: ${JSON.stringify(before)}`);
  assert.ok(gate.some((l) => l.value === after), `no_station_access_site: ${JSON.stringify(after)}`);

  // The reader itself: a literal in a comment is not a sentence, and a changed sentence is noticed.
  const sample = phpLiterals("<?php\n// 'Not this one.'\n/* \"Nor this.\" */\n# 'Nor this either.'\n$a = 'It\\'s here.';\n$b = \"Use $min digits.\";\n"
    + "function f(#[\\SensitiveParameter] string $p = 'After an attribute.') {}\n");
  assert.deepEqual(sample.map((l) => l.value), ["It's here.", 'Use $min digits.', 'After an attribute.']);
  assert.equal(says([{ quote: "'", raw: 'x', value: COPY.login_failed.replace('not', 'no') }], 'login_failed'), false);
  assert.equal(saysPattern([{ quote: '"', raw: 'Use $min to $max digit.', value: '' }], 'pin_rule_digits'), false);
});

test('signin_not_ready is gone and nothing uses it', () => {
  assert.equal(Object.hasOwn(COPY, 'signin_not_ready'), false);
  const dirs = [fileURLToPath(STATION_DIR), fileURLToPath(new URL('../../src/', import.meta.url)), fileURLToPath(new URL('./', import.meta.url))];
  const self = fileURLToPath(import.meta.url);
  const users = [];
  let read = 0;
  for (const dir of dirs) {
    for (const entry of readdirSync(dir, { recursive: true, withFileTypes: true })) {
      if (!entry.isFile() || !/\.(?:js|php|css)$/.test(entry.name)) continue;
      const abs = join(entry.parentPath ?? entry.path, entry.name);
      if (abs === self) continue;
      read += 1;
      if (readFileSync(abs, 'utf8').includes('signin_not_ready')) users.push(abs);
    }
  }
  assert.ok(read > 50, 'the Station, the server code and the JS tests were read');
  assert.deepEqual(users, []);
});
