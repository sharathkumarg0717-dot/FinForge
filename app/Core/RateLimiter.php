<?php
declare(strict_types=1);

namespace FinSync\Core;

final class RateLimiter
{
    public static function allow(string $action, int $maximum, int $windowSeconds): bool
    {
        $directory = dirname(__DIR__, 2) . '/storage/rate-limits';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $file = $directory . '/' . hash('sha256', $action . '|' . $ip) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }
        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle);
        $state = is_string($contents) ? json_decode($contents, true) : null;
        $now = time();
        if (!is_array($state) || !isset($state['start'], $state['count']) || $now - (int)$state['start'] >= $windowSeconds) {
            $state = ['start' => $now, 'count' => 0];
        }
        $allowed = (int)$state['count'] < $maximum;
        if ($allowed) {
            $state['count'] = (int)$state['count'] + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state));
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        return $allowed;
    }
}
