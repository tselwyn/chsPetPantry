<?php
declare(strict_types=1);

namespace Pfpms\Http;

use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Policy;
use Pfpms\Auth\WebSession;
use Pfpms\Security\Csrf;

/**
 * The guard for JSON endpoints under public/api/. Same checks as Page::start, but errors
 * come back as JSON (401/403 with a machine-readable code) and every non-GET request must
 * carry the X-CSRF-Token header. Device-authenticated sync endpoints use their own guard.
 */
final class Api
{
    /** @param array{capability?: ?string, site?: bool, public?: bool} $options */
    public static function start(array $options = []): ?Context
    {
        WebSession::start();
        $ctx = Page::resolve();
        if ($ctx === null) {
            if (!empty($options['public'])) {
                return null;
            }
            Response::json(['error' => 'not_signed_in', 'message' => HttpException::defaultMessage(401)], 401);
        }
        Audit::setActor($ctx->userId(), $ctx->sessionId, $ctx->siteId);
        if (AccountRules::mustChangePassword($ctx->user)) {
            Response::json(['error' => 'password_change_required'], 403);
        }
        if (Policy::acknowledgementRequired($ctx->user)) {
            Response::json(['error' => 'policy_ack_required'], 403);
        }
        $capability = $options['capability'] ?? null;
        if ($capability !== null && !$ctx->can($capability)) {
            Audit::durable('access_denied', 'api', null, 'Denied', "Missing capability $capability", ['endpoint' => Request::appPath()]);
            Response::json(['error' => 'forbidden', 'message' => HttpException::defaultMessage(403)], 403);
        }
        if (!empty($options['site']) && $ctx->siteId === null) {
            Response::json(['error' => 'site_required'], 409);
        }
        if (Request::method() !== 'GET') {
            Csrf::verify();
        }
        return $ctx;
    }
}
