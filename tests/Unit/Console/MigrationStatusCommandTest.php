<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Console;

use SymPress\WordPress\Migration\Application\MigrationStatusReporter;
use SymPress\WordPress\Migration\Console\MigrationStatusCommand;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;
use SymPress\WordPress\Migration\Tests\Support\AddCustomersEmailIndexMigration;
use SymPress\WordPress\Migration\Tests\Support\CreateCustomersTableMigration;
use SymPress\WordPress\Migration\Tests\Support\CreatesMigrationManagers;
use SymPress\WordPress\Migration\Tests\Support\WordPressState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrationStatusCommandTest extends TestCase
{
    use CreatesMigrationManagers;

    private MigrationManager $manager;
    private \wpdb $database;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        WordPressState::reset();
        MigrationRegistry::reset();
        $this->database = new \wpdb();
        $GLOBALS['wpdb'] = $this->database;

        $this->manager = $this->createMigrationManager(
            $this->database,
            [
                new CreateCustomersTableMigration($this->database),
                new AddCustomersEmailIndexMigration($this->database),
            ],
        );

        MigrationRegistry::getInstance()->set('my-plugin', $this->manager);
    }

    public function test_it_lists_registered_plugin_status_as_json(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['--format' => 'json']);

        $rows = json_decode($tester->getDisplay(), true);

        self::assertIsArray($rows);
        self::assertSame('my-plugin', $rows[0]['plugin']);
        self::assertSame('1.0.1', $rows[0]['latest']);
        self::assertSame(2, $rows[0]['pending']);
        self::assertSame('Pending', $rows[0]['status']);
    }

    public function test_it_reports_a_single_plugin_with_verbose_sections(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['plugin' => 'my-plugin'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('my-plugin', $display);
        self::assertStringContainsString('Pending migrations', $display);
        self::assertStringContainsString('CreateCustomersTableMigration', $display);
    }

    public function test_it_returns_failure_for_an_unknown_plugin(): void
    {
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['plugin' => 'missing-plugin']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Plugin "missing-plugin" not found.', $tester->getDisplay());
    }

    private function command(): MigrationStatusCommand
    {
        return new MigrationStatusCommand(new MigrationStatusReporter(MigrationRegistry::getInstance()));
    }
}
