<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Exception;

/** A reviewed migration operation cannot proceed with the current database state. */
class MigrationOperationException extends \RuntimeException
{
}
