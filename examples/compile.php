<?php

/**
 * Run with: php examples/compile.php
 *
 * Every snippet here is written against the ext-sasso API, so it runs
 * unchanged whether the extension or this polyfill is providing it.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sasso\CompileException;
use Sasso\Compiler;
use Sasso\Importer;
use Sasso\ImporterResult;

printf(
    "provider: %s\n\n",
    extension_loaded('sasso') ? 'ext-sasso (native extension)' : 'shyim/sasso-ffi (FFI polyfill)',

);

echo "--- expanded ---\n";
echo (new Compiler())->compile(<<<'SCSS'
    $brand: #3f51b5;
    $gap: 8px;

    .card {
      padding: $gap * 2;
      color: $brand;

      &:hover { color: lighten($brand, 10%); }
      .title { font-weight: 600; }
    }
    SCSS);

echo "\n--- compressed ---\n";
echo (new Compiler())
    ->setStyle(Compiler::STYLE_COMPRESSED)
    ->compile('.a { color: red; } .b { color: blue; }'), "\n";

echo "\n--- indented sass ---\n";
echo (new Compiler())
    ->setSyntax(Compiler::SYNTAX_SASS)
    ->compile(".a\n  color: olive\n");

echo "\n--- a virtual importer ---\n";
$importer = new class implements Importer {
    public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
    {
        return str_starts_with($url, 'virtual:') ? $url : 'virtual:' . $url;
    }

    public function load(string $canonicalUrl): ?ImporterResult
    {
        return match ($canonicalUrl) {
            'virtual:theme' => new ImporterResult('$accent: hotpink; $radius: 4px;'),
            default => null,
        };
    }
};

echo (new Compiler())
    ->setImporter($importer)
    ->compile('@use "theme" as t; .btn { color: t.$accent; border-radius: t.$radius; }');

echo "\n--- an error ---\n";
try {
    (new Compiler())
        ->setUrl('example.scss')
        ->compile(".a {\n  color: ;\n}");
} catch (CompileException $e) {
    echo $e->getMessage(), "\n";
}
