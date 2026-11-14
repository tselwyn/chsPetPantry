<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use mysqli;
use PHPUnit\Framework\TestCase;

/*
 * Base class for tests that use the real database code against MySQL/MariaDB.
 *
 * The first test to run drops and rebuilds the database named by
 * PFPMS_DB_NAME from sql/foodpantrydb.sql, so the tests always run against
 * the schema the team actually ships. Each test then starts from that state:
 * the Pet Pantry tables a test can change are emptied in setUp().
 *
 * If no database server is reachable the tests are skipped, not failed, so the
 * Unit suite still runs on a laptop without MySQL.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $built = false;
    private static ?string $unavailable = null;

    /** Tables tests write to; emptied before every test. */
    private const TABLES = [
        'dbparticipantaudit',
        'dbparticipantalerts',
        'dbparticipantdistributions',
        'dbpets',
        'dbparticipants',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$unavailable === null && !self::$built) {
            self::build();
        }
        if (self::$unavailable !== null) {
            $this->markTestSkipped(self::$unavailable);
        }
        $con = $this->db();
        foreach (self::TABLES as $table) {
            $con->query("DELETE FROM `$table`");
            $con->query("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        }
        $con->close();
    }

    private static function build(): void
    {
        $name = (string) getenv('PFPMS_DB_NAME');
        if (!preg_match('/^[A-Za-z0-9_]+_test$/', $name)) {
            self::$unavailable = "PFPMS_DB_NAME must end in _test (got '$name'); refusing to rebuild it.";
            return;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $server = @new mysqli((string) getenv('PFPMS_DB_HOST'), (string) getenv('PFPMS_DB_USER'), (string) getenv('PFPMS_DB_PASS'));
        if ($server->connect_errno) {
            self::$unavailable = 'No MySQL server for integration tests: ' . $server->connect_error;
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            return;
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $server->query("DROP DATABASE IF EXISTS `$name`");
        $server->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->select_db($name);

        // The dump names the real database; point it at the test one instead
        $sql = (string) file_get_contents(PFPMS_ROOT . '/sql/foodpantrydb.sql');
        $sql = preg_replace('/^\s*CREATE DATABASE\b[^;]*;\s*$/mi', '', $sql);
        $sql = preg_replace('/^\s*USE\s+`?[A-Za-z0-9_]+`?\s*;\s*$/mi', '', $sql);

        $server->multi_query($sql);
        do {
            if ($result = $server->store_result()) {
                $result->free();
            }
        } while ($server->more_results() && $server->next_result());
        $server->close();
        self::$built = true;
    }

    protected function db(): mysqli
    {
        return new mysqli((string) getenv('PFPMS_DB_HOST'), (string) getenv('PFPMS_DB_USER'),
            (string) getenv('PFPMS_DB_PASS'), (string) getenv('PFPMS_DB_NAME'));
    }

    /** Insert a participant and return its id. */
    protected function participant(array $values = []): int
    {
        static $n = 0;
        $n++;
        $row = $values + [
            'first_name' => 'Test',
            'last_name' => "Person$n",
            'street_address' => "$n Main St",
            'city' => 'Culpeper',
            'state' => 'VA',
            'zip' => '22701',
            'phone' => sprintf('540555%04d', $n),
            'email' => "person$n@example.com",
            'preferred_language' => 'English',
            'location' => 'Empowering Culpeper',
            'registration_date' => '2026-10-01',
            'status' => 'Active',
            'alert' => 0,
        ];
        $con = $this->db();
        $columns = array_keys($row);
        $stmt = $con->prepare('INSERT INTO dbparticipants (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $stmt->bind_param(str_repeat('s', count($columns)), ...array_map('strval', array_values($row)));
        $stmt->execute();
        $id = (int) $con->insert_id;
        $con->close();
        return $id;
    }

    /** All rows of a query as associative arrays. */
    protected function rows(string $sql): array
    {
        $con = $this->db();
        $rows = $con->query($sql)->fetch_all(MYSQLI_ASSOC);
        $con->close();
        return $rows;
    }
}
