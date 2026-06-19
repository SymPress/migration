<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Infrastructure;

use SymPress\WordPress\Migration\Contract\MigrationStore;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use SymPress\WordPress\Migration\Value\MigrationRecord;

final class MigrationTracker implements MigrationStore
{
    private const string TABLE_NAME = 'migrations';
    private const string HISTORY_TABLE_NAME = 'migration_history';

    private string $tableName;
    private string $historyTableName;
    private bool $tableChecked = false;
    private bool $tableCreated = false;
    private bool $historyTableChecked = false;
    private bool $historyTableCreated = false;
    private bool $autoCleanup = false;

    public function __construct(private readonly \wpdb $database, ?string $tableName = null)
    {
        $this->tableName = $tableName ?? $database->prefix . self::TABLE_NAME;
        $this->historyTableName = $database->prefix . self::HISTORY_TABLE_NAME;
    }

    #[\Override]
    public function ensureTableExists(): bool
    {
        if ($this->tableCreated && $this->historyTableCreated) {
            return true;
        }

        if ($this->stateTableExists() && $this->historyTableExists()) {
            $this->tableCreated = true;
            $this->historyTableCreated = true;

            return true;
        }

        if (!$this->ensureStateTableExists()) {
            return false;
        }

        return $this->ensureHistoryTableExists();
    }

    public function shouldAutoCleanup(): bool
    {
        return $this->autoCleanup;
    }

    public function enableAutoCleanup(): void
    {
        $this->autoCleanup = true;
    }

    public function disableAutoCleanup(): void
    {
        $this->autoCleanup = false;
    }

    public function record(string $plugin, string $migrationName, string $version): bool
    {
        return $this->saveRecord(
            new MigrationRecord(
                $plugin,
                $migrationName,
                $version,
                $this->currentTimestamp(),
            ),
        );
    }

    #[\Override]
    public function saveRecord(MigrationRecord $record): bool
    {
        if (!$this->ensureTableExists()) {
            return false;
        }

        $existingRecord = $this->findRecord($record->plugin, $record->migration);

        if ($existingRecord !== null) {
            return $this->update($record);
        }

        return $this->insert($record);
    }

    public function remove(string $plugin, string $migrationName): bool
    {
        return $this->deleteRecord($plugin, $migrationName);
    }

    #[\Override]
    public function deleteRecord(string $plugin, string $migrationName): bool
    {
        if (!$this->stateTableExists()) {
            return true;
        }

        $result = $this->database->delete(
            $this->tableName,
            [
                'plugin'    => $plugin,
                'migration' => $migrationName,
            ],
            ['%s', '%s'],
        );

        if ($result === false) {
            return false;
        }

        $this->cleanupTableIfEmpty();

        return true;
    }

    public function removeAllForPlugin(string $plugin): bool
    {
        return $this->deleteAllRecordsForPlugin($plugin);
    }

    #[\Override]
    public function deleteAllRecordsForPlugin(string $plugin): bool
    {
        if (!$this->stateTableExists()) {
            return true;
        }

        $result = $this->database->delete(
            $this->tableName,
            ['plugin' => $plugin],
            ['%s'],
        );

        if ($result === false) {
            return false;
        }

        $this->cleanupTableIfEmpty();

        return true;
    }

    /** @return array{plugin: string, migration: string, version: string, migrated_at: string}|null */
    public function get(string $plugin, string $migrationName): ?array
    {
        $record = $this->findRecord($plugin, $migrationName);

        if ($record === null) {
            return null;
        }

        return $record->toArray();
    }

    #[\Override]
    public function findRecord(string $plugin, string $migrationName): ?MigrationRecord
    {
        if (!$this->stateTableExists()) {
            return null;
        }

        $query = $this->database->prepare(
            'SELECT plugin, migration, version, migrated_at
            FROM %i
            WHERE plugin = %s AND migration = %s',
            $this->tableName,
            $plugin,
            $migrationName,
        );

        $result = $this->database->get_row($query, ARRAY_A);

        if (!is_array($result)) {
            return null;
        }

        return MigrationRecord::fromDatabaseRow($result);
    }

    #[\Override]
    public function getVersion(string $plugin, string $migrationName): ?string
    {
        $record = $this->get($plugin, $migrationName);

        if ($record === null) {
            return null;
        }

        return $record['version'];
    }

    /** @return list<array{plugin: string, migration: string, version: string, migrated_at: string}> */
    public function getAllForPlugin(string $plugin): array
    {
        return array_map(
            static fn (MigrationRecord $record): array => $record->toArray(),
            $this->findRecordsForPlugin($plugin),
        );
    }

