<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Contract;

use SymPress\WordPress\Migration\Value\MigrationExecution;
use SymPress\WordPress\Migration\Value\MigrationRecord;

interface MigrationStore
{
    public function ensureTableExists(): bool;

    public function saveRecord(MigrationRecord $record): bool;

    public function deleteRecord(string $pluginSlug, string $migrationClass): bool;

    public function deleteAllRecordsForPlugin(string $pluginSlug): bool;

    public function findRecord(string $pluginSlug, string $migrationClass): ?MigrationRecord;

    public function appendHistory(MigrationExecution $execution): bool;

    /**
     * @return list<MigrationRecord>
     */
    public function findRecordsForPlugin(string $pluginSlug): array;

    /**
     * @return list<MigrationRecord>
     */
    public function findAllRecords(): array;

    /**
     * @return list<MigrationExecution>
     */
    public function findHistoryForPlugin(string $pluginSlug): array;

    /**
     * @return list<MigrationExecution>
     */
    public function findAllHistory(): array;

    public function getVersion(string $pluginSlug, string $migrationClass): ?string;

    public function hasMigrations(string $pluginSlug): bool;

    public function hasAnyMigrations(): bool;
}
