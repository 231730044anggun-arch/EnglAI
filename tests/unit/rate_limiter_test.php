<?php
declare(strict_types=1);

use EnglAI\Security\RateLimiter;

$bucket = 'unit-test-' . bin2hex(random_bytes(8));
check(RateLimiter::check($bucket, 2, 60), 'Rate limiter request pertama harus diterima.');
check(RateLimiter::check($bucket, 2, 60), 'Rate limiter request kedua harus diterima.');
check(!RateLimiter::check($bucket, 2, 60), 'Rate limiter harus menolak request di atas limit.');
