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

            if (!is_string($key) || $key === '' || strlen($key) > 255 || str_contains($key, "\0")) {
                throw new \InvalidArgumentException('Migration keys must be non-empty strings of at most 255 bytes.');
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

        return $class;
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
                if (!is_string($alias) || $alias === '' || strlen($alias) > 255) {
                    throw new \InvalidArgumentException(
                        'Legacy migration keys must be non-empty strings of at most 255 bytes.',
                    );
                }

                $identities[] = $alias;
            }
        }

        return array_values(array_unique($identities));
    }
}
