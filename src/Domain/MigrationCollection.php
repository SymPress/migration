<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Domain;

use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;
use SymPress\WordPress\Migration\Value\MigrationKey;

/** @implements \IteratorAggregate<int, MigrationContract> */
final class MigrationCollection implements \Countable, \IteratorAggregate
{
    /** @param array<string, MigrationContract> $migrations */
    private function __construct(
        private array $migrations,
    ) {
    }

    #[\NoDiscard]
    public static function empty(): self
    {
        return new self([]);
    }

    /** @param iterable<MigrationContract> $migrations */
    #[\NoDiscard]
    public static function fromIterable(iterable $migrations): self
    {
        $collection = self::empty();

        foreach ($migrations as $migration) {
            $collection = $collection->with($migration);
        }

        return $collection;
    }

    #[\NoDiscard]
    public function with(MigrationContract $migration): self
    {
        $identities = MigrationKey::identities($migration);

        foreach ($this->migrations as $existing) {
            if ($existing === $migration) {
                return $this;
            }

            if (array_intersect($identities, MigrationKey::identities($existing)) !== []) {
                throw new \InvalidArgumentException(
                    'Duplicate migration key or legacy identity; no migration has been executed.',
                );
            }
        }

        $migrations = $this->migrations;
        $migrations[MigrationKey::forMigration($migration)] = $migration;

        return new self($migrations);
    }

    public function get(string $migrationClass): ?MigrationContract
    {
        if (isset($this->migrations[$migrationClass])) {
            return $this->migrations[$migrationClass];
        }

        $matches = array_filter($this->migrations, static fn (MigrationContract $migration): bool => $migration::class === $migrationClass);

        if (count($matches) > 1) {
            throw new \InvalidArgumentException('Ambiguous migration class; use its explicit stable key.');
        }

        return array_first($matches);
    }

    /** @return array<string, MigrationContract> */
    public function all(): array
    {
        return $this->migrations;
    }

    /** @return list<MigrationContract> */
    public function inRegistrationOrder(): array
    {
        return array_values($this->migrations);
    }

    /** @return list<MigrationContract> */
    public function inRollbackOrder(): array
    {
        return array_values(array_reverse($this->migrations, true));
    }

    #[\Override]
    public function count(): int
    {
        return count($this->migrations);
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->inRegistrationOrder());
    }
}
