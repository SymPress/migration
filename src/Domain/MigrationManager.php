<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Domain;

use SymPress\WordPress\Migration\Application\MigrationLifecycle;
use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Exception\MigrationOperationException;
use SymPress\WordPress\Migration\Value\MigrationExecution;
use SymPress\WordPress\Migration\Value\MigrationKey;
use SymPress\WordPress\Migration\Value\MigrationRecord;
use SymPress\WordPress\Migration\Value\PluginSlug;

class MigrationManager
{
    private MigrationCollection $migrations;

    public function __construct(
        private readonly PluginSlug $pluginSlug,
        private readonly MigrationLifecycle $lifecycle,
        ?MigrationCollection $migrations = null,
    ) {

        $this->migrations = $migrations ?? MigrationCollection::empty();
    }

    public function getPluginSlug(): string
    {
        return $this->pluginSlug->value;
    }

    public function registerMigration(MigrationContract $migration): self
    {
        $this->migrations = $this->migrations->with($migration);

        return $this;
    }

    public function replaceMigration(MigrationContract $migration): self
    {
        $this->migrations = $this->migrations->replace($migration);

        return $this;
    }

    /** @param iterable<MigrationContract> $migrations */
    public function registerMigrations(iterable $migrations): self
    {
        foreach ($migrations as $migration) {
            $this->registerMigration($migration);
        }

        return $this;
    }

    /** @return array<string, MigrationContract> */
    public function all(): array
    {
        return $this->migrations->all();
    }

    public function runMigrations(): bool
    {
        return $this->migrateTo();
    }

    public function runMigration(string $migrationClass): bool
    {
        $this->assertLegacyIdentitiesAreMapped();
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }

        if (!$this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
            return true;
        }

