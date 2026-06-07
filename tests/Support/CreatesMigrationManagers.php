<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Support;

use SymPress\WordPress\Migration\Application\MigrationLifecycle;
use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Value\PluginSlug;

trait CreatesMigrationManagers
{
    /**
     * @param iterable<MigrationContract> $migrations
     */
    private function createMigrationManager(
        \wpdb $database,
        iterable $migrations,
        string $pluginSlug = 'my-plugin',
    ): MigrationManager {
        return new MigrationManager(
            PluginSlug::fromString($pluginSlug),
            new MigrationLifecycle(
                new MigrationTracker($database),
                new WordPressSqlExecutor($database),
            ),
            MigrationCollection::fromIterable($migrations),
        );
    }
}
