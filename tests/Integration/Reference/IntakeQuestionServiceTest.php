<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Db;
use Pfpms\Reference\IntakeQuestionRepository;
use Pfpms\Reference\IntakeQuestionService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Intake questions (US-09): validation, Choice options, immutability once answered, retire and restore, reorder, audit. */
final class IntakeQuestionServiceTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser(['role' => 'Coordinator'])['user_id'];
    }

    public function testCreateStoresChoicesAsAJsonListAndAudits(): void
    {
        $id = IntakeQuestionService::create(['prompt' => '  How did you   hear about us? ', 'answer_type' => 'Choice',
            'options' => "Friend\r\n\r\n  Flyer \nSocial media\n", 'is_required' => '1'], $this->userId);
        $q = IntakeQuestionRepository::find($id);
        $this->assertSame('How did you hear about us?', $q['prompt']);
        $this->assertSame(['Friend', 'Flyer', 'Social media'], IntakeQuestionService::optionList($q['options']));
        $this->assertSame(1, (int) $q['is_required']);
        $this->assertSame($this->userId, (int) $q['created_by']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'intake_question_create' AND entity_id = ?", [$id]));
        $this->assertSame([['question_id' => $id, 'prompt' => 'How did you hear about us?', 'answer_type' => 'Choice',
            'options' => ['Friend', 'Flyer', 'Social media'], 'is_required' => true]], IntakeQuestionService::active());
    }

    public function testChoiceNeedsAtLeastTwoDistinctShortOptions(): void
    {
        foreach (["Only one\n\n  \n", "Yes\nyes", "Fine\n" . str_repeat('x', 101), ''] as $options) {
            try {
                IntakeQuestionService::create(['prompt' => 'Pick one', 'answer_type' => 'Choice', 'options' => $options], $this->userId);
                $this->fail('expected a ValidationException for ' . json_encode($options));
            } catch (ValidationException $e) {
                $this->assertSame(['options'], array_keys($e->errors));
            }
        }
        $id = IntakeQuestionService::create(['prompt' => 'Pick one', 'answer_type' => 'Choice', 'options' => ['Sí', 'No']], $this->userId);
        $this->assertSame(['Sí', 'No'], IntakeQuestionService::optionList(IntakeQuestionRepository::find($id)['options']));
    }

    public function testOtherTypesStoreNoOptionsAndBadInputIsReportedPerField(): void
    {
        $id = IntakeQuestionService::create(['prompt' => 'Number of cats indoors', 'answer_type' => 'Number', 'options' => "a\nb"], $this->userId);
        $this->assertNull(IntakeQuestionRepository::find($id)['options']);
        $this->assertSame(0, (int) IntakeQuestionRepository::find($id)['is_required']);
        try {
            IntakeQuestionService::create(['prompt' => str_repeat('x', 256), 'answer_type' => 'Essay'], $this->userId);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['prompt', 'answer_type'], array_keys($e->errors));
        }
    }

    public function testUnansweredQuestionsCanBeEditedAndNoOpSavesAreNotAudited(): void
    {
        $id = IntakeQuestionService::create(['prompt' => 'Housing', 'answer_type' => 'Choice', 'options' => "Rent\nOwn"], $this->userId);
        IntakeQuestionService::update($id, ['prompt' => 'Housing', 'answer_type' => 'Choice', 'options' => "Rent\nOwn"]);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'intake_question_update' AND entity_id = ?", [$id]),
            'the stored JSON may be re-formatted by the database; that is not a change');
        IntakeQuestionService::update($id, ['prompt' => 'Do you rent or own your home?', 'answer_type' => 'Choice', 'options' => "Rent\nOwn\nOther"]);
        $fields = Db::pdo()->prepare("SELECT f.field_name FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                      WHERE a.action = 'intake_question_update' AND a.entity_id = ? ORDER BY f.field_name");
        $fields->execute([$id]);
        $this->assertSame(['options', 'prompt'], $fields->fetchAll(\PDO::FETCH_COLUMN));
        IntakeQuestionService::update($id, ['prompt' => 'Do you rent?', 'answer_type' => 'Yes/No']);
        $this->assertNull(IntakeQuestionRepository::find($id)['options']);
    }

    public function testAnsweredQuestionsKeepTheirWordingTypeAndChoices(): void
    {
        $id = IntakeQuestionService::create(['prompt' => 'Housing', 'answer_type' => 'Choice', 'options' => "Rent\nOwn"], $this->userId);
        $this->answer($id, 'Rent');
        foreach ([
            ['prompt' => 'Home', 'answer_type' => 'Choice', 'options' => "Rent\nOwn"],
            ['prompt' => 'Housing', 'answer_type' => 'Text'],
            ['prompt' => 'Housing', 'answer_type' => 'Choice', 'options' => "Rent\nOwn\nOther"],
        ] as $input) {
            try {
                IntakeQuestionService::update($id, $input);
                $this->fail('expected a ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['_form'], array_keys($e->errors));
            }
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'intake_question_update' AND entity_id = ?", [$id]),
            'refused changes are not audited');
        // Required and order may still change.
        IntakeQuestionService::update($id, ['prompt' => 'Housing', 'answer_type' => 'Choice', 'options' => "Rent\nOwn", 'is_required' => '1']);
        $this->assertSame(1, (int) IntakeQuestionRepository::find($id)['is_required']);
        $this->assertSame([['field_name' => 'is_required', 'old_value' => '0', 'new_value' => '1']], $this->fieldChanges('intake_question_update', $id));
        IntakeQuestionService::setRequired($id, false);
        $this->assertSame(0, (int) IntakeQuestionRepository::find($id)['is_required']);
        $this->assertSame('Housing', IntakeQuestionRepository::find($id)['prompt']);
    }

    public function testRetiringKeepsAnswersAndRestoringAsksItAgainAtTheEnd(): void
    {
        $a = IntakeQuestionService::create(['prompt' => 'A', 'answer_type' => 'Text'], $this->userId);
        $b = IntakeQuestionService::create(['prompt' => 'B', 'answer_type' => 'Date'], $this->userId);
        $this->answer($a, 'yes');
        IntakeQuestionService::retire($a);
        IntakeQuestionService::retire($a);
        $this->assertSame(self::NOW, IntakeQuestionRepository::find($a)['retired_at']);
        $this->assertSame([$b], array_column(IntakeQuestionService::active(), 'question_id'));
        $this->assertSame([$a], array_map('intval', array_column(IntakeQuestionRepository::retired(), 'question_id')));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM intake_answer WHERE question_id = ?', [$a]), 'answers are kept');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'intake_question_retire' AND entity_id = ?", [$a]));
        $c = IntakeQuestionService::create(['prompt' => 'C', 'answer_type' => 'Text'], $this->userId);
        IntakeQuestionService::restore($a);
        IntakeQuestionService::restore($a);
        $this->assertNull(IntakeQuestionRepository::find($a)['retired_at']);
        $this->assertSame([$b, $c, $a], array_column(IntakeQuestionService::active(), 'question_id'));
        $this->assertSame([['field_name' => 'display_order', 'old_value' => '1', 'new_value' => '4'], ['field_name' => 'retired_at', 'old_value' => self::NOW, 'new_value' => null]],
            $this->fieldChanges('intake_question_restore', $a), 'restoring twice is audited once');
        $this->assertSame('yes', $this->scalar('SELECT answer_value FROM intake_answer WHERE question_id = ?', [$a]), 'the answer survives retire and restore');
    }

    /** @return list<array{field_name:string,old_value:?string,new_value:?string}> */
    private function fieldChanges(string $action, int $questionId): array
    {
        $st = Db::pdo()->prepare('SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                  WHERE a.action = ? AND a.entity_id = ? ORDER BY a.audit_id, f.field_name');
        $st->execute([$action, $questionId]);
        return $st->fetchAll();
    }

    public function testQuestionsCanBeReordered(): void
    {
        $a = IntakeQuestionService::create(['prompt' => 'A', 'answer_type' => 'Text'], $this->userId);
        $b = IntakeQuestionService::create(['prompt' => 'B', 'answer_type' => 'Text'], $this->userId);
        $c = IntakeQuestionService::create(['prompt' => 'C', 'answer_type' => 'Text'], $this->userId);
        $this->answer($c, 'x');
        IntakeQuestionService::move($c, 'up');
        $this->assertSame([$a, $c, $b], array_column(IntakeQuestionService::active(), 'question_id'), 'answered questions can still move');
        IntakeQuestionService::move($a, 'up');
        IntakeQuestionService::move($b, 'down');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'intake_question_reorder'"));
        IntakeQuestionService::retire($b);
        $this->expectException(ValidationException::class);
        IntakeQuestionService::move($b, 'up');
    }

    /** Record an answer, with the minimum participant it needs. */
    private function answer(int $questionId, string $value): void
    {
        static $n = 0;
        $n++;
        $site = $this->makeSite('Intake test site ' . $n);
        Db::pdo()->prepare('INSERT INTO participant (participant_code, legal_first_name, legal_last_name, postal_code, household_size,
                                                     home_site_id, registration_site_id, registered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['TQ' . $n, 'Test', 'Owner', '29401', 1, $site, $site, $this->userId]);
        Db::pdo()->prepare('INSERT INTO intake_answer (participant_id, question_id, answer_value) VALUES (?, ?, ?)')
            ->execute([(int) Db::pdo()->lastInsertId(), $questionId, $value]);
    }
}
