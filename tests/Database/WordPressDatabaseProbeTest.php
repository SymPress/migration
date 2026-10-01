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

    public function testDmlFailureRollsBackAndAdvisoryLockIsReleasedAfterException(): void
    {
        $executor = new WordPressSqlExecutor($this->database);
        self::assertTrue($executor->execute("CREATE TABLE {$this->table} (id bigint NOT NULL, PRIMARY KEY  (id)) ENGINE=InnoDB"));
        $this->database->suppress_errors(true);
        try {
            $statements = ["INSERT INTO {$this->table} (id) VALUES (1)", 'INSERT INTO wp_missing_review_table (id) VALUES (2)'];
            self::assertFalse($executor->runOperation('db-probe', $statements, fn (): bool => $executor->execute($statements)));
            self::assertSame('0', $this->database->get_var("SELECT COUNT(*) FROM {$this->table}"));
            try {
                $executor->runOperation('db-probe', [], static function (): bool { throw new \RuntimeException('probe'); });
                self::fail('Expected callback failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('probe', $exception->getMessage());
            }
            $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $databaseName = $this->database->get_var('SELECT DATABASE()');
            $lock = 'sympress-migration:' . substr(hash('sha256', $databaseName . ':' . $this->database->prefix . ':db-probe'), 0, 40);
            self::assertTrue($executor->runOperation('db-probe', [], static fn (): bool => $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === '0'));
            self::assertSame('1', $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock)));
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        } finally {
            $this->database->suppress_errors(false);
        }
    }

    public function testDdlHistoryFailureKeepsAppliedSqlButRollsBackMetadata(): void
    {
        $executor = new WordPressSqlExecutor($this->database);
        $tracker = new MigrationTracker($this->database);
        $lifecycle = new \SymPress\WordPress\Migration\Application\MigrationLifecycle($tracker, $executor);
        self::assertTrue($lifecycle->ensureStorageIsReady());
        $this->database->query("CREATE TRIGGER sympress_review_history_failure BEFORE INSERT ON {$this->historyTable} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Review fixture failure'");
        $migration = new class($this->table) implements \SymPress\WordPress\Migration\Contract\Migration {
            public function __construct(private string $table) {}
            public function getMigrationKey(): string { return 'ddl-history-failure'; }
            public function getVersion(): string { return '1.0.0'; }
            public function up(): array { return ["CREATE TABLE {$this->table} (id bigint NOT NULL, PRIMARY KEY  (id)) ENGINE=InnoDB"]; }
            public function down(): array { return []; }
        };
        $this->database->suppress_errors(true);
        try {
            self::assertFalse($lifecycle->migrate(\SymPress\WordPress\Migration\Value\PluginSlug::fromString('db-probe'), $migration));
            self::assertSame($this->table, $this->database->get_var($this->database->prepare('SHOW TABLES LIKE %s', $this->database->esc_like($this->table))));
            self::assertNull($tracker->findRecord('db-probe', 'ddl-history-failure'));
            self::assertSame([], $tracker->findHistoryForPlugin('db-probe'));
        } finally {
            $this->database->suppress_errors(false);
        }
    }

    private function dropProbeTables(): void
    {
        foreach ([$this->table, $this->stateTable, $this->historyTable] as $table) {
            $this->database->query($this->database->prepare('DROP TABLE IF EXISTS %i', $table));
        }
    }
}
