<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fake-wp/');
define('WP_CLI', true);

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

require_once __DIR__ . '/Support/FakeWpEnvironment.php';
require_once __DIR__ . '/../includes/Autoloader.php';

\MuhCleanMigrator\Autoloader::register();
