<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\CreatesMigrationManagers;
use SymPress\WordPress\Migration\Tests\Support\SchemaHashMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;
use SymPress\WordPress\Migration\Contract\Migration;

final class MigrationManagerTest extends TestCase
{
    use CreatesMigrationManagers;

    private \wpdb $database;
    private MigrationManager $manager;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        $this->database = new \wpdb();
        $GLOBALS['wpdb'] = $this->database;
        $this->manager = $this->createMigrationManager(
            $this->database,
            [
                new CreateCustomersTableMigration($this->database),
                new AddCustomersEmailIndexMigration($this->database),
            ],
        );
    }

    public function test_it_runs_pending_migrations_and_tracks_them_by_class_name(): void
    {
        self::assertTrue($this->manager->runMigrations());
        self::assertTrue($this->database->hasTable('wp_customers'));
        self::assertFalse($this->manager->hasPendingMigrations());
        self::assertCount(2, $this->manager->getMigratedVersions());
        self::assertSame(
            CreateCustomersTableMigration::class,
            $this->manager->getMigratedVersions()[0]['migration'],
        );
        self::assertContains(
            'ALTER TABLE wp_customers ADD INDEX idx_email (email);',
            $this->database->executedStatements,
        );
        self::assertCount(2, $this->manager->getMigrationHistory());
        self::assertSame('up', $this->manager->getMigrationHistory()[0]['direction']);
    }

    public function testSqlPlansAreResolvedOnlyWhileTheDatabaseLockIsHeld(): void
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
        $GLOBALS['wpdb'] = $database;
        $migration = new class ($database) implements Migration {
            public function __construct(private readonly \wpdb $database)
            {
            }

            public function getMigrationKey(): string
            {
                return 'deferred-schema';
            }

            public function getVersion(): string
            {
                return 'schema:deferred';
            }

            public function up(): array
            {
                TestCase::assertTrue($this->database->locked, 'Schema planning must run after GET_LOCK.');
                return ['CREATE TABLE wp_deferred (id INT)'];
            }

            public function down(): array
            {
                TestCase::assertTrue($this->database->locked, 'Rollback planning must run after GET_LOCK.');
                return ['DROP TABLE wp_deferred'];
            }
        };
        $manager = $this->createMigrationManager($database, [$migration]);
        self::assertTrue($manager->runMigrations());
        self::assertFalse($database->locked);
        self::assertTrue($manager->rollbackMigrations());
        self::assertFalse($database->locked);
    }

    public function test_it_can_migrate_to_specific_versions_and_report_current_state(): void
    {
        self::assertTrue($this->manager->migrateTo('1.0.0'));
        self::assertCount(1, $this->manager->getMigratedVersions());
        self::assertSame('1.0.0', $this->manager->getCurrentMigration()['version']);
        self::assertSame('1.0.1', $this->manager->getLatestMigration()['version']);
        self::assertFalse($this->manager->isUpToDate());

        self::assertTrue($this->manager->migrateTo('1.0.1'));
        self::assertTrue($this->manager->isUpToDate());
        self::assertSame('1.0.1', $this->manager->getCurrentMigration()['version']);

        self::assertTrue($this->manager->migrateTo('1.0.0'));
        self::assertCount(1, $this->manager->getMigratedVersions());
        self::assertSame('1.0.0', $this->manager->getCurrentMigration()['version']);
    }

    public function test_it_rolls_back_in_reverse_order_and_tracks_history(): void
    {
        $this->manager->runMigrations();
        $this->database->executedStatements = [];

        self::assertTrue($this->manager->rollbackMigrations());
        self::assertSame(
            'ALTER TABLE wp_customers DROP INDEX idx_email;',
            $this->database->executedStatements[0],
        );
        self::assertSame(
            'DROP TABLE IF EXISTS wp_customers;',
            $this->database->executedStatements[3],
        );
        self::assertSame([], $this->manager->getMigratedVersions());
        self::assertSame('down', $this->manager->getMigrationHistory()[0]['direction']);
        self::assertCount(4, $this->manager->getMigrationHistory());
    }

    public function testForwardOnlyTargetNeverInvokesRollbackAndLibraryDefaultRemainsCompatible(): void
    {
        WordPressState::$environmentType = 'production';
        self::assertTrue($this->manager->migrateTo('1.0.1', false));
        $state = $this->database->migrationRows;
        $history = $this->database->migrationHistoryRows;
        $this->database->executedStatements = [];
        self::assertFalse($this->manager->migrateTo('1.0.0', false));
        self::assertSame($state, $this->database->migrationRows);
        self::assertSame($history, $this->database->migrationHistoryRows);
        self::assertNotContains('ALTER TABLE wp_customers DROP INDEX idx_email;', $this->database->executedStatements);
        self::assertTrue($this->manager->migrateTo('1.0.0'));
        self::assertCount(1, $this->manager->getMigratedVersions());
    }

    public function testForwardOnlyTargetsApplyChangedSchemaHashesAndRejectBackwardStableKeys(): void
    {
        $schema = $this->identityMigration('schema', 'schema:old');
        $later = $this->identityMigration('later', '1.0.0');
        $manager = $this->createMigrationManager($this->database, [$schema, $later]);
        self::assertTrue($manager->migrateTo(null, false));
        $manager->replaceMigration($this->identityMigration('schema', 'schema:new'));
        self::assertTrue($manager->migrateTo('later', false));
        self::assertFalse($manager->hasPendingMigrations());
        $history = $manager->getMigrationHistory();
        self::assertFalse($manager->migrateTo('schema', false));
        self::assertSame($history, $manager->getMigrationHistory());
        self::assertCount(2, $manager->getMigratedVersions());
        self::assertNotContains('SELECT down_later', $this->database->executedStatements);
    }

    public function test_it_stops_rollback_when_a_migration_fails(): void
    {
        $this->manager->runMigrations();
        $this->database->executedStatements = [];
        $this->database->failedStatements[] = 'ALTER TABLE wp_customers DROP INDEX idx_email;';

        self::assertFalse($this->manager->rollbackMigrations());
        self::assertSame(
            ['ALTER TABLE wp_customers DROP INDEX idx_email;'],
            $this->database->executedStatements,
        );
        self::assertCount(2, $this->manager->getMigratedVersions());
        self::assertSame(
            AddCustomersEmailIndexMigration::class,
            $this->manager->getCurrentMigration()['class'],
        );
        self::assertCount(2, $this->manager->getMigrationHistory());
    }

    public function test_it_stops_when_a_migration_fails(): void
    {
        $this->database->failedStatements[] = 'ALTER TABLE wp_customers ADD INDEX idx_email (email);';

        self::assertFalse($this->manager->runMigrations());
        self::assertCount(1, $this->manager->getMigratedVersions());
        self::assertTrue(
            $this->manager->needsUpdate(AddCustomersEmailIndexMigration::class),
        );
        self::assertCount(1, $this->manager->getMigrationHistory());
    }

    public function test_running_an_up_to_date_migration_is_idempotent(): void
    {
        $this->manager->runMigrations();
        $this->database->executedStatements = [];

        self::assertTrue(
            $this->manager->runMigration(CreateCustomersTableMigration::class),
        );
        self::assertSame([], $this->database->executedStatements);
    }

    public function test_schema_hash_versions_are_pending_when_the_hash_changes(): void
    {
        $manager = $this->createMigrationManager(
            $this->database,
            [new SchemaHashMigration($this->database, 'schema:ffffffffffffffff')],
        );

        self::assertTrue($manager->markMigration(SchemaHashMigration::class, 'up'));

        $changedManager = $this->createMigrationManager(
            $this->database,
            [new SchemaHashMigration($this->database, 'schema:0000000000000000')],
        );

        self::assertTrue($changedManager->hasPendingMigrations());
    }

    public function test_it_can_mark_versions_without_executing_sql(): void
    {
        self::assertTrue($this->manager->markMigration(CreateCustomersTableMigration::class, 'up'));
        self::assertFalse($this->database->hasTable('wp_customers'));
        self::assertCount(1, $this->manager->getMigratedVersions());
        self::assertSame('mark_up', $this->manager->getMigrationHistory()[0]['direction']);

        self::assertTrue($this->manager->markMigration(CreateCustomersTableMigration::class, 'down'));
        self::assertSame([], $this->manager->getMigratedVersions());
        self::assertSame('mark_down', $this->manager->getMigrationHistory()[0]['direction']);
    }
    public function testAnonymousRecordsSurviveChangedReleaseDirectory(): void
    {
        $migration = $this->identityMigration('deployment-independent', '1.0.0');
        $legacyClass = str_replace(__DIR__, '/old/releases/2025/tests', $migration::class);
        $migration->aliases = [$legacyClass];
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
        self::assertTrue($tracker->record('my-plugin', $legacyClass, '1.0.0'));
        $manager = $this->createMigrationManager($this->database, [$migration]);
        self::assertFalse($manager->needsUpdate($migration::class));
        self::assertTrue($manager->runMigrations());
        self::assertCount(1, $manager->getMigratedVersions());
        self::assertSame($legacyClass, $manager->getMigratedVersions()[0]['migration']);
    }

    public function testAnonymousMigrationsWithoutKeysFailBeforeExecutingSql(): void
    {
        $first = require __DIR__ . '/fixtures/a/migrate.php';
        $second = require __DIR__ . '/fixtures/b/migrate.php';
        self::assertNotSame($first::class, $second::class);

        foreach ([$first, $second] as $migration) {
            try {
                $this->createMigrationManager($this->database, [$migration]);
                self::fail('An anonymous declaration requires a stable key.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('getMigrationKey', $exception->getMessage());
            }
        }
        self::assertSame([], $this->database->executedStatements);
    }

    public function testDuplicateKeysAndLegacyAliasesAreRejectedBeforeExecution(): void
    {
        foreach ([
            [$this->identityMigration('same', '1.0.0'), $this->identityMigration('same', '1.0.0')],
            [$this->identityMigration('one', '1.0.0', ['old']), $this->identityMigration('two', '1.0.0', ['old'])],
        ] as $migrations) {
            try {
                $this->createMigrationManager($this->database, $migrations);
                self::fail('Overlapping migration identities must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Duplicate', $exception->getMessage());
            }
        }
        self::assertSame([], $this->database->executedStatements);
    }

    public function testDistinctKeysFromOneDeclarationBothExecuteAndClassLookupIsAmbiguous(): void
    {
        $first = $this->identityMigration('one', '1.0.0');
        $second = $this->identityMigration('two', '1.0.1');
        self::assertSame($first::class, $second::class);
        $manager = $this->createMigrationManager($this->database, [$first, $second]);
        self::assertTrue($manager->runMigrations());
        self::assertCount(2, $manager->getMigratedVersions());
        self::assertContains('SELECT one', $this->database->executedStatements);
        self::assertContains('SELECT two', $this->database->executedStatements);
        $this->expectException(\InvalidArgumentException::class);
        $manager->runMigration($first::class);
    }

    public function testAdoptionRetainsRecordedVersionAndHistoryWithoutExecutingMigrationSql(): void
    {
        $legacy = "Migration@anonymous\0/old/release/SchemaMigrationFactory.php:40";
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
        self::assertTrue($tracker->record('my-plugin', $legacy, 'schema:old'));
        self::assertTrue($tracker->appendHistory(new \SymPress\WordPress\Migration\Value\MigrationExecution('my-plugin', $legacy, 'schema:old', 'up', '2026-09-01 12:00:00')));
        $manager = $this->createMigrationManager($this->database, [$this->identityMigration('orm-schema:default', 'schema:new')]);
        self::assertTrue($manager->adoptLegacyMigration('orm-schema:default', $legacy, 'schema:old'));
        self::assertNull($tracker->getVersion('my-plugin', $legacy));
        self::assertSame('schema:old', $tracker->getVersion('my-plugin', 'orm-schema:default'));
        self::assertTrue($manager->hasPendingMigrations());
        self::assertSame(['adopt', 'up'], array_column($manager->getMigrationHistory(), 'direction'));
        self::assertSame($legacy, $manager->getMigrationHistory()[1]['migration']);
        self::assertNotContains('SELECT orm-schema:default', $this->database->executedStatements);
    }

    public function testAdoptionCannotTakeAnIdentityMappedToAnotherMigration(): void
    {
        $legacy = "Migration@anonymous\0/old/release.php:40";
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
        self::assertTrue($tracker->record('my-plugin', $legacy, 'schema:old'));
        $manager = $this->createMigrationManager($this->database, [
            $this->identityMigration('orm-schema:default', 'schema:new'),
            $this->identityMigration('other-schema', 'schema:old', [$legacy]),
        ]);
        try {
            $manager->adoptLegacyMigration('orm-schema:default', $legacy, 'schema:old');
            self::fail('Identity belonging to another migration was adopted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('schema:old', $tracker->getVersion('my-plugin', $legacy));
            self::assertNull($tracker->getVersion('my-plugin', 'orm-schema:default'));
        }
    }

    public function testAdoptionRejectsChangedVersionBeforeRetiringLegacyIdentity(): void
    {
        $legacy = "Migration@anonymous\0/old/release.php:40";
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
        self::assertTrue($tracker->record('my-plugin', $legacy, 'schema:changed'));
        $manager = $this->createMigrationManager($this->database, [$this->identityMigration('orm-schema:default', 'schema:new')]);
        try {
            $manager->adoptLegacyMigration('orm-schema:default', $legacy, 'schema:reviewed');
            self::fail('Changed legacy version accepted.');
        } catch (\RuntimeException) {
            self::assertSame('schema:changed', $tracker->getVersion('my-plugin', $legacy));
            self::assertNull($tracker->getVersion('my-plugin', 'orm-schema:default'));
            self::assertSame([], $manager->getMigrationHistory());
        }
    }

    public function testUnknownLegacyAnonymousStateStopsBeforeAnyMigrationSql(): void
    {
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
        self::assertTrue($tracker->record('my-plugin', "Migration@anonymous\0/old/a/migrate.php:1", '1.0.0'));
        $this->database->executedStatements = [];
        $manager = $this->createMigrationManager($this->database, [$this->identityMigration('new', '1.0.0')]);
        try {
            $manager->runMigrations();
            self::fail('Unmapped legacy state must not be guessed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Unmapped legacy', $exception->getMessage());
        }
        self::assertSame([], $this->database->executedStatements);
    }

    public function testChangedEarlierSchemaRunsBeforeNewMigrationAndStopsOnFailure(): void
    {
        $original = $this->identityMigration('schema', 'schema:old');
        $manager = $this->createMigrationManager($this->database, [$original]);
        self::assertTrue($manager->markMigration('schema', 'up'));
        $changed = $this->identityMigration('schema', 'schema:new');
        $later = $this->identityMigration('later', '1.0.0');
        $manager = $this->createMigrationManager($this->database, [$changed, $later]);
        $this->database->executedStatements = [];
        $this->database->failedStatements[] = 'SELECT schema';
        self::assertFalse($manager->runMigrations());
        self::assertNotContains('SELECT later', $this->database->executedStatements);
        $this->database->failedStatements = [];
        $this->database->executedStatements = [];
        self::assertTrue($manager->runMigrations());
        $operations = array_values(array_filter($this->database->executedStatements, static fn (string $sql): bool => in_array($sql, ['SELECT schema', 'SELECT later'], true)));
        self::assertSame(['SELECT schema', 'SELECT later'], $operations);
        self::assertFalse($manager->hasPendingMigrations());
    }

    public function testLegacyUpgradeAndRollbackRetireAllAppliedAliases(): void
    {
        foreach (['execute', 'mark'] as $mode) {
            $slug = 'legacy-' . $mode;
            $old = "Migration@anonymous\0/old/releases/1/migrate.php:1";
            $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
            self::assertTrue($tracker->record($slug, $old, 'schema:old'));
            $migration = $this->identityMigration('schema-' . $mode, 'schema:new', [$old]);
            $manager = $this->createMigrationManager($this->database, [$migration], $slug);
            self::assertTrue($mode === 'execute' ? $manager->runMigrations() : $manager->markMigration('schema-' . $mode, 'up'));
            self::assertCount(1, $manager->getMigratedVersions());
            self::assertSame('schema-' . $mode, $manager->getMigratedVersions()[0]['migration']);
            self::assertNull($tracker->findRecord($slug, $old));
            self::assertTrue($mode === 'execute' ? $manager->rollbackMigrations() : $manager->markMigration('schema-' . $mode, 'down'));
            self::assertSame([], $manager->getMigratedVersions());
            self::assertNull($manager->getCurrentMigration());
            $history = $manager->getMigrationHistory();
            self::assertTrue($manager->rollbackMigrations());
            self::assertSame($history, $manager->getMigrationHistory());
        }
    }

    private function identityMigration(string $key, string $version, array $aliases = []): \SymPress\WordPress\Migration\Contract\Migration
    {
        return new class ($key, $version, $aliases) implements \SymPress\WordPress\Migration\Contract\Migration {
            public function __construct(private string $key, private string $version, public array $aliases) {}
            public function getMigrationKey(): string { return $this->key; }
            public function getLegacyMigrationKeys(): array { return $this->aliases; }
            public function getVersion(): string { return $this->version; }
            public function up(): string { return 'SELECT ' . $this->key; }
            public function down(): string { return 'SELECT down_' . $this->key; }
        };
    }
}
