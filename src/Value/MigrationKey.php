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

            if (!is_string($key) || $key === '' || strlen($key) > 255) {
                throw new \InvalidArgumentException('Migration keys must be non-empty strings of at most 255 bytes.');
            }

            return $key;
        }

        return self::normalize($migration::class);
    }

    public static function normalize(string $class): string
    {
        if (!str_contains($class, "@anonymous\0")) {
            return $class;
        }

        // PHP anonymous class names contain the absolute release directory.
        // Retain declaration identity while discarding its deployment location.
        $base = strstr($class, "@anonymous\0", true);
        preg_match('/:(\d+)(?:\$[a-z0-9]+)?$/i', $class, $matches);

        $location = substr($class, (int) strpos($class, "@anonymous\0") + strlen("@anonymous\0"));
        $file = basename(preg_replace('/:\d+(?:\$[a-z0-9]+)?$/i', '', $location) ?? $location);

        return $base . '@anonymous:' . $file . ':' . ($matches[1] ?? 'unknown');
    }
}
