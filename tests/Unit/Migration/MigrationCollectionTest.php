<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationCollectionTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function test_it_preserves_registration_and_rollback_order(): void
    {
        $collection = MigrationCollection::fromIterable([
            new CreateCustomersTableMigration($GLOBALS['wpdb']),
            new AddCustomersEmailIndexMigration($GLOBALS['wpdb']),
        ]);

        self::assertSame(
            [
                CreateCustomersTableMigration::class,
                AddCustomersEmailIndexMigration::class,
            ],
            array_map(
                static fn (object $migration): string => $migration::class,
                $collection->inRegistrationOrder(),
            ),
        );

        self::assertSame(
            [
                AddCustomersEmailIndexMigration::class,
                CreateCustomersTableMigration::class,
            ],
            array_map(
                static fn (object $migration): string => $migration::class,
                $collection->inRollbackOrder(),
            ),
        );
    }
}
