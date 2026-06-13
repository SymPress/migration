#!/usr/bin/env php
<?php

declare(strict_types=1);

$command = $argv[1] ?? null;
$packageDir = realpath(__DIR__ . '/..');

if (!is_string($packageDir) || $packageDir === '') {
    fwrite(STDERR, "Could not determine the package directory.\n");
    exit(1);
}

chdir($packageDir);

switch ($command) {
    case 'cs':
        $config = firstExistingFile(['phpcs.xml.dist', 'phpcs.xml']);
        if ($config === null) {
            fwrite(STDOUT, "Skipping: no PHPCS configuration found.\n");
            exit(0);
        }

        exit(runTool('phpcs', ['--standard=' . $config]));

    case 'cs:fix':
        $config = firstExistingFile(['phpcs.xml.dist', 'phpcs.xml']);
        if ($config === null) {
            fwrite(STDOUT, "Skipping: no PHPCS configuration found.\n");
            exit(0);
        }

        exit(runTool('phpcbf', ['--standard=' . $config]));

    case 'static-analysis':
        $config = firstExistingFile(['phpstan.neon.dist', 'phpstan.neon']);
        if ($config === null) {
            fwrite(STDOUT, "Skipping: no PHPStan configuration found.\n");
            exit(0);
        }

        if (usesLocalVendorAutoload($config) && !is_readable('vendor/autoload.php')) {
            fwrite(STDOUT, "Skipping: no local package vendor/autoload.php found for PHPStan.\n");
            exit(0);
        }

        if (findTool('phpstan') === null) {
            fwrite(STDOUT, "Skipping: phpstan is not installed for this package.\n");
            exit(0);
        }

        $missingDependencies = usesProjectVendorAutoload($config) ? [] : missingLocalSymPressDependencies();
        if ($missingDependencies !== []) {
            fwrite(
                STDOUT,
                sprintf(
                    "Skipping: missing local package dependencies for PHPStan (%s).\n",
                    implode(', ', $missingDependencies),
                ),
            );
            exit(0);
        }

        exit(runTool('phpstan', ['analyse', '--memory-limit=1G', '--no-progress', '-c', $config]));

    case 'tests':
        $config = firstExistingFile(['phpunit.xml.dist', 'phpunit.xml']);
        if ($config === null) {
            fwrite(STDOUT, "Skipping: no PHPUnit configuration found.\n");
            exit(0);
        }

        if (!is_readable('vendor/autoload.php')) {
            fwrite(STDOUT, "Skipping: no local package vendor/autoload.php found for PHPUnit.\n");
            exit(0);
        }

        if (findTool('phpunit') === null) {
            fwrite(STDOUT, "Skipping: phpunit is not installed for this package.\n");
            exit(0);
        }

        $missingDependencies = missingLocalSymPressDependencies();
        if ($missingDependencies !== []) {
            fwrite(
                STDOUT,
                sprintf(
                    "Skipping: missing local package dependencies for PHPUnit (%s).\n",
                    implode(', ', $missingDependencies),
                ),
            );
            exit(0);
        }

        exit(runTool('phpunit', ['--configuration', $config, '--no-coverage']));

    default:
        fwrite(STDERR, "Usage: package-quality.php <cs|cs:fix|static-analysis|tests>\n");
        exit(1);
}

/**
 * @param list<string> $fileNames
 */
function firstExistingFile(array $fileNames): ?string
{
    foreach ($fileNames as $fileName) {
        if (is_readable($fileName)) {
            return $fileName;
        }
    }

    return null;
}

/**
 * @param list<string> $arguments
 */
function runTool(string $tool, array $arguments = []): int
{
    $bin = findTool($tool);

    if ($bin === null) {
        fwrite(STDOUT, sprintf("Skipping: %s is not installed for this package.\n", $tool));

        return 0;
    }

    $parts = array_merge([PHP_BINARY, $bin], $arguments);
    passthru(implode(' ', array_map('escapeshellarg', $parts)), $exitCode);

    return (int) $exitCode;
}

function findTool(string $tool): ?string
{
    $packageDir = (string) realpath(__DIR__ . '/..');
    $workspaceDir = dirname($packageDir, 2);
    $candidates = [
        $packageDir . '/vendor/bin/' . $tool,
        $workspaceDir . '/vendor/bin/' . $tool,
        $workspaceDir . '/packages/coding-standards/vendor/bin/' . $tool,
    ];

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function usesLocalVendorAutoload(string $config): bool
{
    $contents = file_get_contents($config);

    return is_string($contents) && preg_match('~(?<![./])vendor/autoload\.php~', $contents) === 1;
}

function usesProjectVendorAutoload(string $config): bool
{
    $contents = file_get_contents($config);

    return is_string($contents) && str_contains($contents, '../../vendor/autoload.php');
}

/**
 * @return list<string>
 */
function missingLocalSymPressDependencies(): array
{
    if (!is_readable('composer.json')) {
        return [];
    }

    $composer = json_decode((string) file_get_contents('composer.json'), true);
    if (!is_array($composer)) {
        return [];
    }

    $requires = array_merge(
        is_array($composer['require'] ?? null) ? $composer['require'] : [],
        is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
    );
    $missing = [];

    foreach (array_keys($requires) as $packageName) {
        if (!is_string($packageName) || !str_starts_with($packageName, 'sympress/')) {
            continue;
        }

        if ($packageName === 'sympress/coding-standards') {
            continue;
        }

        $vendorName = substr($packageName, strlen('sympress/'));
        if (!is_dir('vendor/sympress/' . $vendorName)) {
            $missing[] = $packageName;
        }
    }

    return $missing;
}
