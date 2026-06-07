<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Contract;

interface MigrationSqlExecutor
{
    /**
     * @param string|list<string> $statements
     */
    public function execute(string|array $statements): bool;
}
