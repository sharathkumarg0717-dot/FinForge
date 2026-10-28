<?php
/**
 * Copy this file to config.local.php and adjust for your XAMPP install.
 * Keep config.local.php private; it is excluded from version control.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'finsync',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_path' => '', // Example: '/finsync/public' when hosted below htdocs.
        'environment' => 'local',
    ],
];
