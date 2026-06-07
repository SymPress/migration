<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Cli;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final readonly class SymfonyConsoleRunner
{
    public function __construct(
        private Application $application,
    ) {
    }

    public function has(string $commandName): bool
    {
        return $this->application->has($commandName);
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|array<scalar|null>|null> $assocArgs
     */
    public function run(string $commandName, array $args, array $assocArgs): int
    {
        return $this->application->run(
            new ArgvInput($this->argv($commandName, $args, $assocArgs)),
            new ConsoleOutput(),
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|array<scalar|null>|null> $assocArgs
     * @return list<string>
     */
    private function argv(string $commandName, array $args, array $assocArgs): array
    {
        $argv = ['wp migration', $commandName];

        foreach ($args as $arg) {
            $argv[] = $arg;
        }

        foreach ($assocArgs as $name => $value) {
            foreach ($this->optionTokens((string) $name, $value) as $token) {
                $argv[] = $token;
            }
        }

        return $argv;
    }

    /**
     * @param scalar|array<scalar|null>|null $value
     * @return list<string>
     */
    private function optionTokens(string $name, mixed $value): array
    {
        if (is_array($value)) {
            $tokens = [];

            foreach ($value as $item) {
                $tokens = [...$tokens, ...$this->optionTokens($name, $item)];
            }

            return $tokens;
        }

        if ($value === false) {
            return [sprintf('--no-%s', $name)];
        }

        if ($value === true || $value === null) {
            return [sprintf('--%s', $name)];
        }

        return [sprintf('--%s=%s', $name, (string) $value)];
    }
}
