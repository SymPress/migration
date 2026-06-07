<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Application;

use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;

final readonly class MigrationStatusReporter
{
    public function __construct(
        private MigrationRegistry $registry,
    ) {
    }

    /**
     * @return list<array{
     *     plugin: string,
     *     current: string,
     *     latest: string,
     *     migrated: int,
     *     pending: int,
     *     executions: int,
     *     status: string
     * }>
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->registry->all() as $slug => $manager) {
            $rows[] = $this->overview($slug, $manager);
        }

        return $rows;
    }

    /**
     * @return array{
     *     overview: array{
     *         plugin: string,
     *         current: string,
     *         latest: string,
     *         migrated: int,
     *         pending: int,
     *         executions: int,
     *         status: string
     *     },
     *     pending_migrations: list<array{class: string, name: string, version: string}>,
     *     recent_history: list<array{
     *         plugin: string,
     *         migration: string,
     *         name: string,
     *         version: string,
     *         direction: string,
     *         executed_at: string
     *     }>
     * }|null
     */
    public function plugin(string $pluginSlug): ?array
    {
        $manager = $this->registry->get($pluginSlug);

        if (!$manager instanceof MigrationManager) {
            return null;
        }

        return [
            'overview' => $this->overview($pluginSlug, $manager),
            'pending_migrations' => $manager->getPendingMigrations(),
            'recent_history' => array_slice($this->historyRows($manager), 0, 10),
        ];
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
    private function overview(string $pluginSlug, MigrationManager $manager): array
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
     * @return list<array{
     *     plugin: string,
     *     migration: string,
     *     name: string,
     *     version: string,
     *     direction: string,
     *     executed_at: string
     * }>
     */
    private function historyRows(MigrationManager $manager): array
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
