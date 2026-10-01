<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Contract\Migration;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class MigrationCollectionTest extends TestCase
{
    public function testExplicitReplacementPreservesOrderAndTheOriginalCollection(): void
    {
        $original = $this->keyedMigration('schema');
        $later = $this->keyedMigration('later');
        $replacement = $this->keyedMigration('schema');
        $collection = MigrationCollection::fromIterable([$original, $later]);
        $changed = $collection->replace($replacement);
        self::assertSame([$replacement, $later], $changed->inRegistrationOrder());
        self::assertSame([$original, $later], $collection->inRegistrationOrder());
    }

    public function testExplicitReplacementRejectsAnUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (void) MigrationCollection::empty()->replace($this->keyedMigration('unknown'));
    }

    public function testExplicitReplacementRejectsAnIdentityOwnedByAnotherRegistration(): void
    {
        $collection = MigrationCollection::fromIterable([$this->keyedMigration('schema'), $this->keyedMigration('later')]);
        $this->expectException(\InvalidArgumentException::class);
        (void) $collection->replace($this->keyedMigration('schema', ['later']));
    }

    /** @param list<string> $aliases */
    private function keyedMigration(string $key, array $aliases = []): Migration
    {
        return new class ($key, $aliases) implements Migration {
            /** @param list<string> $aliases */
            public function __construct(private readonly string $key, private readonly array $aliases)
            {
            }

            public function getMigrationKey(): string
            {
                return $this->key;
            }

            /** @return list<string> */
            public function getLegacyMigrationKeys(): array
            {
                return $this->aliases;
            }

            public function getVersion(): string
            {
                return '1';
            }

            public function up(): string
            {
                return '';
            }

            public function down(): string
            {
                return '';
            }
        };
    }

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
