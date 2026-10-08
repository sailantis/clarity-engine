<?php

namespace Clarity\Engine;

use Clarity\ClarityException;

/**
 * What a template is allowed to reach.
 *
 * A policy is a set of RULES plus two ALLOWLISTS.  It replaces the
 * single `sandbox` boolean the engine used to carry: one bit could only say
 * "everything Clarity has" or "nothing that touches PHP", so an application
 * that needed one PHP function had to give up every compile-time guarantee.
 *
 * Every decision a policy makes is made at COMPILE TIME.  Nothing here is
 * consulted while a template renders — the render path has no policy object in
 * it at all — which is what keeps the sandbox free of runtime cost.
 *
 * Rules
 * -----
 *   phpFunctions       bare calls (`strtoupper(name)`) and filter steps that
 *                      resolve to a PHP function
 *   rawPhp             `{% php CODE %}`
 *   methodCalls        `$obj->method(args)` on a `$`-sigil chain
 *   superglobals       `$_SERVER`, `$_GET`, … as chain roots
 *   phpVariables       the render scope seeded into PHP locals — the thing that
 *                      makes `$title` and `{% php echo $title; %}` the same name
 *   variableVariables  `$$name` / `${expr}` (on by default; see below)
 *   newExpressions     `new Foo(args)`
 *   staticCalls        `Foo::method(args)` / `Foo::CONST`
 *   strictTypes        `declare(strict_types=1)` in the compiled template, so a
 *                      value of the wrong type at a call boundary throws instead
 *                      of being coerced
 *
 * Allowlists
 * ----------
 *   functions  names the `phpFunctions` rule may resolve to a PHP function
 *   filters    names accepted after `|>`
 *
 * The rule for both allowlists is the same:
 *
 *   An EMPTY allowlist means unrestricted.  A NON-EMPTY allowlist means only
 *   the listed names resolve; anything else is a compile-time error.
 *
 * `allowFilters()` narrows only: it is consulted where a filter step is already
 * being resolved, so a lone filter allowlist stays sandboxed.  `allowFunctions()`
 * is different in one respect: PHP function calls ARE the construct it names, so
 * granting one turns the `phpFunctions` rule on as well — `default()->allowFunctions('count')`
 * reaches the sandbox without needing a second, unrelated rule to carry it.
 *
 * Empty-means-unrestricted is what makes `Policy::unrestricted()` the engine's
 * old PHP mode exactly, rather than a mode that happens to deny everything.
 *
 * Why `variableVariables` defaults on
 * -----------------------------------
 * Because denying it achieves nothing.  `$$name` and `${expr}` resolve against
 * the render scope and loop locals in every policy, and the engine's own
 * `__c_`-prefixed frame is protected by binding order rather than by rejecting
 * the syntax — so the form reaches nothing a literal name could not.  It exists
 * as a rule so an application can be explicit about wanting it off.
 *
 * Why `strictTypes` exists and defaults on
 * ----------------------------------------
 * A caller cannot opt a template into PHP's strict types: `declare(strict_types=1)`
 * is per-file, and every compiled template is its own file, whose `<?php` the
 * engine emits.  Without this rule a typed filter — `fn(string $s)` — is
 * handed `42` as `"42"` and nothing reports it.  The declaration is a
 * rule, therefore, because that is the only way a template can carry it.
 *
 * It is on in every preset, including {@see restricted()}. Coercion is a silent
 * success: `'1abc'` becomes `1`, a `null` becomes `''`, and nothing anywhere says
 * a type was wrong.  A type error says so.  The cost is a diagnostic, and the
 * alternative is not safety but invisibility — so the strict behaviour is what a
 * template gets unless its application opts out with `denyRule('strictTypes')`.
 *
 * It is not an `allowsPhp()` rule: it grants no construct, and it decides
 * nothing about what a template can name.  What it changes is the *contract at a
 * call boundary*, which is why it is deliberately absent from
 * {@see allowsPhp()} — a strict template is no less sandboxed than a weak one.
 * It hardens the boundary; it does not move it.
 *
 * Scope: the declaration governs calls made *from* the compiled file, so it makes
 * a mismatched argument to a registered filter or function throw, and it makes a
 * fractional float passed to an `int` parameter throw.  It deliberately does NOT
 * remove the engine's output cast in `{{ … }}`: `htmlspecialchars((string)(…))`
 * is how any non-string renders at all, so stripping it would break
 * `{{ 42 }}`, `{{ items |> length }}`, `{{ price }}` — most real templates —
 * rather than catching a mistake.
 */
