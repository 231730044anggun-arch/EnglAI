<?php
declare(strict_types=1);

namespace EnglAI\Security;

final class RateLimiter
{
    public static function check(string $bucket, int $limit, int $windowSeconds): bool
    {
        $directory = dirname(__DIR__, 2) . '/storage/cache/rate-limits';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }

        $key = hash('sha256', $bucket);
        $path = $directory . '/' . $key . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $raw = stream_get_contents($handle);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            if (!is_array($state) || ($state['reset_at'] ?? 0) <= $now) {
                $state = ['count' => 0, 'reset_at' => $now + $windowSeconds];
            }
            if ((int) $state['count'] >= $limit) {
                return false;
            }
            $state['count']++;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state));
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
