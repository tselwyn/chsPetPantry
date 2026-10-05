// StationAssets::FILES against the real public/station/ tree, parsed from the PHP source (the twin of
// StationAssetsTest::testEveryStationFileIsListed), so a Station file added without listing it fails the JS suite too;
// and the test commands (package.json's test:js, the README's direct one, the CI job's cap), which must never let a hang
// run until the job is killed.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const STATION = new URL('../../public/station/', import.meta.url);
const ASSETS_PHP = readFileSync(new URL('../../src/Station/StationAssets.php', import.meta.url), 'utf8');

/** The string items of the PHP array constant `name`. */
function phpList(name) {
  const m = new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`).exec(ASSETS_PHP);
  assert.ok(m, `StationAssets.php declares ${name}`);
  return [...m[1].matchAll(/'([^']*)'/g)].map((item) => item[1]);
}

/** StationAssets::TYPES as {extension: family}. */
function phpTypes() {
  const m = /const TYPES = \[([^\]]*)\];/.exec(ASSETS_PHP);
  assert.ok(m, 'StationAssets.php declares TYPES');
  return Object.fromEntries([...m[1].matchAll(/'([^']+)'\s*=>\s*'([^']+)'/g)].map((pair) => [pair[1], pair[2]]));
}

/** Every file under public/station/, relative to it, with / separators. */
function stationFiles() {
  const root = fileURLToPath(STATION);
  return readdirSync(root, { recursive: true })
    .map((path) => String(path).replaceAll('\\', '/'))
    .filter((path) => statSync(root + path).isFile());
}

const byteOrder = (a, b) => Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));

test('every file under public/station/ except index.php and sw.php is in StationAssets::FILES', () => {
  const files = phpList('FILES');
  assert.ok(files.length >= 31, 'the S2 list has 31 entries');
  const unlisted = stationFiles().filter((path) => !['index.php', 'sw.php'].includes(path) && !files.includes(path));
  assert.deepEqual(unlisted, [], 'add these to StationAssets::FILES (they are hashed into the build and precached)');
  assert.ok(!files.includes('index.php') && !files.includes('sw.php'), 'the PHP entry points are hashed apart');
  for (const entry of ['sw.php', 'index.php']) {
    assert.ok(ASSETS_PHP.includes(`'${entry}' => self::read('${entry}'),`), `${entry}'s bytes are an extra input of the build`);
  }
});

test('FILES is sorted by byte value', () => {
  const files = phpList('FILES');
  assert.deepEqual(files, [...files].sort(byteOrder), 'as PHP sort($files, SORT_STRING)');
  assert.equal(new Set(files).size, files.length, 'unique');
  for (const path of files) {
    assert.match(path, /^[a-z0-9_-]+(\/[a-z0-9_-]+)*\.[a-z]+$/, `${path} is relative, lower case, with no . or .. segment`);
  }
});

test('every listed extension has a Content-Type family', () => {
  const types = phpTypes();
  assert.deepEqual(types, { js: 'javascript', css: 'text/css', json: 'json', png: 'image/png' });
  for (const path of phpList('FILES')) {
    const ext = path.slice(path.lastIndexOf('.') + 1).toLowerCase();
    assert.ok(Object.hasOwn(types, ext), `${path}: the service worker has no Content-Type family for .${ext}`);
  }
});

test('npm run test:js gives every test a timeout, so a hung test fails instead of blocking CI', () => {
  // node --test has no default per-test timeout: an await nobody settles would otherwise run until the CI job is killed.
  const pkg = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'));
  const m = /^node --test --test-timeout=(\d+) "tests\/js\/\*\*\/\*\.test\.js"$/.exec(pkg.scripts['test:js']);
  assert.ok(m, pkg.scripts['test:js']);
  assert.ok(Number(m[1]) >= 10000 && Number(m[1]) <= 60000, 'long enough for the slowest test, short enough to fail fast');
});

test('the CI job has a time cap and the README command gives every test a timeout, as test:js does', () => {
  // CRLF is read as LF, so the check means the same on a checkout that did not keep .gitattributes' eol=lf.
  const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8').replace(/\r\n/g, '\n');
  const ci = read('../../.github/workflows/ci.yml');
  const job = /^ {2}station-js:\n((?: {4}.*\n|\n)*)/m.exec(ci);
  assert.ok(job, 'ci.yml has the station-js job');
  const minutes = /^ {4}timeout-minutes: (\d+)$/m.exec(job[1]);
  assert.ok(minutes, 'station-js sets timeout-minutes: a hang outside a test would otherwise run for 6 hours');
  assert.ok(Number(minutes[1]) >= 5 && Number(minutes[1]) <= 30, minutes[1]);
  const commands = [...read('../../README.md').matchAll(/`(node --test[^`]*)`/g)].map((m) => m[1]);
  assert.ok(commands.length > 0, 'the README shows the direct command');
  for (const command of commands) assert.match(command, /^node --test --test-timeout=\d+ "tests\/js\/\*\*\/\*\.test\.js"$/);
});
