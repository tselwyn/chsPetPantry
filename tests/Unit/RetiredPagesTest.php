<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NFR-SEC-002 / NFR-SEC-003: only Pet Pantry pages can be opened. Every other
 * page left over from the CCDA app starts with the retired-page guard.
 *
 * Adding a new page? Put it in LIVE_PAGES. Bringing an old page back? Delete
 * its guard line and put it in LIVE_PAGES.
 */
final class RetiredPagesTest extends TestCase
{
    /** Pages the Pet Pantry uses, plus files those pages include as code. */
    public const LIVE_PAGES = [
        'changeForgottenPassword.php', 'changePassword.php', 'createUser.php', 'deleteParticipantPet.php',
        'distributionHistory.php', 'distributionRecord.php', 'emailEncryption.php', 'foodReceived.php',
        'forgotPassword.php', 'index.php', 'login.php', 'logout.php', 'participantEdit.php',
        'participantRegister.php', 'participantSearch.php', 'petDelete.php', 'petEdit.php', 'petFormFields.php',
        'registerPet.php', 'registrationForm.php', 'reports.php', 'resetPassword.php', 'sessionKeepAlive.php',
        'viewAuditUsers.php', 'viewModifyUser.php',
    ];

    private const GUARD = "<?php require_once __DIR__ . '/include/retiredPage.php';";

    public function testEveryOtherPageStartsWithTheGuard(): void
    {
        $unguarded = [];
        foreach (glob(PFPMS_ROOT . '/*.php') as $file) {
            $name = basename($file);
            if (in_array($name, self::LIVE_PAGES, true)) {
                continue;
            }
            if (!str_starts_with((string) file_get_contents($file), self::GUARD)) {
                $unguarded[] = $name;
            }
        }
        $this->assertSame([], $unguarded, 'add the guard line, or list the page in LIVE_PAGES');
    }

    public function testLivePagesAreNotGuarded(): void
    {
        foreach (self::LIVE_PAGES as $name) {
            $this->assertFileExists(PFPMS_ROOT . "/$name");
            $this->assertStringNotContainsString('include/retiredPage.php', (string) file_get_contents(PFPMS_ROOT . "/$name"), $name);
        }
    }
}
