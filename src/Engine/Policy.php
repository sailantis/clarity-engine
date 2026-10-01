<?php

namespace Clarity\Engine;

use Clarity\ClarityException;

/**
 * What a template is allowed to reach.
 *
 * A policy is a set of CAPABILITIES plus two ALLOWLISTS.  It replaces the
 * single `sandbox` boolean the engine used to carry: one bit could only say
 * "everything Clarity has" or "nothing that touches PHP", so an application
 * that needed one PHP function had to give up every compile-time guarantee.
 *
 * Every decision a policy makes is made at COMPILE TIME.  Nothing here is
 * consulted while a template renders — the render path has no policy object in
 * it at all — which is what keeps the sandbox free of runtime cost.
 *
 * Capabilities
 * ------------
 *   rawPhp             `{% php CODE %}` and `{% php %}…{% endphp %}`
 *   methodCalls        `$obj->method(args)` on a `$`-sigil chain
 *   superglobals       `$_SERVER`, `$_GET`, … as chain roots
 *   phpVariables       the render scope seeded into PHP locals — the thing that
 *                      makes `$title` and `{% php echo $title; %}` the same name
 *   variableVariables  `$$name` / `${expr}` (on by default; see below)
 *   newExpressions     `new Foo(args)`
 *   staticCalls        `Foo::method(args)` / `Foo::CONST`
 *
 * Allowlists
 * ----------
 *   functions  bare calls and filter steps that resolve to a PHP function
 *   filters    names accepted after `|>`
 *
 * The rule for both is the same and is the whole rule:
 *
 *   An EMPTY allowlist means unrestricted.  A NON-EMPTY allowlist means only
 *   the listed names resolve; anything else is a compile-time error.
 *
 * Empty-means-unrestricted is what makes `Policy::open()` the engine's old PHP
 * mode exactly, rather than a mode that happens to deny everything.
 *
 * Why `variableVariables` defaults on
 * -----------------------------------
 * Because denying it achieves nothing.  `$$name` and `${expr}` resolve against
 * the render scope and loop locals in every policy, and the engine's own
 * `__c_`-prefixed frame is protected by binding order rather than by rejecting
 * the syntax — so the form reaches nothing a literal name could not.  It exists
 * as a capability so an application can be explicit about wanting it off.
 */
final class Policy
{
    /** Every capability, in the order the documentation lists them. */
    public const CAPABILITIES = [
        'rawPhp',
        'methodCalls',
        'superglobals',
        'phpVariables',
        'variableVariables',
        'newExpressions',
        'staticCalls',
    ];

    /** The two allowlists, in documentation order. */
    public const ALLOWLISTS = ['functions', 'filters'];

    /** @var array<string, bool> capability name => allowed */
    private array $capabilities;

    /** @var array<string, true> lowercase function name => true; empty = unrestricted */
    private array $functions = [];

    /** @var array<string, true> filter name => true; empty = unrestricted */
    private array $filters = [];

    /**
     * Names denied outright, applied AFTER the allowlists.
     *
     * The allowlists say what is allowed; this says what is not, and it wins.
     * It is the surviving half of the engine's old `setDeniedFunctions()`: an
     * allowlist cannot express "everything except `exec`", and that is a real
     * thing to want for a mode that is otherwise open.
     *
     * @var array<string, true> lowercase function name => true
     */
    private array $denied = [];

    /**
     * @param array<string, bool> $capabilities Complete capability map.
     */
    private function __construct(array $capabilities)
    {
        $this->capabilities = $capabilities;
    }

    // -------------------------------------------------------------------------
    // Presets
    // -------------------------------------------------------------------------

    /**
     * The default: no template reaches PHP.  Identical to the engine's
     * historical sandbox mode, and what a bare `new ClarityEngine()` uses.
     */
    public static function sandboxed(): self
    {
        return new self([
            'rawPhp'            => false,
            'methodCalls'       => false,
            'superglobals'      => false,
            'phpVariables'      => false,
            'variableVariables' => true,
            'newExpressions'    => false,
            'staticCalls'       => false,
        ]);
    }

