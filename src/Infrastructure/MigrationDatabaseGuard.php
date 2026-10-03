<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Infrastructure;

use SymPress\WordPress\Migration\Exception\MigrationOperationException;

/** Shares ownership across SQL and metadata writers using the same wpdb connection. */
final class MigrationDatabaseGuard
{
    /** @var \WeakMap<\wpdb, self>|null */
    private static ?\WeakMap $active = null;
    private int $transactionDepth = 0;

    public function __construct(private readonly \wpdb $database, private readonly string $lock, private readonly string $session)
    {
        self::$active ??= new \WeakMap();
        self::$active[$database] = $this;
        $this->assertOwned();
    }

    public static function forDatabase(\wpdb $database): ?self
    {
        return self::$active[$database] ?? null;
    }

    public static function assertDatabaseOwnership(\wpdb $database): void
    {
        self::forDatabase($database)?->assertOwned();
    }

    public function isOwned(): bool
    {
        $session = $this->database->get_var('SELECT CONNECTION_ID()');
        $owner = $this->database->get_var($this->database->prepare('SELECT IS_USED_LOCK(%s)', $this->lock));
        return $this->session !== '' && (string) $session === $this->session && (string) $owner === $this->session;
    }

    public function assertOwned(): void
    {
        if ($this->isOwned()) {
            return;
        }
        throw new MigrationOperationException('Migration database session or advisory lock was lost; '
            . 'stop and reconcile SQL and metadata before retrying.');
    }

    public function transactionDepth(): int
    {
        return $this->transactionDepth;
    }

    public function enterTransaction(): void
    {
        $this->transactionDepth++;
    }

    public function leaveTransaction(): void
    {
        $this->transactionDepth--;
    }

    public function close(): void
    {
        unset(self::$active[$this->database]);
        if (!$this->isOwned()) {
            return;
        }
        $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $this->lock));
    }
}
