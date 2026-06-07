<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Registry;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Value\PluginSlug;

final class MigrationRegistry
{
    private static ?self $instance = null;

    /** @var array<string, MigrationManager> */
    private array $migrationManagers = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function set(string $id, MigrationManager $manager): void
    {
        $this->migrationManagers[$this->normalizePluginSlug($id)] = $manager;
    }

    public function register(string $id, MigrationManager $manager): void
    {
        $this->set($id, $manager);
    }

    public function get(string $id): ?MigrationManager
    {
        return $this->migrationManagers[$this->normalizePluginSlug($id)] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->migrationManagers[$this->normalizePluginSlug($id)]);
    }

    /** @return array<string, MigrationManager> */
    public function all(): array
    {
        return $this->migrationManagers;
    }

    /** @return array<string, MigrationManager> */
    public function getAll(): array
    {
        return $this->all();
    }

    public function clear(): void
    {
        $this->migrationManagers = [];
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private function normalizePluginSlug(string $pluginSlug): string
    {
        return PluginSlug::fromString($pluginSlug)->value;
    }
}
