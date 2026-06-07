<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Hook;

use SymPress\WordPress\Migration\Cli\MigrationBootstrap;

final class MigrationCliBootstrap
{
    public function __construct(
        private readonly MigrationBootstrap $bootstrap,
    ) {
    }

    public function initialize(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        $this->bootstrap->init();
    }
}
