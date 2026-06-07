<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Infrastructure;

use SymPress\WordPress\Migration\Contract\MigrationSqlExecutor;

final readonly class WordPressSqlExecutor implements MigrationSqlExecutor
{
    public function __construct(private \wpdb $database)
    {
    }

    /**
     * @param string|list<string> $statements
     */
    #[\Override]
    public function execute(string|array $statements): bool
    {
        foreach ((array) $statements as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            if (!$this->executeStatement($statement)) {
                return false;
            }
        }

        return true;
    }

    private function executeStatement(string $statement): bool
    {
        if ($this->shouldUseDbDelta($statement)) {
            $this->loadWordPressUpgradeLibrary();
            $this->resetLastError();

            dbDelta($statement);

            return $this->lastErrorIsEmpty();
        }

        return $this->database->query($statement) !== false;
    }

    private function shouldUseDbDelta(string $statement): bool
    {
        return str_starts_with(strtoupper($statement), 'CREATE TABLE');
    }

    private function loadWordPressUpgradeLibrary(): void
    {
        if (function_exists('dbDelta')) {
            return;
        }

        if (!defined('ABSPATH')) {
            throw new \RuntimeException('ABSPATH is not defined.');
        }

        $absolutePath = constant('ABSPATH');

        if (!is_string($absolutePath) || $absolutePath === '') {
            throw new \RuntimeException('ABSPATH must be a non-empty string.');
        }

        require_once $absolutePath . 'wp-admin/includes/upgrade.php';
    }

    private function resetLastError(): void
    {
        $this->database->last_error = '';
    }

    private function lastErrorIsEmpty(): bool
    {
        return $this->database->last_error === '';
    }
}
