<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Auth\Policy;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Reference\LanguageService;
use Pfpms\Reference\PolicyRepository;
use Pfpms\Reference\PolicyService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Policy texts admin (plan P2A): validation, duplicates, used versions locked, in-force marking. */
final class PolicyServiceTest extends TestCase
{
    private function doc(string $version, string $language, string $from, string $type = Policy::CONFIDENTIALITY, string $body = 'Keep it private.'): int
    {
        return PolicyService::create(['doc_type' => $type, 'version' => $version, 'language_code' => $language,
            'effective_from' => $from, 'body' => $body]);
    }

    public function testCreateNormalisesAndAudits(): void
    {
        $id = PolicyService::create(['doc_type' => 'Programme Consent', 'version' => ' 2026.1 ', 'language_code' => 'es',
            'effective_from' => '2026-09-01', 'body' => "  First paragraph.\r\n\r\nSecond.  "]);
        $doc = PolicyRepository::find($id);
        $this->assertSame('2026.1', $doc['version']);
        $this->assertSame('Spanish', $doc['language_name']);
        $this->assertSame("First paragraph.\n\nSecond.", $doc['body']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'policy_create' AND entity_id = ?", [$id]));
    }

    public function testValidationReportsEveryFieldAtOnce(): void
    {
        try {
            PolicyService::create(['doc_type' => 'Privacy Policy', 'version' => 'version-eleven', 'language_code' => 'xx',
                'effective_from' => '2026-02-30', 'body' => "  \r\n "]);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['doc_type', 'version', 'language_code', 'effective_from', 'body'], array_keys($e->errors));
        }
    }

