<?php
declare(strict_types=1);

namespace Pfpms\Http;

use Pfpms\Auth\Rbac;

/** Who is making this request, and in which site. Built by Page::start / Api::start. */
final class Context
{
    /**
     * @param array $user user_account row (no password hash)
     * @param list<array{site_id:int,name:string,time_zone:string}> $sites sites usable right now
     * @param ?int $deviceId the tablet the session was opened on (Station sessions), else null
     * @param ?string $authMethod user_session.auth_method (Password, PIN, …)
     * @param ?array $device the tablet authenticated in this request (Api::start 'device'), without secrets
     */
    public function __construct(
        public readonly array $user,
        public readonly string $sessionId,
        public readonly ?int $siteId,
        public readonly array $sites,
        public readonly ?int $deviceId = null,
        public readonly ?string $authMethod = null,
        public readonly ?array $device = null,
    ) {
    }

    public function userId(): int
    {
        return (int) $this->user['user_id'];
    }

    public function role(): string
    {
        return $this->user['role'];
    }

    public function can(string $capability): bool
    {
        return Rbac::can($this->role(), $capability);
    }

    public function displayName(): string
    {
        $display = trim((string) ($this->user['display_name'] ?? ''));
        return $display !== '' ? $display : trim($this->user['first_name'] . ' ' . $this->user['last_name']);
    }

    /** @return array{site_id:int,name:string,time_zone:string}|null */
    public function site(): ?array
    {
        foreach ($this->sites as $site) {
            if ($site['site_id'] === $this->siteId) {
                return $site;
            }
        }
        return null;
    }

    /** @return list<int> */
    public function siteIds(): array
    {
        return array_column($this->sites, 'site_id');
    }
}
