<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use WP_CLI;

final readonly class MigrationCommand
{
    public function __construct(
        private ?MigrationRegistry $registry = null,
        private ?SymfonyConsoleRunner $consoleRunner = null,
    ) {
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
        $pluginSlug = $args[0] ?? null;
        $target = $args[1] ?? null;

        if (!is_string($pluginSlug) || $pluginSlug === '') {
            if ($target !== null) {
                WP_CLI::error('A target version requires a plugin slug.');
            }

            $this->migrateAllPlugins();
            return;
        }

        $this->migrateSinglePlugin($pluginSlug, $this->normalizeOptionalString($target));
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
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);
        $migrationClass = $this->normalizeOptionalString($assocArgs['migration'] ?? null);

        if ($pluginSlug !== null) {
            $this->rollbackSinglePlugin($pluginSlug, $migrationClass);
            return;
        }

        $this->rollbackAllPlugins();
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
        $pluginSlug = $this->requirePluginSlug($args, 'execute');
        $migrationClass = $this->requireMigrationClass($args, 'execute');
        $direction = $this->resolveExecutionDirection($assocArgs);
        $manager = $this->getManagerOrFail($pluginSlug);

        if (!$manager->executeMigration($migrationClass, $direction)) {
            WP_CLI::error(sprintf(
                'Failed to execute migration "%s" with direction "%s" for "%s".',
                $migrationClass,
                $direction,
                $pluginSlug,
            ));
        }

        WP_CLI::success(sprintf(
            'Executed "%s" for migration "%s" on "%s".',
            $direction,
            $migrationClass,
            $pluginSlug,
        ));
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
        $pluginSlug = $this->requirePluginSlug($args, 'version');
        $migrationClass = $this->requireMigrationClass($args, 'version');
        $direction = $this->resolveVersionDirection($assocArgs);
        $manager = $this->getManagerOrFail($pluginSlug);

        if (!$manager->markMigration($migrationClass, $direction)) {
            WP_CLI::error(sprintf(
                'Failed to update metadata for migration "%s" on "%s".',
                $migrationClass,
                $pluginSlug,
            ));
        }

        WP_CLI::success(sprintf(
            'Metadata updated for migration "%s" on "%s".',
            $migrationClass,
            $pluginSlug,
        ));
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
        if ($this->runConsoleCommand('migration:status', $args, $assocArgs)) {
            return;
        }

        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);
        $verbose = isset($assocArgs['verbose']);
        $format = $this->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $this->statusSinglePlugin($pluginSlug, $verbose, $format);
            return;
        }

        $this->statusAllPlugins($format);
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
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);

        if ($pluginSlug !== null) {
            $manager = $this->getManagerOrFail($pluginSlug);

            if ($manager->isUpToDate()) {
                WP_CLI::success(sprintf('"%s" is up to date.', $pluginSlug));
                return;
            }

            WP_CLI::warning(sprintf('"%s" has pending migrations.', $pluginSlug));
            return;
        }

        $format = $this->outputFormat($assocArgs);
        $rows = [];

        foreach ($this->registry()->all() as $slug => $manager) {
            $rows[] = [
                'plugin' => $slug,
                'up_to_date' => $manager->isUpToDate() ? 'yes' : 'no',
            ];
        }

        if ($rows === []) {
            WP_CLI::warning('No plugins with migrations registered.');
            return;
        }

        \WP_CLI\Utils\format_items($format, $rows, ['plugin', 'up_to_date']);
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
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);
        $format = $this->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $manager = $this->getManagerOrFail($pluginSlug);
            $row = $this->normalizeCurrentRow($pluginSlug, $manager->getCurrentMigration());

            if ($row === null) {
                WP_CLI::warning(sprintf('No migrated version found for "%s".', $pluginSlug));
                return;
            }

            \WP_CLI\Utils\format_items(
                $format,
                [$row],
                ['plugin', 'class', 'name', 'version', 'migrated_at'],
            );
            return;
        }

        $rows = [];

        foreach ($this->registry()->all() as $slug => $manager) {
            $row = $this->normalizeCurrentRow($slug, $manager->getCurrentMigration());

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            WP_CLI::warning('No migrated versions found.');
            return;
        }

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['plugin', 'class', 'name', 'version', 'migrated_at'],
        );
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
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);
        $format = $this->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $manager = $this->getManagerOrFail($pluginSlug);
            $row = $this->normalizeLatestRow($pluginSlug, $manager->getLatestMigration());

            if ($row === null) {
                WP_CLI::warning(sprintf('No registered migrations found for "%s".', $pluginSlug));
                return;
            }

            \WP_CLI\Utils\format_items($format, [$row], ['plugin', 'class', 'name', 'version']);
            return;
        }

        $rows = [];

        foreach ($this->registry()->all() as $slug => $manager) {
            $row = $this->normalizeLatestRow($slug, $manager->getLatestMigration());

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            WP_CLI::warning('No registered migrations found.');
            return;
        }

        \WP_CLI\Utils\format_items($format, $rows, ['plugin', 'class', 'name', 'version']);
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
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);
        $format = $this->outputFormat($assocArgs);
        $tracker = $this->tracker();
        $records = $pluginSlug === null
            ? $tracker->findAllHistory()
            : $tracker->findHistoryForPlugin($pluginSlug);

        if ($records === []) {
            WP_CLI::warning('No migration history found.');
            return;
        }

        $rows = array_map(
            fn (MigrationExecution $record): array => [
                'plugin' => $record->plugin,
                'migration' => $record->migration,
                'name' => $this->extractClassName($record->migration),
                'version' => $record->version,
                'direction' => $record->direction,
                'executed_at' => $record->executedAt,
            ],
            $records,
        );

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
        );
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
        if ($this->runConsoleCommand('migration:list', $args, $assocArgs)) {
            return;
        }

        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);
        $format = $this->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $this->listPluginMigrations($pluginSlug, $format);
            return;
        }

        $this->listRegisteredPlugins($format);
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
        $pluginSlug = $this->requirePluginSlug($args, 'info');
        $manager = $this->getManagerOrFail($pluginSlug);
        $format = $this->outputFormat($assocArgs);

        $this->statusSinglePlugin($pluginSlug, true, $format);

        $migrated = $manager->getMigratedVersions();

        if ($migrated !== []) {
            WP_CLI::log("\nMigrated versions:");
            \WP_CLI\Utils\format_items($format, $migrated, ['migration', 'version', 'migrated_at']);
        }

        $pending = $manager->getPendingMigrations();

        if ($pending !== []) {
            WP_CLI::log("\nPending migrations:");
            \WP_CLI\Utils\format_items($format, $pending, ['class', 'name', 'version']);
        }

        $history = $this->tracker()->findHistoryForPlugin($pluginSlug);

        if ($history === []) {
            return;
        }

        WP_CLI::log("\nExecution history:");
        $rows = array_map(
            fn (MigrationExecution $record): array => [
                'plugin' => $record->plugin,
                'migration' => $record->migration,
                'name' => $this->extractClassName($record->migration),
                'version' => $record->version,
                'direction' => $record->direction,
                'executed_at' => $record->executedAt,
            ],
            $history,
        );

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
        );
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
        $pluginSlug = $this->normalizeOptionalString($args[0] ?? null);

        if ($pluginSlug !== null) {
            $manager = $this->getManagerOrFail($pluginSlug);

            if (!$manager->syncMetadataStorage()) {
                WP_CLI::error(sprintf('Failed to sync metadata storage for "%s".', $pluginSlug));
            }

            WP_CLI::success(sprintf('Metadata storage synced for "%s".', $pluginSlug));
            return;
        }

        if (!$this->tracker()->ensureTableExists()) {
            WP_CLI::error('Failed to sync metadata storage.');
        }

        WP_CLI::success('Metadata storage synced.');
    }

    private function migrateSinglePlugin(string $pluginSlug, ?string $target): void
    {
        $manager = $this->getManagerOrFail($pluginSlug);

        if ($target === null && !$manager->hasPendingMigrations()) {
            WP_CLI::success(sprintf('No pending migrations for "%s".', $pluginSlug));
            return;
        }

        if ($target === null) {
            WP_CLI::log(sprintf('Running pending migrations for "%s"...', $pluginSlug));
        }

        if ($target !== null) {
            WP_CLI::log(sprintf('Migrating "%s" to "%s"...', $pluginSlug, $target));
        }

        if (!$manager->migrateTo($target)) {
            WP_CLI::error(sprintf('Migration failed for "%s".', $pluginSlug));
        }

        if ($target === null) {
            WP_CLI::success(sprintf('All migrations completed for "%s".', $pluginSlug));
            return;
        }

        WP_CLI::success(sprintf('Migration target "%s" reached for "%s".', $target, $pluginSlug));
    }

    private function migrateAllPlugins(): void
    {
        $managers = $this->registry()->all();

        if ($managers === []) {
            WP_CLI::warning('No plugins with migrations registered.');
            return;
        }

        $processed = 0;

        foreach ($managers as $slug => $manager) {
            if (!$manager->hasPendingMigrations()) {
                continue;
            }

            $processed++;
            WP_CLI::log(sprintf('Running pending migrations for "%s"...', $slug));

            if ($manager->runMigrations()) {
                WP_CLI::success(sprintf('Completed migrations for "%s".', $slug));
                continue;
            }

            WP_CLI::error(sprintf('Migration failed for "%s".', $slug));
        }

        if ($processed === 0) {
            WP_CLI::success('No pending migrations found.');
            return;
        }

        WP_CLI::success(sprintf('All migrations completed for %d plugin(s).', $processed));
    }

    private function rollbackSinglePlugin(string $pluginSlug, ?string $migrationClass): void
    {
        $manager = $this->getManagerOrFail($pluginSlug);

        if ($migrationClass !== null) {
            $this->rollbackSpecificMigration($pluginSlug, $manager, $migrationClass);
            return;
        }

        WP_CLI::log(sprintf('Rolling back all migrations for "%s"...', $pluginSlug));

        if (!$manager->rollbackMigrations()) {
            WP_CLI::error(sprintf('Rollback failed for "%s".', $pluginSlug));
        }

        WP_CLI::success(sprintf('All migrations rolled back for "%s".', $pluginSlug));
    }

    private function rollbackSpecificMigration(
        string $pluginSlug,
        MigrationManager $manager,
        string $migrationClass,
    ): void {

        WP_CLI::log(sprintf(
            'Rolling back migration "%s" for "%s"...',
            $migrationClass,
            $pluginSlug,
        ));

        if (!$manager->rollbackMigration($migrationClass)) {
            WP_CLI::error(sprintf(
                'Failed to rollback migration "%s" for "%s".',
                $migrationClass,
                $pluginSlug,
            ));
        }

        WP_CLI::success(sprintf(
            'Migration "%s" rolled back for "%s".',
            $migrationClass,
            $pluginSlug,
        ));
    }

    private function rollbackAllPlugins(): void
    {
        $managers = $this->registry()->all();

        if ($managers === []) {
            WP_CLI::warning('No plugins with migrations registered.');
            return;
        }

        $processed = 0;

        foreach ($managers as $slug => $manager) {
            $processed++;
            WP_CLI::log(sprintf('Rolling back migrations for "%s"...', $slug));

            if ($manager->rollbackMigrations()) {
                WP_CLI::success(sprintf('Rollback completed for "%s".', $slug));
                continue;
            }

            WP_CLI::error(sprintf('Rollback failed for "%s".', $slug));
        }

        WP_CLI::success(sprintf('All rollbacks completed for %d plugin(s).', $processed));
    }

    private function statusSinglePlugin(string $pluginSlug, bool $verbose, string $format): void
    {
        $manager = $this->getManagerOrFail($pluginSlug);
        $current = $manager->getCurrentMigration();
        $latest = $manager->getLatestMigration();
        $migrated = $manager->getMigratedVersions();
        $pending = $manager->getPendingMigrations();
        $history = $manager->getMigrationHistory();

        WP_CLI::log(sprintf("\nPlugin: %s", $pluginSlug));
        WP_CLI::log(sprintf('Status: %s', $manager->isUpToDate() ? 'Up to Date' : 'Pending'));
        WP_CLI::log(sprintf('Current: %s', $current['version'] ?? 'none'));
        WP_CLI::log(sprintf('Latest: %s', $latest['version'] ?? 'none'));
        WP_CLI::log(sprintf('Migrated: %d', count($migrated)));
        WP_CLI::log(sprintf('Pending: %d', count($pending)));
        WP_CLI::log(sprintf('Executions: %d', count($history)));

        if ($verbose && $pending !== []) {
            WP_CLI::log("\nPending migrations:");
            \WP_CLI\Utils\format_items($format, $pending, ['class', 'name', 'version']);
        }

        if ($verbose && $history !== []) {
            WP_CLI::log("\nRecent execution history:");
            \WP_CLI\Utils\format_items(
                $format,
                array_slice($this->historyRowsFromManager($manager), 0, 10),
                ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
            );
        }

        if ($manager->isUpToDate()) {
            WP_CLI::success(sprintf("\nPlugin \"%s\" is up to date.", $pluginSlug));
            return;
        }

        WP_CLI::warning(sprintf("\nPlugin \"%s\" has pending migrations.", $pluginSlug));
    }

    private function statusAllPlugins(string $format): void
    {
        $rows = [];

        foreach ($this->registry()->all() as $slug => $manager) {
            $rows[] = $this->pluginOverview($slug, $manager);
        }

        if ($rows === []) {
            WP_CLI::warning('No plugins with migrations registered.');
            return;
        }

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['plugin', 'current', 'latest', 'migrated', 'pending', 'executions', 'status'],
        );
    }

    private function listRegisteredPlugins(string $format): void
    {
        $rows = [];

        foreach ($this->registry()->all() as $slug => $manager) {
            $rows[] = $this->pluginOverview($slug, $manager);
        }

        if ($rows === []) {
            WP_CLI::warning('No plugins with migrations registered.');
            return;
        }

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['plugin', 'current', 'latest', 'migrated', 'pending', 'executions', 'status'],
        );
    }

    private function listPluginMigrations(string $pluginSlug, string $format): void
    {
        $manager = $this->getManagerOrFail($pluginSlug);
        $migratedByClass = [];

        foreach ($manager->getMigratedVersions() as $record) {
            $migratedByClass[$record['migration']] = $record;
        }

        $rows = [];

        foreach ($manager->all() as $class => $migration) {
            $record = $migratedByClass[$class] ?? null;
            $rows[] = [
                'class' => $class,
                'name' => $this->extractClassName($class),
                'version' => $migration->getVersion(),
                'status' => is_array($record) ? 'Migrated' : 'Pending',
                'migrated_at' => is_array($record) ? $record['migrated_at'] : '',
            ];
        }

        if ($rows === []) {
            WP_CLI::warning(sprintf('No registered migrations found for "%s".', $pluginSlug));
            return;
        }

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['class', 'name', 'version', 'status', 'migrated_at'],
        );
    }

    /**
     * @return array{
     *     plugin: string,
     *     current: string,
     *     latest: string,
     *     migrated: int,
     *     pending: int,
     *     executions: int,
     *     status: string
     * }
     */
    private function pluginOverview(string $pluginSlug, MigrationManager $manager): array
    {
        $current = $manager->getCurrentMigration();
        $latest = $manager->getLatestMigration();

        return [
            'plugin' => $pluginSlug,
            'current' => $current['version'] ?? 'none',
            'latest' => $latest['version'] ?? 'none',
            'migrated' => count($manager->getMigratedVersions()),
            'pending' => count($manager->getPendingMigrations()),
            'executions' => count($manager->getMigrationHistory()),
            'status' => $manager->isUpToDate() ? 'Up to Date' : 'Pending',
        ];
    }

    /**
     * @param array{
     *     class: string,
     *     name: string,
     *     version: string,
     *     migrated_at: string
     * }|null $current
     * @return array{
     *     plugin: string,
     *     class: string,
     *     name: string,
     *     version: string,
     *     migrated_at: string
     * }|null
     */
    private function normalizeCurrentRow(string $pluginSlug, ?array $current): ?array
    {
        if ($current === null) {
            return null;
        }

        return [
            'plugin' => $pluginSlug,
            'class' => $current['class'],
            'name' => $current['name'],
            'version' => $current['version'],
            'migrated_at' => $current['migrated_at'],
        ];
    }

    /**
     * @param array{class: string, name: string, version: string}|null $latest
     * @return array{plugin: string, class: string, name: string, version: string}|null
     */
    private function normalizeLatestRow(string $pluginSlug, ?array $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        return [
            'plugin' => $pluginSlug,
            'class' => $latest['class'],
            'name' => $latest['name'],
            'version' => $latest['version'],
        ];
    }

    /**
     * @return list<array{
     *     plugin: string,
     *     migration: string,
     *     name: string,
     *     version: string,
     *     direction: string,
     *     executed_at: string
     * }>
     */
    private function historyRowsFromManager(MigrationManager $manager): array
    {
        return array_map(
            fn (array $row): array => [
                'plugin' => $row['plugin'],
                'migration' => $row['migration'],
                'name' => $this->extractClassName($row['migration']),
                'version' => $row['version'],
                'direction' => $row['direction'],
                'executed_at' => $row['executed_at'],
            ],
            $manager->getMigrationHistory(),
        );
    }

    /**
     * @param array<string, scalar|null> $assocArgs
     */
    private function resolveExecutionDirection(array $assocArgs): string
    {
        $up = isset($assocArgs['up']);
        $down = isset($assocArgs['down']);

        if ($up && $down) {
            WP_CLI::error('Use either --up or --down, not both.');
        }

        if (!$up && !$down) {
            WP_CLI::error('Please provide either --up or --down.');
        }

        if ($up) {
            return 'up';
        }

        return 'down';
    }

    /**
     * @param array<string, scalar|null> $assocArgs
     */
    private function resolveVersionDirection(array $assocArgs): string
    {
        $add = isset($assocArgs['add']);
        $delete = isset($assocArgs['delete']);

        if ($add && $delete) {
            WP_CLI::error('Use either --add or --delete, not both.');
        }

        if (!$add && !$delete) {
            WP_CLI::error('Please provide either --add or --delete.');
        }

        if ($add) {
            return 'up';
        }

        return 'down';
    }

    /**
     * @param list<string> $args
     */
    private function requirePluginSlug(array $args, string $command): string
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
    private function requireMigrationClass(array $args, string $command): string
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

    private function getManagerOrFail(string $pluginSlug): MigrationManager
    {
        $manager = $this->registry()->get($pluginSlug);

        if ($manager instanceof MigrationManager) {
            return $manager;
        }

        $message = sprintf(
            'Plugin "%s" not found. Use "wp migration list" to see registered plugins.',
            $pluginSlug,
        );

        WP_CLI::error($message);
    }

    private function registry(): MigrationRegistry
    {
        return $this->registry ?? MigrationRegistry::getInstance();
    }

    private function tracker(): MigrationTracker
    {
        return new MigrationTracker($this->database());
    }

    private function database(): \wpdb
    {
        $database = $GLOBALS['wpdb'] ?? null;

        if ($database instanceof \wpdb) {
            return $database;
        }

        WP_CLI::error('Global $wpdb is not available.');
    }

    /**
     * @param array<string, scalar|null> $assocArgs
     */
    private function outputFormat(array $assocArgs): string
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
    private function runConsoleCommand(string $commandName, array $args, array $assocArgs): bool
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

    private function normalizeOptionalString(mixed $value): ?string
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

    private function extractClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        $className = array_pop($parts);

        if ($className === '') {
            return $fullClassName;
        }

        return $className;
    }
}
