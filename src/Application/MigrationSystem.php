<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Application;

use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Value\PluginSlug;

final class MigrationSystem
{
    private static ?self $instance = null;

    /** @var array<string, MigrationCollection> */
    private array $registeredMigrations = [];

    private bool $initialized = false;
    private readonly MigrationTracker $tracker;
    private readonly MigrationManagerFactory $migrationManagerFactory;

    public function __construct(
        \wpdb $database,
        ?MigrationTracker $tracker = null,
        ?MigrationManagerFactory $migrationManagerFactory = null,
    ) {

        $this->tracker = $tracker ?? new MigrationTracker($database);
        $this->migrationManagerFactory = $migrationManagerFactory ?? new MigrationManagerFactory(
            new MigrationLifecycle(
                $this->getTracker(),
                new WordPressSqlExecutor($database),
            ),
        );
    }

    public static function getInstance(): self
    {
        return self::bootstrap(self::resolveDatabase());
    }

    public static function bootstrap(
        \wpdb $database,
        ?MigrationTracker $tracker = null,
        ?MigrationManagerFactory $migrationManagerFactory = null,
    ): self {

        if (self::$instance === null) {
            self::$instance = new self($database, $tracker, $migrationManagerFactory);
        }

        return self::$instance;
    }

    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->ensureMigrationTableExists();
        $this->registerHooks();
        $this->initialized = true;
    }

    public function registerMigration(string $pluginSlug, MigrationContract $migration): void
    {
        $pluginSlugValue = PluginSlug::fromString($pluginSlug)->value;
        $collection = $this->registeredMigrations[$pluginSlugValue] ?? MigrationCollection::empty();

        $this->registeredMigrations[$pluginSlugValue] = $collection->with($migration);
    }

    /** @param iterable<MigrationContract> $migrations */
    public function registerMigrations(string $pluginSlug, iterable $migrations): void
    {
        foreach ($migrations as $migration) {
            $this->registerMigration($pluginSlug, $migration);
        }
    }

    /** @return list<MigrationContract> */
    public function getMigrations(string $pluginSlug): array
    {
        $pluginSlugValue = PluginSlug::fromString($pluginSlug)->value;

        if (!isset($this->registeredMigrations[$pluginSlugValue])) {
            return [];
        }

        return $this->registeredMigrations[$pluginSlugValue]->inRegistrationOrder();
    }

    /** @return list<string> */
    public function getRegisteredPlugins(): array
    {
        return array_keys($this->registeredMigrations);
    }

    public function hasPlugin(string $pluginSlug): bool
    {
        return isset($this->registeredMigrations[PluginSlug::fromString($pluginSlug)->value]);
    }

    #[\NoDiscard]
    public function createMigrationManager(string $pluginSlug): ?MigrationManager
    {
        $pluginSlugValue = PluginSlug::fromString($pluginSlug)->value;

        if (!isset($this->registeredMigrations[$pluginSlugValue])) {
            return null;
        }

        return $this->migrationManagerFactory->create(
            $pluginSlugValue,
            $this->registeredMigrations[$pluginSlugValue]->inRegistrationOrder(),
        );
    }

    public function getTracker(): MigrationTracker
    {
        return $this->tracker;
    }

    public function getMigrationManagerFactory(): MigrationManagerFactory
    {
        return $this->migrationManagerFactory;
    }

    #[\NoDiscard]
    public function createDatabaseMigration(string $pluginSlug): ?MigrationManager
    {
        return $this->createMigrationManager($pluginSlug);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function dispatchRegistrationHook(): void
    {
        do_action('db_migration_register', $this);
    }

    public function dispatchRegisteredHook(): void
    {
        do_action('db_migration_registered', $this);
    }

    private function ensureMigrationTableExists(): void
    {
        $this->getTracker()->ensureTableExists();
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'dispatchRegistrationHook'], 5);
        add_action('init', [$this, 'dispatchRegisteredHook'], 15);
    }

    private static function resolveDatabase(): \wpdb
    {
        $database = $GLOBALS['wpdb'] ?? null;

        if ($database instanceof \wpdb) {
            return $database;
        }

        throw new \RuntimeException('Global $wpdb is not available.');
    }
}
