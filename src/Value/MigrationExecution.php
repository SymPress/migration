<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Value;

final class MigrationExecution
{
    private readonly string $pluginValue;
    private readonly string $migrationValue;
    private readonly string $versionValue;
    private readonly string $directionValue;
    private readonly string $executedAtValue;

    public string $plugin {
        get => $this->pluginValue;
    }

    public string $migration {
        get => $this->migrationValue;
    }

    public string $version {
        get => $this->versionValue;
    }

    public string $direction {
        get => $this->directionValue;
    }

    public string $executedAt {
        get => $this->executedAtValue;
    }

    public function __construct(
        string $plugin,
        string $migration,
        string $version,
        string $direction,
        string $executedAt,
    ) {
        $this->pluginValue = $plugin;
        $this->migrationValue = $migration;
        $this->versionValue = $version;
        $this->directionValue = $direction;
        $this->executedAtValue = $executedAt;
    }

    /**
     * @param array{
     *     plugin?: mixed,
     *     migration?: mixed,
     *     version?: mixed,
     *     direction?: mixed,
     *     executed_at?: mixed
     * } $record
     */
    public static function fromDatabaseRow(array $record): self
    {
        return new self(
            self::normalizeValue($record['plugin'] ?? ''),
            self::normalizeValue($record['migration'] ?? ''),
            self::normalizeValue($record['version'] ?? ''),
            self::normalizeValue($record['direction'] ?? ''),
            self::normalizeValue($record['executed_at'] ?? ''),
        );
    }

    /**
     * @return array{
     *     plugin: string,
     *     migration: string,
     *     version: string,
     *     direction: string,
     *     executed_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'plugin' => $this->plugin,
            'migration' => $this->migration,
            'version' => $this->version,
            'direction' => $this->direction,
            'executed_at' => $this->executedAt,
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
