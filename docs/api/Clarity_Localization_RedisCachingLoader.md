# 🧩 Class: RedisCachingLoader

**Full name:** [Clarity\Localization\RedisCachingLoader](../../src/Localization/RedisCachingLoader.php)

Translation loader that wraps another loader and caches results in Redis.

The `RedisCachingLoader` is a decorator for any `TranslationLoaderInterface`
implementation that adds caching via Redis. When translations are loaded for
a given domain and locale, the loader first checks Redis for a cached result.
If found, it returns the cached translations. If not, it delegates to the
inner loader, caches the result in Redis with an optional TTL, and returns it.

This can be used to speed up translation loading in production environments
where the underlying loader may be slow (e.g. database loaders with many
entries or complex queries).

## 🚀 Public methods

### __construct() · [source](../../src/Localization/RedisCachingLoader.php#L19)

`public function __construct(Clarity\Localization\TranslationLoaderInterface $inner, Redis $redis, int $ttl = 3600): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$inner` | [TranslationLoaderInterface](Clarity_Localization_TranslationLoaderInterface.md) | - |  |
| `$redis` | Redis | - |  |
| `$ttl` | int | `3600` |  |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Localization/RedisCachingLoader.php#L26)

`public function load(string $domain, string $locale): array`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**➡️ Return value**

- Type: array


---

### invalidate() · [source](../../src/Localization/RedisCachingLoader.php#L39)

`public function invalidate(string|null $domain = null, string|null $locale = null): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