        return $this->lifecycle->migrate($this->pluginSlug, $migration);
    }

    public function rollbackMigrations(): bool
    {
        $this->assertLegacyIdentitiesAreMapped();
        foreach ($this->migrations->inRollbackOrder() as $migration) {
            if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
                continue;
            }

            if (!$this->lifecycle->rollback($this->pluginSlug, $migration)) {
                return false;
            }
        }

        return true;
    }

    public function rollbackMigration(string $migrationClass): bool
    {
        $this->assertLegacyIdentitiesAreMapped();
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
            return true;
        }

        return $this->lifecycle->rollback($this->pluginSlug, $migration);
    }

    public function migrateTo(?string $targetVersion = null, bool $allowRollback = true): bool
    {
        $this->assertLegacyIdentitiesAreMapped();
        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }

        $targetIndex = $this->resolveTargetIndex($targetVersion);

        if ($targetVersion !== null && $targetIndex === null) {
            return false;
        }

        if ($targetIndex === null) {
            $targetIndex = max(count($this->migrations->inRegistrationOrder()) - 1, -1);
        }

        $currentIndex = $this->currentMigrationIndex();

        if ($currentIndex <= $targetIndex) {
            return $this->migrateForward(0, $targetIndex);
        }

        // Enforce at the branch that can invoke down(), not a CLI status preflight.
        return $allowRollback && $this->rollbackBackward($currentIndex, $targetIndex + 1);
    }

    public function executeMigration(string $migrationClass, string $direction): bool
    {
        if ($direction === 'up') {
            return $this->runMigration($migrationClass);
        }

        if ($direction === 'down') {
            return $this->rollbackMigration($migrationClass);
        }

        return false;
    }

    public function markMigration(string $migrationClass, string $direction): bool
    {
        $this->assertLegacyIdentitiesAreMapped();
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }

        if ($direction === 'up') {
            return $this->lifecycle->markMigrated($this->pluginSlug, $migration);
        }

        if ($direction === 'down') {
            return $this->lifecycle->markRolledBack($this->pluginSlug, $migration);
        }

        return false;
    }

    public function adoptLegacyMigration(string $migrationKey, string $legacyKey, string $expectedVersion, bool $retireSuperseded = false): bool
    {
        $migration = $this->getMigration($migrationKey);
        if ($migration === null || MigrationKey::forMigration($migration) !== $migrationKey) {
            throw new \InvalidArgumentException('Adoption requires the exact registered stable migration key.');
        }
        foreach ($this->migrations->all() as $other) {
            if ($other !== $migration && in_array($legacyKey, MigrationKey::identities($other), true)) {
                throw new \InvalidArgumentException('Legacy identity already belongs to another registered migration.');
            }
        }
        if (!$this->lifecycle->ensureStorageIsReady()) {
            return false;
        }
        return $this->lifecycle->adoptLegacyMigration($this->pluginSlug, $migration, $legacyKey, $expectedVersion, $retireSuperseded);
    }

    public function needsUpdate(string $migrationClass): bool
    {
        $migration = $this->getMigration($migrationClass);

        if ($migration === null) {
            return false;
        }

        return $this->lifecycle->needsUpdate($this->pluginSlug, $migration);
    }

    public function hasPendingMigrations(): bool
    {
        if ($this->getLegacyStateIssues() !== []) {
            return true;
        }
        foreach ($this->migrations->inRegistrationOrder() as $migration) {
            if ($this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
                return true;
            }
        }

        return false;
    }

    public function isUpToDate(): bool
    {
        return !$this->hasPendingMigrations();
    }

    /** @return list<array{plugin: string, migration: string, version: string, migrated_at: string}> */
    public function getMigratedVersions(): array
    {
        return array_map(
            static fn (MigrationRecord $record): array => $record->toArray(),
            $this->lifecycle->recordsForPlugin($this->pluginSlug),
        );
    }

    /**
     * @return list<array{
     *     plugin: string,
     *     migration: string,
     *     version: string,
     *     direction: string,
     *     executed_at: string
     * }>
     */
    public function getMigrationHistory(): array
    {
        return array_map(
            static fn (MigrationExecution $execution): array => $execution->toArray(),
            $this->lifecycle->historyForPlugin($this->pluginSlug),
        );
    }

    /**
     * @return array{
     *     class: class-string<MigrationContract>,
     *     name: string,
     *     version: string,
     *     migrated_at: string
     * }|null
     */
    public function getCurrentMigration(): ?array
    {
        $current = null;

        foreach ($this->migrations->inRegistrationOrder() as $migration) {
            $record = $this->lifecycle->recordForMigration($this->pluginSlug, $migration);

            if ($record === null) {
                continue;
            }

            $current = [
                'class'       => $migration::class,
                'name'        => $this->extractClassName($migration::class),
                'version'     => $record->version,
                'migrated_at' => $record->migratedAt,
            ];
        }

        return $current;
    }

    /** @return array{class: class-string<MigrationContract>, name: string, version: string}|null */
    public function getLatestMigration(): ?array
    {
        $migrations = $this->migrations->inRegistrationOrder();
        $latest = array_pop($migrations);

        if (!$latest instanceof MigrationContract) {
            return null;
        }

        return [
            'class'   => $latest::class,
            'name'    => $this->extractClassName($latest::class),
            'version' => $latest->getVersion(),
        ];
    }

    public function syncMetadataStorage(): bool
    {
        return $this->lifecycle->ensureStorageIsReady();
    }

    /** @return list<array{class: class-string<MigrationContract>, name: string, version: string}> */
    public function getPendingMigrations(): array
    {
        $this->assertLegacyIdentitiesAreMapped();
        $pending = [];

        foreach ($this->migrations->inRegistrationOrder() as $migration) {
            if (!$this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
                continue;
            }

            $pending[] = [
                'class'   => $migration::class,
                'name'    => $this->extractClassName($migration::class),
                'version' => $migration->getVersion(),
            ];
        }

        return $pending;
    }

    private function getMigration(string $migrationClass): ?MigrationContract
    {
        $migration = $this->migrations->get($migrationClass);

        if ($migration instanceof MigrationContract) {
            return $migration;
        }

        $matches = array_filter(
            $this->migrations->inRegistrationOrder(),
            fn (MigrationContract $registered): bool => $this->extractClassName($registered::class) === $migrationClass,
        );

        if (count($matches) > 1) {
            throw new \InvalidArgumentException('Ambiguous migration name; use its explicit stable key.');
        }

        return array_first($matches);
    }

    private function currentMigrationIndex(): int
    {
        $currentIndex = -1;

        foreach ($this->migrations->inRegistrationOrder() as $index => $migration) {
            if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
                continue;
            }

            $currentIndex = $index;
        }

        return $currentIndex;
    }

    private function resolveTargetIndex(?string $targetVersion): ?int
    {
        if ($targetVersion === null) {
            return null;
        }

        foreach ($this->migrations->inRegistrationOrder() as $index => $migration) {
            if (MigrationKey::forMigration($migration) === $targetVersion) {
                return $index;
            }
        }

        $matches = [];

        foreach ($this->migrations->inRegistrationOrder() as $index => $migration) {
            if (
                $migration->getVersion() !== $targetVersion && $migration::class !== $targetVersion
                && $this->extractClassName($migration::class) !== $targetVersion
            ) {
                continue;
            }

            $matches[] = $index;
        }

        if (count($matches) > 1) {
            throw new \InvalidArgumentException('Ambiguous migration target; use its explicit stable key.');
        }

        return array_first($matches);
    }

    /**
     * @return list<array{migration: string, legacy_base64: string, version: string, reason: string, command: string}>
     */
    public function getLegacyStateIssues(): array
    {
        $known = [];
        $records = [];
        foreach ($this->lifecycle->recordsForPlugin($this->pluginSlug) as $record) {
            $records[$record->migration] = $record;
        }
        $conflicts = [];

        foreach ($this->migrations as $migration) {
            $identities = MigrationKey::identities($migration);
            $versions = [];
            foreach ($identities as $identity) {
                $known[$identity] = MigrationKey::forMigration($migration);
                if (!isset($records[$identity])) {
                    continue;
                }
                $versions[] = $records[$identity]->version;
            }
            if (count(array_unique($versions)) <= 1) {
                continue;
            }
            foreach ($identities as $identity) {
                $conflicts[$identity] = true;
            }
        }
        $issues = [];
        foreach ($records as $record) {
            if (
                !str_contains($record->migration, '@anonymous')
                || (isset($known[$record->migration]) && !isset($conflicts[$record->migration]))
            ) {
                continue;
            }
            $stable = $known[$record->migration] ?? '<stable-key>';
            $issues[] = $this->legacyStateIssue(
                $record,
                $stable,
                isset($records[$stable]),
                isset($conflicts[$record->migration]),
            );
        }
        return $issues;
    }

    /** @return array{migration: string, legacy_base64: string, version: string, reason: string, command: string} */
    private function legacyStateIssue(MigrationRecord $record, string $stable, bool $retire, bool $conflict): array
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Exact binary identity transport.
        $encoded = base64_encode($record->migration);
        return [
            'migration' => $record->migration,
            'legacy_base64' => $encoded,
            'version' => $record->version,
            'reason' => $conflict ? 'Conflicting legacy versions' : 'Unmapped legacy identity',
            'command' => sprintf(
                'wp migration adopt %s %s --legacy-base64=%s --expected-version=%s%s --yes --user=<administrator>',
                escapeshellarg($this->pluginSlug->value),
                escapeshellarg($stable),
                escapeshellarg($encoded),
                escapeshellarg($record->version),
                $retire ? ' --retire-superseded' : '',
            ),
        ];
    }

    private function assertLegacyIdentitiesAreMapped(): void
    {
        if ($this->getLegacyStateIssues() !== []) {
            throw new MigrationOperationException('Unmapped legacy or conflicting migration state. '
                . 'Inspect wp migration status and adopt the exact reviewed identities before executing migrations.');
        }
    }

    private function migrateForward(int $startIndex, int $targetIndex): bool
    {
        $migrations = $this->migrations->inRegistrationOrder();

        for ($index = $startIndex; $index <= $targetIndex; $index++) {
            $migration = $migrations[$index] ?? null;

            if (!$migration instanceof MigrationContract) {
                continue;
            }

            if (!$this->lifecycle->needsUpdate($this->pluginSlug, $migration)) {
                continue;
            }

            if (!$this->lifecycle->migrate($this->pluginSlug, $migration)) {
                return false;
            }
        }

        return true;
    }

    private function rollbackBackward(int $startIndex, int $stopIndexExclusive): bool
    {
        $migrations = $this->migrations->inRegistrationOrder();

        for ($index = $startIndex; $index >= $stopIndexExclusive; $index--) {
            $migration = $migrations[$index] ?? null;

            if (!$migration instanceof MigrationContract) {
                continue;
            }

            if (!$this->lifecycle->hasBeenMigrated($this->pluginSlug, $migration)) {
                continue;
            }

            if (!$this->lifecycle->rollback($this->pluginSlug, $migration)) {
                return false;
            }
        }

        return true;
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
