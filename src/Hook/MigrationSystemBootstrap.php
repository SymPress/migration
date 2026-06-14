<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Hook;

use SymPress\WordPress\Migration\Application\MigrationSystem;

final class MigrationSystemBootstrap
{
    private const string SHOULD_INITIALIZE_FILTER = 'sympress_migrations_should_initialize';

    public function __construct(
        private readonly MigrationSystem $system,
    ) {
    }

    public function initialize(): void
    {
        if (!$this->shouldInitialize()) {
            return;
        }

        $this->system->init();

        if (!function_exists('do_action')) {
            return;
        }

        do_action('db_migration_system_ready', $this->system);
    }

    private function shouldInitialize(): bool
    {
        $shouldInitialize = $this->isMigrationRuntime();

        if (!function_exists('apply_filters')) {
            return $shouldInitialize;
        }

        $filtered = apply_filters(self::SHOULD_INITIALIZE_FILTER, $shouldInitialize, $this->system);

        return $this->boolValue($filtered, $shouldInitialize);
    }

    private function isMigrationRuntime(): bool
    {
        if ($this->truthyConstant('WP_CLI')) {
            return true;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        if (function_exists('wp_installing') && wp_installing()) {
            return true;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        if (function_exists('is_admin')) {
            return is_admin() === true;
        }

        return true;
    }

    private function truthyConstant(string $name): bool
    {
        return defined($name)
            && filter_var(constant($name), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
