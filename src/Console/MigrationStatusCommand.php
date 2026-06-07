<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Console;

use SymPress\WordPress\Migration\Application\MigrationStatusReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migration:status|migration:list',
    description: 'Show registered migration status.',
)]
final class MigrationStatusCommand extends Command
{
    private const array OVERVIEW_HEADERS = [
        'plugin',
        'current',
        'latest',
        'migrated',
        'pending',
        'executions',
        'status',
    ];

    public function __construct(
        private readonly MigrationStatusReporter $reporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plugin', InputArgument::OPTIONAL, 'The plugin slug to inspect.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, csv.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input->getOption('format'));
        $pluginSlug = $this->optionalString($input->getArgument('plugin'));

        if (!in_array($format, ['table', 'json', 'csv'], true)) {
            (new SymfonyStyle($input, $output))->error(sprintf('Unsupported output format "%s".', $format));

            return Command::INVALID;
        }

        if ($pluginSlug !== null) {
            return $this->renderPluginStatus($pluginSlug, $format, $input, $output);
        }

        $rows = $this->reporter->all();

        if ($rows === []) {
            (new SymfonyStyle($input, $output))->warning('No plugins with migrations registered.');

            return Command::SUCCESS;
        }

        $this->renderRows($output, $rows, self::OVERVIEW_HEADERS, $format);

        return Command::SUCCESS;
    }

    private function renderPluginStatus(
        string $pluginSlug,
        string $format,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $report = $this->reporter->plugin($pluginSlug);
        $io = new SymfonyStyle($input, $output);

        if ($report === null) {
            $io->error(sprintf('Plugin "%s" not found.', $pluginSlug));

            return Command::FAILURE;
        }

        if ($format !== 'table') {
            $this->renderStructured($output, $report, $format);

            return Command::SUCCESS;
        }

        /** @var array<string, string|int> $overview */
        $overview = $report['overview'];
        $io->definitionList(
            ['Plugin' => $overview['plugin']],
            ['Status' => $overview['status']],
            ['Current' => $overview['current']],
            ['Latest' => $overview['latest']],
            ['Migrated' => (string) $overview['migrated']],
            ['Pending' => (string) $overview['pending']],
            ['Executions' => (string) $overview['executions']],
        );

        if ($output->isVerbose() && $report['pending_migrations'] !== []) {
            $io->section('Pending migrations');
            $this->renderRows($output, $report['pending_migrations'], ['class', 'name', 'version'], 'table');
        }

        if ($output->isVerbose() && $report['recent_history'] !== []) {
            $io->section('Recent execution history');
            $this->renderRows(
                $output,
                $report['recent_history'],
                ['plugin', 'migration', 'name', 'version', 'direction', 'executed_at'],
                'table',
            );
        }

        if ($overview['status'] === 'Up to Date') {
            $io->success(sprintf('Plugin "%s" is up to date.', $pluginSlug));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Plugin "%s" has pending migrations.', $pluginSlug));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $headers
     */
    private function renderRows(OutputInterface $output, array $rows, array $headers, string $format): void
    {
        if ($format === 'table') {
            (new Table($output))
                ->setHeaders($headers)
                ->setRows(array_map(
                    static fn (array $row): array => array_map(
                        static fn (string $header): mixed => $row[$header] ?? '',
                        $headers,
                    ),
                    $rows,
                ))
                ->render();

            return;
        }

        $this->renderStructured($output, $rows, $format);
    }

    private function renderStructured(OutputInterface $output, mixed $data, string $format): void
    {
        if ($format === 'json') {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (is_string($json)) {
                $output->writeln($json);
            }

            return;
        }

        if (!is_array($data)) {
            return;
        }

        $this->renderCsv($output, $data);
    }

    /**
     * @param array<mixed> $data
     */
    private function renderCsv(OutputInterface $output, array $data): void
    {
        $rows = array_is_list($data) ? $data : [$data];
        $headers = $this->csvHeaders($rows);

        if ($headers === []) {
            return;
        }

        $output->writeln($this->csvLine($headers));

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $output->writeln($this->csvLine(array_map(
                static fn (string $header): string => is_scalar($row[$header] ?? null) ? (string) $row[$header] : '',
                $headers,
            )));
        }
    }

    /**
     * @param array<mixed> $rows
     * @return list<string>
     */
    private function csvHeaders(array $rows): array
    {
        $first = $rows[0] ?? null;

        if (!is_array($first)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($first),
            static fn (mixed $key): bool => is_string($key),
        ));
    }

    /**
     * @param list<string> $values
     */
    private function csvLine(array $values): string
    {
        $handle = fopen('php://temp', 'r+');

        if (!is_resource($handle)) {
            return '';
        }

        fputcsv($handle, $values);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        return is_string($line) ? rtrim($line, "\r\n") : '';
    }

    private function format(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return 'table';
        }

        $format = trim((string) $value);

        return $format !== '' ? strtolower($format) : 'table';
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : null;
    }
}
