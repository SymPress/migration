<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Infrastructure;

use SymPress\WordPress\Migration\Contract\MigrationOperationExecutor;
use SymPress\WordPress\Migration\Contract\MigrationSqlExecutor;

final class WordPressSqlExecutor implements MigrationSqlExecutor, MigrationOperationExecutor
{
    private int $transactionDepth = 0;

    public function __construct(private readonly \wpdb $database)
    {
    }

    /** @param string|list<string> $statements */
    #[\Override]
    public function execute(string|array $statements): bool
    {
        foreach ((array) $statements as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            if (!$this->executeStatement($statement)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failure signal without SQL or credentials.
                error_log('SymPress migration SQL failed; earlier DDL may already be applied. '
                    . 'Reconcile database state before retrying.');
                return false;
            }
        }

        return true;
    }

    /**
     * @param string|list<string> $statements
     * @param callable(): bool $operation
     */
    public function runOperation(string $scope, string|array $statements, callable $operation): bool
    {
        $databaseName = $this->database->get_var('SELECT DATABASE()');
        $lock = 'sympress-migration:' . substr(
            hash('sha256', $databaseName . ':' . $this->database->prefix . ':' . $scope),
            0,
            40,
        );
        $acquired = $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s, 10)', $lock));

        if ((string) $acquired !== '1') {
            return false;
        }

        $transactional = $this->supportsTransaction($statements);

        $started = false;
        $counted = false;

        try {
            if ($transactional && $this->transactionDepth === 0) {
                $started = $this->database->query('START TRANSACTION') !== false;

                if (!$started) {
                    return false;
                }
            }

            if ($transactional) {
                $this->transactionDepth++;
                $counted = true;
            }

            $success = $operation();

            if ($started) {
                if ($this->database->query($success ? 'COMMIT' : 'ROLLBACK') === false) {
                    $this->database->query('ROLLBACK');
                    return false;
                }
            }

            if (!$success && !$transactional) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- DDL can commit independently of metadata writes.
                error_log('SymPress migration failed after non-transactional work; '
                    . 'reconcile SQL and metadata before retrying.');
            }

            return $success;
        } catch (\Throwable $exception) {
            if ($started) {
                $this->database->query('ROLLBACK');
            }

            throw $exception;
        } finally {
            if ($counted) {
                $this->transactionDepth--;
            }

            $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @param string|list<string> $statements */
    private function supportsTransaction(string|array $statements): bool
    {
        foreach ((array) $statements as $statement) {
            if (trim($statement) !== '' && preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement) !== 1) {
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

        // @phpstan-ignore sympress.preparedSql (Reviewed Migration::up/down raw SQL contract, including DDL; metadata uses prepared SQL.)
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

        if ($absolutePath === '') {
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
