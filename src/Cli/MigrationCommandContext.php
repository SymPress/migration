<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use WP_CLI;

final readonly class MigrationCommandContext
{
    public function __construct(
        private ?MigrationRegistry $registry = null,
        private ?SymfonyConsoleRunner $consoleRunner = null,
    ) {
    }

    public function registry(): MigrationRegistry
    {
        return $this->registry ?? MigrationRegistry::getInstance();
    }

    public function tracker(): MigrationTracker
    {
        return new MigrationTracker($this->database());
    }

    public function database(): \wpdb
    {
        $database = $GLOBALS['wpdb'] ?? null;

        if ($database instanceof \wpdb) {
            return $database;
        }

        WP_CLI::error('Global $wpdb is not available.');
    }

    public function managerOrFail(string $pluginSlug): MigrationManager
    {
        $manager = $this->registry()->get($pluginSlug);

        if ($manager instanceof MigrationManager) {
            return $manager;
        }

        WP_CLI::error(sprintf(
            'Plugin "%s" not found. Use "wp migration list" to see registered plugins.',
            $pluginSlug,
        ));
    }

    /**
     * @param list<string> $args
     */
    public function requirePluginSlug(array $args, string $command): string
    {
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);

        if ($pluginSlug !== null) {
            return $pluginSlug;
        }

        WP_CLI::error(sprintf(
            'Please provide a plugin slug. Example: wp migration %s my-plugin',
            $command,
        ));
    }

    /**
     * @param list<string> $args
     */
    public function requireMigrationClass(array $args, string $command): string
    {
        $migrationClass = $this->normalizeOptionalString($args[1] ?? null);

        if ($migrationClass !== null) {
            return $migrationClass;
        }

        WP_CLI::error(sprintf(
            'Please provide a migration class. Example: '
            . 'wp migration %s my-plugin Vendor\\\\MyMigration --up',
            $command,
        ));
    }

    /**
     * @param array<string, scalar|null> $assocArgs
     */
    public function outputFormat(array $assocArgs): string
    {
        $format = $this->normalizeOptionalString($assocArgs['format'] ?? null);

        if ($format !== null) {
            return $format;
        }

        return 'table';
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function runConsoleCommand(string $commandName, array $args, array $assocArgs): bool
    {
        if (!$this->consoleRunner instanceof SymfonyConsoleRunner || !$this->consoleRunner->has($commandName)) {
            return false;
        }

        $status = $this->consoleRunner->run($commandName, $args, $assocArgs);

        if ($status !== 0) {
            if (method_exists(WP_CLI::class, 'halt')) {
                WP_CLI::halt($status);
            }

            WP_CLI::error(sprintf('Symfony command "%s" failed with status %d.', $commandName, $status));
        }

        return true;
    }

    public function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $stringValue = trim((string) $value);

        if ($stringValue === '') {
            return null;
        }

        return $stringValue;
    }

    public function extractClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        $className = array_pop($parts);

        if ($className === '') {
            return $fullClassName;
        }

        return $className;
    }
}