    /** @return list<MigrationRecord> */
    #[\Override]
    public function findRecordsForPlugin(string $plugin): array
    {
        if (!$this->stateTableExists()) {
            return [];
        }

        $query = $this->database->prepare(
            'SELECT plugin, migration, version, migrated_at
            FROM %i
            WHERE plugin = %s
            ORDER BY id ASC',
            $this->tableName,
            $plugin,
        );

        $results = $this->database->get_results($query, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        /** @var list<array<string, mixed>> $results */
        return $this->mapRecords($results);
    }

    /** @return list<array{plugin: string, migration: string, version: string, migrated_at: string}> */
    public function getAll(): array
    {
        return array_map(
            static fn (MigrationRecord $record): array => $record->toArray(),
            $this->findAllRecords(),
        );
    }

    /** @return list<MigrationRecord> */
    #[\Override]
    public function findAllRecords(): array
    {
        if (!$this->stateTableExists()) {
            return [];
        }

        $query = "SELECT plugin, migration, version, migrated_at
        FROM {$this->tableName}
        ORDER BY plugin ASC, id ASC";
        $results = $this->database->get_results($query, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        /** @var list<array<string, mixed>> $results */
        return $this->mapRecords($results);
    }

    #[\Override]
    public function hasMigrations(string $plugin): bool
    {
        if (!$this->stateTableExists()) {
            return false;
        }

        $query = $this->database->prepare(
            'SELECT COUNT(*) FROM %i WHERE plugin = %s',
            $this->tableName,
            $plugin,
        );

        $count = $this->database->get_var($query);

        return (int) $count > 0;
    }

    #[\Override]
    public function hasAnyMigrations(): bool
    {
        if (!$this->stateTableExists()) {
            return false;
        }

        $query = "SELECT COUNT(*) FROM {$this->tableName}";
        $count = $this->database->get_var($query);

        return (int) $count > 0;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    #[\Override]
    public function appendHistory(MigrationExecution $execution): bool
    {
        if (!$this->ensureHistoryTableExists()) {
            return false;
        }

        $result = $this->database->insert(
            $this->historyTableName,
            [
                'plugin'      => $execution->plugin,
                'migration'   => $execution->migration,
                'version'     => $execution->version,
                'direction'   => $execution->direction,
                'executed_at' => $execution->executedAt,
            ],
            ['%s', '%s', '%s', '%s', '%s'],
        );

        return $result !== false;
    }

    /** @return list<MigrationExecution> */
    #[\Override]
    public function findHistoryForPlugin(string $pluginSlug): array
    {
        if (!$this->historyTableExists()) {
            return [];
        }

        $query = $this->database->prepare(
            'SELECT plugin, migration, version, direction, executed_at
            FROM %i
            WHERE plugin = %s
            ORDER BY id DESC',
            $this->historyTableName,
            $pluginSlug,
        );

        $results = $this->database->get_results($query, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        /** @var list<array<string, mixed>> $results */
        return $this->mapExecutions($results);
    }

    /** @return list<MigrationExecution> */
    #[\Override]
    public function findAllHistory(): array
    {
        if (!$this->historyTableExists()) {
            return [];
        }

        $query = "SELECT plugin, migration, version, direction, executed_at
        FROM {$this->historyTableName}
        ORDER BY id DESC";
        $results = $this->database->get_results($query, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        /** @var list<array<string, mixed>> $results */
        return $this->mapExecutions($results);
    }

    private function insert(MigrationRecord $record): bool
    {
        $result = $this->database->insert(
            $this->tableName,
            [
                'plugin'      => $record->plugin,
                'migration'   => $record->migration,
                'version'     => $record->version,
                'migrated_at' => $record->migratedAt,
            ],
            ['%s', '%s', '%s', '%s'],
        );

        return $result !== false;
    }

    private function update(MigrationRecord $record): bool
    {
        $result = $this->database->update(
            $this->tableName,
            [
                'version'     => $record->version,
                'migrated_at' => $record->migratedAt,
            ],
            [
                'plugin'    => $record->plugin,
                'migration' => $record->migration,
            ],
            ['%s', '%s'],
            ['%s', '%s'],
        );

        return $result !== false;
    }

    private function cleanupTableIfEmpty(): void
    {
        if (!$this->autoCleanup) {
            return;
        }

        if ($this->hasAnyMigrations()) {
            return;
        }

        if ($this->hasAnyHistory()) {
            return;
        }

        $this->dropTable();
    }

    private function dropTable(): bool
    {
        $currentDropped = $this->dropCurrentTable();
        $historyDropped = $this->dropHistoryTable();

        return $currentDropped && $historyDropped;
    }

    private function dropCurrentTable(): bool
    {
        if (!$this->stateTableExists()) {
            return true;
        }

        $query = "DROP TABLE IF EXISTS {$this->tableName}";
        $result = $this->database->query($query);

        if ($result !== false) {
            $this->tableCreated = false;
            $this->tableChecked = true;
        }

        return $result !== false;
    }

    private function dropHistoryTable(): bool
    {
        if (!$this->historyTableExists()) {
            return true;
        }

        $query = "DROP TABLE IF EXISTS {$this->historyTableName}";
        $result = $this->database->query($query);

        if ($result !== false) {
            $this->historyTableCreated = false;
            $this->historyTableChecked = true;
        }

        return $result !== false;
    }

    private function loadWordPressUpgradeLibrary(): void
    {
        if (function_exists('dbDelta')) {
            return;
        }

        if (!defined('ABSPATH')) {
            throw new \RuntimeException('ABSPATH is not defined.');
        }

        $absolutePath = constant('ABSPATH');

        if ($absolutePath === '') {
            throw new \RuntimeException('ABSPATH must be a non-empty string.');
        }

        require_once $absolutePath . 'wp-admin/includes/upgrade.php';
    }

    private function ensureStateTableExists(): bool
    {
        if ($this->stateTableExists()) {
            return true;
        }

        return $this->createStateTable();
    }

    private function ensureHistoryTableExists(): bool
    {
        if ($this->historyTableExists()) {
            return true;
        }

        return $this->createHistoryTable();
    }

    private function stateTableExists(): bool
    {
        if ($this->tableChecked) {
            return $this->tableCreated;
        }

        $this->tableCreated = $this->queryTableExists($this->tableName);
        $this->tableChecked = true;

        return $this->tableCreated;
    }

    private function historyTableExists(): bool
    {
        if ($this->historyTableChecked) {
            return $this->historyTableCreated;
        }

        $this->historyTableCreated = $this->queryTableExists($this->historyTableName);
        $this->historyTableChecked = true;

        return $this->historyTableCreated;
    }

    private function createStateTable(): bool
    {
        $this->loadWordPressUpgradeLibrary();

        dbDelta($this->stateTableSql());

        $this->tableCreated = $this->queryTableExists($this->tableName);
        $this->tableChecked = true;

        return $this->tableCreated;
    }

    private function createHistoryTable(): bool
    {
        $this->loadWordPressUpgradeLibrary();

        dbDelta($this->historyTableSql());

        $this->historyTableCreated = $this->queryTableExists($this->historyTableName);
        $this->historyTableChecked = true;

        return $this->historyTableCreated;
    }

    private function stateTableSql(): string
    {
        $charsetCollate = $this->database->get_charset_collate();

        return "CREATE TABLE {$this->tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plugin varchar(191) NOT NULL,
            migration varchar(191) NOT NULL,
            version varchar(64) NOT NULL,
            migrated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY plugin_migration (plugin, migration),
            KEY plugin (plugin),
            KEY migration (migration)
        ) {$charsetCollate};";
    }

    private function historyTableSql(): string
    {
        $charsetCollate = $this->database->get_charset_collate();

        return "CREATE TABLE {$this->historyTableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plugin varchar(191) NOT NULL,
            migration varchar(191) NOT NULL,
            version varchar(64) NOT NULL,
            direction varchar(32) NOT NULL,
            executed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY plugin (plugin),
            KEY migration (migration),
            KEY direction (direction)
        ) {$charsetCollate};";
    }

    private function queryTableExists(string $tableName): bool
    {
        $query = $this->database->prepare(
            'SHOW TABLES LIKE %s',
            $tableName,
        );

        $result = $this->database->get_var($query);

        return $result === $tableName;
    }

    private function currentTimestamp(): string
    {
        if (function_exists('current_time')) {
            return current_time('mysql', true);
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<MigrationRecord>
     */
    private function mapRecords(array $results): array
    {
        return array_map(
            static fn (array $record): MigrationRecord => MigrationRecord::fromDatabaseRow($record),
            $results,
        );
    }

    private function hasAnyHistory(): bool
    {
        if (!$this->historyTableExists()) {
            return false;
        }

        $query = "SELECT COUNT(*) FROM {$this->historyTableName}";
        $count = $this->database->get_var($query);

        return (int) $count > 0;
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<MigrationExecution>
     */
    private function mapExecutions(array $results): array
    {
        return array_map(
            static fn (array $record): MigrationExecution => MigrationExecution::fromDatabaseRow($record),
            $results,
        );
    }
}
