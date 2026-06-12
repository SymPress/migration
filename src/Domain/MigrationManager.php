<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Domain;

use SymPress\WordPress\Migration\Application\MigrationLifecycle;
use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use SymPress\WordPress\Migration\Value\MigrationRecord;
use SymPress\WordPress\Migration\Value\PluginSlug;

class MigrationManager
{
    private MigrationCollection $migrations;

    public function __construct(
        private readonly PluginSlug $pluginSlug,
        private readonly MigrationLifecycle $lifecycle,
        ?MigrationCollection $migrations = null,
    ) {

        $this->migrations = $migrations ?? MigrationCollection::empty();
    }

    public function getPluginSlug(): string
    {
        return $this->pluginSlug->value;
    }

    public function registerMigration(MigrationContract $migration): self
    {
        $this->migrations = $this->migrations->with($migration);

        return $this;
    }

    /** @param iterable<MigrationContract> $migrations */
    public function registerMigrations(iterable $migrations): self
    {
        foreach ($migrations as $migration) {
            $this->registerMigration($migration);
        }

        return $this;
    }

    /** @return array<class-string<MigrationContract>, MigrationContract> */
    public function all(): array
    {
        return $this->migrations->all();
    }

    public function runMigrations(): bool
    {
        return $this->migrateTo();
    }

    public function runMigration(string $migrationClass): bool
    {
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }

        if (!$this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
            return true;
        }

