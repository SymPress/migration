<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Contract;

interface Migration
{
    public function getVersion(): string;

    /** @return string|list<string> */
    public function up(): string|array;

    /** @return string|list<string> */
    public function down(): string|array;
}
