<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Value;

final class MigrationRecord
{
    private readonly string $pluginValue;
    private readonly string $migrationValue;
    private readonly string $versionValue;
    private readonly string $migratedAtValue;

    public string $plugin {
        get => $this->pluginValue;
    }

    public string $migration {
        get => $this->migrationValue;
    }

    public string $version {
        get => $this->versionValue;
    }

    public string $migratedAt {
        get => $this->migratedAtValue;
    }

    public function __construct(
        string $plugin,
        string $migration,
        string $version,
        string $migratedAt,
    ) {
        $this->pluginValue = $plugin;
        $this->migrationValue = $migration;
        $this->versionValue = $version;
        $this->migratedAtValue = $migratedAt;
    }

    /**
     * @param array{plugin?: mixed, migration?: mixed, version?: mixed, migrated_at?: mixed} $record
     */
    public static function fromDatabaseRow(array $record): self
    {
        return new self(
            self::normalizeValue($record['plugin'] ?? ''),
            self::normalizeValue($record['migration'] ?? ''),
            self::normalizeValue($record['version'] ?? ''),
            self::normalizeValue($record['migrated_at'] ?? ''),
        );
    }

    /**
     * @return array{plugin: string, migration: string, version: string, migrated_at: string}
     */
    public function toArray(): array
    {
        return [
            'plugin' => $this->plugin,
            'migration' => $this->migration,
            'version' => $this->version,
            'migrated_at' => $this->migratedAt,
        ];
    }

    private static function normalizeValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
