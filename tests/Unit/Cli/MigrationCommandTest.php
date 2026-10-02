<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Cli;

use SymPress\WordPress\Migration\Cli\MigrationCommand;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\CreatesMigrationManagers;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationCommandTest extends TestCase
{
    use CreatesMigrationManagers;

    private MigrationCommand $command;
    private MigrationManager $manager;
    private \wpdb $database;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        MigrationRegistry::reset();
        $this->database = new \wpdb();
        $GLOBALS['wpdb'] = $this->database;

        $this->manager = $this->createMigrationManager(
            $this->database,
            [
                new CreateCustomersTableMigration($this->database),
                new AddCustomersEmailIndexMigration($this->database),
            ],
        );

        MigrationRegistry::getInstance()->set('my-plugin', $this->manager);
        $this->command = new MigrationCommand();
    }

    public function test_run_executes_pending_migrations(): void
    {
        $this->command->run(['my-plugin']);

        self::assertFalse($this->manager->hasPendingMigrations());
        self::assertSame(
            ['All migrations completed for "my-plugin".'],
            WordPressState::$cliCalls['success'],
        );
    }

    public function testAllPluginsContinuesAfterThrownAndReturnedFailuresAndReportsFailure(): void
    {
        $registry = MigrationRegistry::getInstance();
        $registry->clear();
        foreach (['throws', 'returns-false'] as $slug) {
            $failed = new class ($slug, $this->database) extends MigrationManager {
                public function __construct(private string $mode, \wpdb $database)
                {
                    parent::__construct(\SymPress\WordPress\Migration\Value\PluginSlug::fromString($mode), new \SymPress\WordPress\Migration\Application\MigrationLifecycle(new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($database), new \SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor($database)));
                }
                public function hasPendingMigrations(): bool { return true; }
                public function runMigrations(): bool
                {
                    if ($this->mode === 'throws') { throw new \RuntimeException('credentialSentinel'); }
                    return false;
                }
            };
            $registry->set($slug, $failed);
        }
        $registry->set('my-plugin', $this->manager);
        try {
            $this->command->migrate([]);
            self::fail('Aggregate failure must return a failing CLI result.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('2 plugin(s)', $exception->getMessage());
        }
        self::assertFalse($this->manager->hasPendingMigrations());
        self::assertCount(2, WordPressState::$cliCalls['warning']);
        self::assertStringNotContainsString('credentialSentinel', json_encode(WordPressState::$cliCalls, JSON_THROW_ON_ERROR));
    }

    public function testAdoptionRequiresAdministratorAndExplicitConfirmationBeforeStorageMutation(): void
    {
        foreach ([false, true] as $administrator) {
            WordPressState::$administrator = $administrator;
            try {
                $this->command->adopt(['my-plugin', 'stable'], []);
                self::fail('Unconfirmed or unprivileged adoption accepted.');
            } catch (\RuntimeException) {
                self::assertSame([], $this->manager->getMigratedVersions());
            }
        }
        self::assertFalse($this->database->hasTable('wp_migrations'));
    }

    public function testAdministratorCanAdoptTheExactBinaryLegacyIdentityWithoutExecutingSql(): void
    {
        $legacy = "Migration@anonymous\0/old/release.php:40";
        $tracker = new \SymPress\WordPress\Migration\Infrastructure\MigrationTracker($this->database);
        self::assertTrue($tracker->record('my-plugin', $legacy, '0.9.0'));
        WordPressState::$administrator = true;
        $this->command->adopt(['my-plugin', CreateCustomersTableMigration::class], [
            'legacy-base64' => base64_encode($legacy),
            'expected-version' => '0.9.0',
            'yes' => true,
        ]);
        self::assertNull($tracker->getVersion('my-plugin', $legacy));
        self::assertSame('0.9.0', $tracker->getVersion('my-plugin', CreateCustomersTableMigration::class));
        self::assertFalse($this->database->hasTable('wp_customers'));
        self::assertTrue($this->manager->hasPendingMigrations());
        self::assertSame('adopt', $this->manager->getMigrationHistory()[0]['direction']);
        self::assertCount(1, WordPressState::$cliCalls['success']);
    }

    public function test_migrate_can_target_a_specific_version(): void
    {
        $this->command->migrate(['my-plugin', '1.0.0']);

        self::assertCount(1, $this->manager->getMigratedVersions());
        self::assertSame(
            ['Migration target "1.0.0" reached for "my-plugin".'],
            WordPressState::$cliCalls['success'],
        );
    }

    public function test_execute_runs_a_single_migration_up_and_down(): void
    {
        $this->command->execute(['my-plugin', 'CreateCustomersTableMigration'], ['up' => true]);
        self::assertTrue($this->database->hasTable('wp_customers'));

        $this->command->execute(['my-plugin', 'CreateCustomersTableMigration'], ['down' => true]);
        self::assertFalse($this->database->hasTable('wp_customers'));
        self::assertSame(
            'Executed "down" for migration "CreateCustomersTableMigration" on "my-plugin".',
            WordPressState::$cliCalls['success'][1],
        );
    }

    public function test_version_updates_metadata_without_running_sql(): void
    {
        $this->command->version(['my-plugin', 'CreateCustomersTableMigration'], ['add' => true]);

        self::assertFalse($this->database->hasTable('wp_customers'));
        self::assertCount(1, $this->manager->getMigratedVersions());
        self::assertSame('mark_up', $this->manager->getMigrationHistory()[0]['direction']);

        $this->command->version(['my-plugin', 'CreateCustomersTableMigration'], ['delete' => true]);

        self::assertSame([], $this->manager->getMigratedVersions());
        self::assertSame('mark_down', $this->manager->getMigrationHistory()[0]['direction']);
    }

    public function test_history_formats_execution_history(): void
    {
        $this->manager->runMigrations();

        $this->command->history(['my-plugin'], ['format' => 'json']);

        self::assertSame('json', WordPressState::$cliCalls['format_items'][0]['format']);
        self::assertSame(
            'my-plugin',
            WordPressState::$cliCalls['format_items'][0]['items'][0]['plugin'],
        );
        self::assertSame(
            'up',
            WordPressState::$cliCalls['format_items'][0]['items'][0]['direction'],
        );
    }

    public function test_current_and_latest_are_formatted(): void
    {
        $this->manager->migrateTo('1.0.0');

        $this->command->current(['my-plugin'], ['format' => 'json']);
        $this->command->latest(['my-plugin'], ['format' => 'json']);

        self::assertSame(
            '1.0.0',
            WordPressState::$cliCalls['format_items'][0]['items'][0]['version'],
        );
        self::assertSame(
            '1.0.1',
            WordPressState::$cliCalls['format_items'][1]['items'][0]['version'],
        );
    }

    public function test_sync_metadata_storage_creates_state_and_history_tables(): void
    {
        $this->command->syncMetadataStorage();

        self::assertTrue($this->database->hasTable('wp_migrations'));
        self::assertTrue($this->database->hasTable('wp_migration_history'));
        self::assertSame(['Metadata storage synced.'], WordPressState::$cliCalls['success']);
    }

    public function test_list_formats_registered_plugins(): void
    {
        $this->command->list([], ['format' => 'json']);

        self::assertSame('json', WordPressState::$cliCalls['format_items'][0]['format']);
        self::assertSame(
            'my-plugin',
            WordPressState::$cliCalls['format_items'][0]['items'][0]['plugin'],
        );
    }

    public function test_unknown_plugin_errors_fast(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->command->status(['missing-plugin']);
    }
}
