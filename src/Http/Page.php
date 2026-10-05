<?php
declare(strict_types=1);

namespace Pfpms\Http;

use LogicException;
use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\SiteAccess;
use Pfpms\Auth\WebSession;

/**
 * The guard every page runs first, before it reads any input (plan §4 page skeleton):
 * session → forced password change → policy acknowledgement → capability → site.
 */
final class Page
{
    /**
     * @param array{capability?: ?string, site?: bool, public?: bool, session?: bool, password_change?: bool, policy_ack?: bool} $options
     *   capability      required capability (null: any signed-in user)
     *   site            the page works within one site, so one must be selected
     *   public          no sign-in required; returns the Context when signed in, else null
     *   session         false: no PHP session at all (the Station shell and its service worker); needs public
     *   password_change this is the change-password page (allowed while a change is forced)
     *   policy_ack      this is the policy page (allowed while acknowledgement is pending)
     */
    public static function start(array $options = []): ?Context
    {
        if (($options['session'] ?? true) === false) {
            if (empty($options['public'])) {
                throw new LogicException("Page::start(['session' => false]) is only for public pages");
            }
            Audit::setActor(null);
            return null;
        }
        WebSession::start();
        $ctx = self::resolve();

        if ($ctx === null) {
            if (!empty($options['public'])) {
                Audit::setActor(null);
                return null;
            }
            Response::redirect('login.php?next=' . rawurlencode(Request::appPath()));
        }
        Audit::setActor($ctx->userId(), $ctx->sessionId, $ctx->siteId);

        if (!empty($options['public'])) {
            return $ctx;
        }
        if (empty($options['password_change']) && AccountRules::mustChangePassword($ctx->user)) {
            Response::redirect('change_password.php');
        }
        if (empty($options['password_change']) && empty($options['policy_ack']) && Policy::acknowledgementRequired($ctx->user)) {
            Response::redirect('policy_ack.php?next=' . rawurlencode(Request::appPath()));
        }
        $capability = $options['capability'] ?? null;
        if ($capability !== null && !$ctx->can($capability)) {
            Audit::durable('access_denied', 'page', null, 'Denied', "Missing capability $capability", ['page' => Request::appPath()]);
            throw new HttpException(403);
        }
        if (!empty($options['site']) && $ctx->siteId === null) {
            Response::redirect('select_site.php?next=' . rawurlencode(Request::appPath()));
        }
        return $ctx;
    }

    /** The signed-in context for this request, or null (after cleaning up an ended session, with a flash saying why). */
    public static function resolve(): ?Context
    {
        $checked = self::session(true, $ended);
        if ($checked === null) {
            if ($ended !== null) {
                if ($ended === 'timeout') {
                    Flash::info('You were signed out after a period of inactivity. Please sign in again.');
                } elseif ($ended === 'account') {
                    Flash::error('Your account can no longer be used. Please contact an Administrator.');
                } elseif ($ended === 'device') {
                    Flash::info('This tablet was taken out of service, so you were signed out. Ask a Coordinator for another tablet.');
                } else {
                    Flash::info('Your session has ended. Please sign in again.');
                }
            }
            return null;
        }
        [$siteId, $sites] = self::pickSite($checked['user'], $checked['session']);
        return new Context($checked['user'], (string) $checked['session']['session_id'], $siteId, $sites,
            $checked['session']['device_id'] === null ? null : (int) $checked['session']['device_id'], (string) $checked['session']['auth_method']);
    }

    /**
     * The server session behind this PHP session, validated (SessionStore::validate), or null. An ended one is
     * cleaned up (the PHP session restarted) without a flash; $ended then says why: null when the PHP session
     * carried no ids, else 'missing', 'ended', 'timeout', 'account' or 'device'.
     * @return ?array{user: array, session: array}
     */
    public static function session(bool $touch = true, ?string &$ended = null): ?array
    {
        $ended = null;
        $sid = $_SESSION['sid'] ?? null;
        $uid = $_SESSION['uid'] ?? null;
        if (!is_string($sid) || !is_int($uid)) {
            return null;
        }
        $checked = SessionStore::validate($sid, $touch);
        if (isset($checked['ended']) || (int) $checked['user']['user_id'] !== $uid) {
            WebSession::restart();
            $ended = $checked['ended'] ?? 'ended';
            return null;
        }
        return $checked;
    }

    /**
     * The site a web session works in: a lapsed site is dropped (the grant lapsed or the site was deactivated,
     * US-28), a single site is picked, and a change is written back to the PHP and server sessions.
     * @return array{0: ?int, 1: list<array>} the site id and the sites usable now
     */
    public static function pickSite(array $user, array $session, ?array $sites = null): array
    {
        $sites ??= SiteAccess::sitesFor($user);
        $siteIds = array_column($sites, 'site_id');
        $siteId = $_SESSION['site_id'] ?? null;
        if ($siteId !== null && !in_array($siteId, $siteIds, true)) {
            $siteId = null;
        }
        if ($siteId === null && count($sites) === 1) {
            $siteId = $sites[0]['site_id'];
        }
        if ($siteId !== ($_SESSION['site_id'] ?? null)) {
            $_SESSION['site_id'] = $siteId;
            SessionStore::setSite((string) $session['session_id'], $siteId);
        }
        return [$siteId, $sites];
    }
}
