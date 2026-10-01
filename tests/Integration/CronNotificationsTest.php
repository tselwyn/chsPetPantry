<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Auth\SessionStore;
use Pfpms\Auth\SiteAccess;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Cron\Jobs\ExpireSessions;
use Pfpms\Cron\Jobs\SendMail;
use Pfpms\Cron\Runner;
use Pfpms\Db;
use Pfpms\Http\Context;
use Pfpms\Mail\Mailer;
use Pfpms\Notify\Notifications;
use Pfpms\Security\Crypto;
use Pfpms\Tests\TestCase;

/** Scheduled jobs (mail outbox, session expiry) and the notification work queue. */
final class CronNotificationsTest extends TestCase
{
    private array $savedConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedConfig = Config::snapshot();
        Mailer::$sent = [];
    }

    protected function tearDown(): void
    {
        Config::override($this->savedConfig);
        parent::tearDown();
    }

    public function testQueuedMailIsSentAndItsBodyWiped(): void
    {
        $id = $this->queue('volunteer@example.test', 'Your voucher', 'Voucher 7K3P-QX for Rex');
        $result = Runner::run(new SendMail());
        $this->assertSame('ok', $result['status']);
        $this->assertSame([['to' => 'volunteer@example.test', 'subject' => 'Your voucher', 'body' => 'Voucher 7K3P-QX for Rex']], Mailer::$sent);
        $this->assertSame('Sent', $this->scalar('SELECT status FROM outbound_message WHERE message_id = ?', [$id]));
        $this->assertSame('', $this->scalar('SELECT body_ciphertext FROM outbound_message WHERE message_id = ?', [$id]), 'sent bodies are not kept');
    }

    public function testFailingMailBacksOffThenGivesUpAndNotifies(): void
    {
        Config::override(['mail' => ['transport' => 'smtp', 'from_email' => 'x@example.test', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'secure' => '']]] + $this->savedConfig);
        $id = $this->queue('nobody@example.test', 'Hello', 'Body');
        for ($attempt = 1; $attempt <= SendMail::MAX_ATTEMPTS; $attempt++) {
            (new SendMail())->run();
            $this->assertSame($attempt, (int) $this->scalar('SELECT attempts FROM outbound_message WHERE message_id = ?', [$id]));
            (new SendMail())->run(); // not due yet: nothing happens
            $this->assertSame($attempt, (int) $this->scalar('SELECT attempts FROM outbound_message WHERE message_id = ?', [$id]), 'backoff respected');
            Clock::advance('+13 hours');
        }
        $this->assertSame('Failed', $this->scalar('SELECT status FROM outbound_message WHERE message_id = ?', [$id]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'mail_failed' AND entity_id = ?", [$id]));
    }

    public function testSessionsExpireJob(): void
    {
        $user = $this->makeUser();
        $stale = SessionStore::create($user['user_id'], null);
        Clock::advance('+20 minutes');
        $fresh = SessionStore::create($user['user_id'], null);
        Clock::advance('+15 minutes');
        (new ExpireSessions())->run();
        $this->assertSame('Timeout', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$stale]));
        $this->assertNull($this->scalar('SELECT ended_at FROM user_session WHERE session_id = ?', [$fresh]));
    }

    public function testRoleNotificationsAreScopedBySiteAndResolvedOnceForEveryone(): void
    {
        $north = $this->makeSite('Notify North');
        $south = $this->makeSite('Notify South');
        $admin1 = $this->ctx($this->makeUser(['role' => 'Administrator']));
        $admin2 = $this->ctx($this->makeUser(['role' => 'Administrator']));
        $coordinator = $this->makeUser(['role' => 'Coordinator']);
        // starts_at from the frozen clock: the column default is the real time, which may be after the test's NOW.
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, granted_by, starts_at) VALUES (?, ?, ?, ?)')
            ->execute([$coordinator['user_id'], $north, $admin1->userId(), Clock::db()]);
        $coordNorth = $this->ctx($coordinator);
        $volunteer = $this->ctx($this->makeUser());

        Notifications::toRole('Administrator', null, 'test', 'For all Administrators');
        Notifications::toRole('Coordinator', $south, 'test', 'For Coordinators at South');
        Notifications::toRole('Coordinator', $north, 'test', 'For Coordinators at North');
        Notifications::toUser($volunteer->userId(), 'test', 'Just for the volunteer');

        $this->assertSame(['For all Administrators'], array_column(Notifications::listFor($admin1), 'message'));
        $this->assertSame(['For Coordinators at North'], array_column(Notifications::listFor($coordNorth), 'message'), 'not the other site');
        $this->assertSame(['Just for the volunteer'], array_column(Notifications::listFor($volunteer), 'message'));

        $id = (int) Notifications::listFor($admin1)[0]['notification_id'];
        $this->assertFalse(Notifications::resolve($volunteer, $id), 'cannot resolve what is not yours');
        $this->assertTrue(Notifications::resolve($admin2, $id));
        $this->assertSame(0, Notifications::openCountFor($admin1), 'resolved for every Administrator');
        $this->assertFalse(Notifications::resolve($admin1, $id), 'already resolved');
        $this->assertCount(1, Notifications::listFor($admin1, resolved: true));
    }

    private function queue(string $to, string $subject, string $body): int
    {
        Db::pdo()->prepare("INSERT INTO outbound_message (recipient, template_key, subject, body_ciphertext, not_before, created_at) VALUES (?, 'test', ?, ?, ?, ?)")
            ->execute([$to, $subject, Crypto::encrypt($body, 'outbound_message'), Clock::db(), Clock::db()]);
        return (int) Db::pdo()->lastInsertId();
    }

    private function ctx(array $user): Context
    {
        $sites = SiteAccess::sitesFor($user);
        return new Context($user, str_repeat('a', 64), $sites[0]['site_id'] ?? null, $sites);
    }
}
