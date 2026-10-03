<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Exception\MigrationOperationException;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use WP_CLI;

final readonly class MigrationCommandExecutor
{
    public function __construct(
        private MigrationCommandContext $context,
    ) {
    }

    /** @param list<string> $args */
    public function migrate(array $args): void
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

        $this->migrateSinglePlugin($pluginSlug, $this->context->normalizeOptionalString($target));
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function rollback(array $args, array $assocArgs): void
    {
        $this->context->requireRollbackEnvironment();
        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);
        $migrationClass = $this->context->normalizeOptionalString($assocArgs['migration'] ?? null);

        if ($pluginSlug !== null) {
            $this->rollbackSinglePlugin($pluginSlug, $migrationClass);
            return;
        }

        $this->rollbackAllPlugins();
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function execute(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->requirePluginSlug($args, 'execute');
        $migrationClass = $this->context->requireMigrationClass($args, 'execute');
        $direction = $this->resolveExecutionDirection($assocArgs);
        if ($direction === 'down') {
            $this->context->requireRollbackEnvironment();
        }
        $manager = $this->context->managerOrFail($pluginSlug);

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
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function version(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->requirePluginSlug($args, 'version');
        $migrationClass = $this->context->requireMigrationClass($args, 'version');
        $direction = $this->resolveVersionDirection($assocArgs);
        $manager = $this->context->managerOrFail($pluginSlug);

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

    /** @param list<string> $args */
    public function syncMetadataStorage(array $args): void
    {
        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);

        if ($pluginSlug !== null) {
            $manager = $this->context->managerOrFail($pluginSlug);

            if (!$manager->syncMetadataStorage()) {
                WP_CLI::error(sprintf('Failed to sync metadata storage for "%s".', $pluginSlug));
            }

            WP_CLI::success(sprintf('Metadata storage synced for "%s".', $pluginSlug));
            return;
        }

        $tracker = $this->context->tracker();
        $executor = new WordPressSqlExecutor($this->context->database());
        if (!$executor->runOperation('metadata', ['CREATE TABLE'], $tracker->ensureTableExists(...))) {
            WP_CLI::error('Failed to sync metadata storage.');
        }

        WP_CLI::success('Metadata storage synced.');
    }

    private function migrateSinglePlugin(string $pluginSlug, ?string $target): void
    {
        $manager = $this->context->managerOrFail($pluginSlug);

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

        $allowRollback = $this->context->allowsRollback();
        if (!$manager->migrateTo($target, $allowRollback)) {
            WP_CLI::error(sprintf('Migration failed for "%s".%s', $pluginSlug, $allowRollback ? ''
                : ' CLI backward targets are disabled outside local or development WordPress environments.'));
        }

        if ($target === null) {
            WP_CLI::success(sprintf('All migrations completed for "%s".', $pluginSlug));
            return;
        }

        WP_CLI::success(sprintf('Migration target "%s" reached for "%s".', $target, $pluginSlug));
    }

    private function migrateAllPlugins(): void
    {
        $managers = $this->context->registry()->all();

        if ($managers === []) {
            WP_CLI::warning('No plugins with migrations registered.');
            return;
        }

        $processed = 0;
        $failed = 0;

        foreach ($managers as $slug => $manager) {
            try {
                if (!$manager->hasPendingMigrations()) {
                    continue;
                }
                $processed++;
                WP_CLI::log(sprintf('Running pending migrations for "%s"...', $slug));
                if ($manager->runMigrations()) {
                    WP_CLI::success(sprintf('Completed migrations for "%s".', $slug));
                    continue;
                }
            } catch (MigrationOperationException) {
                // Keep SQL, credentials and exception messages out of the CLI diagnostic.
            }
            $failed++;
            WP_CLI::warning(sprintf('Migration failed for "%s"; reconcile its state before retrying.', $slug));
        }

        if ($failed > 0) {
            WP_CLI::error(sprintf(
                'Migrations failed for %d plugin(s); other registered plugins were processed.',
                $failed,
            ));
        }

        if ($processed === 0) {
            WP_CLI::success('No pending migrations found.');
            return;
        }

        WP_CLI::success(sprintf('All migrations completed for %d plugin(s).', $processed));
    }

    private function rollbackSinglePlugin(string $pluginSlug, ?string $migrationClass): void
    {
        $manager = $this->context->managerOrFail($pluginSlug);

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
        $managers = $this->context->registry()->all();

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
}
