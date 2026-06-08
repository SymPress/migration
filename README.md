# Database Migration System

Standalone WordPress MU-plugin for versioned database migrations with Doctrine-style runtime and metadata management.

## Requirements

- WordPress 6.9
- PHP 8.5
- Composer autoloading inside the package directory

The package now targets a real PHP 8.5 baseline and uses modern language features that are stable in the current runtime and QA toolchain, including typed class constants, read-only get-hook properties, `readonly` classes, `#[\Override]`, and selective `#[\NoDiscard]`.

## Quality Gates

- `friendsofphp/php-cs-fixer` handles deterministic PSR-12-oriented formatting.
- `inpsyde/php-coding-standards` handles the WordPress, VIP, PHPCompatibility, and security-oriented PHPCS audit for `migration.php` and `src/`.
- `phpstan/phpstan` and PHPUnit cover the full package, including the PHP 8.5 get-hook value objects that PHPCS cannot parse reliably yet.

## Installation

1. Place the package in `wp-content/mu-plugins/database-migration-system/`.
2. Run `composer install` inside the package directory.
3. Add a root MU loader file because WordPress does not autoload subdirectories.

```php
<?php

declare(strict_types=1);

require WPMU_PLUGIN_DIR . '/database-migration-system/migration.php';
```

A ready-to-copy loader example lives in [`docs/example/mu-loader.php`](./docs/example/mu-loader.php).

## Registering Migrations

```php
use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Domain\AbstractMigration;

final class CreateOrdersTableMigration extends AbstractMigration
{
    protected const string VERSION = '1.0.0';

    public function up(): string|array
    {
        $tableName = $this->prefix . 'orders';

        return "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_number varchar(191) NOT NULL,
            PRIMARY KEY (id),
            KEY order_number (order_number)
        ) {$this->charsetCollate};";
    }

    public function down(): string|array
    {
        return sprintf('DROP TABLE IF EXISTS %sorders;', $this->prefix);
    }
}

add_action('db_migration_register', static function (MigrationSystem $system): void {
    $database = $GLOBALS['wpdb'];

    $system->registerMigrations('orders-plugin', [
        new CreateOrdersTableMigration($database),
    ]);
});
```

Use `MigrationSystem::getInstance()->createMigrationManager('orders-plugin')` when you want to run migrations from an activation hook, deploy hook, admin workflow, or custom release pipeline.

When the project boots `SymPress\Kernel\App`, the MU plugin also registers its `CoreProvider` and `CliProvider` through `kernel`.

## Metadata Storage

The plugin keeps two tables, both shared across all registered plugins:

- `{$wpdb->prefix}migrations`
  Tracks the current migrated state per plugin slug and migration class.
- `{$wpdb->prefix}migration_history`
  Tracks every execution and metadata-only change for full up/down history.

The history table stores:

- plugin slug
- migration FQCN
- version
- direction
- execution timestamp

Directions are persisted as:

- `up`
- `down`
- `mark_up`
- `mark_down`

This gives you a persistent history for:

- migrating up
- rolling back down
- marking versions as migrated without SQL execution
- marking versions as rolled back without SQL execution

`sync-metadata-storage` creates both tables. Auto-cleanup only removes them when explicitly enabled and when both current state and history are empty.

## SQL Execution Rules

- `CREATE TABLE` statements run through `dbDelta()`.
- All other statements run through `$wpdb->query()`.
- `up()` and `down()` may return a single SQL string or a list of SQL statements.

This keeps schema creation WordPress-safe while still allowing explicit `ALTER TABLE`, `UPDATE`, `DELETE`, `INSERT`, `DROP`, or cleanup statements.

## Doctrine-Style Runtime Features

The package focuses on the operational parts that matter in production, similar to Doctrine Migrations:

- migrate to latest
- migrate to a target version
- execute a single migration `up` or `down`
- mark a version as migrated or rolled back without executing SQL
- inspect current version
- inspect latest available version
- inspect status and pending versions
- check whether a plugin is up to date
- sync metadata storage
- inspect execution history

Migration targets can be addressed by:

- semantic version, for example `1.0.2`
- fully qualified migration class name
- short class name, for example `CreateOrdersTableMigration`

## WP-CLI

### Runtime Commands

```bash
wp migration migrate
wp migration migrate orders-plugin
wp migration migrate orders-plugin 1.0.0
wp migration run orders-plugin
wp migration rollback orders-plugin
wp migration rollback orders-plugin --migration=CreateOrdersTableMigration
wp migration execute orders-plugin CreateOrdersTableMigration --up
wp migration execute orders-plugin CreateOrdersTableMigration --down
wp migration version orders-plugin CreateOrdersTableMigration --add
wp migration version orders-plugin CreateOrdersTableMigration --delete
```

### Inspection Commands

```bash
wp migration status
wp migration status orders-plugin --verbose
wp migration current orders-plugin
wp migration latest orders-plugin
wp migration up-to-date orders-plugin
wp migration list
wp migration list orders-plugin
wp migration history
wp migration history orders-plugin --format=json
wp migration info orders-plugin
```

### Metadata Command

```bash
wp migration sync-metadata-storage
```

## Programmatic Usage

```php
$manager = MigrationSystem::getInstance()->createMigrationManager('orders-plugin');

if ($manager === null) {
    return;
}

$manager->syncMetadataStorage();
$manager->migrateTo('1.0.0');
$manager->executeMigration(CreateOrdersTableMigration::class, 'up');
$manager->markMigration(CreateOrdersTableMigration::class, 'down');

$current = $manager->getCurrentMigration();
$latest = $manager->getLatestMigration();
$history = $manager->getMigrationHistory();
$pending = $manager->getPendingMigrations();
```

## Development

```bash
composer test
composer phpstan
composer cs:style
composer cs:audit
composer cs
composer cs:fix
composer validate --strict
```

## License

This package is licensed under `GPL-2.0-or-later`.
