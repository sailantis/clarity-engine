<?php
namespace Clarity\Localization;

/**
 * Translation loader that wraps another loader and caches results in Redis.
 *
 * The `RedisCachingLoader` is a decorator for any `TranslationLoaderInterface`
 * implementation that adds caching via Redis. When translations are loaded for
 * a given domain and locale, the loader first checks Redis for a cached result.
 * If found, it returns the cached translations. If not, it delegates to the
 * inner loader, caches the result in Redis with an optional TTL, and returns it.
 *
 * This can be used to speed up translation loading in production environments
 * where the underlying loader may be slow (e.g. database loaders with many
 * entries or complex queries).
 */
class RedisCachingLoader implements TranslationLoaderInterface
{
    public function __construct(
        private TranslationLoaderInterface $inner,
        private \Redis $redis,
        private int $ttl = 3600
    ) {
    }

    public function load(string $domain, string $locale): array
    {
        $key = "translations:{$domain}:{$locale}";
        $cached = $this->redis->get($key);
        if ($cached !== false) {
            return unserialize($cached);
        }

        $data = $this->inner->load($domain, $locale);
        $this->redis->setex($key, $this->ttl, serialize($data));
        return $data;
    }

    public function invalidate(?string $domain = null, ?string $locale = null): void
    {
        // 1) Specific domain+locale
        if ($domain !== null && $locale !== null) {
            $this->redis->del("translations:{$domain}:{$locale}");
            return;
        }

        // 2) All locales for a specific domain
        if ($domain !== null) {
            $pattern = "translations:{$domain}:*";
            foreach ($this->redis->keys($pattern) as $key) {
                $this->redis->del($key);
            }
            return;
        }

        // 3) All translations
        foreach ($this->redis->keys("translations:*") as $key) {
            $this->redis->del($key);
        }
    }

}
