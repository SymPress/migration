<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Contract;

interface MigrationOperationExecutor
{
    /**
     * @param string|list<string> $statements
     * @param callable(): bool $operation
     */
    public function runOperation(string $scope, string|array $statements, callable $operation): bool;
}
