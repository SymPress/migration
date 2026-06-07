<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Factory;

use SymPress\WordPress\Migration\Application\MigrationLifecycle;
use SymPress\WordPress\Migration\Application\MigrationManagerFactory;
use SymPress\WordPress\Migration\Application\MigrationSystem;
use SymPress\WordPress\Migration\Cli\MigrationBootstrap;
use SymPress\WordPress\Migration\Cli\MigrationCommand;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Registry\MigrationRegistry;

final class MigrationServiceFactory
{
    public static function database(): \wpdb
    {
        $database = $GLOBALS['wpdb'] ?? null;

        if ($database instanceof \wpdb) {
            return $database;
        }

        throw new \RuntimeException('Global $wpdb is not available.');
    }

    public static function registry(): MigrationRegistry
    {
        return MigrationRegistry::getInstance();
    }

    public static function system(\wpdb $database): MigrationSystem
    {
        return MigrationSystem::bootstrap($database);
    }

    public static function tracker(MigrationSystem $system): MigrationTracker
    {
        return $system->getTracker();
    }

    public static function managerFactory(MigrationSystem $system): MigrationManagerFactory
    {
        return $system->getMigrationManagerFactory();
    }

    public static function lifecycle(MigrationManagerFactory $factory): MigrationLifecycle
    {
        return $factory->getLifecycle();
    }

    public static function sqlExecutor(MigrationLifecycle $lifecycle): WordPressSqlExecutor
    {
        $sqlExecutor = $lifecycle->getSqlExecutor();

        if ($sqlExecutor instanceof WordPressSqlExecutor) {
            return $sqlExecutor;
        }

        throw new \RuntimeException('Migration SQL executor service is invalid.');
    }

    public static function cliBootstrap(MigrationRegistry $registry, MigrationCommand $command): MigrationBootstrap
    {
        return MigrationBootstrap::bootstrap($registry, MigrationCommand::class, $command);
    }
}
