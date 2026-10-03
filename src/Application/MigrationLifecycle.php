<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Application;

use SymPress\WordPress\Migration\Contract\DeferredMigrationOperationExecutor;
use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Contract\MigrationOperationExecutor;
use SymPress\WordPress\Migration\Contract\MigrationSqlExecutor;
use SymPress\WordPress\Migration\Contract\MigrationStore;
use SymPress\WordPress\Migration\Exception\MigrationOperationException;
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
        MigrationKey::identities($migration);
        if ($this->sqlExecutor instanceof DeferredMigrationOperationExecutor) {
            return $this->sqlExecutor->runDeferredOperation(
                $pluginSlug->value,
                fn (): string|array => $this->needsUpdate($pluginSlug, $migration) ? $migration->up() : [],
                fn (string|array $statements): bool => !$this->needsUpdate($pluginSlug, $migration) || $this->migrateUnlocked($pluginSlug, $migration, $statements),
            );
        }
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

            if (!$this->saveCurrentRecord($migration, $record)) {
                return false;
            }

            return $this->store->appendHistory(
                $this->createExecution($record, 'up', $timestamp),
            );
        });
    }

    public function rollback(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        MigrationKey::identities($migration);
        if ($this->sqlExecutor instanceof DeferredMigrationOperationExecutor) {
            return $this->sqlExecutor->runDeferredOperation(
                $pluginSlug->value,
                fn (): string|array => $this->hasBeenMigrated($pluginSlug, $migration) ? $migration->down() : [],
                fn (string|array $statements): bool => !$this->hasBeenMigrated($pluginSlug, $migration) || $this->rollbackUnlocked($pluginSlug, $migration, $statements),
            );
        }
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

            if (!$this->deleteAppliedIdentities($pluginSlug, $migration)) {
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
        $records = [];

        foreach (MigrationKey::identities($migration) as $identity) {
            $record = $this->store->findRecord($pluginSlug->value, $identity);

            if ($record === null) {
                continue;
            }

            $records[] = $record;
        }

        $versions = array_unique(array_map(static fn (MigrationRecord $record): string => $record->version, $records));

        if (count($versions) > 1) {
            throw new MigrationOperationException('Conflicting legacy migration versions; '
                . 'reconcile the exact mapped identities before execution.');
        }

        return array_first($records);
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

    public function adoptLegacyMigration(PluginSlug $pluginSlug, MigrationContract $migration, string $legacyKey, string $expectedVersion, bool $retireSuperseded = false): bool
    {
        $key = MigrationKey::forMigration($migration);
        MigrationKey::assertStorageIdentity($legacyKey);
        if (!str_contains($legacyKey, '@anonymous') || $legacyKey === $key || $expectedVersion === '') {
            throw new \InvalidArgumentException(
                'Adoption requires an exact anonymous legacy identity and its recorded version.',
            );
        }
        return $this->runOperation($pluginSlug, [], function () use ($pluginSlug, $key, $legacyKey, $expectedVersion, $retireSuperseded): bool {
            $legacy = $this->store->findRecord($pluginSlug->value, $legacyKey);
            $current = $this->store->findRecord($pluginSlug->value, $key);
            if (
                $legacy === null || $legacy->version !== $expectedVersion
            ) {
                throw new MigrationOperationException(
                    'Legacy state does not match the reviewed version or conflicts with the stable identity.',
                );
            }
            if ($current !== null) {
                if (!$retireSuperseded) {
                    throw new MigrationOperationException('Stable identity already exists; review this additional '
                        . 'legacy record and use --retire-superseded to retain the stable version.');
                }
                return $this->store->deleteRecord($pluginSlug->value, $legacyKey)
                    && $this->store->appendHistory(
                        $this->createExecution($legacy, 'retire', $this->currentTimestamp()),
                    );
            }
            if ($retireSuperseded) {
                throw new MigrationOperationException(
                    '--retire-superseded requires an existing adopted stable identity.',
                );
            }
            $record = new MigrationRecord($pluginSlug->value, $key, $legacy->version, $legacy->migratedAt);
            if (!$this->store->saveRecord($record) || !$this->store->deleteRecord($pluginSlug->value, $legacyKey)) {
                return false;
            }
            return $this->store->appendHistory($this->createExecution($record, 'adopt', $this->currentTimestamp()));
        });
    }

    private function markMigratedUnlocked(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        $existingRecord = $this->recordForMigration($pluginSlug, $migration);

        if (
            $existingRecord !== null && $existingRecord->version === $migration->getVersion()
            && $existingRecord->migration === MigrationKey::forMigration($migration)
            && !$this->hasAppliedAliases($pluginSlug, $migration)
        ) {
            return true;
        }

        $timestamp = $this->currentTimestamp();
        $record = new MigrationRecord(
            $pluginSlug->value,
            MigrationKey::forMigration($migration),
            $migration->getVersion(),
            $timestamp,
        );

        if (!$this->saveCurrentRecord($migration, $record)) {
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

        if (!$this->deleteAppliedIdentities($pluginSlug, $migration)) {
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

    private function saveCurrentRecord(MigrationContract $migration, MigrationRecord $record): bool
    {
        if (!$this->store->saveRecord($record)) {
            return false;
        }

        foreach (MigrationKey::identities($migration) as $identity) {
            if ($identity !== $record->migration && !$this->store->deleteRecord($record->plugin, $identity)) {
                return false;
            }
        }

        return true;
    }

    private function deleteAppliedIdentities(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        foreach (MigrationKey::identities($migration) as $identity) {
            if (!$this->store->deleteRecord($pluginSlug->value, $identity)) {
                return false;
            }
        }

        return true;
    }

    private function hasAppliedAliases(PluginSlug $pluginSlug, MigrationContract $migration): bool
    {
        foreach (MigrationKey::identities($migration) as $identity) {
            if ($identity !== MigrationKey::forMigration($migration) && $this->store->findRecord($pluginSlug->value, $identity) !== null) {
                return true;
            }
        }

        return false;
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
