# shyim/sasso-ffi

A pure-PHP **polyfill for [ext-sasso](https://github.com/shyim/php-sasso)**, the
PHP extension that compiles SCSS/Sass → CSS with the pure-Rust
[sasso](https://github.com/shyim/sasso) compiler.

Same classes, same constants, same method signatures — implemented over PHP FFI
against sasso's C ABI instead of a compiled extension. Write against
`Sasso\Compiler` once and it runs whether or not the extension is installed.

```bash
composer require shyim/sasso-ffi
```

```php
use Sasso\Compiler;

$css = (new Compiler())
    ->setStyle(Compiler::STYLE_COMPRESSED)
    ->addImportPath(__DIR__ . '/scss')
    ->compile('@use "base"; .x { color: base.$brand; }');
```

Requires PHP >= 8.2 and `ext-ffi`. No `node`, no `sass` binary, no build step.

## Polyfill, not an alternative

This package **conflicts** with both `ext-sasso` and `shyim/sasso`:

```json
"conflict": {
    "ext-sasso": "*",
    "shyim/sasso": "*"
}
```

If the native extension is present, Composer refuses to install the polyfill —
the extension already provides these classes, and two definitions of
`Sasso\Compiler` cannot coexist:

```
Problem 1
  - ext-sasso is present at version 0.2.0 and cannot be modified by Composer
  - shyim/sasso-ffi dev-main conflicts with ext-sasso *.
```

The intended pattern is to depend on the polyfill and let anyone who has the
extension satisfy the same API natively — so prefer the extension where you can
install it, and fall back to this everywhere else (shared hosting, a base image
you don't control, an environment without a compiler).


## API

Identical to ext-sasso's stubs.

```php
namespace Sasso;

class Compiler {
    const STYLE_EXPANDED = 0;
    const STYLE_COMPRESSED = 1;
    const SYNTAX_SCSS = 0;
    const SYNTAX_SASS = 1;
    const SYNTAX_CSS = 2;

    public function setStyle(int $style): static;
    public function setSyntax(int $syntax): static;
    public function setUnicode(bool $unicode): static;
    public function setUrl(?string $url = null): static;
    public function addImportPath(string $path): static;
    public function setImportPaths(array $paths): static;
    public function setImporter(mixed $importer): static;   // ?Sasso\Importer
    public function compile(string $source): string;
}

interface Importer {
    public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string;
    public function load(string $canonicalUrl): ?ImporterResult;
}

class ImporterResult {
    public string $contents;
    public int $syntax;             // SYNTAX_* constant
    public ?string $sourceMapUrl;
    public function __construct(string $contents, ?int $syntax = null, ?string $sourceMapUrl = null);
}

class CompileException extends \Exception {}
```

Setters are fluent and mutate the compiler; an out-of-range `STYLE_*`/`SYNTAX_*`
value throws `\ValueError` at `compile()` time, matching the extension. The
compiler is reusable across compiles.

### Errors

`Sasso\CompileException` carries sasso's diagnostic — a byte-exact snippet when
a url is set, otherwise the `Error: <msg> (line:col)` one-liner. The position is
part of the message; like the extension, there are no extra accessors.

```php
try {
    (new Compiler())->setUrl('app.scss')->compile('.a { color: ; }');
} catch (Sasso\CompileException $e) {
    echo $e->getMessage();
}
```

```
Error: unexpected character ';' in value
  ╷
1 │ .a { color: ; }
  │             ^
  ╵
  app.scss 1:13  root stylesheet
```

### Custom importers

```php
use Sasso\{Compiler, Importer, ImporterResult};

final class ArrayImporter implements Importer
{
    public function __construct(private array $files) {}

    public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string
    {
        return isset($this->files[$url]) ? "array:$url" : null;
    }

    public function load(string $canonicalUrl): ?ImporterResult
    {
        return new ImporterResult($this->files[substr($canonicalUrl, 6)]);
    }
}

$css = (new Compiler())
    ->setImporter(new ArrayImporter(['theme' => '$accent: hotpink;']))
    ->addImportPath(__DIR__ . '/scss')   // fallback when canonicalize() returns null
    ->compile('@use "theme" as t; .btn { color: t.$accent; }');
```

The importer is consulted first; configured import paths act as a **fallback**
when `canonicalize()` returns `null`. That mirrors ext-sasso — note the raw C
ABI does not do this (an importer there replaces load paths outright), so the
polyfill emulates the fallback itself, following Sass's partial conventions
(`foo.scss`, `_foo.scss`, `foo/_index.scss`).

Throwing from either method aborts the compile and your exception propagates
unchanged rather than being flattened into a `CompileException`.

## How the native library gets there

Provisioning is delegated to
[shyim/composer-binary-downloader](https://github.com/shyim/composer-binary-downloader),
a generic plugin that reads `extra.binaries` from this package's `composer.json`.
On `composer install`/`update` it detects the target triple for your PHP process,
downloads the matching `-c-api` archive from the sasso release, verifies its
SHA-256 against a pinned checksum, and extracts the shared library to
`vendor/shyim/sasso-ffi/bin/<target>/`.

The consuming project allows the plugin, and — because downloading code from a
URL a dependency chose is gated the same way plugins are — approves this package
as a binary source:

```json
{
    "config": {
        "allow-plugins": {
            "shyim/composer-binary-downloader": true
        }
    },
    "extra": {
        "allow-binaries": {
            "shyim/sasso-ffi": true
        }
    }
}
```

An interactive `composer install` prompts for the `allow-binaries` entry and
writes it for you; CI needs it committed.

Detection follows the **PHP binary**, not the host CPU — an x86_64 PHP under
Rosetta gets the x86_64 library, which is the one it can actually load.

| Target | Library |
| --- | --- |
| `x86_64-apple-darwin` | `libsasso.dylib` |
| `aarch64-apple-darwin` | `libsasso.dylib` |
| `x86_64-unknown-linux-gnu` | `libsasso.so` |
| `aarch64-unknown-linux-gnu` | `libsasso.so` |
| `x86_64-unknown-linux-musl` | `libsasso.so` |
| `aarch64-unknown-linux-musl` | `libsasso.so` |
| `x86_64-pc-windows-msvc` | `sasso.dll` |

Loading the compiler never downloads anything: `Sasso\Compiler` resolves the
already-installed path, so a web request cannot stall on a release host. If the
download did not happen — `--no-plugins`, a `vendor/` built on another platform —
the first compile fails with a message naming the expected path and the command
that fixes it.

```bash
composer binary:install                              # this platform
composer binary:install sasso --target=x86_64-unknown-linux-gnu
composer binary:install -t all --force
composer binary:list                                 # configured vs. on disk

# Without the plugin (--no-plugins, or a root checkout):
php vendor/bin/binary-download
```

`composer binary:list` is the diagnostic for a failing `FFI::cdef()`: it prints
the exact path that was expected and whether it exists.

Cross-target prefetch is the useful one for Docker: bake the Linux library into
an image from an arm64 laptop without waiting for the container to fetch it.

## Environment variables

| Variable | Effect |
| --- | --- |
Read by the binary downloader, which derives them from the `sasso` binary name:

| Variable | Effect |
| --- | --- |
| `SASSO_LIBRARY` | Load this library file directly; skips detection and download |
| `SASSO_TARGET` | Force a target triple |
| `SASSO_DOWNLOAD_BASE_URL` | Fetch archives from a mirror (drops the pinned checksum) |
| `SASSO_SKIP_DOWNLOAD` | Never download; a missing library is an error |
| `BINARY_DOWNLOADER_SKIP` | The same, for every binary in the project |

Air-gapped builds want `SASSO_LIBRARY` (or a vendored `bin/<target>/`) plus
`SASSO_SKIP_DOWNLOAD`, which turns a missing library into an explicit error
rather than a network call.

## Notes

- Bundled sasso release: **0.8.2**, pinned in `composer.json` under
  `extra.binaries.sasso.version`. The library's own `sasso_version()` reports a
  separate internal compiler version that only loosely tracks the release tag.
- The FFI handle is loaded once per process and shared across compilers.
- Only `-c-api` archives are published for the seven targets above. There is no
  32-bit build, so those platforms need `SASSO_LIBRARY` pointing at your own.
- The PHP surface is exactly ext-sasso's — provisioning lives in the binary
  downloader, so there are no extra classes here to learn. Its
  `Shyim\BinaryDownloader\Binaries` API is available for diagnostics.

## Development

```bash
composer install
vendor/bin/phpunit
```

The test suite is written strictly against the ext-sasso surface, so it can be
run against the native extension unchanged to verify parity.

## License

MIT. sasso and ext-sasso are separate projects under their own licenses.
