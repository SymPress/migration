<?php
/**
 * Example: Integrating migrations in your regular plugin
 * File: wp-content/plugins/your-plugin/your-plugin.php
 *
 * Plugin Name: Your Plugin
 * Description: Example plugin using the migration system
 * Version: 1.0.0
 * Requires PHP: 8.5
 */

declare(strict_types=1);

namespace YourPlugin;

use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Domain\AbstractMigration;

if (!defined('ABSPATH')) {
    return;
}

final class CreateUsersTableMigration extends AbstractMigration
{
    protected const VERSION = '1.0.0';

    public function up(): string|array
    {
        $tableName = $this->prefix . 'your_plugin_users';

        return "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            custom_field varchar(255) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) {$this->charsetCollate};";
    }

    public function down(): string|array
    {
        $tableName = $this->prefix . 'your_plugin_users';

        return "DROP TABLE IF EXISTS {$tableName};";
    }
}

final class AddIndexToUsersTableMigration extends AbstractMigration
{
    protected const VERSION = '1.0.1';

    public function up(): string|array
    {
        $tableName = $this->prefix . 'your_plugin_users';

        return "ALTER TABLE {$tableName}
                ADD INDEX idx_custom_field (custom_field);";
    }

    public function down(): string|array
    {
        $tableName = $this->prefix . 'your_plugin_users';

        return "ALTER TABLE {$tableName}
                DROP INDEX idx_custom_field;";
    }
}

add_action('db_migration_register', static function (MigrationSystem $system): void {
    global $wpdb;

    $system->registerMigrations('your-plugin-slug', [
        new CreateUsersTableMigration($wpdb),
        new AddIndexToUsersTableMigration($wpdb),
    ]);
});

register_activation_hook(__FILE__, static function (): void {
    $manager = MigrationSystem::getInstance()->createMigrationManager('your-plugin-slug');

    if ($manager === null) {
        return;
    }

    $manager->runMigrations();
});