    /**
     * Everything on, no allowlist: templates have the full power of PHP.
     *
     * This is the engine's former PHP mode (`setSandboxMode(false)`), and it is
     * exactly as dangerous.  Intended for templates written by trusted authors
     * (Blade / Stempler / Plates parity), never for templates a request can
     * choose.
     */
    public static function open(): self
    {
        return new self([
            'rawPhp'            => true,
            'methodCalls'       => true,
            'superglobals'      => true,
            'phpVariables'      => true,
            'variableVariables' => true,
            'newExpressions'    => true,
            'staticCalls'       => true,
        ]);
    }

    /**
     * For templates that are trusted but should not be able to reach around the
     * engine: raw PHP, method calls, superglobals and scope-seeded locals.
     *
     * `newExpressions` and `staticCalls` stay off — constructing an arbitrary
     * class is a different order of trust from calling a method on an object the
     * application already passed in.
     */
    public static function trusted(): self
    {
        return new self([
            'rawPhp'            => true,
            'methodCalls'       => true,
            'superglobals'      => true,
            'phpVariables'      => true,
            'variableVariables' => true,
            'newExpressions'    => false,
            'staticCalls'       => false,
        ]);
    }

    /**
     * Start from {@see sandboxed()} and change what you mean to change.
     *
     * ```
     * Policy::custom()
     *     ->allowCapability('methodCalls')
     *     ->allowFunctions('strtoupper', 'count');
     * ```
     */
    public static function custom(): self
    {
        return self::sandboxed();
    }

    // -------------------------------------------------------------------------
    // Construction from configuration
    // -------------------------------------------------------------------------

    /**
     * Build a policy from a plain array — the form a config file can express.
     *
     * ```
     * Policy::fromArray([
     *     'capabilities' => ['methodCalls' => true],
     *     'functions'    => ['strtoupper', 'count'],
     *     'filters'      => ['markdown'],
     * ]);
     * ```
     *
     * An omitted `capabilities` key starts from {@see sandboxed()}, so a config
     * only has to name what it changes.  Every key is validated; an unknown
     * capability or allowlist is refused rather than ignored, because a policy
     * that silently drops a rule is worse than one that refuses to load.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $policy = self::sandboxed();

        foreach ($data as $key => $value) {
            if ($key === 'capabilities') {
                if (!\is_array($value)) {
                    throw new ClarityException("Policy 'capabilities' must be an array of name => bool.");
                }
                foreach ($value as $name => $allowed) {
                    if (!\in_array($name, self::CAPABILITIES, true)) {
                        throw new ClarityException(
                            "Unknown policy capability '{$name}'. Known capabilities: "
                                . \implode(', ', self::CAPABILITIES) . '.'
                        );
                    }
                    $policy->capabilities[$name] = (bool) $allowed;
                }
                continue;
            }

            if ($key === 'functions' || $key === 'filters' || $key === 'deniedFunctions') {
                if (!\is_array($value)) {
                    throw new ClarityException("Policy '{$key}' must be a list of names.");
                }
                $names = [];
                foreach ($value as $name) {
                    if (!\is_string($name) || \trim($name) === '') {
                        throw new ClarityException("Policy '{$key}' accepts non-empty strings only.");
                    }
                    $names[] = $name;
                }
                match ($key) {
                    'functions'       => $policy->allowFunctions(...$names),
                    'filters'         => $policy->allowFilters(...$names),
                    'deniedFunctions' => $policy->denyFunctions(...$names)
                };
                continue;
            }

            throw new ClarityException(
                "Unknown policy key '{$key}'. Known keys: "
                    . \implode(', ', \array_merge(['capabilities'], self::ALLOWLISTS, ['deniedFunctions'])) . '.'
            );
        }

        return $policy;
    }

    /**
     * The array form of this policy.  Round-trips through {@see fromArray()}.
     *
     * @return array{capabilities: array<string, bool>, functions: list<string>, filters: list<string>, deniedFunctions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'capabilities'    => $this->capabilities,
            'functions'       => \array_keys($this->functions),
            'filters'         => \array_keys($this->filters),
            'deniedFunctions' => \array_keys($this->denied),
        ];
    }

    /**
     * A {@see self} from either form, so a config key can take both.
     *
     * @param self|array<string, mixed> $value
     */
    public static function fromUserValue(self|array $value): self
    {
        return $value instanceof self ? $value : self::fromArray($value);
    }

