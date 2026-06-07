<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Integration;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Cli\MigrationBootstrap;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationIntegrationTest extends TestCase
{
    private const string STATE_GLOBAL_KEY = 'sympress_wordpress_migration_muplugin_state';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        MigrationBootstrap::reset();
        MigrationRegistry::reset();
        MigrationSystem::reset();
        unset($GLOBALS[self::STATE_GLOBAL_KEY]);
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function test_the_mu_plugin_bootstrap_registers_and_syncs_migrations(): void
    {
        add_action('db_migration_register', static function (MigrationSystem $system): void {
            $database = $GLOBALS['wpdb'];

            $system->registerMigrations('shop-plugin', [
                new CreateCustomersTableMigration($database),
                new AddCustomersEmailIndexMigration($database),
            ]);
        });

        require dirname(__DIR__, 2) . '/migration.php';
        do_action('muplugins_loaded');
        do_action('init');

        $manager = MigrationRegistry::getInstance()->get('shop-plugin');

        self::assertNotNull($manager);
        self::assertTrue($manager->hasPendingMigrations());
        self::assertTrue(MigrationSystem::getInstance()->isInitialized());
    }
}
