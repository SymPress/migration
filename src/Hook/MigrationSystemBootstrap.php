<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Hook;

use SymPress\WordPress\Migration\Application\MigrationSystem;

final class MigrationSystemBootstrap
{
    public function __construct(
        private readonly MigrationSystem $system,
    ) {
    }

    public function initialize(): void
    {
        $this->system->init();
        do_action('db_migration_system_ready', $this->system);
    }
}
