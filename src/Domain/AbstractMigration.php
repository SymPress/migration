<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Domain;

use SymPress\WordPress\Migration\Contract\Migration;

abstract class AbstractMigration implements Migration
{
    protected const string VERSION = '1.0.0';

    protected \wpdb $database;
    protected string $prefix;
    protected string $charsetCollate;

    public function __construct(?\wpdb $database = null)
    {
        $this->database = $this->resolveDatabase($database);
        $this->prefix = $this->database->prefix;
        $this->charsetCollate = $this->database->get_charset_collate();
    }

    #[\Override]
    public function getVersion(): string
    {
        return static::VERSION;
    }

    private function resolveDatabase(?\wpdb $database): \wpdb
    {
        if ($database instanceof \wpdb) {
            return $database;
        }

        $globalDatabase = $GLOBALS['wpdb'] ?? null;

        if ($globalDatabase instanceof \wpdb) {
            return $globalDatabase;
        }

        throw new \RuntimeException('Global $wpdb is not available.');
    }
}
