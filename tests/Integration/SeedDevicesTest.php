<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Audit\Audit;
use Pfpms\Auth\Tokens;
use Pfpms\Db;
use Pfpms\Db\SqlSplitter;
use Pfpms\Device\DeviceGuard;
use Pfpms\Device\DeviceRepository;
use Pfpms\Http\HttpException;
use Pfpms\Tests\TestCase;

/**
 * seeds/dev/004_devices.sql (50-design §12.1): the four dev tablets' credentials are known strings in the guard's
 * format, 'pfd1_' + the lower-case label with hyphens for spaces, padded with 0s to 43 characters (D-46).
 */
final class SeedDevicesTest extends TestCase
{
    private const SEED = APP_ROOT . '/seeds/dev/004_devices.sql';

    /** The formula §12.1 documents, as the seed spells it (t.`label` is the derived table's column). */
    private const FORMULA = "SHA2(CONCAT('pfd1_', RPAD(REPLACE(LOWER(t.`label`), ' ', '-'), 43, '0')), 256)";

    /** The pre-P2B formula, kept only to find the rows the seed re-keys. */
    private const OLD_FORMULA = "SHA2(CONCAT('pfpms-dev-device:', t.`label`), 256)";

    private const LABELS = ['Front desk 1', 'Front desk 2', 'Intake table', 'Spare tablet'];

    protected function tearDown(): void
    {
        Audit::setActor(null); // the guard sets the tablet as the actor
        parent::tearDown();
    }

    public function testSeededCredentialsMatchTheGuardFormat(): void
    {
        [$update, $insert] = $this->statements();
        $labels = $this->labelsIn($insert);
        $this->assertSame(self::LABELS, $labels, 'the four seeded tablets');
        $this->assertSame($labels, $this->labelsIn($update), 'the re-key statement names the same four');
        foreach ($labels as $label) {
            $credential = self::credential($label);
            $this->assertMatchesRegularExpression(DeviceGuard::FORMAT, $credential, $label);
            $this->assertSame(48, strlen($credential), "$label: 'pfd1_' and 43 characters");
        }
        $this->assertStringStartsWith('pfd1_front-desk-1000', self::credential('Front desk 1'));
        $this->assertStringContainsString('(pfd1_front-desk-1000…)', (string) file_get_contents(self::SEED), 'the comment shows the known credential');
    }

    public function testTheSeedUsesTheDocumentedFormula(): void
    {
        [$update, $insert] = $this->statements();
        $this->assertSame(1, substr_count($update, self::FORMULA), 'the re-key SET');
        $this->assertSame(1, substr_count($update, self::OLD_FORMULA), 'the old formula only finds the rows to re-key');
        $this->assertSame(2, substr_count($insert, self::FORMULA), 'the INSERT and its first NOT EXISTS');
        $this->assertSame(0, substr_count($insert, 'pfpms-dev-device:'), 'no insert uses the old credential');
    }

    public function testTheSqlFormulaGivesTheHashOfTheDocumentedCredential(): void
    {
        $sql = 'SELECT ' . str_replace('t.`label`', '?', self::FORMULA);
        foreach (self::LABELS as $label) {
            $this->assertSame(Tokens::hash(self::credential($label)), $this->scalar($sql, [$label]),
                "$label: MySQL's SHA2/RPAD/REPLACE/LOWER agree with the credential the guard hashes");
        }
    }

    public function testTheSeededTabletsInServiceAuthenticateWithTheirCredentials(): void
    {
        $site = $this->makeSite('Dev Site North');
        $this->runSeed();
        foreach (['Front desk 1', 'Front desk 2', 'Intake table'] as $label) {
            $device = DeviceGuard::authenticate('PFPMS-Device ' . self::credential($label), DeviceGuard::IN_SERVICE, '203.0.113.7', 'api/device/heartbeat.php');
            $this->assertSame([$label, $site, 1, 1, 0, 0, 'none'],
                [$device['label'], (int) $device['site_id'], (int) $device['in_service'], (int) $device['site_active'], (int) $device['has_vault_key'],
                 (int) $device['has_proof_key'], $device['proof']], "$label: online-only, with no vault key and no proof key");
        }
    }

    public function testTheSeededSpareTabletIsRetiredAndToldToWipe(): void
    {
        $this->makeSite('Dev Site North');
        $this->runSeed();
        $credential = 'PFPMS-Device ' . self::credential('Spare tablet');
        $known = DeviceGuard::authenticate($credential, DeviceGuard::KNOWN, '203.0.113.7', 'api/device/heartbeat.php');
        $this->assertSame(['wipe' => 'Push Then Wipe'], DeviceGuard::directive($known));
        try {
            DeviceGuard::authenticate($credential, DeviceGuard::IN_SERVICE, '203.0.113.7', 'api/auth/login.php');
            $this->fail('a retired tablet is not in service');
        } catch (HttpException $e) {
            $this->assertSame([403, 'device_revoked', ['directive' => ['wipe' => 'Push Then Wipe']]], [$e->status, $e->errorCode, $e->extra]);
        }
    }

    public function testTheSeedReKeysTabletsSeededBeforeP2B(): void
    {
        $site = $this->makeSite('Dev Site North');
        $old = $this->makeDevice($site, ['label' => 'Front desk 1', 'is_site_registered' => 1,
            'token_hash' => $this->scalar("SELECT SHA2(CONCAT('pfpms-dev-device:', ?), 256)", ['Front desk 1'])]);
        $this->runSeed();
        $this->assertSame($old, (int) DeviceRepository::byCredentialHash(Tokens::hash(self::credential('Front desk 1')))['device_id'],
            'the earlier row now answers to the new credential');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM device WHERE site_id = ? AND label = 'Front desk 1'", [$site]), 'and was not added again');
        $this->assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM device WHERE site_id = ?', [$site]));
    }

    public function testTheSeedIsSafeToRunTwice(): void
    {
        $site = $this->makeSite('Dev Site North');
        $this->runSeed();
        $this->runSeed();
        $this->assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM device WHERE site_id = ?', [$site]));
    }

    /** The documented dev credential of a seeded tablet (the seed comment and §12.1). */
    private static function credential(string $label): string
    {
        return 'pfd1_' . str_pad(str_replace(' ', '-', strtolower($label)), 43, '0');
    }

    /** @return array{0: string, 1: string} the re-key UPDATE and the INSERT, in that order */
    private function statements(): array
    {
        $statements = SqlSplitter::split((string) file_get_contents(self::SEED));
        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('UPDATE `device`', ltrim($statements[0]), 'the re-key comes first');
        $this->assertStringStartsWith('INSERT INTO `device`', ltrim($statements[1]));
        return [$statements[0], $statements[1]];
    }

    /** @return list<string> the labels of the statement's derived table t, in order */
    private function labelsIn(string $statement): array
    {
        preg_match_all("~(?:\(\s*SELECT|UNION ALL SELECT)\s+'([^']*)'~", $statement, $m);
        return $m[1];
    }

    private function runSeed(): void
    {
        foreach (SqlSplitter::split((string) file_get_contents(self::SEED)) as $statement) {
            Db::pdo()->exec($statement);
        }
    }
}
