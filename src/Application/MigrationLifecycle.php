<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Application;

use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Contract\MigrationSqlExecutor;
use SymPress\WordPress\Migration\Contract\MigrationStore;
use SymPress\WordPress\Migration\Value\MigrationExecution;
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
        return $this->store->ensureTableExists();
    }

    public function needsUpdate(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $currentVersion = $this->store->getVersion(
            $pluginSlug->value,
            $migration::class,
        );

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
        return $this->store->getVersion($pluginSlug->value, $migration::class) !== null;
    }

    public function migrate(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        if (!$this->sqlExecutor->execute($migration->up())) {
            return false;
        }

        $timestamp = $this->currentTimestamp();
        $record = new MigrationRecord(
            $pluginSlug->value,
            $migration::class,
            $migration->getVersion(),
            $timestamp,
        );

        if (!$this->store->saveRecord($record)) {
            return false;
        }

        return $this->store->appendHistory(
            $this->createExecution($record, 'up', $timestamp),
        );
    }

    public function rollback(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        if (!$this->sqlExecutor->execute($migration->down())) {
            return false;
        }

        $timestamp = $this->currentTimestamp();

        if (!$this->store->deleteRecord($pluginSlug->value, $migration::class)) {
            return false;
        }

        return $this->store->appendHistory(
            new MigrationExecution(
                $pluginSlug->value,
                $migration::class,
                $migration->getVersion(),
                'down',
                $timestamp,
            ),
        );
    }

    /** @return list<MigrationRecord> */
    public function recordsForPlugin(PluginSlug $pluginSlug): array
    {
        return $this->store->findRecordsForPlugin($pluginSlug->value);
    }

    public function recordForMigration(PluginSlug $pluginSlug, MigrationContract $migration): ?MigrationRecord
    {
        return $this->store->findRecord($pluginSlug->value, $migration::class);
    }

    /** @return list<MigrationExecution> */
    public function historyForPlugin(PluginSlug $pluginSlug): array
    {
        return $this->store->findHistoryForPlugin($pluginSlug->value);
    }

    public function markMigrated(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $existingRecord = $this->recordForMigration($pluginSlug, $migration);

        if ($existingRecord !== null && $existingRecord->version === $migration->getVersion()) {
            return true;
        }

        $timestamp = $this->currentTimestamp();
        $record = new MigrationRecord(
            $pluginSlug->value,
            $migration::class,
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
        if (!$this->hasBeenMigrated($pluginSlug, $migration)) {
            return true;
        }

        $timestamp = $this->currentTimestamp();

        if (!$this->store->deleteRecord($pluginSlug->value, $migration::class)) {
            return false;
        }

        return $this->store->appendHistory(
            new MigrationExecution(
                $pluginSlug->value,
                $migration::class,
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