    // -------------------------------------------------------------------------
    // Capabilities
    // -------------------------------------------------------------------------

    public function allows(string $capability): bool
    {
        if (!\array_key_exists($capability, $this->capabilities)) {
            throw new ClarityException(
                "Unknown policy capability '{$capability}'. Known capabilities: "
                    . \implode(', ', self::CAPABILITIES) . '.'
            );
        }
        return $this->capabilities[$capability];
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Turn capabilities on.  Accepts more than one so a grant reads as a list.
     *
     * @param string ...$capabilities
     */
    public function allowCapability(string ...$capabilities): self
    {
        foreach ($capabilities as $capability) {
            $this->allows($capability); // validates the name
            $this->capabilities[$capability] = true;
        }
        return $this;
    }

    /**
     * Turn capabilities off.  Accepts more than one so a denial reads as a list.
     *
     * @param string ...$capabilities
     */
    public function denyCapability(string ...$capabilities): self
    {
        foreach ($capabilities as $capability) {
            $this->allows($capability); // validates the name
            $this->capabilities[$capability] = false;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Allowlists
    // -------------------------------------------------------------------------

    /**
     * Permit PHP functions to be called by their own name, without registering
     * them.  A non-empty list becomes the complete set that may be called.
     *
     * Distinct from `addFunction()`, which registers a CALLABLE under a name:
     * a name that is registered and allowed stays the registered callable, a name
     * that is allowed and not registered calls the PHP function of that name.
     */
    public function allowFunctions(string ...$names): self
    {
        foreach ($names as $name) {
            $this->functions[self::normalizeName($name)] = true;
        }
        return $this;
    }

    /**
     * Permit names after `|>`.  A non-empty list becomes the complete set.
     *
     * Registered filters are always usable and are not part of this list; the
     * list is about the PHP-function fallback.
     */
    public function allowFilters(string ...$names): self
    {
        foreach ($names as $name) {
            $this->filters[self::normalizeName($name)] = true;
        }
        return $this;
    }

    /**
     * Whether the function allowlist restricts anything at all.
     *
     * An EMPTY allowlist is not "nothing allowed" — it is "no filter applied", so
     * the mode decides.  See the class docblock.
     */
    public function restrictsFunctions(): bool
    {
        return $this->functions !== [];
    }

    /** Whether the filter allowlist restricts anything at all. */
    public function restrictsFilters(): bool
    {
        return $this->filters !== [];
    }

    /**
     * Whether a PHP function may be called by name under this policy.
     *
     * Only meaningful where a PHP function is reachable at all; the caller
     * decides that from the capabilities.  Names are case-insensitive and a
     * leading namespace separator is ignored, because PHP's are.
     */
    public function allowsFunction(string $name): bool
    {
        return !$this->restrictsFunctions()
            || isset($this->functions[self::normalizeName($name)]);
    }

    /** Whether a name may be used as a `|>` filter step under this policy. */
    public function allowsFilter(string $name): bool
    {
        return !$this->restrictsFilters()
            || isset($this->filters[self::normalizeName($name)]);
    }

    /** @return list<string> */
    public function allowedFunctions(): array
    {
        return \array_keys($this->functions);
    }

    /** @return list<string> */
    public function allowedFilters(): array
    {
        return \array_keys($this->filters);
    }

    /**
     * Refuse names outright, whatever the allowlists say.
     *
     * Applied last, so a name that is both allowlisted and denied is denied. The
     * denial is the more specific statement, and a policy that allows and denies
     * the same name is a mistake worth resolving in favour of the safer reading.
     */
    public function denyFunctions(string ...$names): self
    {
        foreach ($names as $name) {
            $this->denied[self::normalizeName($name)] = true;
        }
        return $this;
    }

    /** Whether this policy denies a function name outright. */
    public function deniesFunction(string $name): bool
    {
        return isset($this->denied[self::normalizeName($name)]);
    }

    /** @return list<string> */
    public function deniedFunctions(): array
    {
        return \array_keys($this->denied);
    }

    // -------------------------------------------------------------------------
    // Coarse questions
    // -------------------------------------------------------------------------

    /**
     * True when every capability is on and neither allowlist restricts anything:
     * the engine's former PHP mode.
     */
    public function isOpen(): bool
    {
        foreach ($this->capabilities as $allowed) {
            if (!$allowed) {
                return false;
            }
        }
        return !$this->restrictsFunctions()
            && !$this->restrictsFilters()
            && $this->denied === [];
    }

    /**
     * True when this template may reach PHP at all — the gate for the two things
     * that are not a named capability because they ARE "PHP is reachable": a bare
     * call to an unregistered name, and a filter step falling back to a PHP
     * function.
     *
     * A NON-EMPTY allowlist counts as reachable too, because listing names is the
     * explicit statement "these PHP functions may be used" — otherwise
     * `Policy::sandboxed()->allowFunctions('count')` would be a grant that grants
     * nothing, which is the opposite of what it says.
     *
     * `variableVariables` is deliberately not part of this.  It decides a syntax
     * the engine resolves against its own scope, so turning it off does not make
     * a template any less able to run PHP.
     */
    public function allowsPhp(): bool
    {
        if ($this->restrictsFunctions() || $this->restrictsFilters()) {
            return true;
        }

        foreach ([
            'rawPhp',
            'methodCalls',
            'superglobals',
            'phpVariables',
            'newExpressions',
            'staticCalls',
        ] as $capability) {
            if ($this->capabilities[$capability]) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when PHP is unreachable in every direction the engine has: the
     * engine's former sandbox mode, and its default.
     *
     * Answers the old `isSandboxed()` question from the policy, so the state has
     * one source of truth rather than a second flag that could disagree.
     */
    public function isSandboxed(): bool
    {
        return !$this->allowsPhp();
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    /**
     * A stable fingerprint of the effective policy.
     *
     * The compiled template records this and the loader recompiles on a
     * mismatch, which is what makes changing a policy safe — a class compiled
     * under one policy must never be served under another, and the cache keys on
     * template source only.
     *
     * A DIGEST, not the policy: the compiled file is source code that ships to a
     * server and should not carry a readable inventory of what a template may
     * call.  It covers the FULL identity — every capability and every allowlist
     * entry, sorted, with lengths — so two policies that differ in their last
     * entry cannot collide.
     */
    public function digest(): string
    {
        $capabilities = [];
        foreach ($this->capabilities as $name => $allowed) {
            $capabilities[] = $name . "\x01" . ($allowed ? '1' : '0');
        }
        \sort($capabilities);

        $functions = \array_keys($this->functions);
        $filters   = \array_keys($this->filters);
        $denied    = \array_keys($this->denied);
        \sort($functions);
        \sort($filters);
        \sort($denied);

        $canonical = \implode("\x1E", $capabilities)
            . "\x1F" . \implode("\x1E", $functions)
            . "\x1F" . \implode("\x1E", $filters)
            . "\x1F" . \implode("\x1E", $denied);

        return \hash('fnv1a64', $canonical);
    }

    private static function normalizeName(string $name): string
    {
        return \strtolower(\ltrim(\trim($name), '\\'));
    }
}
