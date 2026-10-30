<?php
declare(strict_types=1);

/**
 * FinSync - Root entry point for shared hosting (InfinityFree, cPanel, Apache)
 * Automatically forwards requests to the public directory.
 */

$publicIndex = __DIR__ . '/public/index.php';
if (is_file($publicIndex)) {
    require_once $publicIndex;
} else {
    http_response_code(500);
    echo "FinSync: Could not locate public/index.php.";
}

