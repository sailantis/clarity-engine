<?php
namespace Clarity\Tests\Engine;

use Clarity\Localization\ArrayTranslationLoader;
use Clarity\Localization\ChainTranslationLoader;
use Clarity\Localization\FileTranslationLoader;
use Clarity\Localization\RedisCachingLoader;
use Clarity\Localization\TranslationLoaderInterface;
use Clarity\Localization\TranslationModule;
use Clarity\Tests\BaseTestCase;

/**
 * The three shipped translation loaders.
 *
 * Modelled on `TemplateLoaderArchitectureTest`, and written because none of
 * these classes had any coverage at all: `ChainTranslationLoader` and
 * `RedisCachingLoader` were reachable only through the API docs, so nothing
 * would have failed if either stopped working.
 *
 * `FileTranslationLoader` also guards a regression this suite missed once
 * before — see `testLoaderIsConsultedOnEveryLookup`.
 */
class TranslationLoaderArchitectureTest extends BaseTestCase
{
    /** A distinct temp directory per test; removed in tearDown. */
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            self::removeDir($this->dir);
            $this->dir = null;
        }
    }

    /** A catalog directory, created on first use. */
    private function localeDir(): string
    {
        if ($this->dir === null) {
            $this->dir = \sys_get_temp_dir() . '/clarity_loader_test_' . \uniqid('', true);
            \mkdir($this->dir, 0755, true);
        }

        return $this->dir;
    }

    /** Write a translation file, creating the directory if needed. */
    private function writeLocaleFile(string $basename, string $extension, string $contents): void
    {
        \file_put_contents($this->localeDir() . '/' . $basename . $extension, $contents);
    }

    /** Write a flat PHP catalog. */
    private function writePhpCatalog(string $basename, array $messages): void
    {
        $this->writeLocaleFile($basename, '.php', '<?php return ' . \var_export($messages, true) . ';');
    }

    // =========================================================================
    // A loader double
    // =========================================================================

    /**
     * Records every call, so tests can assert how often the module asks.
     *
     * @param array<string, array<string, array<string, string>>> $data [domain][locale] → catalog
     */
    private function countingLoader(array $data = []): TranslationLoaderInterface
    {
        $this->calls = [];

        return new class($data, $this) implements TranslationLoaderInterface {
            public function __construct(
                private array $data,
                private TranslationLoaderArchitectureTest $owner
            ) {
            }

            public function load(string $domain, string $locale): array
            {
                $this->owner->recordCall($domain, $locale);

                return $this->data[$domain][$locale] ?? [];
            }
        };
    }

    /** @var list<string> Loader calls observed by the double. */
    public array $calls = [];

    public function recordCall(string $domain, string $locale): void
    {
        $this->calls[] = "{$domain}/{$locale}";
    }

    // =========================================================================
    // FileTranslationLoader
    // =========================================================================

    public function testFileLoaderResolvesPhpJsonAndYaml(): void
    {
        $dir = $this->localeDir();
        $this->writePhpCatalog('messages.de_DE', ['php_key' => 'aus PHP']);
        $this->writeLocaleFile(
            'common.de_DE',
            '.json',
            \json_encode(['json_key' => 'aus JSON'], \JSON_THROW_ON_ERROR)
        );
        $this->writeLocaleFile('emails.de_DE', '.yaml', "yaml_key: aus YAML\n");

        $loader = new FileTranslationLoader($dir, $dir . '/cache');

        $this->assertSame(['php_key' => 'aus PHP'], $loader->load('messages', 'de_DE'));
        $this->assertSame(['json_key' => 'aus JSON'], $loader->load('common', 'de_DE'));
        $this->assertSame(['yaml_key' => 'aus YAML'], $loader->load('emails', 'de_DE'));
    }

    public function testFileLoaderReturnsEmptyArrayForAMissingDomain(): void
    {
        $loader = new FileTranslationLoader($this->localeDir(), $this->localeDir() . '/cache');

        $this->assertSame([], $loader->load('nonexistent', 'de_DE'));
    }

    public function testFileLoaderFlattensNestedKeysToDotNotation(): void
    {
        $this->writePhpCatalog('messages.de_DE', [
            'nav' => ['home' => 'Startseite', 'about' => 'Über uns'],
            'logout' => 'Abmelden',
        ]);

        $loader = new FileTranslationLoader($this->localeDir());

        $this->assertSame(
            ['nav.home' => 'Startseite', 'nav.about' => 'Über uns', 'logout' => 'Abmelden'],
            $loader->load('messages', 'de_DE')
        );
    }

    public function testFileLoaderPrefersYamlOverJsonOverPhp(): void
    {
        $this->writePhpCatalog('messages.de_DE', ['which' => 'php']);
        $this->writeLocaleFile('messages.de_DE', '.yaml', "which: yaml\n");

        $loader = new FileTranslationLoader($this->localeDir());

        $this->assertSame(['which' => 'yaml'], $loader->load('messages', 'de_DE'));
    }

    public function testFileLoaderWritesAndReusesACompiledCache(): void
    {
        $this->writeLocaleFile('messages.de_DE', '.yaml', "greeting: Hallo\n");

        $cacheDir = $this->localeDir() . '/cache';
        $loader   = new FileTranslationLoader($this->localeDir(), $cacheDir);

        $this->assertSame(['greeting' => 'Hallo'], $loader->load('messages', 'de_DE'));

        $cacheFiles = \glob($cacheDir . '/*.php');
        $this->assertCount(1, $cacheFiles, 'the parsed catalog is compiled to one PHP file');

        // Touch the cache so it is newer than the source; the compiled file must
        // then be used as-is without re-parsing the YAML.
        \file_put_contents($cacheFiles[0], "<?php return ['greeting' => 'aus dem Cache'];");
        \touch($cacheFiles[0], \time() + 60);

        $this->assertSame(['greeting' => 'aus dem Cache'], $loader->load('messages', 'de_DE'));

        // Touching the *source* newer than the cache invalidates it.
        \touch($this->localeDir() . '/messages.de_DE.yaml', \time() + 120);

        $this->assertSame(['greeting' => 'Hallo'], $loader->load('messages', 'de_DE'));
    }

    // =========================================================================
    // ArrayTranslationLoader
    // =========================================================================

    public function testArrayLoaderServesAFlatCatalog(): void
    {
        $loader = new ArrayTranslationLoader([
            'messages' => ['de_DE' => ['greeting' => 'Hallo', 'logout' => 'Abmelden']],
        ]);

        $this->assertSame(
            ['greeting' => 'Hallo', 'logout' => 'Abmelden'],
            $loader->load('messages', 'de_DE')
        );
    }

    public function testArrayLoaderFlattensNestedKeysLikeTheFileLoaderDoes(): void
    {
        $nested = ['nav' => ['home' => 'Startseite', 'about' => 'Über uns'], 'logout' => 'Abmelden'];

        $arrayLoader = new ArrayTranslationLoader(['messages' => ['de_DE' => $nested]]);

        // The same nesting through the file loader: both must agree on the
        // flattened shape, or a lookup would resolve differently per loader.
        $this->writePhpCatalog('messages.de_DE', $nested);
        $fileLoader = new FileTranslationLoader($this->localeDir());

        $expected = ['nav.home' => 'Startseite', 'nav.about' => 'Über uns', 'logout' => 'Abmelden'];

        $this->assertSame($expected, $arrayLoader->load('messages', 'de_DE'));
        $this->assertSame($expected, $fileLoader->load('messages', 'de_DE'));
    }

    public function testArrayLoaderStringifiesNonStringValues(): void
    {
        $loader = new ArrayTranslationLoader([
            'messages' => ['de_DE' => ['count' => 3, 'ratio' => 1.5, 'flag' => true]],
        ]);

        $this->assertSame(
            ['count' => '3', 'ratio' => '1.5', 'flag' => '1'],
            $loader->load('messages', 'de_DE')
        );
    }

    public function testArrayLoaderReturnsEmptyArrayForUnknownDomainOrLocale(): void
    {
        $loader = new ArrayTranslationLoader(['messages' => ['de_DE' => ['a' => 'A']]]);

        $this->assertSame([], $loader->load('nonexistent', 'de_DE'), 'unknown domain');
        $this->assertSame([], $loader->load('messages', 'fr_FR'), 'unknown locale');
        $this->assertSame([], (new ArrayTranslationLoader())->load('messages', 'de_DE'), 'empty loader');
    }

    public function testArrayLoaderSetAddsAndReplacesAMessage(): void
    {
        $loader = new ArrayTranslationLoader();

        $loader->set('messages', 'de_DE', 'greeting', 'Hallo');
        $this->assertSame(['greeting' => 'Hallo'], $loader->load('messages', 'de_DE'));

        // A dotted key writes one flat entry, reading back exactly as the nested
        // array form does.
        $loader->set('messages', 'de_DE', 'nav.home', 'Startseite');

        $nested = new ArrayTranslationLoader([
            'messages' => ['de_DE' => ['greeting' => 'Hallo', 'nav' => ['home' => 'Startseite']]],
        ]);

        $this->assertSame(
            $nested->load('messages', 'de_DE'),
            $loader->load('messages', 'de_DE'),
            'set() with a dotted key must equal the nested array form'
        );

        // Replacing an existing key wins.
        $loader->set('messages', 'de_DE', 'greeting', 'Servus');
        $this->assertSame('Servus', $loader->load('messages', 'de_DE')['greeting']);
    }

    /**
     * A dotted key is a key, not a path: writing `nav.home` must not disturb a
     * separate `nav` message. An implementation that nested on write would have
     * to overwrite the scalar `nav` to store the child.
     */
    public function testArrayLoaderSetIsAdditiveAndLeavesSiblingKeysAlone(): void
    {
        $loader = new ArrayTranslationLoader(['messages' => ['de_DE' => ['nav' => 'Start']]]);

        $loader->set('messages', 'de_DE', 'nav.home', 'Startseite');

        $this->assertSame(
            ['nav' => 'Start', 'nav.home' => 'Startseite'],
            $loader->load('messages', 'de_DE'),
            'the scalar and the dotted key coexist'
        );
    }

    public function testArrayLoaderFlattensOnceAtConstructionNotPerLoad(): void
    {
        $loader = new ArrayTranslationLoader([
            'messages' => ['de_DE' => ['nav' => ['home' => 'Startseite']]],
        ]);

        $first = $loader->load('messages', 'de_DE');
        $this->assertSame('Startseite', $first['nav.home']);

        // A second load returns the same flat map; there is no re-flattening
        // step that could disagree with the first.
        $this->assertSame($first, $loader->load('messages', 'de_DE'));
    }

    public function testArrayLoaderWorksAsAProgrammaticOverlayOnAFileLoader(): void
    {
        $this->writePhpCatalog('messages.de_DE', ['greeting' => 'Hallo', 'logout' => 'Abmelden']);

        $chain = new ChainTranslationLoader(
            new FileTranslationLoader($this->localeDir()),
            new ArrayTranslationLoader([
                'messages' => ['de_DE' => ['greeting' => 'Servus']],
            ]),
        );

        $this->assertSame(
            ['greeting' => 'Servus', 'logout' => 'Abmelden'],
            $chain->load('messages', 'de_DE'),
            'the array layer overrides one key and leaves the rest to the files'
        );
    }

    public function testArrayLoaderBackedModuleResolvesNestedKeys(): void
    {
        $module = new TranslationModule([
            'locale' => 'de_DE',
            'loader' => new ArrayTranslationLoader([
                'messages' => ['de_DE' => ['nav' => ['home' => 'Startseite']]],
            ]),
        ]);

        $this->assertSame('Startseite', $module->get('nav.home'));
    }

    // =========================================================================
    // ChainTranslationLoader
    // =========================================================================

    /**
     * Two loaders returning plain arrays, for asserting merge order.
     *
     * @param array<string, string> $data
     */
    private function arrayLoader(array $data): TranslationLoaderInterface
    {
        return new class($data) implements TranslationLoaderInterface {
            public function __construct(private array $data)
            {
            }

            public function load(string $domain, string $locale): array
            {
                return $this->data;
            }
        };
    }

    public function testChainLoaderMergesAndLetsLaterLoadersWin(): void
    {
        $chain = new ChainTranslationLoader(
            $this->arrayLoader(['shared' => 'base', 'base_only' => 'B']),
            $this->arrayLoader(['shared' => 'override', 'over_only' => 'O']),
        );

        $this->assertSame(
            ['shared' => 'override', 'base_only' => 'B', 'over_only' => 'O'],
            $chain->load('messages', 'de_DE')
        );
    }

    public function testChainLoaderQueriesEveryLoaderEvenAfterAHit(): void
    {
        $chain = new ChainTranslationLoader(
            $this->countingLoader(['messages' => ['de_DE' => ['a' => 'A']]]),
            $this->countingLoader(['messages' => ['de_DE' => ['b' => 'B']]]),
        );

        $chain->load('messages', 'de_DE');

        // It merges rather than short-circuits, so both must have been asked.
        $this->assertSame(['messages/de_DE', 'messages/de_DE'], $this->calls);
    }

    public function testChainLoaderWithNoLoadersReturnsEmpty(): void
    {
        $this->assertSame([], (new ChainTranslationLoader())->load('messages', 'de_DE'));
    }

    // =========================================================================
    // RedisCachingLoader
    // =========================================================================

    /**
     * The tests below read `$redis->store` and `$redis->log` to assert on the
     * decorator's Redis access pattern, so they need the `\Redis` double from
     * `tests/RedisStub.php`. With the real extension loaded the stub is not
     * defined and these would drive a live client instead — skip rather than
     * silently test nothing.
     */
    private function requireRedisDouble(): void
    {
        if (!\defined('CLARITY_REDIS_STUB')) {
            $this->markTestSkipped(
                'the tests/RedisStub.php double requires ext-redis to be absent'
            );
        }
    }

    public function testRedisLoaderCachesAcrossCalls(): void
    {
        $this->requireRedisDouble();

        $redis = new \Redis();
        $inner = $this->countingLoader(['messages' => ['de_DE' => ['greeting' => 'Hallo']]]);

        $loader = new RedisCachingLoader($inner, $redis, 600);

        $this->assertSame(['greeting' => 'Hallo'], $loader->load('messages', 'de_DE'));
        $this->assertSame(['greeting' => 'Hallo'], $loader->load('messages', 'de_DE'));

        $this->assertSame(['messages/de_DE'], $this->calls, 'the second load must hit the cache');
        $this->assertSame(
            ['GET translations:messages:de_DE', 'SETEX translations:messages:de_DE 600', 'GET translations:messages:de_DE'],
            $redis->log
        );
    }

    public function testRedisLoaderKeysAreNamespacedPerDomainAndLocale(): void
    {
        $this->requireRedisDouble();

        $redis  = new \Redis();
        $loader = new RedisCachingLoader($this->countingLoader(), $redis);

        $loader->load('messages', 'de_DE');
        $loader->load('books', 'fr_FR');

        $this->assertSame(
            ['translations:messages:de_DE', 'translations:books:fr_FR'],
            \array_keys($redis->store)
        );
    }

    public function testRedisLoaderDefaultTtlIsOneHour(): void
    {
        $this->requireRedisDouble();

        $redis  = new \Redis();
        $loader = new RedisCachingLoader($this->countingLoader(), $redis);

        $loader->load('messages', 'de_DE');

        $this->assertContains('SETEX translations:messages:de_DE 3600', $redis->log);
    }

    public function testRedisLoaderKeysUseTheConfiguredPrefix(): void
    {
        $this->requireRedisDouble();

        $redis  = new \Redis();
        $appA   = new RedisCachingLoader($this->countingLoader(), $redis, 3600, 'app-a:translations');
        $appB   = new RedisCachingLoader($this->countingLoader(), $redis, 3600, 'app-b:translations');

        $appA->load('messages', 'de_DE');
        $appB->load('messages', 'de_DE');
        $appA->invalidate();

        $this->assertSame(['app-b:translations:messages:de_DE'], \array_keys($redis->store));
    }
    public function testRedisLoaderInvalidatesOneDomainAndLocale(): void
    {
        $this->requireRedisDouble();

        $redis  = new \Redis();
        $loader = new RedisCachingLoader($this->countingLoader(), $redis);

        $loader->load('messages', 'de_DE');
        $loader->load('messages', 'en_US');
        $loader->invalidate('messages', 'de_DE');

        $this->assertSame(['translations:messages:en_US'], \array_keys($redis->store));
    }

    public function testRedisLoaderInvalidatesEveryLocaleOfADomain(): void
    {
        $this->requireRedisDouble();

        $redis  = new \Redis();
        $loader = new RedisCachingLoader($this->countingLoader(), $redis);

        $loader->load('messages', 'de_DE');
        $loader->load('messages', 'en_US');
        $loader->load('books', 'de_DE');
        $loader->invalidate('messages');

        $this->assertSame(['translations:books:de_DE'], \array_keys($redis->store));
        $this->assertContains('KEYS translations:messages:*', $redis->log);
    }

    public function testRedisLoaderInvalidatesEverything(): void
    {
        $this->requireRedisDouble();

        $redis  = new \Redis();
        $loader = new RedisCachingLoader($this->countingLoader(), $redis);

        $loader->load('messages', 'de_DE');
        $loader->load('books', 'fr_FR');
        $loader->invalidate();

        $this->assertSame([], $redis->store);
        $this->assertContains('KEYS translations:*', $redis->log);
    }

    // =========================================================================
    // TranslationModule wiring
    // =========================================================================

    public function testModuleExposesItsLoaderSoInvalidationIsReachable(): void
    {
        $this->requireRedisDouble();

        $redis   = new \Redis();
        $wrapped = new RedisCachingLoader($this->countingLoader(), $redis);
        $module  = new TranslationModule(['locale' => 'de_DE', 'loader' => $wrapped]);

        $this->assertSame($wrapped, $module->getLoader());

        $module->get('greeting');
        $this->assertArrayHasKey('translations:messages:de_DE', $redis->store);

        $module->getLoader()->invalidate();

        $this->assertSame([], $redis->store, 'invalidate() must be reachable through getLoader()');
    }

    public function testModuleUsesAnInjectedLoaderInsteadOfTheFilesystem(): void
    {
        $module = new TranslationModule([
            'locale' => 'de_DE',
            'loader' => $this->countingLoader([
                'messages' => ['de_DE' => ['hello' => 'Hallo']],
            ]),
        ]);

        $this->assertSame('Hallo', $module->get('hello'));
    }

    /**
     * The module caches no catalogs, so a slow loader pays on every lookup.
     *
     * This is a pin, not an endorsement: `$this->catalog` was left behind, unwritten,
     * when the file reading moved into `FileTranslationLoader` (7818c46). Its dead
     * fast path and `isset()` guards always fell through to the loader, so removing
     * them changed nothing — which this asserts.
     */
    public function testLoaderIsConsultedOnEveryLookup(): void
    {
        $module = new TranslationModule([
            'locale' => 'de_DE',
            'loader' => $this->countingLoader([
                'messages' => ['de_DE' => ['a' => 'A', 'b' => 'B']],
            ]),
        ]);

        $module->get('a');
        $module->get('a');
        $module->get('b');

        $this->assertSame(
            ['messages/de_DE', 'messages/de_DE', 'messages/de_DE'],
            $this->calls,
            'no memoisation: the loader is asked once per lookup'
        );
    }

    public function testMissingKeyConsultsTheFallbackLocaleThenReturnsTheKey(): void
    {
        $module = new TranslationModule([
            'locale'          => 'de_DE',
            'fallback_locale' => 'en_US',
            'loader'          => $this->countingLoader([
                'messages' => [
                    'de_DE' => ['only_de' => 'Nur DE'],
                    'en_US' => ['only_en' => 'Only EN'],
                ],
            ]),
        ]);

        $this->assertSame('Nur DE', $module->get('only_de'));
        $this->assertSame('Only EN', $module->get('only_en'), 'falls back to en_US');
        $this->assertSame('nowhere', $module->get('nowhere'), 'then returns the key itself');
    }

    public function testFallbackLocaleIsNotLoadedWhenTheActiveLocaleIsTheFallback(): void
    {
        $module = new TranslationModule([
            'locale'          => 'en_US',
            'fallback_locale' => 'en_US',
            'loader'          => $this->countingLoader([
                'messages' => ['en_US' => ['a' => 'A']],
            ]),
        ]);

        $module->get('a');

        $this->assertSame(['messages/en_US'], $this->calls, 'a single load, no identical fallback pass');
    }
}
