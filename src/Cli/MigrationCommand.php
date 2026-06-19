<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use SymPress\WordPress\Migration\Registry\MigrationRegistry;

final readonly class MigrationCommand
{
    private MigrationCommandExecutor $executor;
    private MigrationCommandReporter $reporter;

    public function __construct(
        ?MigrationRegistry $registry = null,
        ?SymfonyConsoleRunner $consoleRunner = null,
    ) {

        $context = new MigrationCommandContext($registry, $consoleRunner);
        $this->executor = new MigrationCommandExecutor($context);
        $this->reporter = new MigrationCommandReporter($context);
    }

    /**
     * Backward-compatible alias for `migrate`.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to migrate. If omitted, runs pending migrations for all plugins.
     *
     * [<target>]
     * : Optional target version, migration FQCN, or short class name.
     *
     * @param list<string> $args
     */
    public function run(array $args): void
    {
        $this->migrate($args);
    }

    /**
     * Run migrations up to the latest or a specific target.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to migrate. If omitted, runs all pending migrations for all plugins.
     *
     * [<target>]
     * : Optional target version, migration FQCN, or short class name.
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function migrate(array $args, array $assocArgs = []): void
    {
        $this->executor->migrate($args);
    }

    /**
     * Rollback all migrated versions or a specific migration.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to rollback. If omitted, rolls back all plugins.
     *
     * [--migration=<class>]
     * : Specific migration class or short class name to rollback.
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function rollback(array $args, array $assocArgs): void
    {
        $this->executor->rollback($args, $assocArgs);
    }

    /**
     * Execute exactly one migration in the requested direction.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : The plugin slug.
     *
     * <migration>
     * : Migration FQCN or short class name.
     *
     * --up
     * : Execute the migration up.
     *
     * --down
     * : Execute the migration down.
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function execute(array $args, array $assocArgs): void
    {
        $this->executor->execute($args, $assocArgs);
    }

    /**
     * Mark a version as migrated or rolled back without executing SQL.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : The plugin slug.
     *
     * <migration>
     * : Migration FQCN or short class name.
     *
     * --add
     * : Mark the version as migrated.
     *
     * --delete
     * : Mark the version as rolled back.
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function version(array $args, array $assocArgs): void
    {
        $this->executor->version($args, $assocArgs);
    }

    /**
     * Show migration status.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to inspect. If omitted, shows all registered plugins.
     *
     * [--verbose]
     * : Show pending migrations and recent history for a single plugin.
     *
     * [--format=<format>]
     * : Output format for multi-row output. Default: table
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function status(array $args, array $assocArgs = []): void
    {
        $this->reporter->status($args, $assocArgs);
    }

    /**
     * Check whether plugins are fully migrated.
     *
     * @subcommand up-to-date
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to inspect. If omitted, checks all registered plugins.
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function upToDate(array $args = [], array $assocArgs = []): void
    {
        $this->reporter->upToDate($args, $assocArgs);
    }

    /**
     * Show the current migrated version for one or all plugins.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to inspect. If omitted, shows all registered plugins.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default: table
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function current(array $args = [], array $assocArgs = []): void
    {
        $this->reporter->current($args, $assocArgs);
    }

    /**
     * Show the latest available migration for one or all plugins.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : The plugin slug to inspect. If omitted, shows all registered plugins.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default: table
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function latest(array $args = [], array $assocArgs = []): void
    {
        $this->reporter->latest($args, $assocArgs);
    }

    /**
     * Show migration execution history from metadata storage.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : Optional plugin slug filter.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default: table
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function history(array $args = [], array $assocArgs = []): void
    {
        $this->reporter->history($args, $assocArgs);
    }

    /**
     * List plugins or migrations.
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : Optional plugin slug. Without a slug, all registered plugins are listed.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default: table
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function list(array $args = [], array $assocArgs = []): void
    {
        $this->reporter->list($args, $assocArgs);
    }

    /**
     * Show a full plugin migration overview.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : The plugin slug to inspect.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default: table
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function info(array $args, array $assocArgs = []): void
    {
        $this->reporter->info($args, $assocArgs);
    }

    /**
     * Ensure migration metadata tables exist.
     *
     * @subcommand sync-metadata-storage
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : Optional plugin slug. Metadata storage itself is shared globally.
     *
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function syncMetadataStorage(array $args = [], array $assocArgs = []): void
    {
        $this->executor->syncMetadataStorage($args);
    }
}
