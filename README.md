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

This package is also a Composer plugin. On `composer install`/`update` it
detects the target triple for your PHP process, downloads the matching `-c-api`
archive from the sasso release, verifies its SHA-256 against a pinned checksum,
and extracts the shared library to `vendor/shyim/sasso-ffi/bin/<target>/`.

Allow the plugin once in the consuming project (Composer 2.2+):

```json
{
    "config": {
        "allow-plugins": {
            "shyim/sasso-ffi": true
        }
    }
}
```

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

If the plugin never runs — `--no-plugins`, a `vendor/` built on another
platform, a PHAR — the library is fetched on first use instead, so a failed
download at install time is a warning rather than a hard error.

```bash
# When installed as a dependency (plugin allowed):
composer sasso:install
composer sasso:install --target=x86_64-unknown-linux-gnu
composer sasso:install --target=all --force

# Always works (root checkout, --no-plugins, CI):
php bin/sasso-install
php bin/sasso-install --target=x86_64-unknown-linux-gnu
php bin/sasso-install --target=all --force
# or, after composer install in a consumer project:
vendor/bin/sasso-install
```

`composer sasso:install` is registered by the plugin. Composer never loads the
**root** package as a plugin, so inside this repository use `php bin/sasso-install`
(or `composer run sasso-install`) instead.

Cross-target prefetch is the useful one for Docker: bake the Linux library into
an image from an arm64 laptop without waiting for the container to fetch it.

## Environment variables

| Variable | Effect |
| --- | --- |
| `SASSO_LIBRARY` | Load this library file directly; skips detection and download |
| `SASSO_TARGET` | Force a target triple |
| `SASSO_DOWNLOAD_BASE_URL` | Fetch archives from a mirror (disables the pinned checksum) |
| `SASSO_SKIP_DOWNLOAD=1` | Composer plugin does nothing at install time |
| `SASSO_NO_DOWNLOAD=1` | Never fetch at runtime; error instead |

Air-gapped builds want `SASSO_LIBRARY` (or a vendored `bin/<target>/`) plus
`SASSO_NO_DOWNLOAD=1`, which turns a missing library into an explicit error
rather than a network call.

## Notes

- Bundled sasso release: **0.8.2** (`Sasso\Platform::VERSION`). The library's own
  `sasso_version()` reports a separate internal compiler version that tracks the
  release tag loosely — use `Platform::VERSION` for the release these bindings
  target.
- The FFI handle is loaded once per process and shared across compilers.
- Only `-c-api` archives are published for the seven targets above. There is no
  32-bit build, so those platforms need `SASSO_LIBRARY` pointing at your own.
- Beyond the extension's surface this package also exposes `Sasso\Platform`,
  `Sasso\Downloader`, and the plugin classes. They handle binary provisioning,
  which the extension has no equivalent of; the compiler API itself adds nothing.

## Development

```bash
composer install
vendor/bin/phpunit
```

The test suite is written strictly against the ext-sasso surface, so it can be
run against the native extension unchanged to verify parity.

## License

MIT. sasso and ext-sasso are separate projects under their own licenses.
