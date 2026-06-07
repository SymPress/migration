<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Unit\Migration;

use SymPress\WordPress\Migration\Value\PluginSlug;
use PHPUnit\Framework\TestCase;

final class PluginSlugTest extends TestCase
{
    public function test_it_normalizes_valid_plugin_slugs(): void
    {
        $pluginSlug = PluginSlug::fromString(' My-Plugin ');

        self::assertSame('my-plugin', $pluginSlug->value);
    }

    public function test_it_rejects_invalid_plugin_slugs(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginSlug::fromString('My Plugin!');
    }
}
