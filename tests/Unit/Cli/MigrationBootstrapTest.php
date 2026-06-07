<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Cli;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Cli\MigrationBootstrap;
use SymPress\WordPress\Migration\Cli\MigrationCommand;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationBootstrapTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        MigrationBootstrap::reset();
        MigrationRegistry::reset();
        MigrationSystem::reset();
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function test_it_registers_the_cli_command_and_syncs_the_registry(): void
    {
        $system = MigrationSystem::getInstance();
        $system->registerMigration(
            'my-plugin',
            new CreateCustomersTableMigration($GLOBALS['wpdb']),
        );

        $bootstrap = MigrationBootstrap::getInstance();
        $bootstrap->init();
        do_action('db_migration_registered', $system);

        self::assertSame(
            [['name' => 'migration', 'class' => MigrationCommand::class]],
            WordPressState::$cliCalls['commands'],
        );
        self::assertTrue($bootstrap->getRegistry()->has('my-plugin'));
    }
}
