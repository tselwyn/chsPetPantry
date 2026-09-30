<?php
declare(strict_types=1);

namespace Pfpms\Http;

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
     * @param array{capability?: ?string, site?: bool, public?: bool, password_change?: bool, policy_ack?: bool} $options
     *   capability      required capability (null: any signed-in user)
     *   site            the page works within one site, so one must be selected
     *   public          no sign-in required; returns the Context when signed in, else null
     *   password_change this is the change-password page (allowed while a change is forced)
     *   policy_ack      this is the policy page (allowed while acknowledgement is pending)
     */
    public static function start(array $options = []): ?Context
    {
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

    /** The signed-in context for this request, or null (after cleaning up an ended session). */
    public static function resolve(): ?Context
    {
        $sid = $_SESSION['sid'] ?? null;
        $uid = $_SESSION['uid'] ?? null;
        if (!is_string($sid) || !is_int($uid)) {
            return null;
        }
        $checked = SessionStore::validate($sid);
        if (isset($checked['ended']) || (int) $checked['user']['user_id'] !== $uid) {
            WebSession::restart();
            $reason = $checked['ended'] ?? 'ended';
            if ($reason === 'timeout') {
                Flash::info('You were signed out after a period of inactivity. Please sign in again.');
            } elseif ($reason === 'account') {
                Flash::error('Your account can no longer be used. Please contact an Administrator.');
            } elseif ($reason === 'device') {
                Flash::info('This tablet was taken out of service, so you were signed out. Ask a Coordinator for another tablet.');
            } else {
                Flash::info('Your session has ended. Please sign in again.');
            }
            return null;
        }

        $user = $checked['user'];
        $sites = SiteAccess::sitesFor($user);
        $siteIds = array_column($sites, 'site_id');
        $siteId = $_SESSION['site_id'] ?? null;
        if ($siteId !== null && !in_array($siteId, $siteIds, true)) {
            $siteId = null; // the grant lapsed or the site was deactivated (US-28)
        }
        if ($siteId === null && count($sites) === 1) {
            $siteId = $sites[0]['site_id'];
        }
        if ($siteId !== ($_SESSION['site_id'] ?? null)) {
            $_SESSION['site_id'] = $siteId;
            SessionStore::setSite($sid, $siteId);
        }
        return new Context($user, $sid, $siteId, $sites);
    }
}