final class Policy
{
    /** Every rule, in the order the documentation lists them. */
    public const RULES = [
        'methodCalls',
        'newExpressions',
        'phpFunctions',
        'phpVariables',
        'rawPhp',
        'staticCalls',
        'strictTypes',
        'superglobals',
        'variableVariables',
    ];

    /** @var array<string, bool> rule name => allowed */
    private array $rules;

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
     * @param array<string, bool> $rules Complete rule map.
     */
    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    // -------------------------------------------------------------------------
    // Presets
    // -------------------------------------------------------------------------

    /**
     * The default: no template reaches PHP.  Identical to the engine's
     * historical sandbox mode, and what a bare `new ClarityEngine()` uses.
     *
     * Two rules are on rather than off, for opposite reasons:
     * `strictTypes` because it is desirable (it costs nothing and reports
     * mismatches instead of hiding them), and `variableVariables` because
     * turning it off would achieve nothing (see below).
     */
    public static function restricted(): self
    {
        return new self([
            'methodCalls'       => false,
            'newExpressions'    => false,
            'phpFunctions'      => false,
            'phpVariables'      => false,
            'rawPhp'            => false,
            'staticCalls'       => false,
            'strictTypes'       => true,
            'superglobals'      => false,
            'variableVariables' => true,
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
    public static function unrestricted(): self
    {
        return new self([
            'methodCalls'       => true,
            'newExpressions'    => true,
            'phpFunctions'      => true,
            'phpVariables'      => true,
            'rawPhp'            => true,
            'staticCalls'       => true,
            'strictTypes'       => true,
            'superglobals'      => true,
            'variableVariables' => true,
        ]);
    }

    /**
     * Trusted templates have access to most of the engine's rules, but not everything.
     *
     * What stays off: `rawPhp`, and the two rules that let a template name
     * a class of its own. Raw `{% php %}` blocks and constructing an arbitrary
     * class are both a different order of trust from calling a method on an
     * object the application already passed in.
     *
     * `phpFunctions` is on, so bare PHP function calls in template expressions
     * are available; that is the counterpart of the object access the other
     * rules grant.  `denyFunctions()` narrows it.
     *
     * `strictTypes` is on. It is not a reach rule — it grants no construct
     * and names no class — so it is not one of the things this preset
     * withholds; a trusted template is simply held to the types it declares.
     */
    public static function trusted(): self
    {
        return new self([
            'methodCalls'       => true,
            'newExpressions'    => false,
            'phpFunctions'      => true,
            'phpVariables'      => true,
            'rawPhp'            => false,
            'staticCalls'       => false,
            'strictTypes'       => true,
            'superglobals'      => true,
            'variableVariables' => true,
        ]);
    }

    /**
     * Start from the engine's default ({@see restricted()}) and change what you
     * mean to change.  Nothing here is a blank slate: this is the sandboxed
     * policy, so every rule you do not name stays off.
     *
     * ```php
     * Policy::default()
     *     ->allowRule('methodCalls')
     *     ->allowFunctions('strtoupper', 'count');
     * ```
     */
    public static function default(): self
    {
        return self::restricted();
    }

    // -------------------------------------------------------------------------
    // Construction from configuration
    // -------------------------------------------------------------------------

    /**
     * Build a policy from a plain array — the form a config file can express.
     *
     * ```
     * Policy::fromArray([
     *     'rules' => ['methodCalls' => true],
     *     'functions'    => ['strtoupper', 'count'],
     *     'filters'      => ['markdown'],
     * ]);
     * ```
     *
     * An omitted `rules` key starts from {@see restricted()}, so a config
     * only has to name what it changes.  Every key is validated; an unknown
     * rule or allowlist is refused rather than ignored, because a policy
     * that silently drops a rule is worse than one that refuses to load.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $policy = self::restricted();

        foreach ($data as $key => $value) {
            if ($key === 'rules') {
                if (!\is_array($value)) {
                    throw new ClarityException("Policy 'rules' must be an array of name => bool.");
                }
                foreach ($value as $name => $allowed) {
                    if (!\in_array($name, self::RULES, true)) {
                        throw new ClarityException(
                            "Unknown policy rule '{$name}'. Known rules: "
                                . \implode(', ', self::RULES) . '.'
                        );
                    }
                    $policy->rules[$name] = (bool) $allowed;
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
                    . \implode(', ', \array_merge(['rules'], ['functions', 'filters', 'deniedFunctions'])) . '.'
            );
        }

        return $policy;
    }

    /**
     * The array form of this policy.  Round-trips through {@see fromArray()}.
     *
     * @return array{rules: array<string, bool>, functions: list<string>, filters: list<string>, deniedFunctions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'rules'           => $this->rules,
            'functions'       => \array_keys($this->functions),
            'filters'         => \array_keys($this->filters),
            'deniedFunctions' => \array_keys($this->denied),
        ];
    }

    // -------------------------------------------------------------------------
    // Rules
    // -------------------------------------------------------------------------

    public function allows(string $rule): bool
    {
        if (!\array_key_exists($rule, $this->rules)) {
            throw new ClarityException(
                "Unknown policy rule '{$rule}'. Known rules: "
                    . \implode(', ', self::RULES) . '.'
            );
        }
        return $this->rules[$rule];
    }

    /**
     * @return array<string, bool>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Turn rules on.  Accepts more than one so a grant reads as a list.
     *
     * @param string ...$rules
     */
    public function allowRule(string ...$rules): self
    {
        foreach ($rules as $rule) {
            $this->allows($rule); // validates the name
            $this->rules[$rule] = true;
        }
        return $this;
    }

    /**
     * Turn rules off.  Accepts more than one so a denial reads as a list.
     *
     * @param string ...$rules
     */
    public function denyRule(string ...$rules): self
    {
        foreach ($rules as $rule) {
            $this->allows($rule); // validates the name
            $this->rules[$rule] = false;
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
     * Turns the `phpFunctions` rule on as it grants, because a PHP function call
     * IS the construct the rule names: `default()->allowFunctions('count')` is
     * enough to reach the sandbox, with no second rule to carry it.  Only a
     * non-empty list does so: with no names it is a no-op rather than an
     * accidental grant of every PHP function, since an EMPTY allowlist is
     * unrestricted.
     *
     * Distinct from `addFunction()`, which registers a CALLABLE under a name:
     * a name that is registered and allowed stays the registered callable, a name
     * that is allowed and not registered calls the PHP function of that name.
     */
    public function allowFunctions(string ...$names): self
    {
        if ($names === []) {
            return $this;
        }
        foreach ($names as $name) {
            $this->functions[self::normalizeName($name)] = true;
        }
        $this->rules['phpFunctions'] = true;
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
     * what decides is the rules plus this list.  See the class docblock.
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
     * decides that from the rules.  Names are case-insensitive and a
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
     * Deny PHP function calls resolved from template expressions.
     *
     * Raw `{% php %}` blocks are emitted verbatim and are not inspected by this
     * deny list. Denial takes precedence over the function allowlist.
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
     * True when every rule is on and neither allowlist restricts anything:
     * the engine's former PHP mode.
     */
    public function isUnrestricted(): bool
    {
        foreach ($this->rules as $allowed) {
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
     * that are not a named rule because they ARE "PHP is reachable": a bare
     * call to an unregistered name, and a filter step falling back to a PHP
     * function.
     *
     * The `phpFunctions` rule decides the first of those directly; the rest decide
     * whether the constructs that carry a call exist at all.  The allowlists do
     * NOT count as reachable on their own — they narrow which PHP functions a
     * construct may call, they do not create a construct to call them from.
     * `allowFunctions()` is the exception in one direction: it turns the
     * `phpFunctions` rule on as it grants, so it IS sufficient on its own.
     *
     * `variableVariables` is deliberately not part of this.  It decides a syntax
     * the engine resolves against its own scope, so turning it off does not make
     * a template any less able to run PHP.
     */
    public function allowsPhp(): bool
    {
        foreach ([
            'phpFunctions',
            'rawPhp',
            'methodCalls',
            'superglobals',
            'phpVariables',
            'newExpressions',
            'staticCalls',
        ] as $rule) {
            if ($this->rules[$rule]) {
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

    /**
     * Whether compiled templates declare PHP's strict types.
     *
     * `declare(strict_types=1)` is per-file and the engine emits the file, so this
     * is the only way a template can carry it. It is read at compile time by the
     * code builder, which is why the rule and not a global flag: the digest
     * then makes the cache recompile when it changes.
     *
     * Deliberately NOT part of {@see allowsPhp()}: a strict template reaches no
     * less PHP than a weak one, it is merely held to the types it declares.
     */
    public function strictTypes(): bool
    {
        return $this->allows('strictTypes');
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
     */
    public function digest(): string
    {
        $rules = [];
        foreach ($this->rules as $name => $allowed) {
            $rules[] = $name . ":" . ($allowed ? '1' : '0');
        }
        \sort($rules);

        $functions = \array_keys($this->functions);
        $filters   = \array_keys($this->filters);
        $denied    = \array_keys($this->denied);
        \sort($functions);
        \sort($filters);
        \sort($denied);

        $canonical = \implode("\x1E", $rules)
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
