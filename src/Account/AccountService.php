<?php
declare(strict_types=1);

namespace Pfpms\Account;

use PDOException;
use Pfpms\Audit\Audit;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Mail\Mailer;
use Pfpms\Reference\SiteRepository;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Manage User Accounts (UC-11).
 *
 * - New accounts are invitations: the account is Pending and the person chooses their own
 *   password through a single-use link valid for temp_credential_hours (72 h, §4.2). Nobody
 *   types or sees another person's password. An Administrator reset replaces the password with
 *   an unusable one and sends a new link, so a stolen password stops working at once.
 * - Only one link that can set the password is live at a time; a new link, a password change,
 *   an email correction or a deactivation cancels the others.
 * - Least privilege by role and sites. A Volunteer may hold at most volunteer_max_sites sites
 *   (§3.3.2); Volunteers and Coordinators need at least one. Administrators and Board members
 *   see every site through their role, so they hold no site grants.
 * - There is always at least one Active Administrator with no end date and no deactivation
 *   scheduled (§3.3.3), checked again under lock when the change is written. Nobody changes
 *   their own role, sites, dates, email or status, or resets their own sign-in (§3.3.4); every
 *   refusal is audited.
 * - Any change to role, sites, dates, email or the export permission ends the person's sessions
 *   at once (§4.4).
 * - Deactivation never deletes anything: attribution in history stays (§3.2.3).
 * - Account dates are organisation dates (Clock::orgToday), not UTC dates.
 */
final class AccountService
{
    public const DETAIL_FIELDS = ['first_name', 'last_name', 'email', 'phone', 'role', 'start_date', 'expiry_date',
        'onboarding_completed_at', 'can_extract_identifiable'];
    /** Changes that alter what the person can reach, so they need a reason and their sessions end. */
    private const ACCESS_FIELDS = ['role', 'email', 'start_date', 'expiry_date', 'can_extract_identifiable'];
    private const LAST_ADMIN = 'This is the only Administrator with no end date. Make someone else an Administrator, with no end date, first.';

    /**
     * Create a Pending account and send the invitation.
     * @return array{user_id: int, mailed: bool} mailed is false when the email could not go now (it is queued for retry)
     * @throws ValidationException with every problem at once
     */
    public static function invite(array $input, array $siteIds, int $actorId): array
    {
        $errors = [];
        $v = self::validateDetails($input, null, $errors);
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            $errors['username'] = 'Use 3 to 50 letters, digits, dots, dashes or underscores.';
        } elseif (AccountRepository::usernameTaken($username)) {
            $errors['username'] = 'That username is already taken.';
        }
        $sites = $v['role'] === null ? [] : self::validateSites($v['role'], $siteIds, [], $errors);
        if ($errors) {
            throw new ValidationException($errors);
        }
        $v['username'] = $username;
        $v['created_by'] = $actorId;
        // An unusable random password until the person chooses one; hashed like a real one so a
        // sign-in attempt against a Pending account takes as long as any other.
        $v['password_hash'] = self::unusablePasswordHash();

