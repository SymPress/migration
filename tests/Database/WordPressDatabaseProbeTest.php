<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Database;

use PHPUnit\Framework\TestCase;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use SymPress\WordPress\Migration\Value\MigrationRecord;

final class WordPressDatabaseProbeTest extends TestCase
{
    private \wpdb $database;
    private string $table;
    private string $stateTable;
    private string $historyTable;

    #[\Override]
    protected function setUp(): void
    {
        self::assertArrayHasKey('wpdb', $GLOBALS);
        self::assertInstanceOf(\wpdb::class, $GLOBALS['wpdb']);

        $this->database = $GLOBALS['wpdb'];
        $this->table = $this->database->prefix . 'sympress_migration_probe';
        $this->stateTable = $this->database->prefix . 'migrations';
        $this->historyTable = $this->database->prefix . 'migration_history';
        $this->dropProbeTables();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->dropProbeTables();
    }

    public function test_it_executes_migration_sql_against_wordpress_and_mariadb(): void
    {
        $executor = new WordPressSqlExecutor($this->database);
        $charsetCollate = $this->database->get_charset_collate();

        self::assertTrue($executor->execute(<<<SQL
            CREATE TABLE {$this->table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                label varchar(191) NOT NULL,
                PRIMARY KEY  (id)
            ) {$charsetCollate};
            SQL));
        self::assertSame($this->table, $this->database->get_var($this->database->prepare(
            'SHOW TABLES LIKE %s',
            $this->database->esc_like($this->table),
        )));

        $insert = $this->database->prepare(
            "INSERT INTO {$this->table} (label) VALUES (%s)",
            'database probe',
        );
        self::assertIsString($insert);
        self::assertTrue($executor->execute($insert));
        self::assertSame('database probe', $this->database->get_var(
            "SELECT label FROM {$this->table} WHERE id = 1",
        ));

        self::assertTrue($executor->execute(
            "ALTER TABLE {$this->table} ADD INDEX idx_label (label)",
        ));
        self::assertSame('idx_label', $this->database->get_var(
            $this->database->prepare(
                'SHOW INDEX FROM %i WHERE Key_name = %s',
                $this->table,
                'idx_label',
            ),
            2,
        ));
    }

    public function test_it_persists_current_state_and_append_only_history(): void
    {
        $tracker = new MigrationTracker($this->database);
        $record = new MigrationRecord(
            'database-probe',
            'DatabaseProbeMigration',
            '1.0.0',
            '2026-07-15 10:00:00',
        );

        self::assertTrue($tracker->ensureTableExists());
        self::assertTrue($tracker->saveRecord($record));
        self::assertSame('1.0.0', $tracker->getVersion('database-probe', 'DatabaseProbeMigration'));
        self::assertTrue($tracker->appendHistory(new MigrationExecution(
            'database-probe',
            'DatabaseProbeMigration',
            '1.0.0',
            'up',
            '2026-07-15 10:00:00',
        )));

        self::assertTrue($tracker->deleteRecord('database-probe', 'DatabaseProbeMigration'));
        self::assertTrue($tracker->appendHistory(new MigrationExecution(
            'database-probe',
            'DatabaseProbeMigration',
            '1.0.0',
            'down',
            '2026-07-15 10:01:00',
        )));
        self::assertNull($tracker->findRecord('database-probe', 'DatabaseProbeMigration'));

        $history = $tracker->findHistoryForPlugin('database-probe');
        self::assertCount(2, $history);
        self::assertSame(['down', 'up'], array_map(
            static fn (MigrationExecution $execution): string => $execution->direction,
            $history,
        ));
        self::assertSame(['2026-07-15 10:01:00', '2026-07-15 10:00:00'], array_map(
            static fn (MigrationExecution $execution): string => $execution->executedAt,
            $history,
        ));
    }

    private function dropProbeTables(): void
    {
        foreach ([$this->table, $this->stateTable, $this->historyTable] as $table) {
            $this->database->query($this->database->prepare('DROP TABLE IF EXISTS %i', $table));
        }
    }
}
