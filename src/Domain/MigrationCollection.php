<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Domain;

use SymPress\WordPress\Migration\Contract\Migration as MigrationContract;

/** @implements \IteratorAggregate<int, MigrationContract> */
final class MigrationCollection implements \Countable, \IteratorAggregate
{
    /** @param array<class-string<MigrationContract>, MigrationContract> $migrations */
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
        $migrations = $this->migrations;
        $migrations[$migration::class] = $migration;

        return new self($migrations);
    }

    public function get(string $migrationClass): ?MigrationContract
    {
        return $this->migrations[$migrationClass] ?? null;
    }

    /** @return array<class-string<MigrationContract>, MigrationContract> */
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