        try {
            [$userId, $token] = Db::transaction(function () use ($v, $sites, $actorId): array {
                $userId = AccountRepository::insert($v);
                foreach ($sites as $siteId) {
                    AccountRepository::grantSite($userId, $siteId, $actorId, 'Account created');
                }
                $token = self::newActivationToken($userId);
                Audit::record('user_create', 'user_account', $userId, details: [
                    'username' => $v['username'], 'role' => $v['role'], 'site_ids' => $sites, 'email' => $v['email'],
                ]);
                return [$userId, $token];
            });
        } catch (PDOException $e) {
            throw self::duplicate($e, $username) ?? $e;
        }
        return ['user_id' => $userId, 'mailed' => self::sendInvitation($userId, $token)];
    }

    /**
     * Save details, role and sites. A change of access (role, sites, dates, email, export
     * permission) needs a reason (§3.2.1) and ends the person's sessions. Correcting the email
     * cancels links sent to the old address; a Pending account gets a new invitation.
     * @return array{changes: array<string, array{0:mixed,1:mixed}>, mailed: ?bool}
     *   changes is empty when nothing changed; mailed says whether the new invitation went (null: none was due)
     * @throws ValidationException
     * @throws StaleAccountException when someone else saved the account first
     */
    public static function update(int $userId, array $input, array $siteIds, int $rowVersion, int $actorId): array
    {
        $before = self::load($userId);
        $errors = [];
        $v = self::validateDetails($input, $userId, $errors);
        $currentSites = AccountRepository::standingSiteIds($userId);
        $sites = $v['role'] === null ? $currentSites : self::validateSites($v['role'], $siteIds, $currentSites, $errors);
        $changes = Audit::diff($before, $v, array_values(array_diff(self::DETAIL_FIELDS, array_keys($errors))));
        $sitesChanged = !isset($errors['sites']) && $sites !== $currentSites;
        if ($sitesChanged) {
            $changes['site_ids'] = [implode(',', $currentSites), implode(',', $sites)];
        }
        if (!$changes && !$errors) {
            return ['changes' => [], 'mailed' => null];
        }
        $accessChanged = $sitesChanged || array_intersect(array_keys($changes), self::ACCESS_FIELDS);
        if ($userId === $actorId && $accessChanged) {
            Audit::durable('user_update', 'user_account', $userId, 'Denied', 'Tried to change own role, sites, dates, email or export permission');
            throw ValidationException::one('_form', 'You cannot change your own role, sites, dates, email or export permission. Ask another Administrator.');
        }
        $reason = Validator::text($input['reason'] ?? null, 255);
        if ($accessChanged && $reason === null) {
            $errors['reason'] = 'Say why the role, sites, dates, email or export permission are changing. It is kept in the audit log.';
        }
        if ($v['role'] !== null && !isset($errors['expiry_date'])) {
            self::guardLastAdministrator($before, $v['role'], $v['expiry_date'], $errors, 'user_update');
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $emailChanged = isset($changes['email']);
        try {
            $token = Db::transaction(function () use ($userId, $before, $v, $sites, $currentSites, $sitesChanged, $rowVersion, $changes, $accessChanged, $emailChanged, $reason, $actorId): ?string {
                self::recheckLastAdministrator($before, $v['role'], $v['expiry_date']);
                $columns = array_intersect_key($v, array_flip(self::DETAIL_FIELDS));
                if (!Db::updateVersioned('user_account', 'user_id', $userId, $rowVersion, $columns)) {
                    throw new StaleAccountException();
                }
                if ($sitesChanged) {
                    AccountRepository::endStandingGrants($userId, array_diff($currentSites, $sites));
                    foreach (array_diff($sites, $currentSites) as $siteId) {
                        AccountRepository::grantSite($userId, $siteId, $actorId, $reason);
                    }
                }
                Audit::record('user_update', 'user_account', $userId, reason: $reason, changes: $changes);
                if ($accessChanged) {
                    Tokens::revokeAll($userId, Tokens::DEVICE_REGISTRATION); // tablet codes they created stop working (P2A admin_devices)
                    SessionStore::endAllForUser($userId, 'Permission Change', $actorId);
                }
                if (!$emailChanged) {
                    return null;
                }
                // Links already sent went to the old address, which may be wrong or someone else's.
                Tokens::revokeAll($userId, Tokens::PASSWORD_RESET);
                Tokens::revokeAll($userId, Tokens::TEMPORARY_CREDENTIAL);
                return $before['status'] === 'Pending' ? self::newActivationToken($userId) : null;
            });
        } catch (PDOException $e) {
            throw self::duplicate($e, null) ?? $e;
        }
        return ['changes' => $changes, 'mailed' => $token === null ? null : self::sendInvitation($userId, $token)];
    }

    /**
     * Send a new invitation link (the account is still Pending, e.g. the first email bounced, §3.3.5).
     * @return bool whether the email went now
     */
    public static function resendInvitation(int $userId, int $actorId): bool
    {
        $account = self::load($userId);
        self::refuseSelf($userId, $actorId, 'invitation_resend');
        if ($account['status'] !== 'Pending') {
            throw ValidationException::one('_form', 'This person has already activated their account. Send a password reset instead.');
        }
        $token = Db::transaction(function () use ($userId): string {
            $token = self::newActivationToken($userId);
            Audit::record('invitation_resend', 'user_account', $userId);
            return $token;
        });
        return self::sendInvitation($userId, $token);
    }

    /**
     * Reset (§3.2.2): clear any lockout, replace the password so the old one stops working, end
     * every session, and email the person a single-use link to choose a new one.
     * @return bool whether the email went now
     */
    public static function sendReset(int $userId, int $actorId): bool
    {
        $account = self::load($userId);
        self::refuseSelf($userId, $actorId, 'user_reset');
        self::requireActivated($account);
        $token = Db::transaction(function () use ($userId, $account, $actorId): string {
            $token = self::resetCredentials($userId, $actorId);
            Audit::record('user_reset', 'user_account', $userId, details: ['was_locked' => AccountStatus::isLocked($account)]);
            return $token;
        });
        $org = Settings::string('organisation_name', 'CHS Pet Pantry');
        $hours = self::tokenHours();
        return Mailer::send($account['email'], "$org: choose a new password",
            "Hello {$account['first_name']},\n\nAn Administrator has reset the password for your $org account ({$account['username']}). "
            . "Your old password no longer works.\nOpen this link within $hours hours to choose a new one. It works once:\n\n"
            . absolute_url('activate.php', ['token' => $token])
            . "\n\nIf you did not expect this, tell an Administrator.\n", 'user_reset', ['user_id' => $userId]);
    }

    /**
     * Clear a lockout without changing the password (§3.2.2), and tell the person.
     * @return bool whether the email went now
     */
    public static function unlock(int $userId, int $actorId): bool
    {
        $account = self::load($userId);
        self::refuseSelf($userId, $actorId, 'user_unlock');
        Db::transaction(function () use ($userId, $account): void {
            Db::pdo()->prepare("UPDATE user_account SET failed_login_count = 0, locked_until = NULL, status = IF(status = 'Locked', 'Active', status)
                                 WHERE user_id = ?")->execute([$userId]);
            Audit::record('user_unlock', 'user_account', $userId, changes: ['locked_until' => [$account['locked_until'], null]]);
        });
        $org = Settings::string('organisation_name', 'CHS Pet Pantry');
        return Mailer::send($account['email'], "$org: your account has been unlocked",
            "Hello {$account['first_name']},\n\nAn Administrator has unlocked your $org account ({$account['username']}). You can sign in again.\n"
            . "If you did not ask for this, tell an Administrator.\n", 'user_unlock', ['user_id' => $userId]);
    }

    /**
     * Deactivate now or from a date (§3.2.3). When it takes effect, sessions end and outstanding
     * links are cancelled (for a later date the cron job does that). History is kept.
     * @return bool true when it took effect now, false when it was scheduled
     * @throws ValidationException
     */
    public static function deactivate(int $userId, ?string $reason, ?string $effectiveDate, int $actorId): bool
    {
        $account = self::load($userId);
        self::refuseSelf($userId, $actorId, 'user_deactivate');
        $errors = [];
        $reason = Validator::text($reason, 255);
        if ($reason === null) {
            $errors['deactivate_reason'] = 'Say why the account is being deactivated.';
        }
        $today = Clock::orgToday();
        $date = ($effectiveDate === null || trim($effectiveDate) === '') ? $today : Validator::date(trim($effectiveDate));
        if ($date === null || $date < $today) {
            $errors['effective_date'] = 'Choose today or a later date.';
        }
        self::guardLastAdministrator($account, 'Board', null, $errors, 'user_deactivate', '_form'); // i.e. "no longer an Administrator"
        if ($errors) {
            throw new ValidationException($errors);
        }
        $now = $date === $today;
        Db::transaction(function () use ($userId, $account, $reason, $date, $now, $actorId): void {
            self::recheckLastAdministrator($account, 'Board', null);
            Db::pdo()->prepare('UPDATE user_account SET status = ?, deactivated_reason = ?, deactivation_effective_date = ?, row_version = row_version + 1 WHERE user_id = ?')
                ->execute([$now ? 'Inactive' : $account['status'], $reason, $date, $userId]);
            if ($now) {
                Tokens::revokeAll($userId, Tokens::TEMPORARY_CREDENTIAL);
                Tokens::revokeAll($userId, Tokens::PASSWORD_RESET);
                Tokens::revokeAll($userId, Tokens::DEVICE_REGISTRATION);
                SessionStore::endAllForUser($userId, 'Deactivated', $actorId);
            }
            Audit::record('user_deactivate', 'user_account', $userId, reason: $reason, changes: [
                'status' => [$account['status'], $now ? 'Inactive' : $account['status']],
                'deactivation_effective_date' => [$account['deactivation_effective_date'], $date],
            ]);
        });
        return $now;
    }

    /**
     * Undo a deactivation, or cancel a scheduled one, with a reason. A person who never activated
     * goes back to Pending with a new invitation.
     * @return ?bool whether the new invitation email went (null when none was due)
     */
    public static function reactivate(int $userId, ?string $reason, int $actorId): ?bool
    {
        $account = self::load($userId);
        self::refuseSelf($userId, $actorId, 'user_reactivate');
        if ($account['status'] !== 'Inactive' && $account['deactivation_effective_date'] === null) {
            return null;
        }
        $reason = Validator::text($reason, 255);
        if ($reason === null) {
            throw ValidationException::one('reactivate_reason', 'Say why the account is being reactivated. It is kept in the audit log.');
        }
        $neverActivated = $account['password_changed_at'] === null;
        $status = $account['status'] !== 'Inactive' ? $account['status'] : ($neverActivated ? 'Pending' : 'Active');
        $token = Db::transaction(function () use ($userId, $account, $status, $reason): ?string {
            Db::pdo()->prepare('UPDATE user_account SET status = ?, deactivated_reason = NULL, deactivation_effective_date = NULL, row_version = row_version + 1 WHERE user_id = ?')
                ->execute([$status, $userId]);
            Audit::record('user_reactivate', 'user_account', $userId, reason: $reason, changes: [
                'status' => [$account['status'], $status], 'deactivation_effective_date' => [$account['deactivation_effective_date'], null],
            ]);
            // Only a Pending account coming back from Inactive needs a new link (deactivation cancelled the old ones).
            return $account['status'] === 'Inactive' && $status === 'Pending' ? self::newActivationToken($userId) : null;
        });
        return $token === null ? null : self::sendInvitation($userId, $token);
    }

    /**
     * A fresh single-use link for a printed sheet, for a person whose email does not arrive
     * (§3.3.5). For an account that was already activated this is a password reset: the old
     * password stops working, sessions end and the person is told by email. Cancels earlier links.
     */
    public static function issueActivationSheet(int $userId, int $actorId): string
    {
        $account = self::load($userId);
        self::refuseSelf($userId, $actorId, 'activation_sheet_issue');
        if ($account['status'] === 'Inactive') {
            throw ValidationException::one('_form', 'Reactivate the account first.');
        }
        $pending = $account['status'] === 'Pending';
        $token = Db::transaction(function () use ($userId, $pending, $actorId): string {
            $token = $pending ? self::newActivationToken($userId) : self::resetCredentials($userId, $actorId);
            Audit::record('activation_sheet_issue', 'user_account', $userId, details: ['kind' => $pending ? 'invitation' : 'password reset']);
            return $token;
        });
        if (!$pending) {
            $org = Settings::string('organisation_name', 'CHS Pet Pantry');
            Mailer::send($account['email'], "$org: your password was reset",
                "Hello {$account['first_name']},\n\nAn Administrator has printed a sheet for your $org account ({$account['username']}) "
                . "so you can choose a new password. Your old password no longer works.\n\n"
                . "If you did not ask for this, tell an Administrator at once.\n", 'user_reset_sheet', ['user_id' => $userId]);
        }
        return $token;
    }

    /**
     * The person opens their link and chooses a password. Returns the user id.
     * @throws ValidationException when the link is not valid or the password is refused
     */
    public static function activate(#[\SensitiveParameter] string $rawToken, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirm): int
    {
        $token = Tokens::find($rawToken, Tokens::TEMPORARY_CREDENTIAL);
        $account = $token ? AccountRepository::find((int) $token['user_id']) : null;
        if ($token === null || $account === null || $account['status'] === 'Inactive') {
            throw ValidationException::one('_form', 'This link is not valid. It may have expired or already been used.');
        }
        if ($password !== $confirm) {
            throw ValidationException::one('new_password', 'The passwords do not match.');
        }
        $problems = PasswordPolicy::check($password, $account);
        if ($problems) {
            throw ValidationException::one('new_password', implode(' ', $problems));
        }
        $userId = (int) $account['user_id'];
        $ok = Db::transaction(function () use ($token, $userId, $password, $account): bool {
            if (!Tokens::consume((int) $token['token_id'])) {
                return false;
            }
            Auth::setPassword($userId, $password, 'Password Reset'); // also cancels every other link
            Db::pdo()->prepare("UPDATE user_account SET status = IF(status = 'Locked', 'Active', status) WHERE user_id = ?")->execute([$userId]);
            // The link holder acted, not whoever may be signed in on this browser.
            Audit::record($account['status'] === 'Pending' ? 'account_activate' : 'password_reset', 'user_account', $userId,
                actor: ['user_id' => $userId, 'session_id' => null, 'site_id' => null]);
            return true;
        });
        if (!$ok) {
            throw ValidationException::one('_form', 'This link is not valid. It may have expired or already been used.');
        }
        return $userId;
    }

    public static function tokenHours(): int
    {
        return max(1, Settings::int('temp_credential_hours', 72));
    }

    private static function newActivationToken(int $userId): string
    {
        Tokens::revokeAll($userId, Tokens::TEMPORARY_CREDENTIAL);
        return Tokens::issue($userId, Tokens::TEMPORARY_CREDENTIAL, self::tokenHours() * 60);
    }

    /** Replace the password with an unusable one, clear any lockout, end every session and cancel self-service links; returns the new link. */
    private static function resetCredentials(int $userId, int $actorId): string
    {
        Db::pdo()->prepare("UPDATE user_account SET password_hash = ?, failed_login_count = 0, locked_until = NULL, must_change_password = 1,
                                   status = IF(status = 'Locked', 'Active', status) WHERE user_id = ?")
            ->execute([self::unusablePasswordHash(), $userId]);
        Tokens::revokeAll($userId, Tokens::PASSWORD_RESET);
        Tokens::revokeAll($userId, Tokens::DEVICE_REGISTRATION); // the account may be in the wrong hands: its tablet codes stop working
        $token = self::newActivationToken($userId); // before the sessions: tokens, then sessions, the order every path uses
        SessionStore::endAllForUser($userId, 'Password Reset', $actorId);
        return $token;
    }

    private static function unusablePasswordHash(): string
    {
        return PasswordPolicy::hash(bin2hex(random_bytes(24)));
    }

    /** @return bool whether the email went now (false: queued for retry) */
    private static function sendInvitation(int $userId, string $token): bool
    {
        $account = self::load($userId);
        $org = Settings::string('organisation_name', 'CHS Pet Pantry');
        $hours = self::tokenHours();
        return Mailer::send($account['email'], "$org: your new account",
            "Hello {$account['first_name']},\n\nAn account has been created for you on $org.\nYour username is: {$account['username']}\n\n"
            . "Open this link within $hours hours to choose your password and sign in. It works once:\n\n"
            . absolute_url('activate.php', ['token' => $token])
            . "\n\nIf the link has expired, ask an Administrator to send a new one.\n", 'user_invitation', ['user_id' => $userId]);
    }

    private static function load(int $userId): array
    {
        return AccountRepository::find($userId) ?? throw ValidationException::one('_form', 'That account no longer exists.');
    }

    /** Status and sign-in actions are for other people's accounts only (§3.3.4); the attempt is audited. */
    private static function refuseSelf(int $userId, int $actorId, string $action): void
    {
        if ($userId === $actorId) {
            Audit::durable($action, 'user_account', $userId, 'Denied', 'Tried to change the status or sign-in of own account');
            throw ValidationException::one('_form', 'You cannot do this to your own account. Ask another Administrator.');
        }
    }

    private static function requireActivated(array $account): void
    {
        if ($account['status'] === 'Inactive') {
            throw ValidationException::one('_form', 'Reactivate the account first.');
        }
        if ($account['status'] === 'Pending') {
            throw ValidationException::one('_form', 'This person has not activated their account yet. Send a new invitation instead.');
        }
    }

    /** A unique-key clash that slipped past the checks (a double submit, or two Administrators at once). */
    private static function duplicate(PDOException $e, ?string $username): ?ValidationException
    {
        if (!Db::isDuplicateKey($e)) {
            return null;
        }
        return $username !== null && AccountRepository::usernameTaken($username)
            ? ValidationException::one('username', 'That username is already taken.')
            : ValidationException::one('email', 'This email already belongs to another account.');
    }

    /** Would this change take away the last lasting Administrator's lasting access? */
    private static function removesLastingAdministrator(array $before, string $newRole, ?string $newExpiry): bool
    {
        $wasLasting = $before['role'] === 'Administrator' && $before['status'] === 'Active'
            && $before['expiry_date'] === null && $before['deactivation_effective_date'] === null;
        return $wasLasting && !($newRole === 'Administrator' && $newExpiry === null);
    }

    /**
     * Refuse a change that would leave no Administrator able to sign in (§3.3.3), now or later:
     * at least one Active Administrator with no end date and no scheduled deactivation must remain.
     */
    private static function guardLastAdministrator(array $before, string $newRole, ?string $newExpiry, array &$errors, string $action, ?string $errorKey = null): void
    {
        if (self::removesLastingAdministrator($before, $newRole, $newExpiry) && AccountRepository::lastingAdministratorCount((int) $before['user_id']) === 0) {
            $errors[$errorKey ?? ($newRole === 'Administrator' ? 'expiry_date' : 'role')] = self::LAST_ADMIN;
            Audit::durable($action, 'user_account', (int) $before['user_id'], 'Denied', 'Would leave no Administrator with lasting access');
        }
    }

    /** The same rule inside the write transaction, with the Administrator rows locked, so concurrent changes cannot both pass. */
    private static function recheckLastAdministrator(array $before, string $newRole, ?string $newExpiry): void
    {
        if (self::removesLastingAdministrator($before, $newRole, $newExpiry) && AccountRepository::lastingAdministratorCount((int) $before['user_id'], lock: true) === 0) {
            throw ValidationException::one('_form', self::LAST_ADMIN);
        }
    }

    /**
     * @param list<int> $heldSiteIds sites the account holds now; one that has since been closed may stay
     * @param array<string, string> $errors
     * @return list<int> the site ids to grant (sorted), empty for roles that see every site
     */
    private static function validateSites(string $role, array $siteIds, array $heldSiteIds, array &$errors): array
    {
        if (Rbac::can($role, 'site.all')) {
            return [];
        }
        $sites = [];
        foreach ($siteIds as $id) {
            $id = (int) $id;
            $site = SiteRepository::find($id);
            if ($site === null || (!$site['is_active'] && !in_array($id, $heldSiteIds, true))) {
                $errors['sites'] = 'Choose only active sites.';
                return [];
            }
            $sites[] = $id;
        }
        $sites = array_values(array_unique($sites));
        sort($sites);
        $max = max(1, Settings::int('volunteer_max_sites', 2));
        if (!$sites) {
            $errors['sites'] = "A $role needs at least one site.";
        } elseif ($role === 'Volunteer' && count($sites) > $max) {
            $errors['sites'] = "A Volunteer can be given at most $max site" . ($max === 1 ? '' : 's') . '. Make them a Coordinator, or remove a site.';
        }
        return $sites;
    }

    /**
     * Normalise the detail fields, adding every problem to $errors (a field with a problem comes back null).
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private static function validateDetails(array $input, ?int $userId, array &$errors): array
    {
        $today = Clock::orgToday();
        $first = Validator::text($input['first_name'] ?? null, 50);
        $last = Validator::text($input['last_name'] ?? null, 50);
        if ($first === null) {
            $errors['first_name'] = 'Enter the first name.';
        }
        if ($last === null) {
            $errors['last_name'] = 'Enter the last name.';
        }
        $email = Validator::email($input['email'] ?? null);
        if ($email === null) {
            $errors['email'] = 'Enter a valid email address. The invitation and password links go there.';
        } else {
            $existing = AccountRepository::findByEmail($email);
            if ($existing !== null && (int) $existing['user_id'] !== ($userId ?? 0)) {
                $errors['email'] = "This email already belongs to {$existing['first_name']} {$existing['last_name']} ({$existing['status']}). Open that account to edit or reactivate it.";
                $errors['existing_user_id'] = (string) $existing['user_id'];
            }
        }
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $phone = $phoneRaw === '' ? null : Validator::phone($phoneRaw);
        if ($phoneRaw !== '' && $phone === null) {
            $errors['phone'] = 'Enter a 10-digit phone number, or leave it blank.';
        }
        $role = Validator::oneOf($input['role'] ?? null, Rbac::ROLES);
        if ($role === null) {
            $errors['role'] = 'Choose a role.';
        }
        $startRaw = trim((string) ($input['start_date'] ?? ''));
        $start = $startRaw === '' ? $today : Validator::date($startRaw);
        if ($start === null) {
            $errors['start_date'] = 'Enter a real date.';
        }
        $expiryRaw = trim((string) ($input['expiry_date'] ?? ''));
        $expiry = $expiryRaw === '' ? null : Validator::date($expiryRaw);
        if ($expiryRaw !== '' && $expiry === null) {
            $errors['expiry_date'] = 'Enter a real date, or leave it blank for no end date.';
        } elseif ($expiry !== null && $start !== null && $expiry < $start) {
            $errors['expiry_date'] = 'The end date cannot be before the start date.';
        }
        $onboardingRaw = trim((string) ($input['onboarding_completed_at'] ?? ''));
        $onboarding = $onboardingRaw === '' ? null : Validator::date($onboardingRaw);
        if ($onboardingRaw !== '' && ($onboarding === null || $onboarding > $today)) {
            $errors['onboarding_completed_at'] = 'Enter the date the training was completed (not in the future).';
            $onboarding = null;
        }
        $identifiable = !empty($input['can_extract_identifiable']) ? 1 : 0;
        if ($identifiable && $role !== null && $role !== 'Administrator') {
            $errors['can_extract_identifiable'] = 'Only Administrators can be allowed to export identifiable participant data.';
        }
        return ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone, 'role' => $role,
            'start_date' => $start, 'expiry_date' => $expiry,
            'onboarding_completed_at' => $onboarding === null ? null : "$onboarding 00:00:00",
            'can_extract_identifiable' => $identifiable];
    }
}
