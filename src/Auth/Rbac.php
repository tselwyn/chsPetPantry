<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use RuntimeException;

/** Role-based capabilities, read from src/Auth/capabilities.php. */
final class Rbac
{
    public const ROLES = ['Volunteer', 'Coordinator', 'Administrator', 'Board'];

    /** Capabilities that change nothing (Board may hold only these, plus its own profile). */
    public const READ_ONLY = ['home.view', 'profile.self', 'dashboard.board', 'report.aggregate.view', 'site.all',
        'participant.search', 'participant.view', 'history.view', 'inventory.view', 'event.dashboard',
        'session.roster', 'audit.view'];

    /** @var array<string, list<string>>|null */
    private static ?array $resolved = null;

    public static function can(string $role, string $capability): bool
    {
        return in_array($capability, self::capabilities($role), true);
    }

    /** @return list<string> */
    public static function capabilities(string $role): array
    {
        return self::resolved()[$role] ?? [];
    }

    /** @return list<string> every capability any role holds */
    public static function all(): array
    {
        $all = [];
        foreach (self::resolved() as $caps) {
            array_push($all, ...$caps);
        }
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }

    /** @return array<string, list<string>> */
    private static function resolved(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        $matrix = require __DIR__ . '/capabilities.php';
        $resolve = function (string $role, array $seen = []) use (&$resolve, $matrix): array {
            if (!isset($matrix[$role]) || in_array($role, $seen, true)) {
                throw new RuntimeException("Capability matrix: unknown or circular role '$role'");
            }
            $own = $matrix[$role]['capabilities'];
            $parent = $matrix[$role]['inherits'];
            return $parent === null ? $own : array_merge($resolve($parent, [...$seen, $role]), $own);
        };
        self::$resolved = [];
        foreach (self::ROLES as $role) {
            self::$resolved[$role] = array_values(array_unique($resolve($role)));
        }
        return self::$resolved;
    }
}
