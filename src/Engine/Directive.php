<?php
namespace Clarity\Engine;

/**
 * What one custom directive IS in a paired construct.
 *
 * Passed as the optional third argument to {@see ClarityEngine::addDirective()}.
 * It replaces the former `keyword => role` array, whose two forms said different
 * things in the same shape: `['endcache' => 'required']` meant "this tag is my
 * closing tag", while `['cache' => 'owner']` meant "this tag belongs to cache".
 * Here the factory NAME states the role, so the two directions cannot be
 * confused and no magic string has to be spelled correctly.
 *
 * There are four factories, and they cover TWO independent dimensions:
 *
 * Structural — the tag takes part in the construct and changes the compiler
 * stack.  This is the dimension a formatter also reads: an opener indents the
 * body, a branch dedents and re-indents, a closer dedents.
 *
 *   - {@see opens()}   — the tag CREATES the construct and names its parts:
 *     exactly one closer plus any number of optional branch tags.
 *   - {@see branches()} — the tag IS a branch segment of $owner (like
 *     `{% else %}`: it ends the current body and starts the next).
 *   - {@see closes()}  — the tag ENDS $owner.
 *
 * Containment — the tag changes nothing structurally; it is an ordinary leaf
 * that is merely not allowed everywhere.  A formatter prints it at the current
 * depth, which is why this factory is a preposition, not a verb:
 *
 *   - {@see inside()}  — the tag may appear ONLY directly inside $owner.
 *
 * The opener is the single source of truth for the structure.  A member's
 * `branches()`/`closes()` is an assertion that must AGREE with what the opener
 * declares; a disagreement is a registration error, not a silent override.
 * That is what catches "registered the closer, forgot the opener" — the claim
 * points at an owner that never declared the tag.
 */
final class Directive
{
    private const OPENER      = 'opener';
    private const BRANCH      = 'branch';
    private const CLOSER      = 'closer';
    private const CONTAINMENT = 'containment';

    /**
     * @param string       $shape    One of the private shape constants.
     * @param string|null  $owner    Opener keyword a branch/closer/containment tag claims.
     * @param string|null  $closer   Closing keyword an opener declares.
     * @param list<string> $branches Optional branch keywords an opener declares.
     */
    private function __construct(
        private readonly string $shape,
        private readonly ?string $owner,
        private readonly ?string $closer,
        private readonly array $branches,
    ) {
    }

    /**
     * The tag OPENS a construct and names its parts.
     *
     * `$closer` is a single named parameter, so "exactly one closing tag" is
     * guaranteed by the shape of the call — there is no position that could
     * accidentally receive a second one.  The variadic tail carries the optional
     * branch tags:
     *
     * ```php
     * Directive::opens('endcache', 'cache_else');
     * ```
     *
     * @param string ...$branches Optional branch tag keywords, at most one per tag word.
     */
    public static function opens(string $closer, string ...$branches): self
    {
        return new self(self::OPENER, null, $closer, $branches);
    }

    /**
     * The tag IS a branch segment of `$owner`, like `{% else %}` (ends the
     * current body, starts the next; the construct stays open).
     */
    public static function branches(string $owner): self
    {
        return new self(self::BRANCH, $owner, null, []);
    }

    /**
     * The tag ENDS `$owner`.
     */
    public static function closes(string $owner): self
    {
        return new self(self::CLOSER, $owner, null, []);
    }

    /**
     * The tag may appear ONLY directly inside `$owner`, and is otherwise an
     * ordinary leaf: it opens/bloses nothing and carries no cardinality.
     */
    public static function inside(string $owner): self
    {
        return new self(self::CONTAINMENT, $owner, null, []);
    }

    /** Whether this tag opens a construct (and therefore declares members). */
    public function isOpener(): bool
    {
        return $this->shape === self::OPENER;
    }

    /** Whether this tag claims to be a branch segment of its owner. */
    public function isBranch(): bool
    {
        return $this->shape === self::BRANCH;
    }

    /** Whether this tag claims to close its owner. */
    public function isCloser(): bool
    {
        return $this->shape === self::CLOSER;
    }

    /** Whether this tag is a leaf restricted to the inside of its owner. */
    public function isContainment(): bool
    {
        return $this->shape === self::CONTAINMENT;
    }

    /** The opener keyword a branch/closer/containment tag claims, or null for an opener. */
    public function owner(): ?string
    {
        return $this->owner;
    }

    /** The closing keyword an opener declares, or null for a member. */
    public function closer(): ?string
    {
        return $this->closer;
    }

    /**
     * The optional branch keywords an opener declares.
     *
     * @return list<string>
     */
    public function branchTags(): array
    {
        return $this->branches;
    }
}
