<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use WP_CLI;

final class MigrationBootstrap
{
    private static ?self $instance = null;
    private bool $initialized = false;
    private readonly MigrationRegistry $registry;
    /** @var class-string */
    private string $commandClass;
    private ?object $command;

    /**
     * @param class-string $commandClass
     */
    private function __construct(MigrationRegistry $registry, string $commandClass, ?object $command = null)
    {
        $this->registry = $registry;
        $this->commandClass = $commandClass;
        $this->command = $command;
    }

    public static function getInstance(): self
    {
        return self::bootstrap();
    }

    /**
     * @param class-string $commandClass
     */
    public static function bootstrap(
        ?MigrationRegistry $registry = null,
        string $commandClass = MigrationCommand::class,
        ?object $command = null,
    ): self {

        if (self::$instance === null) {
            self::$instance = new self(
                $registry ?? MigrationRegistry::getInstance(),
                $commandClass,
                $command,
            );
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!$this->isWpCliAvailable()) {
            return;
        }

        $this->registerHooks();
        $this->registerCommands();

        $this->initialized = true;
    }

    private function isWpCliAvailable(): bool
    {
        if (!defined('WP_CLI')) {
            return false;
        }

        return (bool) constant('WP_CLI');
    }

    private function registerHooks(): void
    {
        // Sync registered migrations with CLI registry after registration
        add_action('db_migration_registered', [$this, 'syncMigrationsToRegistry'], 20, 1);
    }

    /**
     * @throws \Exception
     */
    private function registerCommands(): void
    {
        if ($this->command !== null) {
            // WP-CLI accepts command objects, but the test stub used by phpstan is narrower.
            // @phpstan-ignore-next-line argument.type
            WP_CLI::add_command('migration', $this->command);

            return;
        }

        WP_CLI::add_command('migration', $this->commandClass);
    }

    public function syncMigrationsToRegistry(MigrationSystem $system): void
    {
        $plugins = $system->getRegisteredPlugins();

        foreach ($plugins as $pluginSlug) {
            $databaseMigration = $system->createMigrationManager($pluginSlug);

            if ($databaseMigration === null) {
                continue;
            }

            $this->getRegistry()->set($pluginSlug, $databaseMigration);
        }
    }

    public function getRegistry(): MigrationRegistry
    {
        return $this->registry;
    }
}
