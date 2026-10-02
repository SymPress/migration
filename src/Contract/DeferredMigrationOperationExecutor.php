<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Contract;

/**
 * Resolves the live SQL plan after acquiring the operation lock and before
 * choosing the transaction boundary.
 */
interface DeferredMigrationOperationExecutor extends MigrationOperationExecutor
{
    /**
     * @param callable(): (string|list<string>) $statements
     * @param callable(string|list<string>): bool $operation
     */
    public function runDeferredOperation(string $scope, callable $statements, callable $operation): bool;
}
