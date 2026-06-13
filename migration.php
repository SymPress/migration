<?php

/**
 * Plugin Name: Database Migration System
 * Description: Standalone MU plugin for versioned WordPress database migrations.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.5
 * Author: Brian Schaeffner
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SymPress\WordPress\Migration;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Cli\MigrationBootstrap;
use SymPress\WordPress\Migration\Hook\MigrationCliBootstrap;
use SymPress\WordPress\Migration\Hook\MigrationSystemBootstrap;

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists(MigrationBundle::class)) {
    require_once __DIR__ . '/vendor/autoload.php';
}

add_action('kernel.add-providers', static function (): void {
});

add_action('muplugins_loaded', static function (): void {
    (new MigrationSystemBootstrap(MigrationSystem::getInstance()))->initialize();
    (new MigrationCliBootstrap(MigrationBootstrap::getInstance()))->initialize();
}, 5);
