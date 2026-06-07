<?php

declare(strict_types=1);

namespace SymPress\WordPress\Migration\Tests\Support {

    final class WordPressState
    {
        /** @var array<string, array<int, list<array{callback: callable, accepted_args: int}>>> */
        public static array $hooks = [];

        /** @var list<string> */
        public static array $dbDelta = [];

        /**
         * @var array{
         *     commands: list<array{name: string, class: string}>,
         *     log: list<string>,
         *     success: list<string>,
         *     warning: list<string>,
         *     error: list<string>,
         *     format_items: list<array{format: string, items: array, fields: array}>
         * }
         */
        public static array $cliCalls = [];

        public static string $currentTime = '2026-03-27 12:00:00';

        public static function reset(): void
        {
            self::$hooks = [];
            self::$dbDelta = [];
            self::$cliCalls = [
                'commands' => [],
                'log' => [],
                'success' => [],
                'warning' => [],
                'error' => [],
                'format_items' => [],
            ];
            self::$currentTime = '2026-03-27 12:00:00';
        }
    }
}

namespace {

    use SymPress\WordPress\Migration\Tests\Support\WordPressState;

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!class_exists('wpdb')) {
        class wpdb
        {
            public string $prefix = 'wp_';
            public string $base_prefix = 'wp_';
            public string $last_error = '';

            /** @var array<string, true> */
            public array $tables = [];

            /** @var array<string, array{id: int, plugin: string, migration: string, version: string, migrated_at: string}> */
            public array $migrationRows = [];

            /**
             * @var list<array{
             *     id: int,
             *     plugin: string,
             *     migration: string,
             *     version: string,
             *     direction: string,
             *     executed_at: string
             * }>
             */
            public array $migrationHistoryRows = [];

            /** @var list<string> */
            public array $executedStatements = [];

            /** @var list<string> */
            public array $failedStatements = [];

            private int $autoIncrement = 1;
            private int $historyAutoIncrement = 1;

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }

            public function prepare(string $query, mixed ...$args): string
            {
                $query = str_replace(['%d', '%f'], '%s', $query);
                $preparedArgs = array_map([$this, 'prepareValue'], $args);

                return vsprintf($query, $preparedArgs);
            }

            public function get_var(string $query): string|int|null
            {
                if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/i", trim($query), $matches) === 1) {
                    return $this->hasTable($matches[1]) ? $matches[1] : null;
                }

                if (preg_match("/^SELECT COUNT\\(\\*\\) FROM ([a-zA-Z0-9_]+)(?: WHERE plugin = '([^']+)')?$/i", trim($query), $matches) !== 1) {
                    return null;
                }

                $table = $matches[1];

                if (!$this->hasTable($table)) {
                    return 0;
                }

                $rows = $this->rowsForTable($table);
                $plugin = $matches[2] ?? null;

                if (is_string($plugin) && $plugin !== '') {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => $row['plugin'] === $plugin,
                    ));
                }

                return count($rows);
            }

            public function get_row(string $query, string|int $output = ARRAY_A): ?array
            {
                if (preg_match(
                    "/FROM ([a-zA-Z0-9_]+)\s+WHERE plugin = '([^']+)' AND migration = '([^']+)'/i",
                    $query,
                    $matches,
                ) !== 1) {
                    return null;
                }

                if (!$this->hasTable($matches[1])) {
                    return null;
                }

                $key = $this->recordKey($matches[2], $matches[3]);

                return $this->migrationRows[$key] ?? null;
            }

            public function get_results(string $query, string|int $output = ARRAY_A): ?array
            {
                if (preg_match(
                    "/FROM ([a-zA-Z0-9_]+)\s+WHERE plugin = '([^']+)'\s+ORDER BY id ASC/i",
                    $query,
                    $matches,
                ) === 1) {
                    if (!$this->hasTable($matches[1])) {
                        return [];
                    }

                    $rows = array_values(array_filter(
                        $this->rowsForTable($matches[1]),
                        static fn (array $row): bool => $row['plugin'] === $matches[2],
                    ));

                    usort(
                        $rows,
                        static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
                    );

                    return array_map([$this, 'stripInternalId'], $rows);
                }

                if (preg_match(
                    "/FROM ([a-zA-Z0-9_]+)\s+WHERE plugin = '([^']+)'\s+ORDER BY id DESC/i",
                    $query,
                    $matches,
                ) === 1) {
                    if (!$this->hasTable($matches[1])) {
                        return [];
                    }

                    $rows = array_values(array_filter(
                        $this->rowsForTable($matches[1]),
                        static fn (array $row): bool => $row['plugin'] === $matches[2],
                    ));

                    usort(
                        $rows,
                        static fn (array $left, array $right): int => $right['id'] <=> $left['id'],
                    );

                    return array_map([$this, 'stripInternalId'], $rows);
                }

                if (preg_match(
                    "/FROM ([a-zA-Z0-9_]+)\s+ORDER BY id DESC/i",
                    $query,
                    $matches,
                ) === 1) {
                    if (!$this->hasTable($matches[1])) {
                        return [];
                    }

                    $rows = array_values($this->rowsForTable($matches[1]));

                    usort(
                        $rows,
                        static fn (array $left, array $right): int => $right['id'] <=> $left['id'],
                    );

                    return array_map([$this, 'stripInternalId'], $rows);
                }

                if (preg_match(
                    "/FROM ([a-zA-Z0-9_]+)\s+ORDER BY plugin ASC, id ASC/i",
                    $query,
                    $matches,
                ) !== 1) {
                    return [];
                }

                if (!$this->hasTable($matches[1])) {
                    return [];
                }

                $rows = array_values($this->rowsForTable($matches[1]));

                usort(
                    $rows,
                    static function (array $left, array $right): int {
                        $pluginComparison = $left['plugin'] <=> $right['plugin'];

                        if ($pluginComparison !== 0) {
                            return $pluginComparison;
                        }

                        return $left['id'] <=> $right['id'];
                    },
                );

                return array_map([$this, 'stripInternalId'], $rows);
            }

            public function insert(string $table, array $data, array $formats = []): int|false
            {
                if (!$this->hasTable($table)) {
                    $this->last_error = 'Table does not exist.';

                    return false;
                }

                if ($this->isHistoryTable($table)) {
                    $this->migrationHistoryRows[] = [
                        'id' => $this->historyAutoIncrement++,
                        'plugin' => $data['plugin'],
                        'migration' => $data['migration'],
                        'version' => $data['version'],
                        'direction' => $data['direction'],
                        'executed_at' => $data['executed_at'],
                    ];
                    $this->last_error = '';

                    return 1;
                }

                $key = $this->recordKey($data['plugin'], $data['migration']);

                $this->migrationRows[$key] = [
                    'id' => $this->autoIncrement++,
                    'plugin' => $data['plugin'],
                    'migration' => $data['migration'],
                    'version' => $data['version'],
                    'migrated_at' => $data['migrated_at'],
                ];
                $this->last_error = '';

                return 1;
            }

            public function update(
                string $table,
                array $data,
                array $where,
                array $formats = [],
                array $whereFormats = [],
            ): int|false {
                if (!$this->hasTable($table)) {
                    $this->last_error = 'Table does not exist.';

                    return false;
                }

                $key = $this->recordKey($where['plugin'], $where['migration']);

                if (!isset($this->migrationRows[$key])) {
                    $this->last_error = 'Row does not exist.';

                    return false;
                }

                $this->migrationRows[$key]['version'] = $data['version'];
                $this->migrationRows[$key]['migrated_at'] = $data['migrated_at'];
                $this->last_error = '';

                return 1;
            }

            public function delete(string $table, array $where, array $whereFormats = []): int|false
            {
                if (!$this->hasTable($table)) {
                    $this->last_error = 'Table does not exist.';

                    return false;
                }

                if (isset($where['plugin'], $where['migration'])) {
                    $key = $this->recordKey($where['plugin'], $where['migration']);

                    if (!isset($this->migrationRows[$key])) {
                        $this->last_error = '';

                        return 0;
                    }

                    unset($this->migrationRows[$key]);
                    $this->last_error = '';

                    return 1;
                }

                if (!isset($where['plugin'])) {
                    $this->last_error = '';

                    return 0;
                }

                $deleted = 0;

                foreach ($this->migrationRows as $key => $row) {
                    if ($row['plugin'] !== $where['plugin']) {
                        continue;
                    }

                    unset($this->migrationRows[$key]);
                    $deleted++;
                }

                $this->last_error = '';

                return $deleted;
            }

            public function query(string $query): int|false
            {
                $statement = $this->normalizeStatement($query);
                $this->executedStatements[] = $statement;

                if (in_array($statement, $this->failedStatements, true)) {
                    $this->last_error = 'Simulated query failure.';

                    return false;
                }

                if (preg_match('/^CREATE TABLE `?([a-zA-Z0-9_]+)`?/i', $statement, $matches) === 1) {
                    $this->tables[$matches[1]] = true;
                    $this->last_error = '';

                    return 1;
                }

                if (preg_match('/^DROP TABLE IF EXISTS `?([a-zA-Z0-9_]+)`?/i', $statement, $matches) === 1) {
                    unset($this->tables[$matches[1]]);

                    if ($this->isHistoryTable($matches[1])) {
                        $this->migrationHistoryRows = [];
                    }

                    if ($this->isStateTable($matches[1])) {
                        $this->migrationRows = [];
                    }

                    $this->last_error = '';

                    return 1;
                }

                $this->last_error = '';

                return 1;
            }

            public function hasTable(string $table): bool
            {
                return isset($this->tables[$table]);
            }

            private function prepareValue(mixed $value): string
            {
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }

                return sprintf("'%s'", str_replace("'", "\\'", (string) $value));
            }

            private function recordKey(string $plugin, string $migration): string
            {
                return sprintf('%s|%s', $plugin, $migration);
            }

            private function normalizeStatement(string $statement): string
            {
                return trim((string) preg_replace('/\s+/', ' ', $statement));
            }

            /**
             * @return list<array{id: int, plugin: string, migration: string, version: string, migrated_at: string}>
             */
            private function currentRows(): array
            {
                return array_values($this->migrationRows);
            }

            /**
             * @return list<array{id: int, plugin: string, migration: string, version: string, direction: string, executed_at: string}>
             */
            private function historyRows(): array
            {
                return $this->migrationHistoryRows;
            }

            /**
             * @return array<int, array<string, scalar>>
             */
            private function rowsForTable(string $table): array
            {
                if ($this->isHistoryTable($table)) {
                    return $this->historyRows();
                }

                return $this->currentRows();
            }

            private function isHistoryTable(string $table): bool
            {
                return str_ends_with($table, 'migration_history');
            }

            private function isStateTable(string $table): bool
            {
                return !$this->isHistoryTable($table);
            }

            /**
             * @param array<string, scalar> $row
             * @return array<string, scalar>
             */
            private function stripInternalId(array $row): array
            {
                unset($row['id']);

                return $row;
            }
        }
    }

    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static function add_command(string $name, string $class): void
            {
                WordPressState::$cliCalls['commands'][] = [
                    'name' => $name,
                    'class' => $class,
                ];
            }

            public static function log(string $message): void
            {
                WordPressState::$cliCalls['log'][] = $message;
            }

            public static function success(string $message): void
            {
                WordPressState::$cliCalls['success'][] = $message;
            }

            public static function warning(string $message): void
            {
                WordPressState::$cliCalls['warning'][] = $message;
            }

            public static function error(string $message): never
            {
                WordPressState::$cliCalls['error'][] = $message;

                throw new RuntimeException($message);
            }
        }
    }

    if (!function_exists('add_action')) {
        function add_action(
            string $hook,
            callable $callback,
            int $priority = 10,
            int $acceptedArgs = 1,
        ): void {
            WordPressState::$hooks[$hook][$priority][] = [
                'callback' => $callback,
                'accepted_args' => $acceptedArgs,
            ];
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void
        {
            $callbacksByPriority = WordPressState::$hooks[$hook] ?? [];

            if ($callbacksByPriority === []) {
                return;
            }

            ksort($callbacksByPriority);

            foreach ($callbacksByPriority as $callbacks) {
                foreach ($callbacks as $callback) {
                    $acceptedArgs = $callback['accepted_args'];
                    $callbackArgs = array_slice($args, 0, $acceptedArgs);

                    ($callback['callback'])(...$callbackArgs);
                }
            }
        }
    }

    if (!function_exists('current_time')) {
        function current_time(string $type = 'mysql', bool $gmt = false): string
        {
            return WordPressState::$currentTime;
        }
    }

    if (!function_exists('dbDelta')) {
        function dbDelta(string $sql): array
        {
            WordPressState::$dbDelta[] = $sql;

            $database = $GLOBALS['wpdb'] ?? null;

            if ($database instanceof wpdb) {
                $database->query($sql);
            }

            return [$sql];
        }
    }
}

namespace WP_CLI\Utils {

    use SymPress\WordPress\Migration\Tests\Support\WordPressState;

    function format_items(string $format, array $items, array $fields): void
    {
        WordPressState::$cliCalls['format_items'][] = [
            'format' => $format,
            'items' => $items,
            'fields' => $fields,
        ];
    }
}
