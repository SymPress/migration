<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Value;

final class PluginSlug
{
    private readonly string $normalizedValue;

    public string $value {
        get => $this->normalizedValue;
    }

    private function __construct(string $value)
    {
        $this->normalizedValue = self::normalize($value);
    }

    public static function fromString(string $pluginSlug): self
    {
        return new self($pluginSlug);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function normalize(string $pluginSlug): string
    {
        $normalizedPluginSlug = strtolower(trim($pluginSlug));

        if ($normalizedPluginSlug === '') {
            throw new \InvalidArgumentException('Plugin slug must not be empty.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $normalizedPluginSlug) === 1) {
            return $normalizedPluginSlug;
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid plugin slug "%s". Use lowercase letters, numbers, dashes or underscores.',
            $pluginSlug,
        ));
    }
}
