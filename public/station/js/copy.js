// Every people-facing string of the Station (docs/design/50-design-station.md §7.6-§7.8, §10), by key. Views call
// t(key, params); no view holds its own sentence. People-facing copy says "tablet", in plain short sentences.
// boot.js repeats the boot-failure strings word for word in its BOOT_COPY (it cannot import this file);
// tests/js/source_rules.test.js checks that both agree.

/** The strings, by key. `{name}` placeholders are filled by t() from its params, as text (never HTML). */
export const COPY = Object.freeze({
  // chrome (views/chrome.js)
  app_name: 'Pet Pantry Station',
  version: 'Version {build}',
  tablet_at: '{label} at {site}',
  online: 'Online',
  offline: 'Offline',
  connecting: 'Connecting…',
  dev_relax: 'Development mode: install checks relaxed.',
  header_missing: "The server isn't receiving this tablet's key, so nothing can upload. Records are kept on this tablet. Tell the Administrator.",
  close_other_window: 'Close the other Station window on this tablet.',
  update_ready: 'An update is ready. It is applied when nobody is using the tablet.',
  about_link: 'About this tablet',
  back: 'Back',

  // starting (views/starting.js)
  starting: 'Starting…',
  checking: 'Checking this tablet…',
  storage_unavailable: "This tablet's browser cannot keep the Station's data. Close other Station windows and try again, or use another tablet.",

  // boot failure (also in boot.js BOOT_COPY, word for word)
  boot_failed: 'The Station could not start. Your records on this tablet are not affected.',
  try_again: 'Try again',
  repair_app: 'Repair the app',
  repairing: 'Repairing the app…',
  repair_offline: 'Repair needs a connection. Your records are safe; try again when the tablet is online.',

  // registration (views/device.js)
  register_title: 'Register this tablet',
  register_intro: 'Scan the square code on the registration sheet, or type the code under it.',
  getting_ready: 'Getting this tablet ready…',
  scan_button: 'Scan the code',
  scan_stop: 'Stop scanning',
  scan_hint: 'Hold the sheet in front of the camera.',
  camera_refused: "The camera can't be used here. Type the code instead.",
  code_label: 'Registration code',
  code_hint: 'It looks like XXXX-XXXX-XXXX-XXXX-X.',
  code_ok: 'The code looks right.',
  register_button: 'Register',
  registering: 'Registering…',
  reg_mistyped: 'Check the code: one of the characters looks wrong.',
  reg_not_installed: 'Open the installed app from the home screen, then scan the code again.',
  reg_invalid: 'This code is not valid. It may have expired or already been used. Ask a Coordinator for a new registration sheet.',
  reg_busy: 'Someone else is changing this tablet right now. Wait a few seconds and try again.',
  reg_no_answer: 'Could not reach the server. Check the Wi-Fi and press Register again.',
  reg_rate_limited: 'Too many tries from this network. Wait 15 minutes, then try again.',
  reg_bad_request: 'The tablet sent an unusable request. Close the app, open it again and scan the code again.',
  reg_maintenance: 'The server is being updated. Try again in a few minutes.',
  reg_failed: 'Something went wrong. Press Register again.',
  reg_selftest_failed: "This tablet's browser cannot keep its keys safely. Update it, or use another tablet.",
  reg_storage_refused: 'This tablet may not keep its data. It will work online only.',
  registered: 'Registered to {site} as {label}.',
  continue: 'Continue',
  server_error: 'Something went wrong on the server. Try again in a minute.',
  incident: 'Problem number: {incident}',

  // lock screen, S2 placeholder (views/login.js; S3 replaces the note with the real form)
  signin_title: 'Sign in',
  username_label: 'Username or email',
  password_label: 'Password',
  signin_button: 'Sign in',
  signin_not_ready: 'Signing in is not ready in this version of the Station.',

  // about (views/about.js)
  about_title: 'About this tablet',
  about_name: 'Tablet name',
  about_site: 'Site',
  about_version: 'Version',
  about_last_contact: 'Last contact with the server',
  about_never: 'Not yet',
  about_key: "Server receives this tablet's key",
  yes: 'Yes',
  no: 'No',
  checking_short: 'Checking…',
  key_unknown: 'Not known',
  not_registered: 'Not registered yet',
  about_unsynced: 'Records not uploaded yet',
  about_clock: 'Tablet clock',
  clock_right: 'Right',
  clock_slow: '{n} seconds slow',
  clock_fast: '{n} seconds fast',
  clock_slow_min: '{n} minutes slow',
  clock_fast_min: '{n} minutes fast',
  clock_unknown: 'Not checked yet',
  about_storage: 'Storage used',
  storage_mb: '{mb} MB',
  storage_unknown: 'Not known',
  about_storage_kept: 'Data kept by the browser',
  check_tablet: 'Check this tablet',
  check_needs_person: 'Someone must be signed in to check this tablet.',
  repair_this_app: 'Repair this app',
  repair_registering: 'This tablet is still registering. Go back and press Register again first.',
  update_now: 'Update now',
  captive_portal: 'The Wi-Fi here may need you to sign in to it first (open a web browser).',
  unknown_tablet: 'The server does not recognise this tablet. Ask a Coordinator.',

  // wipes (views/wipe.js)
  wipe_retiring: 'This tablet has been retired. It is uploading its records, then it will erase itself. Keep the app open and online.',
  wipe_erasing: 'This tablet is being erased.',
  wipe_stuck: 'Retired, but {n} record(s) could not be uploaded, so it has not erased itself. Ask a Coordinator.',
  wipe_confirming: 'Waiting for the server. Keep the app open and online.',
  erased_uploaded: 'This tablet has been erased. Its records were uploaded first.',
  erased_not_uploaded: 'This tablet has been erased without uploading.',
  erased_unknown: 'This tablet has been erased.',
  register_again: 'Register this tablet',

  // two windows (views/elsewhere.js)
  elsewhere: 'The Station is open in another window on this tablet.',
  use_here: 'Use this window here',
  replaced: 'This Station window was replaced by another one. You can close it.',
});

/**
 * The text of a key, with its `{name}` placeholders replaced from params (as text; a placeholder without a param stays
 * as it is). An unknown key returns the key itself, so a missing string is visible (views.test.js fails on it).
 * @param {string} key a key of COPY
 * @param {Object<string, string|number>} [params]
 * @returns {string}
 */
export function t(key, params = {}) {
  const s = COPY[key];
  if (s === undefined) return key;
  return s.replace(/\{([a-z_]+)\}/g, (m, name) => (Object.hasOwn(params, name) ? String(params[name]) : m));
}
