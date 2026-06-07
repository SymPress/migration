<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use PHPUnit\Framework\TestCase;

final class MigrationTrackerTest extends TestCase
{
    private \wpdb $database;
    private MigrationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        $this->database = new \wpdb();
        $GLOBALS['wpdb'] = $this->database;
        $this->tracker = new MigrationTracker($this->database);
    }

    public function test_it_creates_state_and_history_tables_once(): void
    {
        self::assertFalse($this->database->hasTable('wp_migrations'));
        self::assertFalse($this->database->hasTable('wp_migration_history'));
        self::assertTrue($this->tracker->ensureTableExists());
        self::assertTrue($this->tracker->ensureTableExists());
        self::assertTrue($this->database->hasTable('wp_migrations'));
        self::assertTrue($this->database->hasTable('wp_migration_history'));
        self::assertCount(2, WordPressState::$dbDelta);
    }

    public function test_it_records_versions_and_persists_execution_history(): void
    {
        self::assertTrue($this->tracker->record('my-plugin', 'Vendor\\CreateTable', '1.0.0'));
        self::assertTrue($this->tracker->record('my-plugin', 'Vendor\\CreateTable', '1.1.0'));
        self::assertSame(
            '1.1.0',
            $this->tracker->getVersion('my-plugin', 'Vendor\\CreateTable'),
        );
        self::assertCount(1, $this->tracker->getAllForPlugin('my-plugin'));

        self::assertTrue($this->tracker->appendHistory(
            new MigrationExecution(
                'my-plugin',
                'Vendor\\CreateTable',
                '1.0.0',
                'up',
                '2026-03-27 12:00:00',
            ),
        ));
        self::assertTrue($this->tracker->appendHistory(
            new MigrationExecution(
                'my-plugin',
                'Vendor\\CreateTable',
                '1.1.0',
                'down',
                '2026-03-27 12:05:00',
            ),
        ));

        $history = $this->tracker->findHistoryForPlugin('my-plugin');

        self::assertCount(2, $history);
        self::assertSame('down', $history[0]->direction);
        self::assertSame('1.1.0', $history[0]->version);
        self::assertSame('up', $history[1]->direction);
        self::assertCount(2, $this->tracker->findAllHistory());
    }

    public function test_auto_cleanup_is_opt_in_and_respects_history(): void
    {
        $this->tracker->record('my-plugin', 'Vendor\\CreateTable', '1.0.0');
        $this->tracker->remove('my-plugin', 'Vendor\\CreateTable');

        self::assertTrue($this->database->hasTable('wp_migrations'));
        self::assertTrue($this->database->hasTable('wp_migration_history'));

        $this->tracker->record('my-plugin', 'Vendor\\CreateTable', '1.0.0');
        $this->tracker->enableAutoCleanup();
        $this->tracker->remove('my-plugin', 'Vendor\\CreateTable');

        self::assertFalse($this->database->hasTable('wp_migrations'));
        self::assertFalse($this->database->hasTable('wp_migration_history'));
    }
}
