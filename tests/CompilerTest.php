<?php

declare(strict_types=1);

namespace Sasso\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sasso\CompileException;
use Sasso\Compiler;
use Sasso\Importer;
use Sasso\ImporterResult;

/**
 * These tests are written strictly against the ext-sasso surface, so the same
 * suite passes against the native extension and against this polyfill.
 */
final class CompilerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/sasso-test-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/partials', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function testCompilesNestingAndVariables(): void
    {
        $css = (new Compiler())->compile('$c: #333; .a { color: $c; .b { margin: 1px + 2px; } }');

        $this->assertStringContainsString('color: #333;', $css);
        $this->assertStringContainsString('.a .b', $css);
        $this->assertStringContainsString('margin: 3px;', $css);
    }

    public function testSettersAreFluentAndReturnTheSameInstance(): void
    {
        $compiler = new Compiler();

        $this->assertSame($compiler, $compiler->setStyle(Compiler::STYLE_COMPRESSED));
        $this->assertSame($compiler, $compiler->setSyntax(Compiler::SYNTAX_SCSS));
        $this->assertSame($compiler, $compiler->setUnicode(false));
        $this->assertSame($compiler, $compiler->setUrl('a.scss'));
        $this->assertSame($compiler, $compiler->addImportPath($this->workspace));
        $this->assertSame($compiler, $compiler->setImportPaths([$this->workspace]));
        $this->assertSame($compiler, $compiler->setImporter(null));
    }

    public function testCompressedStyleStripsWhitespace(): void
    {
        $css = (new Compiler())->setStyle(Compiler::STYLE_COMPRESSED)->compile('.a { color: red; }');

        $this->assertSame('.a{color:red}', trim($css));
    }

    public function testIndentedSassSyntax(): void
    {
        $css = (new Compiler())->setSyntax(Compiler::SYNTAX_SASS)->compile(".a\n  color: blue\n");

        $this->assertStringContainsString('color: blue;', $css);
    }

    public function testPlainCssSyntax(): void
    {
        $css = (new Compiler())->setSyntax(Compiler::SYNTAX_CSS)->compile('.a { color: red; }');

        $this->assertStringContainsString('color: red;', $css);
    }

    public function testCompilerIsReusableAcrossCompiles(): void
    {
        $compiler = (new Compiler())->setStyle(Compiler::STYLE_COMPRESSED);

        $this->assertSame('.a{color:red}', trim($compiler->compile('.a { color: red; }')));
        $this->assertSame('.b{color:blue}', trim($compiler->compile('.b { color: blue; }')));
    }

    public function testSourceProducingNoRulesReturnsEmptyString(): void
    {
        $this->assertSame('', (new Compiler())->compile('// nothing here'));
    }

    public function testUtf8SurvivesTheRoundTrip(): void
    {
        $css = (new Compiler())->compile('.a::after { content: "→ café ✓"; }');

        $this->assertStringContainsString('→ café ✓', $css);
    }

    public function testInvalidSourceThrowsCompileException(): void
    {
        $this->expectException(CompileException::class);

        (new Compiler())->compile('.a { color: ; }');
    }

    public function testUrlEnablesSourceSnippetInDiagnostics(): void
    {
        try {
            (new Compiler())->setUrl('app.scss')->compile('.a { color: ; }');
            $this->fail('Expected a CompileException.');
        } catch (CompileException $e) {
            $this->assertStringContainsString('app.scss', $e->getMessage());
            $this->assertStringContainsString('1:13', $e->getMessage());
        }
    }

    public function testUnicodeOptionSelectsAsciiDiagnostics(): void
    {
        try {
            (new Compiler())->setUrl('in.scss')->setUnicode(false)->compile('.a { color: ; }');
            $this->fail('Expected a CompileException.');
        } catch (CompileException $e) {
            $this->assertStringNotContainsString('╷', $e->getMessage());
        }
    }

    #[DataProvider('invalidConstants')]
    public function testOutOfRangeConstantsAreRejectedAtCompileTime(string $setter, int $value): void
    {
        $this->expectException(\ValueError::class);

        (new Compiler())->{$setter}($value)->compile('.a { color: red; }');
    }

    public static function invalidConstants(): iterable
    {
        yield 'style' => ['setStyle', 99];
        yield 'negative style' => ['setStyle', -1];
        yield 'syntax' => ['setSyntax', 42];
    }

    public function testSetImporterRejectsNonImporter(): void
    {
        $this->expectException(\TypeError::class);

        (new Compiler())->setImporter(new \stdClass());
    }

    public function testImportPathResolvesPartial(): void
    {
        file_put_contents($this->workspace . '/partials/_vars.scss', '$brand: rebeccapurple;');

        $css = (new Compiler())
            ->addImportPath($this->workspace . '/partials')
            ->compile('@use "vars" as v; .a { color: v.$brand; }');

        $this->assertStringContainsString('rebeccapurple', $css);
    }

    public function testSetImportPathsReplacesPreviousPaths(): void
    {
        file_put_contents($this->workspace . '/partials/_vars.scss', '$brand: teal;');

        $compiler = (new Compiler())
            ->addImportPath('/nonexistent/first')
            ->setImportPaths([$this->workspace . '/partials']);

        $this->assertStringContainsString(
            'teal',
            $compiler->compile('@use "vars" as v; .a { color: v.$brand; }'),
        );
    }

    public function testCustomImporterResolvesVirtualModules(): void
    {
        $importer = new class implements Importer {
            /** @var list<string> */
            public array $calls = [];

            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                $this->calls[] = 'canonicalize:' . $url;

                return str_starts_with($url, 'virtual:') ? $url : 'virtual:' . $url;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                $this->calls[] = 'load:' . $canonicalUrl;

                return $canonicalUrl === 'virtual:theme'
                    ? new ImporterResult('$accent: hotpink;')
                    : null;
            }
        };

        $css = (new Compiler())
            ->setImporter($importer)
            ->compile('@use "theme" as t; .c { color: t.$accent; }');

        $this->assertStringContainsString('hotpink', $css);
        $this->assertSame(['canonicalize:theme', 'load:virtual:theme'], $importer->calls);
    }

    public function testImporterReceivesFromImportFlag(): void
    {
        $importer = new class implements Importer {
            /** @var list<bool> */
            public array $flags = [];

            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                $this->flags[] = $fromImport;

                return 'v:' . $url;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                return new ImporterResult('$v: 1px;');
            }
        };

        (new Compiler())->setImporter($importer)->compile('@use "a" as a; .x { margin: a.$v; }');
        $this->assertSame([false], $importer->flags, '@use should report fromImport=false');

        $importer->flags = [];
        (new Compiler())->setImporter($importer)->compile('@import "a"; .x { margin: $v; }');
        $this->assertSame([true], $importer->flags, '@import should report fromImport=true');
    }

    public function testImporterReceivesContainingUrlForNestedImports(): void
    {
        $importer = new class implements Importer {
            /** @var list<array{string, ?string}> */
            public array $seen = [];

            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                $this->seen[] = [$url, $containingUrl];

                return 'v:' . $url;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                return match ($canonicalUrl) {
                    'v:outer' => new ImporterResult('@use "inner" as i; $outer: i.$v;'),
                    'v:inner' => new ImporterResult('$v: 4px;'),
                    default => null,
                };
            }
        };

        (new Compiler())->setImporter($importer)->compile('@use "outer" as o; .a { margin: o.$outer; }');

        $this->assertSame(['outer', null], $importer->seen[0]);
        $this->assertSame('inner', $importer->seen[1][0]);
        $this->assertSame('v:outer', $importer->seen[1][1], 'nested import should report its importing file');
    }

    public function testImporterResultSyntaxIsHonoured(): void
    {
        $importer = new class implements Importer {
            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                return 'v:' . $url;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                return new ImporterResult(".indented\n  color: olive\n", Compiler::SYNTAX_SASS);
            }
        };

        $css = (new Compiler())->setImporter($importer)->compile('@use "x";');

        $this->assertStringContainsString('color: olive;', $css);
    }

    public function testImportPathsActAsFallbackWhenImporterDeclines(): void
    {
        file_put_contents($this->workspace . '/partials/_disk.scss', '$fromDisk: navy;');

        $importer = new class implements Importer {
            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                // Handles only "virtual", declining everything else.
                return $url === 'virtual' ? 'v:virtual' : null;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                return $canonicalUrl === 'v:virtual' ? new ImporterResult('$fromVirtual: gold;') : null;
            }
        };

        $css = (new Compiler())
            ->setImporter($importer)
            ->addImportPath($this->workspace . '/partials')
            ->compile('@use "virtual" as v; @use "disk" as d; .a { color: v.$fromVirtual; background: d.$fromDisk; }');

        $this->assertStringContainsString('color: gold;', $css);
        $this->assertStringContainsString('background: navy;', $css, 'import paths should back up a declining importer');
    }

    public function testImporterReturningNullEverywhereFailsTheCompile(): void
    {
        $importer = new class implements Importer {
            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                return null;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                return null;
            }
        };

        $this->expectException(CompileException::class);

        (new Compiler())->setImporter($importer)->compile('@use "nope"; .a { color: red; }');
    }

    public function testExceptionFromImporterPropagatesUnchanged(): void
    {
        $importer = new class implements Importer {
            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                return 'x:' . $url;
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                throw new \LogicException('importer exploded');
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('importer exploded');

        (new Compiler())->setImporter($importer)->compile('@use "whatever"; .a { color: red; }');
    }

    public function testSetImporterNullRestoresImportPaths(): void
    {
        file_put_contents($this->workspace . '/partials/_vars.scss', '$brand: maroon;');

        $importer = new class implements Importer {
            public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
            {
                throw new \LogicException('should not be consulted once cleared');
            }

            public function load(string $canonicalUrl): ?ImporterResult
            {
                return null;
            }
        };

        $css = (new Compiler())
            ->setImporter($importer)
            ->setImporter(null)
            ->addImportPath($this->workspace . '/partials')
            ->compile('@use "vars" as v; .a { color: v.$brand; }');

        $this->assertStringContainsString('maroon', $css);
    }

    public function testImporterResultDefaults(): void
    {
        $result = new ImporterResult('.a { color: red; }');

        $this->assertSame('.a { color: red; }', $result->contents);
        $this->assertSame(Compiler::SYNTAX_SCSS, $result->syntax);
        $this->assertNull($result->sourceMapUrl);
    }

    public function testConstantValuesMatchTheExtension(): void
    {
        $this->assertSame(0, Compiler::STYLE_EXPANDED);
        $this->assertSame(1, Compiler::STYLE_COMPRESSED);
        $this->assertSame(0, Compiler::SYNTAX_SCSS);
        $this->assertSame(1, Compiler::SYNTAX_SASS);
        $this->assertSame(2, Compiler::SYNTAX_CSS);
    }

    public function testRepeatedCompilesDoNotGrowMemory(): void
    {
        $compiler = new Compiler();
        $source = '$x: 1px; .a { margin: $x * 2; .b { color: red; } }';

        for ($i = 0; $i < 200; $i++) {
            $compiler->compile($source);
        }

        gc_collect_cycles();
        $baseline = memory_get_usage();

        for ($i = 0; $i < 2000; $i++) {
            $compiler->compile($source);
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;

        // A leaked buffer per compile would run to megabytes over this loop;
        // the small allowance absorbs the test runner's own bookkeeping.
        $this->assertLessThan(
            102400,
            $growth,
            sprintf('FFI buffers appear to accumulate across compiles (grew %d bytes over 2000)', $growth),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
