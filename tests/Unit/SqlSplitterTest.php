<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Db\SqlSplitter;
use RuntimeException;

final class SqlSplitterTest extends TestCase
{
    public function testQuotesAndCommentsDoNotSplit(): void
    {
        $sql = "-- note; not a statement\nINSERT INTO t VALUES ('a;b', \"c;d\", `e;f`); # trailing; comment\n"
            . "/* block; */ SELECT 'it''s;';\nSELECT 'back\\'slash;';";
        $this->assertSame(["INSERT INTO t VALUES ('a;b', \"c;d\", `e;f`)", "SELECT 'it''s;'", "SELECT 'back\\'slash;'"], SqlSplitter::split($sql));
    }

    public function testDelimiterDirectiveForTriggers(): void
    {
        $sql = "SELECT 1; DELIMITER //\nCREATE TRIGGER x BEFORE UPDATE ON t FOR EACH ROW BEGIN SET @a = 1; END//\nDELIMITER ;\nSELECT 2;";
        $this->assertSame(['SELECT 1', 'CREATE TRIGGER x BEFORE UPDATE ON t FOR EACH ROW BEGIN SET @a = 1; END', 'SELECT 2'], SqlSplitter::split($sql));
    }

    public function testColumnNamedDelimiterIsNotADirective(): void
    {
        $this->assertCount(1, SqlSplitter::split("CREATE TABLE p (\n  id INT,\n  delimiter CHAR(1)\n);"));
    }

    public function testCommentsKeepTokensApart(): void
    {
        $this->assertSame(['SELECT 1 0'], SqlSplitter::split('SELECT 1/*c*/0;'));
    }

    public function testBomAndCrlf(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("\xEF\xBB\xBFSELECT 1;\r\nSELECT 2;\r\n"));
    }

    public function testUnterminatedCommentIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SqlSplitter::split("SELECT 1; /* never closed\nSELECT 2;");
    }

    public function testExecutableCommentIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SqlSplitter::split('/*!50100 SELECT 1 */;');
    }

    public function testRealMigrationsSplit(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertCount(118, SqlSplitter::split((string) file_get_contents("$root/migrations/0001_schema_v2_0_1.sql")));
        $this->assertCount(14, SqlSplitter::split((string) file_get_contents("$root/migrations/optional/9001_immutability_triggers.sql")));
    }
}
