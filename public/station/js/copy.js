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

  // the person bar under the header (views/chrome.js)
  switch_user: 'Switch user',
  end_shift: 'End shift / Lock device',

  // lock screen, picker and PIN pad (views/login.js)
  signin_title: 'Sign in',
  username_label: 'Username or email',
  password_label: 'Password',
  signin_button: 'Sign in',
  signing_in: 'Signing in…',
  picker_title: 'Who is using the tablet?',
  someone_else: 'Someone else',
  pin_title: 'Enter your PIN.',
  pin_count: '{n} digits entered',
  pin_count_one: '1 digit entered',
  pin_delete: 'Delete',
  pin_ok: 'OK',
  pin_cancel: 'Cancel',
  use_password: 'Use my password',

  // the confidentiality agreement (views/ack.js)
  ack_title: 'Confidentiality agreement',
  ack_intro: 'Please read the agreement, then choose.',
  ack_version: 'Version {version}',
  ack_accept: 'I accept',
  ack_decline: 'I do not accept',
  ack_loading: 'Loading the agreement…',
  sending: 'Sending…',

  // the forced password change (views/password.js)
  password_title: 'Choose a new password',
  password_intro: 'You need to set a new password before you continue.',
  current_password_label: 'Current password',
  new_password_label: 'New password',
  repeat_password_label: 'New password again',
  password_save: 'Save the new password',
  saving: 'Saving…',
  cancel: 'Cancel',
  password_mismatch: 'The new passwords do not match.',

  // setting a PIN (views/pin_set.js)
  pin_set_title: 'Set a PIN',
  pin_set_intro: 'A PIN lets you switch quickly on this tablet.',
  pin_set_password_label: 'Your password',
  pin_new_label: 'New PIN',
  pin_repeat_label: 'New PIN again',
  pin_set_save: 'Save the PIN',
  pin_set_ok: 'Your PIN is set. Use it to switch quickly on this tablet today.',

  // home (views/home.js)
  home_signed_in: 'Signed in as {name}',
  home_online: 'Working online.',
  home_set_pin_hint: 'Set a PIN to switch quickly on this tablet.',
  set_pin_button: 'Set a PIN',
  test_tablet: 'This is a test tablet without a vault key: people can sign in, but it cannot record.',
  grant_unavailable: 'This tablet cannot record for you right now. Sign in again later.',
  home_empty: 'There are no tasks on this tablet yet.',

  // returned by session.js (its MESSAGE_KEYS; server_error is above). Where the server sends the same sentence, the
  // words are the same (tests/js/copy_keys.test.js compares them).
  signin_missing: 'Enter your username (or email) and password.',
  login_failed: 'That username or password is not correct.',
  account_locked: 'This account is locked. Please try again later or contact an Administrator.',
  account_unusable: 'This account cannot be used at the moment. Please contact an Administrator.',
  no_station_access_site: "You don't have access to {site}, where this tablet is used.",
  no_station_access_role: 'Your role does not use the Station.',
  rate_limited_wait: 'Too many attempts. Please wait a few minutes and try again.',
  signin_no_answer: 'Could not reach the server. Check the Wi-Fi and try again.',
  signin_clock: "This tablet's clock is too far from the server's. Try again.",
  device_proof_invalid: 'This tablet needs to be registered again. Ask a Coordinator.',
  device_site_inactive: "This tablet's site is not active. Ask a Coordinator.",
  device_not_registered: 'This tablet is not registered for use.',
  signin_failed: 'Something went wrong. Try again.',
  pin_wrong_plain: 'That PIN is not correct.',
  pin_wrong_one: 'Wrong PIN. 1 try left before a password is needed.',
  pin_wrong_many: 'Wrong PIN. {n} tries left before a password is needed.',
  pin_locked: 'Too many wrong PINs. Sign in with your password.',
  pin_unavailable: 'Quick switching is not available for this person on this tablet now. Sign in with your password.',
  pin_no_answer: 'Could not reach the server. Sign in with your password when the tablet is online.',
  pin_rule_digits: 'Use {min} to {max} digits.',
  pin_rule_digits_exact: 'Use {n} digits.',
  pin_rule_guessable: 'Choose a PIN that is harder to guess than 1234 or 0000.',
  pin_rule_mismatch: 'The two PINs are different.',
  pin_set_wrong_password: 'That password is not correct.',
  pin_set_password_missing: 'Enter your password.',
  pin_set_offline: 'Setting a PIN needs a connection. Try again when the tablet is online.',
  pin_not_allowed: 'Your role cannot use a PIN on this tablet.',
  policy_changed: 'The agreement was updated while you were reading it. Please read the current version.',
  ack_failed: 'The agreement could not be loaded. Check the Wi-Fi and try again.',
  ack_send_failed: 'Your answer could not be sent. Check the Wi-Fi and try again.',
  ack_declined: 'You need to accept the confidentiality agreement to use the Station. You have been signed out.',
  gate_expired: 'That took too long. Sign in again.',
  password_rule: 'Use at least {n} characters. A short sentence you can remember works well.',
  current_password_wrong: 'The current password is not correct.',
  password_offline: 'Changing your password needs a connection. Try again when the tablet is online.',
  notice_idle: 'The tablet was locked because nobody used it for a while.',
  notice_absolute: 'You have been signed in for a long time. Sign in with your password.',
  notice_signed_out: 'You were signed out. Sign in again to go on.',
  notice_timeout: 'You were signed out after a period of inactivity. Please sign in again.',
  shift_ended: 'The shift has ended on this tablet.',
  grant_expired: 'Your sign-in on this tablet has expired. Sign in with your password.',
  grant_revoked: 'Your sign-in on this tablet was cancelled. Sign in with your password.',

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

/**
 * The text of a session.js Message (S3 spec §3.1): `{text}` is the server's own sentence, shown as it is (as text);
 * `{key, params}` goes through t(). Anything else is '' (nothing to show).
 * @param {{key: string, params?: Object<string, string|number>}|{text: string}|null|undefined} message
 * @returns {string}
 */
export function messageText(message) {
  if (message === null || typeof message !== 'object') return '';
  if (typeof message.text === 'string') return message.text;
  if (typeof message.key === 'string') return t(message.key, message.params ?? {});
  return '';
}

/**
 * The problem-number line of a session.js Message (the server's incident for a server_error): t('incident', …) when
 * params.incident is set, else ''.
 * @param {{params?: {incident?: string|null}}|null|undefined} message
 * @returns {string}
 */
export function incidentText(message) {
  const incident = message?.params?.incident;
  return incident === null || incident === undefined || incident === '' ? '' : t('incident', { incident });
}

/**
 * How many PIN digits are entered, for the PIN pad's status line: "1 digit entered", "3 digits entered".
 * @param {number} n
 * @returns {string}
 */
export function pinCount(n) {
  return n === 1 ? t('pin_count_one') : t('pin_count', { n });
}
