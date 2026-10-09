# Class: RedisCachingLoader

**Full name:** [Clarity\Localization\RedisCachingLoader](../../src/Localization/RedisCachingLoader.php)

Translation loader that wraps another loader and caches results in Redis.

The `RedisCachingLoader` is a decorator for any `TranslationLoaderInterface`
implementation that adds caching via Redis. When translations are loaded for
a given domain and locale, the loader first checks Redis for a cached result.
If found, it returns the cached translations. If not, it delegates to the
inner loader, caches the result in Redis for `$ttl` seconds (default 3600), and
returns it. Empty results are cached too.

This can be used to speed up translation loading in production environments
where the underlying loader may be slow (e.g. database loaders with many
entries or complex queries).

Keys are `{$keyPrefix}:{domain}:{locale}`. When several applications share one
Redis instance, give each its own prefix.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/RedisCachingLoader.php#L27)</small>

`public function __construct(Clarity\Localization\TranslationLoaderInterface $inner, Redis $redis, int $ttl = 3600, string $keyPrefix = 'translations'): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$inner` | [TranslationLoaderInterface](Clarity_Localization_TranslationLoaderInterface.md) | - |  |
| `$redis` | Redis | - |  |
| `$ttl` | int | `3600` | Seconds a cached catalogue stays in Redis. |
| `$keyPrefix` | string | `'translations'` | Prefix for every key this loader writes or deletes. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Localization/RedisCachingLoader.php#L35)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`


---

### invalidate() · <small>[🗎](../../src/Localization/RedisCachingLoader.php#L55)</small>

`public function invalidate(string|null $domain = null, string|null $locale = null): void`

Delete cached catalogues from Redis.

- Domain and locale given: deletes that one catalogue.
- Domain given without a locale: deletes every locale of that domain.
- No domain given: deletes all cached catalogues. A locale without a domain is ignored.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
