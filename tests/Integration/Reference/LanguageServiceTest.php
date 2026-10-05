<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Reference\LanguageRepository;
use Pfpms\Reference\LanguageService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Languages admin (US-23): code format, uniqueness, audit, default language stays active. */
final class LanguageServiceTest extends TestCase
{
    public function testCreateNormalisesTheCodeAndAudits(): void
    {
        $code = LanguageService::create(['language_code' => ' PT_br ', 'name' => '  Portuguese   (Brazil) ']);
        $this->assertSame('pt-BR', $code);
        $language = LanguageRepository::find('pt-BR');
        $this->assertSame('Portuguese (Brazil)', $language['name']);
        $this->assertSame(1, (int) $language['is_active']);
        $this->assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'language_create' AND entity_type = 'language'
                AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.language_code')) = 'pt-BR'"
        ));
    }

    public function testCodeFormat(): void
    {
        $cases = [
            'vi' => 'vi', 'VI' => 'vi', 'haw' => 'haw', 'zh-tw' => 'zh-TW', 'zh_TW' => 'zh-TW', ' so ' => 'so',
            'english' => null, 'e' => null, 'en-GBR' => null, 'es-419' => null, 'e1' => null, 'en--US' => null, '' => null,
            'zh-Hant' => null,
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, LanguageService::normaliseCode((string) $input), "code '$input'");
        }
    }

    public function testValidationReportsEveryFieldAtOnce(): void
    {
        try {
            LanguageService::create(['language_code' => 'Spanish', 'name' => '']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['language_code', 'name'], array_keys($e->errors));
        }
    }

    public function testCodesAndNamesAreUnique(): void
    {
        try {
            LanguageService::create(['language_code' => 'ES', 'name' => 'SPANISH']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['language_code', 'name'], array_keys($e->errors));
        }
    }

    public function testRenameRecordsTheChangeAndNoOpsAreNotAudited(): void
    {
        LanguageService::rename('es', 'Español');
        $this->assertSame('Español', LanguageRepository::find('es')['name']);
        $field = \Pfpms\Db::pdo()->query("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                           WHERE a.action = 'language_rename'")->fetchAll();
        $this->assertSame([['field_name' => 'name', 'old_value' => 'Spanish', 'new_value' => 'Español']], $field);
        LanguageService::rename('es', ' Español ');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'language_rename'"), 'no-op saves are not audited');
    }

    public function testRenameRefusesAnotherLanguagesName(): void
    {
        $this->expectException(ValidationException::class);
        LanguageService::rename('es', 'english');
    }

    public function testTheDefaultLanguageCannotBeDeactivated(): void
    {
        try {
            LanguageService::setActive('en', false);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['_form'], array_keys($e->errors));
        }
        $this->assertSame(1, (int) LanguageRepository::find('en')['is_active']);

        $this->setSetting('default_language', 'es');
        LanguageService::setActive('en', false);
        $this->assertSame(0, (int) LanguageRepository::find('en')['is_active']);
        $this->expectException(ValidationException::class);
        LanguageService::setActive('ES', false);
    }

    public function testARefusedDeactivationChangesNothingAndIsNotAudited(): void
    {
        try {
            LanguageService::setActive('EN', false);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('default language', $e->errors['_form']);
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'language_%'"));
    }

    public function testAddingAnInactiveLanguageAgainPointsToActivate(): void
    {
        LanguageService::setActive('es', false);
        try {
            LanguageService::create(['language_code' => 'es', 'name' => 'Castellano']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['language_code'], array_keys($e->errors));
            $this->assertStringContainsString('Activate', $e->errors['language_code']);
        }
    }

    public function testChangingALanguageThatIsGoneIsAFormError(): void
    {
        foreach ([fn() => LanguageService::rename('xx', 'Unknown'), fn() => LanguageService::setActive('xx', false)] as $change) {
            try {
                $change();
                $this->fail('expected a ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['_form'], array_keys($e->errors));
            }
        }
    }

    public function testActivationChangesAreAuditedOnce(): void
    {
        LanguageService::setActive('es', false);
        LanguageService::setActive('es', false);
        LanguageService::setActive('es', true);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'language_deactivate'"));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'language_activate'"));
        $this->assertSame(['en', 'es'], array_column(LanguageRepository::active(), 'language_code'));
    }
}
