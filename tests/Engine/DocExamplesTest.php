<?php
namespace Clarity\Tests\Engine;

use Clarity\Engine\Compiler;
use Clarity\Engine\Registry;
use Clarity\Template\FileLoader;
use PHPUnit\Framework\TestCase;

/**
 * Every documented example must COMPILE.
 *
 * `docs/examples/*.clarity.html` are the templates the documentation shows and
 * the ones a reader copies first, so they are the most visible possible place
 * for the syntax to drift. They are not rendered here because they are
 * illustrative fragments and several need data a test has no business inventing
 * — compiling is the contract they actually have to satisfy.
 *
 * This caught a pre-existing defect: `04-loops` contained
 * `map(t = > t |> upper)`, with a space between `=` and `>`. Nothing compiled
 * the examples, so the typo survived every green run.
 */
class DocExamplesTest extends TestCase
{
    public static function exampleProvider(): array
    {
        $root  = \dirname(__DIR__, 2) . '/docs/examples';
        $cases = [];

        if (!\is_dir($root)) {
            return ['missing' => ['__missing__']];
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (!$file->isFile() || !\str_ends_with($file->getFilename(), '.clarity.html')) {
                continue;
            }
            $path = $file->getPathname();
            $view = \substr(\str_replace('\\', '/', $path), \strlen(\str_replace('\\', '/', $root)) + 1);
            $view = \substr($view, 0, -\strlen('.clarity.html'));
            $cases[$view] = [$view];
        }

        \ksort($cases);

        return $cases;
    }

    /**
     * @dataProvider exampleProvider
     */
    public function testExampleCompiles(string $view): void
    {
        $this->assertNotSame('__missing__', $view, 'docs/examples must exist');

        $root   = \dirname(__DIR__, 2) . '/docs/examples';
        $loader = new FileLoader($root, '.clarity.html');

        (new Compiler())->setRegistry(new Registry())->compile($view, $loader);

        $this->addToAssertionCount(1);
    }
}
