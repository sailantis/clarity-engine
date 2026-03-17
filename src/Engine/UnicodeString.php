<?php
namespace Clarity\Engine;

use LogicException;

/**
 * A UTF-8 string that can be accessed by character index and counted.
 *
 * This is used internally by the Clarity DSL engine to support
 * character indexing and array access for strings with multibyte characters.
 *
 * ArrayAccess: Get the character at the given index (0-based).
 *
 * Example:
 *   $s = new UnicodeString("😿 Hello");
 *   echo $s[0]; // "😿"
 *
 * Note: This class is immutable, so offsetSet and offsetUnset will throw exceptions.
 */
class UnicodeString implements \ArrayAccess, \Countable, \JsonSerializable
{
    private array $chars;

    public function __construct(string|array $str, int $offset = 0, ?int $length = null)
    {
        if (\is_array($str)) {
            $this->chars = $str;
        } else {
            $this->chars = preg_split(
                '//u',
                $str,
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        }
        if ($offset !== 0 || $length !== null) {
            $this->chars = \array_slice($this->chars, $offset, $length);
        }
    }

    public function substring(int $offset, ?int $length = null): static
    {
        return new static($this->chars, $offset, $length);
    }

    public function toUpper(): static
    {
        return new static(mb_strtoupper(\implode('', $this->chars)));
    }

    public function toLower(): static
    {
        return new static(mb_strtolower(\implode('', $this->chars)));
    }

    public function offsetExists($offset): bool
    {
        $offset = (int) $offset;
        return isset($this->chars[$offset]);
    }

    public function offsetGet($offset): string
    {
        $offset = (int) $offset;
        if (!isset($this->chars[$offset])) {
            throw new LogicException("Invalid character index {$offset} for UnicodeString.");
        }
        return $this->chars[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        throw new LogicException("UnicodeString ist immutable.");
    }

    public function offsetUnset($offset): void
    {
        throw new LogicException("UnicodeString ist immutable.");
    }

    public function __toString(): string
    {
        return \implode('', $this->chars);
    }

    public function count(): int
    {
        return \count($this->chars);
    }

    public function jsonSerialize(): mixed
    {
        return \implode('', $this->chars);
    }
}