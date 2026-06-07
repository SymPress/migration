<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Domain\AbstractMigration;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;

final class AbstractMigrationTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function test_it_uses_the_injected_database_context(): void
    {
        $database = new \wpdb();
        $database->prefix = 'custom_';

        $migration = new class ($database) extends AbstractMigration {
            protected const string VERSION = '2.4.0';

            public function up(): string|array
            {
                return '';
            }

            public function down(): string|array
            {
                return '';
            }

            public function readPrefix(): string
            {
                return $this->prefix;
            }

            public function readCharsetCollate(): string
            {
                return $this->charsetCollate;
            }
        };

        self::assertSame('custom_', $migration->readPrefix());
        self::assertSame(
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $migration->readCharsetCollate(),
        );
        self::assertSame('2.4.0', $migration->getVersion());
    }

    public function test_it_falls_back_to_the_global_database(): void
    {
        $GLOBALS['wpdb']->prefix = 'global_';

        $migration = new class () extends AbstractMigration {
            public function up(): string|array
            {
                return '';
            }

            public function down(): string|array
            {
                return '';
            }

            public function readPrefix(): string
            {
                return $this->prefix;
            }
        };

        self::assertSame('global_', $migration->readPrefix());
    }
}
