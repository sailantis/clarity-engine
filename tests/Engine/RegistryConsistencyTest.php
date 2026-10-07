<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\Engine\Registry;
use Clarity\Tests\BaseTestCase;

/**
 * Structural invariants tying the three places a filter/function name can be
 * declared together:
 *
 *   1. `Registry::$inlineDefinitions` — codegen records (`php`, params, …)
 *   2. `Registry::$filters`       — the pipeable set (runtime-backed filters)
 *   3. `Registry::$callables`     — the runtime `$__c_fn` table
 *   4. `.phpstorm.meta.php`       — what the editor autocompletes
 *
 * Each of those can drift from the others, and the drift is INVISIBLE to a
 * render-and-assert test because it is not a behaviour, it is a contradiction:
 *
 *   - a name in the pipeable set with neither a `php` template nor a callable
 *     compiles fine and then fatals at runtime with "bool is not callable"
 *     (this was the bare `format` marker);
 *   - a name declared in the meta file but deleted from the engine offers
 *     autocomplete for something that cannot compile (this was `expand`).
 *
 * So these tests enumerate the tables and assert they agree, rather than
 * exercising any single filter.
 */
class RegistryConsistencyTest extends BaseTestCase
{
    /** The meta file lives at the package root, two levels above tests/Engine. */
    private const META_FILE = __DIR__ . '/../../.phpstorm.meta.php';

    /**
     * Filters the COMPILER intercepts before the registry is ever consulted.
     *
     * They are legitimately absent from `$inlineDefinitions` while still being
     * declared in the meta file, so the "meta ⊆ engine" check has to allow them.
     * `raw` only turns off auto-escaping for one output expression — it has no
     * callable and never reaches the runtime table.
     *
     * Keep this list as short as possible: every entry here is a hole in the
     * consistency check.
     *
     * @var list<string>
     */
    private const COMPILER_INTERCEPTED_FILTERS = ['raw'];

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a registry that has every built-in AND every module-registered name,
     * so the checks cover the full surface a user can actually type.
     */
    private function fullRegistry(): Registry
    {
        $engine = new ClarityEngine();
        $engine->addModule(new \Clarity\Localization\TranslationModule());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule());

        $prop = new \ReflectionProperty($engine, 'registry');
        $prop->setAccessible(true);

