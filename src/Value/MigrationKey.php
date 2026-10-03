<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Value;

use SymPress\WordPress\Migration\Contract\Migration;

final class MigrationKey
{
    public static function forMigration(Migration $migration): string
    {
        if (method_exists($migration, 'getMigrationKey')) {
            $key = $migration->getMigrationKey();

            if (!is_string($key) || $key === '' || strlen($key) > 191 || str_contains($key, "\0")) {
                throw new \InvalidArgumentException('Migration keys must be non-empty strings of at most 191 bytes.');
            }

            return $key;
        }

        return self::normalize($migration::class);
    }

    public static function normalize(string $class): string
    {
        if (str_contains($class, "@anonymous\0")) {
            throw new \InvalidArgumentException(
                'Anonymous migrations require an explicit deployment-independent getMigrationKey().',
            );
        }

        self::assertStorageIdentity($class);
        return $class;
    }

    public static function assertStorageIdentity(string $key): void
    {
        if ($key === '' || strlen($key) > 191) {
            throw new \InvalidArgumentException('Migration identities must be non-empty strings of at most 191 bytes.');
        }
    }

    /** @return non-empty-list<string> */
    public static function identities(Migration $migration): array
    {
        $key = self::forMigration($migration);
        $identities = [$key];

        if (!str_contains($migration::class, "@anonymous\0")) {
            $identities[] = $migration::class;
        }

        if (method_exists($migration, 'getLegacyMigrationKeys')) {
            $aliases = $migration->getLegacyMigrationKeys();

            if (!is_array($aliases) || !array_is_list($aliases)) {
                throw new \InvalidArgumentException(
                    'Legacy migration keys must be a list of exact recorded identities.',
                );
            }

            foreach ($aliases as $alias) {
                if (!is_string($alias) || $alias === '' || strlen($alias) > 191) {
                    throw new \InvalidArgumentException(
                        'Legacy migration keys must be non-empty strings of at most 191 bytes.',
                    );
                }

                $identities[] = $alias;
            }
        }

        foreach ($identities as $identity) {
            self::assertStorageIdentity($identity);
        }
        return array_values(array_unique($identities));
    }
}
