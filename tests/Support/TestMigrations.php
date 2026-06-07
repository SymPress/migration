<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Support;

use SymPress\WordPress\Migration\Domain\AbstractMigration;

final class CreateCustomersTableMigration extends AbstractMigration
{
    protected const string VERSION = '1.0.0';

    public function up(): string|array
    {
        $tableName = $this->prefix . 'customers';

        return "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(191) NOT NULL,
            PRIMARY KEY (id),
            KEY email (email)
        ) {$this->charsetCollate};";
    }

    public function down(): string|array
    {
        return sprintf('DROP TABLE IF EXISTS %scustomers;', $this->prefix);
    }
}

final class AddCustomersEmailIndexMigration extends AbstractMigration
{
    protected const string VERSION = '1.0.1';

    public function up(): string|array
    {
        return sprintf(
            'ALTER TABLE %scustomers ADD INDEX idx_email (email);',
            $this->prefix,
        );
    }

    public function down(): string|array
    {
        return sprintf(
            'ALTER TABLE %scustomers DROP INDEX idx_email;',
            $this->prefix,
        );
    }
}
