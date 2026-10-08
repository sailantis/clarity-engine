<?php
/**
 * A minimal `\Redis` stand-in for testing `RedisCachingLoader`.
 *
 * `RedisCachingLoader` type-hints the concrete `\Redis` extension class, so its
 * tests cannot run without that extension — and CI installs only `mbstring` and
 * `intl`. This double implements the commands the loader uses, so the decorator's
 * own logic (key naming, value serialization, TTL, and the three invalidation
 * granularities) is exercised everywhere rather than never.
 *
 * It is included from `tests/bootstrap.php` because a global-namespace class is
 * outside the `Clarity\Tests\` PSR-4 prefix and so cannot be autoloaded. If the
 * real extension is loaded, this file does nothing and instead marks
 * `CLARITY_REDIS_STUB` undefined — the tests that need introspection then skip,
 * rather than silently driving a real client.
 */

declare(strict_types=1);

if (!class_exists('Redis', false)) {
    define('CLARITY_REDIS_STUB', true);

    final class Redis
    {
        /** @var array<string, string> */
        public array $store = [];

        /** Every command issued, in order — for asserting the access pattern. */
        public array $log = [];

        public function get(string $key): string|false
        {
            $this->log[] = "GET {$key}";

            return $this->store[$key] ?? false;
        }

        public function setex(string $key, int $ttl, string $value): bool
        {
            $this->log[] = "SETEX {$key} {$ttl}";
            $this->store[$key] = $value;

            return true;
        }

        public function del(string $key): int
        {
            $this->log[] = "DEL {$key}";
            $existed = isset($this->store[$key]);
            unset($this->store[$key]);

            return $existed ? 1 : 0;
        }

        /**
         * Redis `KEYS` is a glob match, not a regex.
         *
         * @return list<string>
         */
        public function keys(string $pattern): array
        {
            $this->log[] = "KEYS {$pattern}";

            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

            return array_values(array_filter(
                array_keys($this->store),
                static fn (string $key): bool => (bool) preg_match($regex, $key)
            ));
        }
    }
}
