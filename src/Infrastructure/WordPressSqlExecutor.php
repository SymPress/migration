<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Infrastructure;

use SymPress\WordPress\Migration\Contract\DeferredMigrationOperationExecutor;
use SymPress\WordPress\Migration\Contract\MigrationSqlExecutor;
use SymPress\WordPress\Migration\Exception\MigrationOperationException;

final class WordPressSqlExecutor implements MigrationSqlExecutor, DeferredMigrationOperationExecutor
{
    public function __construct(private readonly \wpdb $database, private readonly int $lockTimeout = 10)
    {
        if ($lockTimeout < 0 || $lockTimeout > 3600) {
            throw new \InvalidArgumentException('Migration lock timeout must be between 0 and 3600 seconds.');
        }
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
        return $this->runDeferredOperation($scope, static fn (): string|array => $statements, static fn (): bool => $operation());
    }

    /**
     * @param callable(): (string|list<string>) $statements
     * @param callable(string|list<string>): bool $operation
     */
    public function runDeferredOperation(string $scope, callable $statements, callable $operation): bool
    {
        if ($scope === '') {
            throw new \InvalidArgumentException('Migration operation scope must not be empty.');
        }
        $guard = MigrationDatabaseGuard::forDatabase($this->database);
        if ($guard !== null) {
            return $this->runLockedOperation($guard, $statements, $operation);
        }
        $databaseName = $this->database->get_var('SELECT DATABASE()');
        if (!is_string($databaseName) || $databaseName === '') {
            throw new MigrationOperationException('Migration requires an active named database.');
        }
        // Plugins, custom metadata tables and shared ORM tables use one database-wide domain.
        $lock = 'sympress-migration:' . substr(
            hash('sha256', $databaseName),
            0,
            40,
        );
        $acquired = $this->database->get_var($this->database->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lock,
            $this->lockTimeout,
        ));

        if ((string) $acquired !== '1') {
            return false;
        }

        try {
            $guard = new MigrationDatabaseGuard(
                $this->database,
                $lock,
                (string) $this->database->get_var('SELECT CONNECTION_ID()'),
            );
            return $this->runLockedOperation($guard, $statements, $operation);
        } finally {
            ($guard ?? MigrationDatabaseGuard::forDatabase($this->database))?->close();
        }
    }

    /**
     * @param callable(): (string|list<string>) $statements
     * @param callable(string|list<string>): bool $operation
     */
    private function runLockedOperation(MigrationDatabaseGuard $guard, callable $statements, callable $operation): bool
    {
        $started = false;
        $counted = false;

        try {
            $guard->assertOwned();
            $resolved = $statements();
            $guard->assertOwned();
            $transactional = $this->supportsTransaction($resolved);
            if ($transactional && $guard->transactionDepth() === 0) {
                $started = $this->database->query('START TRANSACTION') !== false;

                if (!$started) {
                    return false;
                }
            }

            if ($transactional) {
                $guard->enterTransaction();
                $counted = true;
            }

            $guard->assertOwned();
            $success = $operation($resolved);
            $guard->assertOwned();

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
            if ($started && $guard->isOwned()) {
                $this->database->query('ROLLBACK');
            }

            throw $exception;
        } finally {
            if ($counted) {
                $guard->leaveTransaction();
            }
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
        MigrationDatabaseGuard::assertDatabaseOwnership($this->database);
        if ($this->shouldUseDbDelta($statement)) {
            $this->loadWordPressUpgradeLibrary();
            $this->resetLastError();

            dbDelta($statement);
            MigrationDatabaseGuard::assertDatabaseOwnership($this->database);

            return $this->lastErrorIsEmpty();
        }

        // @phpstan-ignore sympress.preparedSql (Reviewed Migration::up/down raw SQL contract, including DDL; metadata uses prepared SQL.)
        $result = $this->database->query($statement);
        MigrationDatabaseGuard::assertDatabaseOwnership($this->database);
        return $result !== false;
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
