<?php

declare(strict_types=1);

/**
 * File: wp-content/mu-plugins/database-migration-system.php
 *
 * WordPress only autoloads PHP files in the root of the mu-plugins directory.
 * This loader forwards execution into the package directory.
 */

require WPMU_PLUGIN_DIR . '/database-migration-system/migration.php';
