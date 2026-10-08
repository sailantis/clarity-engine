<?php
/**
 * The test suite bootstrap.
 *
 * Composer's autoloader, plus the global-namespace `\Redis` double used by the
 * `RedisCachingLoader` tests (see `tests/RedisStub.php`). The stub is required
 * explicitly because a global-namespace class is outside the `Clarity\Tests\`
 * PSR-4 prefix and so cannot be autoloaded.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/RedisStub.php';
