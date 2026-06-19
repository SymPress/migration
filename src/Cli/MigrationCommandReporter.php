<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use SymPress\WordPress\Migration\Application\MigrationStatusReporter;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use WP_CLI;

final readonly class MigrationCommandReporter
{
    public function __construct(
        private MigrationCommandContext $context,
    ) {
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function status(array $args, array $assocArgs): void
    {
        if ($this->context->runConsoleCommand('migration:status', $args, $assocArgs)) {
            return;
        }

        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);
        $verbose = isset($assocArgs['verbose']);
        $format = $this->context->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $this->statusSinglePlugin($pluginSlug, $verbose, $format);
            return;
        }

        $this->statusAllPlugins($format);
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function upToDate(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);

        if ($pluginSlug !== null) {
            $manager = $this->context->managerOrFail($pluginSlug);

            if ($manager->isUpToDate()) {
                WP_CLI::success(sprintf('"%s" is up to date.', $pluginSlug));
                return;
            }

            WP_CLI::warning(sprintf('"%s" has pending migrations.', $pluginSlug));
            return;
        }

        $format = $this->context->outputFormat($assocArgs);
        $rows = [];

        foreach ($this->context->registry()->all() as $slug => $manager) {
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
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function current(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);
        $format = $this->context->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $manager = $this->context->managerOrFail($pluginSlug);
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

        foreach ($this->context->registry()->all() as $slug => $manager) {
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
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function latest(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);
        $format = $this->context->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $manager = $this->context->managerOrFail($pluginSlug);
            $row = $this->normalizeLatestRow($pluginSlug, $manager->getLatestMigration());

            if ($row === null) {
                WP_CLI::warning(sprintf('No registered migrations found for "%s".', $pluginSlug));
                return;
            }

            \WP_CLI\Utils\format_items($format, [$row], ['plugin', 'class', 'name', 'version']);
            return;
        }

        $rows = [];

        foreach ($this->context->registry()->all() as $slug => $manager) {
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
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function history(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);
        $format = $this->context->outputFormat($assocArgs);
        $tracker = $this->context->tracker();
        $records = $pluginSlug === null
            ? $tracker->findAllHistory()
            : $tracker->findHistoryForPlugin($pluginSlug);

        if ($records === []) {
            WP_CLI::warning('No migration history found.');
            return;
        }

        \WP_CLI\Utils\format_items(
            $format,
            $this->executionRows($records),
            ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function list(array $args, array $assocArgs): void
    {
        if ($this->context->runConsoleCommand('migration:list', $args, $assocArgs)) {
            return;
        }

        $pluginSlug = $this->context->normalizeOptionalString($args[0] ?? null);
        $format = $this->context->outputFormat($assocArgs);

        if ($pluginSlug !== null) {
            $this->listPluginMigrations($pluginSlug, $format);
            return;
        }

        $this->listRegisteredPlugins($format);
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|null> $assocArgs
     */
    public function info(array $args, array $assocArgs): void
    {
        $pluginSlug = $this->context->requirePluginSlug($args, 'info');
        $manager = $this->context->managerOrFail($pluginSlug);
        $format = $this->context->outputFormat($assocArgs);

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

        $history = $this->context->tracker()->findHistoryForPlugin($pluginSlug);

        if ($history === []) {
            return;
        }

        WP_CLI::log("\nExecution history:");
        \WP_CLI\Utils\format_items(
            $format,
            $this->executionRows($history),
            ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
        );
    }

    private function statusSinglePlugin(string $pluginSlug, bool $verbose, string $format): void
    {
        $report = $this->statusReporter()->plugin($pluginSlug);

        if ($report === null) {
            $this->context->managerOrFail($pluginSlug);
            return;
        }

        $overview = $report['overview'];
        WP_CLI::log(sprintf("\nPlugin: %s", $pluginSlug));
        WP_CLI::log(sprintf('Status: %s', $overview['status']));
        WP_CLI::log(sprintf('Current: %s', $overview['current']));
        WP_CLI::log(sprintf('Latest: %s', $overview['latest']));
        WP_CLI::log(sprintf('Migrated: %d', $overview['migrated']));
        WP_CLI::log(sprintf('Pending: %d', $overview['pending']));
        WP_CLI::log(sprintf('Executions: %d', $overview['executions']));

        if ($verbose && $report['pending_migrations'] !== []) {
            WP_CLI::log("\nPending migrations:");
            \WP_CLI\Utils\format_items($format, $report['pending_migrations'], ['class', 'name', 'version']);
        }

        if ($verbose && $report['recent_history'] !== []) {
            WP_CLI::log("\nRecent execution history:");
            \WP_CLI\Utils\format_items(
                $format,
                $report['recent_history'],
                ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
            );
        }

        if ($overview['status'] === 'Up to Date') {
            WP_CLI::success(sprintf("\nPlugin \"%s\" is up to date.", $pluginSlug));
            return;
        }

        WP_CLI::warning(sprintf("\nPlugin \"%s\" has pending migrations.", $pluginSlug));
    }

    private function statusAllPlugins(string $format): void
    {
        $rows = $this->statusReporter()->all();

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
        $this->statusAllPlugins($format);
    }

    private function listPluginMigrations(string $pluginSlug, string $format): void
    {
        $manager = $this->context->managerOrFail($pluginSlug);
        $migratedByClass = [];

        foreach ($manager->getMigratedVersions() as $record) {
            $migratedByClass[$record['migration']] = $record;
        }

        $rows = [];

        foreach ($manager->all() as $class => $migration) {
            $record = $migratedByClass[$class] ?? null;
            $rows[] = [
                'class' => $class,
                'name' => $this->context->extractClassName($class),
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

    private function statusReporter(): MigrationStatusReporter
    {
        return new MigrationStatusReporter($this->context->registry());
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
     * @param list<MigrationExecution> $records
     * @return list<array{
     *     plugin: string,
     *     migration: string,
     *     name: string,
     *     version: string,
     *     direction: string,
     *     executed_at: string
     * }>
     */
    private function executionRows(array $records): array
    {
        return array_map(
            fn (MigrationExecution $record): array => [
                'plugin' => $record->plugin,
                'migration' => $record->migration,
                'name' => $this->context->extractClassName($record->migration),
                'version' => $record->version,
                'direction' => $record->direction,
                'executed_at' => $record->executedAt,
            ],
            $records,
        );
    }
}
