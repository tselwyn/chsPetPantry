<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Auth\Rbac;
use Pfpms\Auth\SiteAccess;
use Pfpms\Http\Context;

/**
 * Which tablets a person may manage. Scope always comes from the tablet's own site
 * (device.site_id), never from the site the session is working at, so a site switched in another
 * tab or a lapsed temporary grant (US-28) cannot widen it.
 *
 * - Administrators (site.all) cover every tablet, including those at inactive sites or with no site.
 * - Everyone else covers the active sites they can use right now.
 * - New tablets (and, in P2B, redeeming a code) need an active site the person can use now.
 */
final class DeviceScope
{
    /** @param list<int> $siteIds active sites the person can use now (SiteAccess::sitesFor) */
    public function __construct(
        public readonly int $actorId,
        public readonly string $role,
        public readonly bool $allSites,
        public readonly array $siteIds,
        /** The account's row_version when the scope was built: a change of access since then makes it out of date. */
        public readonly ?int $rowVersion = null,
    ) {
    }

    public static function fromContext(Context $ctx): self
    {
        return new self($ctx->userId(), $ctx->role(), $ctx->can('site.all'), $ctx->siteIds(),
            isset($ctx->user['row_version']) ? (int) $ctx->user['row_version'] : null);
    }

    /** For a user row: P2B re-checks the person who created a code when it is redeemed. */
    public static function forUser(array $user): self
    {
        return new self((int) $user['user_id'], (string) $user['role'], Rbac::can((string) $user['role'], 'site.all'),
            array_column(SiteAccess::sitesFor($user), 'site_id'), isset($user['row_version']) ? (int) $user['row_version'] : null);
    }

    public function can(string $capability): bool
    {
        return Rbac::can($this->role, $capability);
    }

    /** An existing tablet: site.all covers every site (inactive and none included); others need the site now. */
    public function covers(?int $siteId): bool
    {
        return $this->allSites || ($siteId !== null && in_array($siteId, $this->siteIds, true));
    }

    /** A new tablet: only an active site the person can use now. */
    public function canAddAt(int $siteId): bool
    {
        return in_array($siteId, $this->siteIds, true);
    }
}
