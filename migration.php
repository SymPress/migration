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

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists(MigrationBundle::class)) {
    require_once __DIR__ . '/vendor/autoload.php';
}
