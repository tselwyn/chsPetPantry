<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Auth\Rbac;

/** Invariants of the capability matrix (plan §9). */
final class RbacTest extends TestCase
{
    public function testBoardHoldsOnlyReadOnlyCapabilities(): void
    {
        foreach (Rbac::capabilities('Board') as $capability) {
            $this->assertContains($capability, Rbac::READ_ONLY, "Board must not hold write capability $capability");
        }
    }

    public function testVolunteerCannotDeleteImportOrManageUsers(): void
    {
        foreach (Rbac::capabilities('Volunteer') as $capability) {
            $this->assertFalse(str_ends_with($capability, '.delete') || str_starts_with($capability, 'import.')
                || $capability === 'user.manage' || $capability === 'site.all', "Volunteer must not hold $capability");
        }
    }

    public function testInheritance(): void
    {
        foreach (Rbac::capabilities('Volunteer') as $capability) {
            $this->assertTrue(Rbac::can('Coordinator', $capability), "Coordinator should inherit $capability");
        }
        foreach (Rbac::capabilities('Coordinator') as $capability) {
            $this->assertTrue(Rbac::can('Administrator', $capability), "Administrator should inherit $capability");
        }
        $this->assertFalse(Rbac::can('Board', 'participant.view'));
    }

    public function testOnlyAdministratorsEraseDevices(): void
    {
        $this->assertTrue(Rbac::can('Administrator', 'device.erase'), 'plan: only an Admin Wipe-Now discards the outbox');
        foreach (['Coordinator', 'Volunteer', 'Board'] as $role) {
            $this->assertFalse(Rbac::can($role, 'device.erase'), $role);
        }
        $this->assertTrue(Rbac::can('Coordinator', 'device.register'));
        $this->assertFalse(Rbac::can('Volunteer', 'device.register'));
    }

    public function testUnknownRoleHasNothing(): void
    {
        $this->assertSame([], Rbac::capabilities('Superadmin'));
        $this->assertFalse(Rbac::can('Superadmin', 'home.view'));
    }

    public function testEveryCapabilityUsedInCodeExists(): void
    {
        $known = Rbac::all();
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob("$root/public/*.php") ?: [], glob("$root/public/*/*.php") ?: [], [$root . '/src/View/nav.php']);
        foreach ($files as $file) {
            preg_match_all("/'capability'\\s*=>\\s*'([a-z_.]+)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $capability) {
                $this->assertContains($capability, $known, basename($file) . " uses unknown capability $capability");
            }
        }
    }
}
