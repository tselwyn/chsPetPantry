<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

/** UC-15 / FR-049: the participant status report includes DND participants. */
final class ParticipantReportsTest extends DatabaseTestCase
{
    public function testTheStatusReportListsActiveInactiveExpiredAndDndButNotDeleted(): void
    {
        foreach (['Active', 'Inactive', 'Expired', 'DND', 'Deleted'] as $status) {
            $this->participant(['status' => $status, 'last_name' => "Status$status"]);
        }

        $statuses = array_column(get_participant_status_report(), 'status');

        $this->assertSame(['Active', 'Inactive', 'Expired', 'DND'], $statuses, 'grouped in this order');
    }

    public function testTheStatusReportStillFiltersByLocation(): void
    {
        $this->participant(['status' => 'DND', 'location' => 'Senior Center']);
        $this->participant(['status' => 'DND', 'location' => 'Home Delivery']);

        $rows = get_participant_status_report('Senior Center');

        $this->assertCount(1, $rows);
        $this->assertSame('Senior Center', $rows[0]['location']);
    }
}