    public function testAnInactiveLanguageCannotBeChosen(): void
    {
        LanguageService::setActive('es', false);
        try {
            $this->doc('1', 'es', '2026-01-01');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['language_code'], array_keys($e->errors));
        }
    }

    public function testADuplicateVersionIsRefusedButATranslationIsNot(): void
    {
        $this->doc('1', 'en', '2026-01-01');
        $this->doc('1', 'es', '2026-01-01');
        $this->doc('1', 'en', '2026-01-01', 'Retention Notice');
        try {
            $this->doc('1', 'EN', '2026-03-01');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['version'], array_keys($e->errors));
            $this->assertStringContainsString('already exists in English', $e->errors['version']);
        }
    }

    public function testAnUnusedVersionCanBeEditedAndOnlyRealChangesAreAudited(): void
    {
        $id = $this->doc('1', 'en', '2026-01-01');
        PolicyService::update($id, ['effective_from' => '2026-02-01', 'body' => 'Keep it private.']);
        $fields = Db::pdo()->prepare("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                       WHERE a.action = 'policy_update' AND a.entity_id = ?");
        $fields->execute([$id]);
        $this->assertSame([['field_name' => 'effective_from', 'old_value' => '2026-01-01', 'new_value' => '2026-02-01']], $fields->fetchAll());
        PolicyService::update($id, ['effective_from' => '2026-02-01', 'body' => "Keep it private.\r\n"]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'policy_update' AND entity_id = ?", [$id]), 'no-op saves are not audited');
    }

    public function testEditingChangesOnlyTheTextAndStartDate(): void
    {
        $id = $this->doc('1', 'en', '2026-01-01');
        PolicyService::update($id, ['doc_type' => 'Retention Notice', 'version' => '9', 'language_code' => 'es',
            'effective_from' => '2026-01-01', 'body' => 'New wording.']);
        $doc = PolicyRepository::find($id);
        $this->assertSame([Policy::CONFIDENTIALITY, '1', 'en', '2026-01-01', 'New wording.'],
            [$doc['doc_type'], $doc['version'], $doc['language_code'], $doc['effective_from'], $doc['body']]);
        $this->assertSame(['body'], Db::pdo()->query("SELECT f.field_name FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                                        WHERE a.action = 'policy_update'")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testEditingValidatesEveryFieldAndSavesNothing(): void
    {
        $id = $this->doc('1', 'en', '2026-01-01');
        try {
            PolicyService::update($id, ['effective_from' => '2026-13-01', 'body' => " \r\n "]);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['effective_from', 'body'], array_keys($e->errors));
        }
        $doc = PolicyRepository::find($id);
        $this->assertSame(['2026-01-01', 'Keep it private.'], [$doc['effective_from'], $doc['body']]);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'policy_update'"));

        try {
            PolicyService::update($id + 1000, ['effective_from' => '2026-01-01', 'body' => 'Text']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['_form'], array_keys($e->errors));
        }
    }

    public function testTheTextMustFitTheColumnAndBeReadable(): void
    {
        $id = $this->doc('1', 'en', '2026-01-01', body: str_repeat('a', 65535));
        $this->assertSame(65535, strlen(PolicyRepository::find($id)['body']));
        foreach ([str_repeat('a', 65536), "Bad \xC3\x28 bytes"] as $body) {
            try {
                $this->doc('2', 'en', '2026-01-01', body: $body);
                $this->fail('expected a ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['body'], array_keys($e->errors));
            }
        }
    }

    public function testAVersionSomeoneAcceptedIsLocked(): void
    {
        $id = $this->doc('1', 'en', '2026-01-01');
        Policy::acknowledge($this->makeUser()['user_id'], $id);
        $this->assertTrue(PolicyRepository::isUsed($id));
        try {
            PolicyService::update($id, ['effective_from' => '2026-01-01', 'body' => 'Changed wording.']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['_form'], array_keys($e->errors));
        }
        $this->assertSame('Keep it private.', PolicyRepository::find($id)['body']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'policy_update'"));
    }

    public function testAVersionAParticipantAgreedToIsLocked(): void
    {
        $id = $this->doc('1', 'en', '2026-01-01', 'Programme Consent');
        $user = $this->makeUser();
        $site = $this->makeSite('Consent Site');
        Db::pdo()->prepare("INSERT INTO participant (participant_code, legal_first_name, legal_last_name, postal_code, household_size,
                                                     home_site_id, registration_site_id, registered_by)
                            VALUES ('P-TEST-1', 'Ana', 'Lopez', '29401', 2, ?, ?, ?)")->execute([$site, $site, $user['user_id']]);
        $participantId = (int) Db::pdo()->lastInsertId();
        Db::pdo()->prepare('INSERT INTO participant_consent (participant_id, document_id, consenting_person, recorded_by) VALUES (?, ?, ?, ?)')
            ->execute([$participantId, $id, 'Ana Lopez', $user['user_id']]);
        $this->assertSame(['acknowledgements' => 0, 'consents' => 1], PolicyRepository::usage($id));
        $this->expectException(ValidationException::class);
        PolicyService::update($id, ['effective_from' => '2026-03-01', 'body' => 'Keep it private.']);
    }

    public function testInForceIsTheLatestStartDateOnOrBeforeToday(): void
    {
        $v1 = $this->doc('1', 'en', '2026-01-01');
        $v2 = $this->doc('2', 'en', '2026-10-01'); // today: the clock is frozen at 2026-10-01
        $v3 = $this->doc('3', 'en', '2026-11-01');
        $es1 = $this->doc('1', 'es', '2026-01-01');

        $type = $this->overviewOf(Policy::CONFIDENTIALITY);
        $this->assertSame([$v1 => 'replaced', $v2 => 'in_force', $v3 => 'scheduled', $es1 => 'replaced'], $this->statuses($type));
        $shown = array_column($type['shown'], null, 'language_code');
        $this->assertSame($v2, (int) $shown['en']['document']['document_id']);
        $this->assertFalse($shown['en']['fallback']);
        $this->assertSame($v2, (int) $shown['es']['document']['document_id'], 'Spanish readers see English version 2');
        $this->assertTrue($shown['es']['fallback']);

        $es2 = $this->doc('2', 'es', '2026-10-01');
        $type = $this->overviewOf(Policy::CONFIDENTIALITY);
        $this->assertSame('in_force', $this->statuses($type)[$es2]);
        $this->assertSame('in_force', $this->statuses($type)[$v2]);
        $this->assertFalse(array_column($type['shown'], null, 'language_code')['es']['fallback']);
    }

    public function testAVersionStartingTomorrowIsNotYetInForce(): void
    {
        $yesterday = $this->doc('1', 'en', '2026-09-30');
        $tomorrow = $this->doc('2', 'en', '2026-10-02');
        $type = $this->overviewOf(Policy::CONFIDENTIALITY);
        $this->assertSame([$yesterday => 'in_force', $tomorrow => 'scheduled'], $this->statuses($type));
        $this->assertSame($yesterday, (int) array_column($type['shown'], null, 'language_code')['en']['document']['document_id']);
    }

    public function testInForceFollowsTheOrganisationsDateNotTheUtcDate(): void
    {
        $this->setSetting('organisation_time_zone', 'America/New_York');
        $old = $this->doc('1', 'en', '2026-09-01');
        $next = $this->doc('2', 'en', '2026-10-02');

        Clock::freeze('2026-10-02 02:00:00'); // 10 pm on 1 October in New York, already 2 October in UTC
        $this->assertSame($old, (int) Policy::current(Policy::CONFIDENTIALITY)['document_id'], 'a version effective tomorrow is not in force the evening before');
        $this->assertSame([$old => 'in_force', $next => 'scheduled'], $this->statuses($this->overviewOf(Policy::CONFIDENTIALITY)));
        $this->assertSame('2026-10-01', PolicyService::blank(null)['effective_from'], 'a new text starts on the local today');

        Clock::freeze('2026-10-02 03:59:59'); // 11:59:59 pm in New York
        $this->assertSame($old, (int) Policy::current(Policy::CONFIDENTIALITY)['document_id']);
        Clock::freeze('2026-10-02 04:00:00'); // midnight in New York
        $this->assertSame($next, (int) Policy::current(Policy::CONFIDENTIALITY)['document_id']);
        $this->assertSame([$old => 'replaced', $next => 'in_force'], $this->statuses($this->overviewOf(Policy::CONFIDENTIALITY)));
        $this->assertSame('2026-10-02', PolicyService::blank(null)['effective_from']);
    }

    public function testATypeWithNoTextShowsNothingInForce(): void
    {
        $this->doc('1', 'en', '2026-12-01', 'SNV Explanation');
        $type = $this->overviewOf('SNV Explanation');
        $this->assertSame(['scheduled'], array_values($this->statuses($type)));
        $this->assertSame([null, null], array_column($type['shown'], 'document'));
        $this->assertSame([], $this->overviewOf('Retention Notice')['documents']);
    }

    public function testPrefillForANewVersionAndATranslation(): void
    {
        $source = PolicyRepository::find($this->doc('2026.9', 'en', '2026-01-01'));
        $version = PolicyService::prefill($source, 'version');
        $this->assertSame(['2026.10', 'en', ''], [$version['version'], $version['language_code'], $version['effective_from']]);
        $translation = PolicyService::prefill($source, 'translation');
        $this->assertSame(['2026.9', '', '2026-01-01'], [$translation['version'], $translation['language_code'], $translation['effective_from']]);
        $this->assertSame('', PolicyService::nextVersion('draft'));
    }

    private function overviewOf(string $docType): array
    {
        return array_column(PolicyService::overview(), null, 'doc_type')[$docType];
    }

    /** @return array<int, string> document_id => status */
    private function statuses(array $type): array
    {
        $out = [];
        foreach ($type['documents'] as $d) {
            $out[(int) $d['document_id']] = $d['status'];
        }
        ksort($out);
        return $out;
    }
}
