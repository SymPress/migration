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

    public function testDeferredPlanningFailureAlwaysReleasesTheLock(): void
    {
        $database = new class extends \wpdb {
            public bool $locked = false;

            public function get_var(string $query): string|int|null
            {
                if (str_starts_with($query, 'SELECT GET_LOCK(')) {
                    $this->locked = true;
                } elseif (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
                    $this->locked = false;
                }
                return parent::get_var($query);
            }
        };
        try {
            (new WordPressSqlExecutor($database))->runDeferredOperation('broken-plan', static function (): array {
                throw new \RuntimeException('Cannot inspect live schema.');
            }, static fn (): bool => false);
            self::fail('The planning error must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Cannot inspect live schema.', $exception->getMessage());
        }
        self::assertFalse($database->locked);
        self::assertSame([], $database->executedStatements);
    }

    public function testPluginsPrefixesAndMetadataShareOneLockAndNestedExecutorsReuseOwnership(): void
    {
        $database = new class extends \wpdb {
            /** @var list<string> */ public array $locks = [];
            public function get_var(string $query): string|int|null
            {
                if (str_starts_with($query, 'SELECT GET_LOCK(')) { $this->locks[] = $query; }
                return parent::get_var($query);
            }
        };
        $executor = new WordPressSqlExecutor($database, 0);
        self::assertTrue($executor->runOperation('plugin-one', [], fn (): bool => (new WordPressSqlExecutor($database))->runOperation('metadata', [], static fn (): bool => true)));
        self::assertCount(1, $database->locks);
        $database->prefix = 'other_';
        self::assertTrue($executor->runOperation('plugin-two', [], static fn (): bool => true));
        self::assertSame($database->locks[0], $database->locks[1]);
        self::assertStringContainsString(', 0)', $database->locks[0]);
    }

    public function testReconnectedSessionIsRejectedBeforeSqlAndMetadataWrites(): void
    {
        $database = new class extends \wpdb {
            public int $session = 100;
            public function get_var(string $query): string|int|null
            {
                return $query === 'SELECT CONNECTION_ID()' ? $this->session : parent::get_var($query);
            }
        };
        $GLOBALS['wpdb'] = $database;
        $executor = new WordPressSqlExecutor($database);
        try {
            $executor->runDeferredOperation('shared-table', function () use ($database): array {
                $database->session = 101;
                return ['ALTER TABLE wp_shared ADD COLUMN stale int'];
            }, fn (array $sql): bool => $executor->execute($sql));
            self::fail('A reconnected session cannot write migration SQL.');
        } catch (\SymPress\WordPress\Migration\Exception\MigrationOperationException) {
            self::assertSame([], $database->executedStatements);
        }
        $database->session = 100;
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($database);
        self::assertTrue($tracker->ensureTableExists());
        try {
            $executor->runOperation('metadata', [], function () use ($database, $tracker): bool {
                $database->session = 101;
                return $tracker->record('plugin', 'stable', '1.0.0');
            });
            self::fail('A reconnected session cannot write metadata.');
        } catch (\SymPress\WordPress\Migration\Exception\MigrationOperationException) {
            self::assertSame([], $tracker->findRecordsForPlugin('plugin'));
        }
    }
}
