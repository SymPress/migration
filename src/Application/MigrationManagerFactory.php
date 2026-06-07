<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Application;

use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Value\PluginSlug;

final readonly class MigrationManagerFactory
{
    public function __construct(private MigrationLifecycle $lifecycle)
    {
    }

    /**
     * @param iterable<MigrationContract> $migrations
     */
    #[\NoDiscard]
    public function create(string $pluginSlug, iterable $migrations = []): MigrationManager
    {
        return new MigrationManager(
            PluginSlug::fromString($pluginSlug),
            $this->getLifecycle(),
            MigrationCollection::fromIterable($migrations),
        );
    }

    public function getLifecycle(): MigrationLifecycle
    {
        return $this->lifecycle;
    }
}
