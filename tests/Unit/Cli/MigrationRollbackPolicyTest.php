<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\WordPress\Migration\Cli\MigrationCommand;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\CreatesMigrationManagers;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use Symfony\Component\Process\Process;

final class MigrationRollbackPolicyTest extends TestCase
{
    use CreatesMigrationManagers;

    /** @return iterable<string, array{string}> */
    public static function restrictedEnvironments(): iterable
    {
        foreach (['production', 'staging', 'unknown', '', 'DEVELOPMENT'] as $environment) {
            yield $environment === '' ? 'empty' : $environment => [$environment];
        }
    }

    #[DataProvider('restrictedEnvironments')]
    public function testRestrictedEnvironmentsRejectEveryCliRollbackBeforeDownOrStateDeletion(string $environment): void
    {
        WordPressState::reset();
        MigrationRegistry::reset();
        WordPressState::$environmentType = $environment;
        $database = new \wpdb();
        $GLOBALS['wpdb'] = $database;
        $manager = $this->createMigrationManager($database, [new CreateCustomersTableMigration($database), new AddCustomersEmailIndexMigration($database)]);
        MigrationRegistry::getInstance()->set('my-plugin', $manager);
        self::assertTrue($manager->runMigrations());
        $command = new MigrationCommand();
        $state = $database->migrationRows;
        $history = $database->migrationHistoryRows;
        $tables = $database->tables;

        foreach ([
            fn () => $command->rollback([], []),
            fn () => $command->rollback(['my-plugin'], []),
            fn () => $command->rollback(['my-plugin'], ['migration' => 'AddCustomersEmailIndexMigration', 'force' => true]),
            fn () => $command->execute(['my-plugin', 'AddCustomersEmailIndexMigration'], ['down' => true, 'force' => true]),
            fn () => $command->migrate(['my-plugin', '1.0.0']),
            fn () => $command->migrate(['my-plugin', CreateCustomersTableMigration::class]),
            fn () => $command->run(['my-plugin', 'CreateCustomersTableMigration']),
        ] as $operation) {
            $database->executedStatements = [];
            try {
                $operation();
                self::fail('Restricted CLI rollback must fail.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('disabled', $exception->getMessage());
            }
            self::assertSame($state, $database->migrationRows);
            self::assertSame($history, $database->migrationHistoryRows);
            self::assertSame($tables, $database->tables);
            self::assertNotContains('ALTER TABLE wp_customers DROP INDEX idx_email;', $database->executedStatements);
            self::assertNotContains('DROP TABLE IF EXISTS wp_customers;', $database->executedStatements);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function localEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'development' => ['development'];
    }

    #[DataProvider('localEnvironments')]
    public function testLocalCliRollbacksRemainAvailable(string $environment): void
    {
        WordPressState::reset();
        MigrationRegistry::reset();
        WordPressState::$environmentType = $environment;
        $database = new \wpdb();
        $GLOBALS['wpdb'] = $database;
        $manager = $this->createMigrationManager($database, [new CreateCustomersTableMigration($database), new AddCustomersEmailIndexMigration($database)]);
        MigrationRegistry::getInstance()->set('my-plugin', $manager);
        $command = new MigrationCommand();
        $command->migrate(['my-plugin']);
        $command->migrate(['my-plugin', '1.0.0']);
        self::assertCount(1, $manager->getMigratedVersions());
        $command->execute(['my-plugin', 'CreateCustomersTableMigration'], ['down' => true]);
        self::assertSame([], $manager->getMigratedVersions());
        $command->execute(['my-plugin', 'CreateCustomersTableMigration'], ['up' => true]);
        $command->rollback(['my-plugin'], ['migration' => 'CreateCustomersTableMigration']);
        self::assertSame([], $manager->getMigratedVersions());
        $command->migrate(['my-plugin']);
        $command->rollback([], []);
        self::assertSame([], $manager->getMigratedVersions());
    }

    #[DataProvider('restrictedEnvironments')]
    public function testRestrictedEnvironmentsStillPermitForwardAndSingleUp(string $environment): void
    {
        WordPressState::reset();
        MigrationRegistry::reset();
        WordPressState::$environmentType = $environment;
        $database = new \wpdb();
        $GLOBALS['wpdb'] = $database;
        $manager = $this->createMigrationManager($database, [new CreateCustomersTableMigration($database), new AddCustomersEmailIndexMigration($database)]);
        MigrationRegistry::getInstance()->set('my-plugin', $manager);
        $command = new MigrationCommand();
        $command->execute(['my-plugin', 'CreateCustomersTableMigration'], ['up' => true]);
        $command->migrate(['my-plugin', '1.0.1']);
        self::assertFalse($manager->hasPendingMigrations());
        self::assertCount(2, $manager->getMigratedVersions());
        $command->migrate([]);
        self::assertCount(2, $manager->getMigratedVersions());
    }

    public function testWordPressNotLoadedFailsClosedWithoutProcessEnvironmentFallback(): void
    {
        $code = 'require "vendor/autoload.php"; echo (int) (new \\SymPress\\WordPress\\Migration\\Cli\\MigrationCommandContext())->allowsRollback();';
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 3), ['WP_ENVIRONMENT_TYPE' => 'development']);
        $process->mustRun();
        self::assertSame('0', $process->getOutput());
    }
}
