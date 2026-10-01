<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Application;

use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Contract\MigrationOperationExecutor;
use SymPress\WordPress\Migration\Contract\MigrationSqlExecutor;
use SymPress\WordPress\Migration\Contract\MigrationStore;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use SymPress\WordPress\Migration\Value\MigrationKey;
use SymPress\WordPress\Migration\Value\MigrationRecord;
use SymPress\WordPress\Migration\Value\PluginSlug;

final readonly class MigrationLifecycle
{
    public function __construct(
        private MigrationStore $store,
        private MigrationSqlExecutor $sqlExecutor,
    ) {
    }

    public function ensureStorageIsReady(): bool
    {
        if ($this->sqlExecutor instanceof MigrationOperationExecutor) {
            return $this->sqlExecutor->runOperation('metadata', ['CREATE TABLE'], $this->store->ensureTableExists(...));
        }

        return $this->store->ensureTableExists();
    }

    public function needsUpdate(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $currentVersion = $this->recordForMigration($pluginSlug, $migration)?->version;

        if ($currentVersion === null) {
            return true;
        }

        if ($this->isSchemaVersion($currentVersion) || $this->isSchemaVersion($migration->getVersion())) {
            return $currentVersion !== $migration->getVersion();
        }

        return version_compare($currentVersion, $migration->getVersion(), '<');
    }

    public function hasBeenMigrated(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        return $this->recordForMigration($pluginSlug, $migration) !== null;
    }

    public function migrate(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $statements = $migration->up();

        return $this->runOperation($pluginSlug, $statements, function () use ($pluginSlug, $migration, $statements): bool {
            // A second worker may have completed this migration while waiting for the lock.
            return !$this->needsUpdate($pluginSlug, $migration) || $this->migrateUnlocked($pluginSlug, $migration, $statements);
        });
    }

    /** @param string|list<string> $statements */
    private function migrateUnlocked(PluginSlug $pluginSlug, MigrationContract $migration, string|array $statements): bool
    {
        if (!$this->sqlExecutor->execute($statements)) {
            return false;
        }

        return $this->runOperation($pluginSlug, [], function () use ($pluginSlug, $migration): bool {
            $timestamp = $this->currentTimestamp();
            $record = new MigrationRecord(
                $pluginSlug->value,
                MigrationKey::forMigration($migration),
                $migration->getVersion(),
                $timestamp,
            );

            if (!$this->store->saveRecord($record)) {
                return false;
            }

            return $this->store->appendHistory(
                $this->createExecution($record, 'up', $timestamp),
            );
        });
    }

    public function rollback(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $statements = $migration->down();

        return $this->runOperation($pluginSlug, $statements, function () use ($pluginSlug, $migration, $statements): bool {
            return !$this->hasBeenMigrated($pluginSlug, $migration) || $this->rollbackUnlocked($pluginSlug, $migration, $statements);
        });
    }

    /** @param string|list<string> $statements */
    private function rollbackUnlocked(PluginSlug $pluginSlug, MigrationContract $migration, string|array $statements): bool
    {
        if (!$this->sqlExecutor->execute($statements)) {
            return false;
        }

        return $this->runOperation($pluginSlug, [], function () use ($pluginSlug, $migration): bool {
            $timestamp = $this->currentTimestamp();

            if (!$this->store->deleteRecord($pluginSlug->value, $this->recordForMigration($pluginSlug, $migration)->migration ?? MigrationKey::forMigration($migration))) {
                return false;
            }

            return $this->store->appendHistory(
                new MigrationExecution(
                    $pluginSlug->value,
                    MigrationKey::forMigration($migration),
                    $migration->getVersion(),
                    'down',
                    $timestamp,
                ),
            );
        });
    }

    /** @return list<MigrationRecord> */
    public function recordsForPlugin(PluginSlug $pluginSlug): array
    {
        return $this->store->findRecordsForPlugin($pluginSlug->value);
    }

    public function recordForMigration(PluginSlug $pluginSlug, MigrationContract $migration): ?MigrationRecord
    {
        $key = MigrationKey::forMigration($migration);
        $record = $this->store->findRecord($pluginSlug->value, $key);

        if ($record !== null) {
            return $record;
        }

        foreach ($this->store->findRecordsForPlugin($pluginSlug->value) as $legacy) {
            if (MigrationKey::normalize($legacy->migration) === MigrationKey::normalize($migration::class)) {
                return $legacy;
            }
        }

        return null;
    }

    /** @return list<MigrationExecution> */
    public function historyForPlugin(PluginSlug $pluginSlug): array
    {
        return $this->store->findHistoryForPlugin($pluginSlug->value);
    }

    public function markMigrated(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        return $this->runOperation($pluginSlug, [], fn (): bool => $this->markMigratedUnlocked($pluginSlug, $migration));
    }

    private function markMigratedUnlocked(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $existingRecord = $this->recordForMigration($pluginSlug, $migration);

        if ($existingRecord !== null && $existingRecord->version === $migration->getVersion()) {
            return true;
        }

        $timestamp = $this->currentTimestamp();
        $record = new MigrationRecord(
            $pluginSlug->value,
            MigrationKey::forMigration($migration),
            $migration->getVersion(),
            $timestamp,
        );

        if (!$this->store->saveRecord($record)) {
            return false;
        }

        return $this->store->appendHistory(
            $this->createExecution($record, 'mark_up', $timestamp),
        );
    }

    public function markRolledBack(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        return $this->runOperation($pluginSlug, [], fn (): bool => $this->markRolledBackUnlocked($pluginSlug, $migration));
    }

    private function markRolledBackUnlocked(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        if (!$this->hasBeenMigrated($pluginSlug, $migration)) {
            return true;
        }

        $timestamp = $this->currentTimestamp();

        if (!$this->store->deleteRecord($pluginSlug->value, $this->recordForMigration($pluginSlug, $migration)->migration ?? MigrationKey::forMigration($migration))) {
            return false;
        }

        return $this->store->appendHistory(
            new MigrationExecution(
                $pluginSlug->value,
                MigrationKey::forMigration($migration),
                $migration->getVersion(),
                'mark_down',
                $timestamp,
            ),
        );
    }

    public function getStore(): MigrationStore
    {
        return $this->store;
    }

    public function getSqlExecutor(): MigrationSqlExecutor
    {
        return $this->sqlExecutor;
    }

    /**
     * @param string|list<string> $statements
     * @param callable(): bool $operation
     */
    private function runOperation(PluginSlug $pluginSlug, string|array $statements, callable $operation): bool
    {
        if ($this->sqlExecutor instanceof MigrationOperationExecutor) {
            return $this->sqlExecutor->runOperation($pluginSlug->value, $statements, $operation);
        }

        return $operation();
    }

    private function createExecution(
        MigrationRecord $record,
        string $direction,
        string $timestamp,
    ): MigrationExecution {

        return new MigrationExecution(
            $record->plugin,
            $record->migration,
            $record->version,
            $direction,
            $timestamp,
        );
    }

    private function currentTimestamp(): string
    {
        if (function_exists('current_time')) {
            return current_time('mysql', true);
        }

        return gmdate('Y-m-d H:i:s');
    }

    private function isSchemaVersion(string $version): bool
    {
        return str_starts_with($version, 'schema:');
    }
}
