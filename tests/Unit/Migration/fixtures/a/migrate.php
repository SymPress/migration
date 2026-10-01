<?php

return new class implements \SymPress\WordPress\Migration\Contract\Migration {
    public function getVersion(): string { return '1.0.0'; }
    public function up(): string { return 'SELECT first'; }
    public function down(): string { return ''; }
};