        return $prop->getValue($engine);
    }

    /**
     * Read a private/protected array property off the registry.
     *
     * @return array<string, mixed>
     */
    private function readPrivate(Registry $registry, string $property): array
    {
        $prop = new \ReflectionProperty($registry, $property);
        $prop->setAccessible(true);

        return $prop->getValue($registry);
    }

    /**
     * Parse the `override(\Clarity::TYPE(0), map([ … ]))` blocks of the meta file.
     *
     * @return array{filter: array<string, int>, function: array<string, int>, directive: array<string, int>}
     *         type → (declared name → occurrence count)
     */
    private function metaDeclarations(): array
    {
        // Strip comments with the tokenizer before matching: the file's own
        // docblock contains an EXAMPLE `override(\Clarity::filter(0), map([...]))`
        // line that a naive regex would treat as a real block and double-count.
        $source = '';
        foreach (\token_get_all((string) file_get_contents(self::META_FILE)) as $token) {
            if (\is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $source .= $token[1];
            } else {
                $source .= $token;
            }
        }

        $declared = ['filter' => [], 'function' => [], 'directive' => []];

        preg_match_all(
            '/override\(\\\\Clarity::(filter|function|directive)\(0\),\s*map\(\[/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $i => $capture) {
            $type  = $matches[1][$i][0];
            $start = $capture[1] + \strlen($capture[0]);
            $end   = \strpos($source, ']));', $start);
            $this->assertIsInt($end, "unterminated map() block for '{$type}' in the meta file");

            $block = \substr($source, $start, $end - $start);
            // Top-level keys are indented exactly 8 spaces; nested keys (params,
            // return, description, …) sit deeper and are ignored.
            preg_match_all("/^        '([A-Za-z_][A-Za-z0-9_]*)'\s*=>/m", $block, $keys);

            foreach ($keys[1] as $key) {
                $declared[$type][$key] = ($declared[$type][$key] ?? 0) + 1;
            }
        }

        return $declared;
    }

    // =========================================================================
    // The registry's tables must agree with each other
    // =========================================================================

    public function testEveryFilterableNameHasAnImplementation(): void
    {
        $registry  = $this->fullRegistry();
        $filters   = $this->readPrivate($registry, 'filters');
        $inline    = $this->readPrivate($registry, 'inlineDefinitions');
        $callables = $this->readPrivate($registry, 'callables');

        // A name declared pipeable must be backed by something: a codegen
        // template (`php`) or a runtime callable. This is the invariant the
        // bare `format` marker violated.
        foreach (\array_keys($filters) as $name) {
            $this->assertTrue(
                isset($inline[$name]['php']) || isset($callables[$name]),
                "'{$name}' is in the pipeable set but has neither a 'php' template nor a runtime callable"
            );
        }
    }

    public function testInlineTemplatesAlwaysCarryCodegen(): void
    {
        // Every record in the inline table must have a `php` template — that is
        // the entire reason for the table's existence. A record without one is
        // inert and would make hasInlineFilter()/getInlineFilter() disagree.
        $registry = $this->fullRegistry();
        $inline   = $this->readPrivate($registry, 'inlineDefinitions');

        foreach ($inline as $name => $definition) {
            $this->assertArrayHasKey(
                'php',
                $definition,
                "'{$name}' is in the inline-filter table but has no 'php' template"
            );
        }
    }

    public function testEveryCallableIsReachable(): void
    {
        // A runtime callable is reachable when it is pipeable ($filters) or
        // callable ($callables is itself that table). The point of the check is
        // the converse shape: a callable that nothing can reach is dead weight.
        $registry  = $this->fullRegistry();
        $callables = $this->readPrivate($registry, 'callables');

        foreach (\array_keys($callables) as $name) {
            $this->assertTrue(
                $registry->hasCallable($name),
                "'{$name}' has a runtime callable but is not reachable by any form"
            );
        }
    }

    /**
     * A CALL-ONLY inline definition must be callable and must NOT be filterable:
     * that pair is the whole point of the `filter => false` flag. A record that
     * answered `hasFilter()` true would be reachable under `|>`, which its author
     * declared meaningless.
     */
    public function testCallOnlyInlineDefinitionsAreCallableNotFilterable(): void
    {
        $registry = $this->fullRegistry();
        $inline   = $this->readPrivate($registry, 'inlineDefinitions');

        foreach ($inline as $name => $definition) {
            if (($definition['filter'] ?? true) !== false) {
                continue;
            }

            $this->assertTrue(
                $registry->hasCallable($name),
                "'{$name}' is a call-only inline definition but is not callable"
            );
            $this->assertFalse(
                $registry->hasFilter($name),
                "'{$name}' is a call-only inline definition but is filterable"
            );
            $this->assertArrayNotHasKey(
                $name,
                $this->readPrivate($registry, 'filters'),
                "'{$name}' is call-only and must not be declared pipeable"
            );
        }
    }

    /**
     * A call-only inline definition has no runtime callable: it compiles inline,
     * so listing it in the runtime table would be dead weight the compiler never
     * consults. `isset` is the only built-in in this shape.
     */
    public function testCallOnlyInlineDefinitionsHaveNoRuntimeCallable(): void
    {
        $registry = $this->fullRegistry();
        $inline   = $this->readPrivate($registry, 'inlineDefinitions');
        $callables = $this->readPrivate($registry, 'callables');

        foreach ($inline as $name => $definition) {
            if (($definition['filter'] ?? true) !== false) {
                continue;
            }

            $this->assertArrayNotHasKey(
                $name,
                $callables,
                "'{$name}' compiles inline and must not need a runtime entry"
            );
        }
    }

    public function testInlineFilterSchemaIsInternallyConsistent(): void
    {
        $registry = $this->fullRegistry();
        $inline   = $this->readPrivate($registry, 'inlineDefinitions');

        foreach ($inline as $name => $definition) {
            $params = $definition['params'] ?? [];

            // Declared defaults must refer to declared parameters.
            foreach (\array_keys($definition['defaults'] ?? []) as $param) {
                $this->assertContains(
                    $param,
                    $params,
                    "'{$name}' declares a default for '{$param}', which is not one of its params"
                );
            }

            // A `valueParam` must be a real parameter.
            if (isset($definition['valueParam'])) {
                $this->assertContains(
                    $definition['valueParam'],
                    $params,
                    "'{$name}' declares valueParam '{$definition['valueParam']}', which is not one of its params"
                );
            }

            $this->assertSame(
                $params,
                \array_values(\array_unique($params)),
                "'{$name}' lists a parameter name more than once"
            );
        }
    }

    // =========================================================================
    // The meta file must not promise names the engine cannot deliver
    // =========================================================================

    public function testMetaDeclaresNoNameTheEngineLacks(): void
    {
        $registry  = $this->fullRegistry();
        $callables = $this->readPrivate($registry, 'callables');
        $declared  = $this->metaDeclarations();

        foreach (\array_keys($declared['filter']) as $name) {
            $this->assertTrue(
                $registry->hasFilter($name) || \in_array($name, self::COMPILER_INTERCEPTED_FILTERS, true),
                "the meta file declares filter '{$name}', but the engine has no such filter"
            );
        }

        foreach (\array_keys($declared['function']) as $name) {
            $this->assertTrue(
                $registry->hasCallable($name),
                "the meta file declares function '{$name}', but the engine has no such callable"
            );
        }

        foreach (\array_keys($declared['directive']) as $name) {
            $this->assertTrue(
                $registry->hasDirective($name),
                "the meta file declares directive '{$name}', but the engine has no such directive"
            );
        }

        // Sanity: the runtime table must contain every callable name the meta
        // file advertises as a FUNCTION (that is where dispatch happens).
        foreach (\array_keys($declared['function']) as $name) {
            $this->assertTrue(
                isset($callables[$name]) || $registry->hasInlineFilter($name),
                "the meta file declares function '{$name}', but nothing dispatches it"
            );
        }
    }

    public function testMetaHasNoDuplicateDeclarations(): void
    {
        $declared = $this->metaDeclarations();

        foreach ($declared as $type => $names) {
            foreach ($names as $name => $count) {
                $this->assertSame(
                    1,
                    $count,
                    "the meta file declares {$type} '{$name}' {$count} times (a later map() wins silently)"
                );
            }
        }
    }

    // =========================================================================
    // The two aliases that must stay aliases
    // =========================================================================

    public function testAliasesShareTheirTargetDefinition(): void
    {
        $registry  = $this->fullRegistry();
        $inline    = $this->readPrivate($registry, 'inlineDefinitions');
        $callables = $this->readPrivate($registry, 'callables');

        // `format` = `sprintf` is an inline-template alias; `len` = `length` is a
        // callable alias. A copied definition can drift, an identical one cannot.
        $this->assertSame($inline['sprintf'], $inline['format'], "'format' must mirror 'sprintf'");
        $this->assertSame($callables['length'], $callables['len'], "'len' must mirror 'length'");
    }

    public function testAliasesAreFilterableThroughTheirOwnName(): void
    {
        $registry = $this->fullRegistry();
        $filters  = $this->readPrivate($registry, 'filters');

        // `len` is runtime-backed (it is not inline), so it must be declared
        // pipeable in its own right — a shared callable is not enough.
        $this->assertArrayHasKey('len', $filters);
        $this->assertTrue($registry->hasFilter('len'));
        $this->assertTrue($registry->hasFilter('format'));
    }
}
