<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Integration;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Cli\MigrationBootstrap;
use SymPress\WordPress\Migration\Hook\MigrationSystemBootstrap;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationSystemInitTest extends TestCase
{
    private const string APP_PROVIDER_HOOK = 'kernel.add-providers';
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

    public function test_loader_file_registers_the_app_and_muplugin_hooks(): void
    {
        require dirname(__DIR__, 2) . '/migration.php';

        self::assertArrayHasKey(self::APP_PROVIDER_HOOK, WordPressState::$hooks);
        self::assertArrayHasKey('muplugins_loaded', WordPressState::$hooks);
    }

    public function test_kernel_and_muplugin_bootstraps_fire_the_ready_action_once(): void
    {
        require dirname(__DIR__, 2) . '/migration.php';
        // The kernel registers the same bootstrap on the shared system singleton at priority 2.
        add_action('muplugins_loaded', [new MigrationSystemBootstrap(MigrationSystem::getInstance()), 'initialize'], 2);
        $readyCalls = 0;
        add_action('db_migration_system_ready', static function () use (&$readyCalls): void {
            ++$readyCalls;
        });

        do_action('muplugins_loaded');

        self::assertTrue(MigrationSystem::getInstance()->isInitialized());
        self::assertSame(1, $readyCalls);
    }
}
