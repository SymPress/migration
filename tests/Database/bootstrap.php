<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$databaseName = getenv('WORDPRESS_DB_NAME');

if (!is_string($databaseName) || !preg_match('/^sympress_review_[a-z0-9_]+$/', $databaseName)) {
    throw new RuntimeException('Database tests require an explicit disposable sympress_review_* database.');
}

$wordpressPath = dirname(__DIR__, 2) . '/vendor/wordpress/wordpress/';

if (!is_file($wordpressPath . 'wp-settings.php')) {
    throw new RuntimeException('WordPress core is not installed. Run composer install first.');
}

$environment = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false ? $default : $value;
};

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define('ABSPATH', $wordpressPath);
define('DB_NAME', $databaseName);
define('DB_USER', $environment('WORDPRESS_DB_USER', 'wordpress'));
define('DB_PASSWORD', $environment('WORDPRESS_DB_PASSWORD', 'wordpress'));
define('DB_HOST', $environment('WORDPRESS_DB_HOST', '127.0.0.1:3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_INSTALLING', true);
define('WP_DEBUG', false);

$table_prefix = 'wp_';

require_once ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
