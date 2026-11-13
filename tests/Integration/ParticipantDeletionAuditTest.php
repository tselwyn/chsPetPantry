<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

/** UC-09 / NFR-SEC-007: deleting a participant saves an audit record. */
final class ParticipantDeletionAuditTest extends DatabaseTestCase
{
    public function testDeletingAParticipantRecordsTheAccountTimeAndPreviousStatus(): void
    {
        $id = $this->participant(['status' => 'Inactive']);
        $before = time();

        $this->assertTrue(delete_participant($id, 'chsadmin'));

        $participant = $this->rows("SELECT status FROM dbparticipants WHERE id = $id");
        $this->assertSame('Deleted', $participant[0]['status'], 'the participant is kept, marked Deleted');

        $audit = $this->rows('SELECT * FROM dbparticipantaudit');
        $this->assertCount(1, $audit);
        $this->assertSame($id, (int) $audit[0]['participant_id']);
        $this->assertSame('Deleted', $audit[0]['action']);
        $this->assertSame('Inactive', $audit[0]['previous_status']);
        $this->assertSame('chsadmin', $audit[0]['account_id']);
        $this->assertEqualsWithDelta($before, strtotime($audit[0]['created_at']), 5, 'time is recorded in the app time zone');
    }

    public function testDeletingTwiceRecordsItOnce(): void
    {
        $id = $this->participant();
        $this->assertTrue(delete_participant($id, 'chsadmin'));
        $this->assertFalse(delete_participant($id, 'chsadmin'), 'already deleted');
        $this->assertCount(1, $this->rows('SELECT id FROM dbparticipantaudit'));
    }

    public function testNoDeletionWithoutAnAccount(): void
    {
        $id = $this->participant();
        $this->assertFalse(delete_participant($id, ''));
        $this->assertSame('Active', $this->rows("SELECT status FROM dbparticipants WHERE id = $id")[0]['status']);
        $this->assertCount(0, $this->rows('SELECT id FROM dbparticipantaudit'));
    }

    public function testAMissingParticipantIsNotAudited(): void
    {
        $this->assertFalse(delete_participant(999999, 'chsadmin'));
        $this->assertCount(0, $this->rows('SELECT id FROM dbparticipantaudit'));
    }

    public function testTheAuditIsReadBackNewestFirst(): void
    {
        $id = $this->participant();
        delete_participant($id, 'chsadmin');
        $audit = get_participant_deletion_audit($id);
        $this->assertCount(1, $audit);
        $this->assertSame('chsadmin', $audit[0]['account_id']);
        $this->assertSame([], get_participant_deletion_audit($this->participant()));
    }
}
