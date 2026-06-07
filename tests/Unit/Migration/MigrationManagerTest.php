<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\CreatesMigrationManagers;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

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
            $this->database->executedStatements[1],
        );
        self::assertSame([], $this->manager->getMigratedVersions());
        self::assertSame('down', $this->manager->getMigrationHistory()[0]['direction']);
        self::assertCount(4, $this->manager->getMigrationHistory());
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
}
