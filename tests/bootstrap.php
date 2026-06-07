<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/TestEnvironment.php';
require_once __DIR__ . '/Support/CreatesMigrationManagers.php';
require_once __DIR__ . '/Support/TestMigrations.php';

\SymPress\WordPress\Migration\Tests\Support\WordPressState::reset();
