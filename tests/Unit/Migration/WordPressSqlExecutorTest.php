<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;

final class WordPressSqlExecutorTest extends TestCase
{
    private \wpdb $database;
    private WordPressSqlExecutor $executor;

    #[\Override]
    protected function setUp(): void
    {
        WordPressState::reset();
        $this->database = new \wpdb();
        $GLOBALS['wpdb'] = $this->database;
        $this->executor = new WordPressSqlExecutor($this->database);
    }

    public function test_it_routes_create_table_through_db_delta_and_other_sql_through_wpdb(): void
    {
        self::assertTrue($this->executor->execute([
            ' CREATE TABLE wp_orders (id bigint unsigned NOT NULL); ',
            '',
            'ALTER TABLE wp_orders ADD INDEX idx_id (id);',
        ]));

        self::assertSame(
            ['CREATE TABLE wp_orders (id bigint unsigned NOT NULL);'],
            WordPressState::$dbDelta,
        );
        self::assertSame([
            'CREATE TABLE wp_orders (id bigint unsigned NOT NULL);',
            'ALTER TABLE wp_orders ADD INDEX idx_id (id);',
        ], $this->database->executedStatements);
    }

    public function test_it_stops_after_the_first_database_error(): void
    {
        $failed = 'ALTER TABLE wp_orders ADD INDEX idx_id (id);';
        $this->database->failedStatements[] = $failed;

        self::assertFalse($this->executor->execute([
            $failed,
            'DROP TABLE IF EXISTS wp_orders;',
        ]));
        self::assertSame([$failed], $this->database->executedStatements);
    }
}
