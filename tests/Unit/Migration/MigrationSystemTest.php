<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationSystemTest extends TestCase
{
    private \wpdb $database;
    private MigrationSystem $system;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        MigrationSystem::reset();
        $this->database = new \wpdb();
        $GLOBALS['wpdb'] = $this->database;
        $this->system = new MigrationSystem($this->database);
    }

    public function test_it_registers_migrations_and_creates_a_manager(): void
    {
        $this->system->registerMigrations('my-plugin', [
            new CreateCustomersTableMigration($this->database),
            new AddCustomersEmailIndexMigration($this->database),
        ]);

        self::assertTrue($this->system->hasPlugin('my-plugin'));
        self::assertCount(2, $this->system->getMigrations('my-plugin'));
        self::assertInstanceOf(
            MigrationManager::class,
            $this->system->createMigrationManager('my-plugin'),
        );
    }

    public function test_it_dispatches_registration_hooks_during_init(): void
    {
        $events = [];

        add_action('db_migration_register', static function (MigrationSystem $system) use (&$events): void {
            $events[] = 'register';
            $events[] = $system::class;
        });

        add_action('db_migration_registered', static function () use (&$events): void {
            $events[] = 'registered';
        });

        $this->system->init();
        do_action('init');

        self::assertTrue($this->system->isInitialized());
        self::assertSame(
            ['register', MigrationSystem::class, 'registered'],
            $events,
        );
    }

    public function test_it_rejects_invalid_plugin_slugs(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->system->registerMigrations('My Plugin', [
            new CreateCustomersTableMigration($this->database),
        ]);
    }
}
