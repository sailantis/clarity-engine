<?php
namespace Clarity\Tests\Engine;

/**
 * A named class for the static-call test.  Anonymous classes cannot be addressed
 * by name, which is the whole thing being tested.
 *
 * @internal
 */
final class PolicyCapabilityFixture
{
    public static function label(): string
    {
        return 'fixture';
    }
}
