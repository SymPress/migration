<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\CreatesMigrationManagers;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationRegistryTest extends TestCase
{
    use CreatesMigrationManagers;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        MigrationRegistry::reset();
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function test_it_stores_and_clears_managers(): void
    {
        $registry = MigrationRegistry::getInstance();
        $manager = $this->createMigrationManager(
            $GLOBALS['wpdb'],
            [new CreateCustomersTableMigration($GLOBALS['wpdb'])],
        );

        $registry->register('my-plugin', $manager);

        self::assertTrue($registry->has('my-plugin'));
        self::assertSame($manager, $registry->get('my-plugin'));
        self::assertCount(1, $registry->getAll());

        $registry->clear();

        self::assertSame([], $registry->all());
    }
}