        return $this->lifecycle->migrate($this->pluginSlug, $migration);
    }

    public function rollbackMigrations(): bool
    {
        foreach ($this->migrations->inRollbackOrder() as $migration) {
            if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
                continue;
            }

            if (!$this->lifecycle->rollback($this->pluginSlug, $migration)) {
                return false;
            }
        }

        return true;
    }

    public function rollbackMigration(string $migrationClass): bool
    {
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
            return true;
        }

        return $this->lifecycle->rollback($this->pluginSlug, $migration);
    }

    public function migrateTo(?string $targetVersion = null): bool
    {
        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }

        $targetIndex = $this->resolveTargetIndex($targetVersion);

        if ($targetVersion !== null && $targetIndex === null) {
            return false;
        }

        if ($targetIndex === null) {
            $targetIndex = max(count($this->migrations->inRegistrationOrder()) - 1, -1);
        }

        $currentIndex = $this->currentMigrationIndex();

        if ($currentIndex === $targetIndex) {
            return true;
        }

        if ($currentIndex < $targetIndex) {
            return $this->migrateForward($currentIndex + 1, $targetIndex);
        }

        return $this->rollbackBackward($currentIndex, $targetIndex + 1);
    }

    public function executeMigration(string $migrationClass, string $direction): bool
    {
        if ($direction === 'up') {
            return $this->runMigration($migrationClass);
        }

        if ($direction === 'down') {
            return $this->rollbackMigration($migrationClass);
        }

        return false;
    }

    public function markMigration(string $migrationClass, string $direction): bool
    {
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }

        if ($direction === 'up') {
            return $this->lifecycle->markMigrated($this->pluginSlug, $migration);
        }

        if ($direction === 'down') {
            return $this->lifecycle->markRolledBack($this->pluginSlug, $migration);
        }

        return false;
    }

    public function needsUpdate(string $migrationClass): bool
    {
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        return $this->lifecycle->needsUpdate($this->pluginSlug, $migration);
    }

    public function hasPendingMigrations(): bool
    {
        foreach ($this->migrations->inRegistrationOrder() as $migration) {
            if ($this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
                return true;
            }
        }

        return false;
    }

    public function isUpToDate(): bool
    {
        return !$this->hasPendingMigrations();
    }

    /** @return list<array{plugin: string, migration: string, version: string, migrated_at: string}> */
    public function getMigratedVersions(): array
    {
        return array_map(
            static fn (MigrationRecord $record): array => $record->toArray(),
            $this->lifecycle->recordsForPlugin($this->pluginSlug),
        );
    }

    /**
     * @return list<array{
     *     plugin: string,
     *     migration: string,
     *     version: string,
     *     direction: string,
     *     executed_at: string
     * }>
     */
    public function getMigrationHistory(): array
    {
        return array_map(
            static fn (MigrationExecution $execution): array => $execution->toArray(),
            $this->lifecycle->historyForPlugin($this->pluginSlug),
        );
    }

    /**
     * @return array{
     *     class: class-string<MigrationContract>,
     *     name: string,
     *     version: string,
     *     migrated_at: string
     * }|null
     */
    public function getCurrentMigration(): ?array
    {
        $current = null;

        foreach ($this->migrations->inRegistrationOrder() as $migration) {
            $record = $this->lifecycle->recordForMigration($this->pluginSlug, $migration);

            if ($record === null) {
                continue;
            }

            $current = [
                'class'       => $migration::class,
                'name'        => $this->extractClassName($migration::class),
                'version'     => $record->version,
                'migrated_at' => $record->migratedAt,
            ];
        }

        return $current;
    }

    /** @return array{class: class-string<MigrationContract>, name: string, version: string}|null */
    public function getLatestMigration(): ?array
    {
        $migrations = $this->migrations->inRegistrationOrder();
        $latest = array_pop($migrations);

        if (!$latest instanceof MigrationContract) {
            return null;
        }

        return [
            'class'   => $latest::class,
            'name'    => $this->extractClassName($latest::class),
            'version' => $latest->getVersion(),
        ];
    }

    public function syncMetadataStorage(): bool
    {
        return $this->lifecycle->ensureStorageIsReady();
    }

    /** @return list<array{class: class-string<MigrationContract>, name: string, version: string}> */
    public function getPendingMigrations(): array
    {
        $pending = [];

        foreach ($this->migrations->inRegistrationOrder() as $migration) {
            if (!$this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
                continue;
            }

            $pending[] = [
                'class'   => $migration::class,
                'name'    => $this->extractClassName($migration::class),
                'version' => $migration->getVersion(),
            ];
        }

        return $pending;
    }

    private function getMigration(string $migrationClass): ?MigrationContract
    {
        $migration = $this->migrations->get($migrationClass);

        if ($migration instanceof MigrationContract) {
            return $migration;
        }

        foreach ($this->migrations->inRegistrationOrder() as $registeredMigration) {
            if ($this->extractClassName($registeredMigration::class) !== $migrationClass) {
                continue;
            }

            return $registeredMigration;
        }

        return null;
    }

    private function currentMigrationIndex(): int
    {
        $currentIndex = -1;

        foreach ($this->migrations->inRegistrationOrder() as $index => $migration) {
            if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
                continue;
            }

            $currentIndex = $index;
        }

        return $currentIndex;
    }

    private function resolveTargetIndex(?string $targetVersion): ?int
    {
        if ($targetVersion === null) {
            return null;
        }

        foreach ($this->migrations->inRegistrationOrder() as $index => $migration) {
            if ($migration->getVersion() === $targetVersion) {
                return $index;
            }

            if ($migration::class === $targetVersion) {
                return $index;
            }

            if ($this->extractClassName($migration::class) === $targetVersion) {
                return $index;
            }
        }

        return null;
    }

    private function migrateForward(int $startIndex, int $targetIndex): bool
    {
        $migrations = $this->migrations->inRegistrationOrder();

        for ($index = $startIndex; $index <= $targetIndex; $index++) {
            $migration = $migrations[$index] ?? null;

            if (!$migration instanceof MigrationContract) {
                continue;
            }

            if (!$this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
                continue;
            }

            if (!$this->lifecycle->migrate($this->pluginSlug, $migration)) {
                return false;
            }
        }

        return true;
    }

    private function rollbackBackward(int $startIndex, int $stopIndexExclusive): bool
    {
        $migrations = $this->migrations->inRegistrationOrder();

        for ($index = $startIndex; $index >= $stopIndexExclusive; $index--) {
            $migration = $migrations[$index] ?? null;

            if (!$migration instanceof MigrationContract) {
                continue;
            }

            if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
                continue;
            }

            if (!$this->lifecycle->rollback($this->pluginSlug, $migration)) {
                return false;
            }
        }

        return true;
    }

    private function extractClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        $className = array_pop($parts);

        if ($className === '') {
            return $fullClassName;
        }

        return $className;
    }
}
