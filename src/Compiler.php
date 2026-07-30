<?php

declare(strict_types=1);

namespace Sasso;

use FFI;

/**
 * A fluent SCSS → CSS compiler backed by the Rust `sasso` crate.
 *
 * ```php
 * $css = (new Sasso\Compiler())
 *     ->setStyle(Sasso\Compiler::STYLE_COMPRESSED)
 *     ->addImportPath(__DIR__ . '/scss')
 *     ->compile('@import "base"; .a { color: red; &:hover { color: blue; } }');
 * ```
 */
class Compiler
{
    /** Output style: human-readable, indented CSS (the default). */
    public const STYLE_EXPANDED = 0;

    /** Output style: minified, single-line CSS. */
    public const STYLE_COMPRESSED = 1;

    /** Input syntax: brace/semicolon SCSS (the default). */
    public const SYNTAX_SCSS = 0;

    /** Input syntax: indented `.sass`. */
    public const SYNTAX_SASS = 1;

    /** Input syntax: plain CSS. */
    public const SYNTAX_CSS = 2;

    private int $style = self::STYLE_EXPANDED;
    private int $syntax = self::SYNTAX_SCSS;
    private bool $unicode = true;
    private ?string $url = null;

    /** @var list<string> */
    private array $importPaths = [];

    private ?Importer $importer = null;

    /** Create a compiler with default options (expanded, SCSS, Unicode diagnostics). */
    public function __construct()
    {
    }

    /**
     * Set the output style (one of the `STYLE_*` constants). Returns `$this`.
     *
     * An out-of-range value is validated (and throws) at `compile()` time.
     */
    public function setStyle(int $style): static
    {
        $this->style = $style;

        return $this;
    }

    /**
     * Set the input syntax (one of the `SYNTAX_*` constants). Returns `$this`.
     *
     * An out-of-range value is validated (and throws) at `compile()` time.
     */
    public function setSyntax(int $syntax): static
    {
        $this->syntax = $syntax;

        return $this;
    }

    /** Toggle Unicode box-drawing glyphs in diagnostics (`false` = ASCII). */
    public function setUnicode(bool $unicode): static
    {
        $this->unicode = $unicode;

        return $this;
    }

    /**
     * Set the input's path/URL as it should appear in diagnostics, enabling
     * byte-exact error snippets. Pass `null` to disable.
     */
    public function setUrl(?string $url = null): static
    {
        $this->url = $url;

        return $this;
    }

    /** Append a load path searched for `@import`/`@use`/`@forward` partials. */
    public function addImportPath(string $path): static
    {
        $this->importPaths[] = $path;

        return $this;
    }

    /**
     * Replace all load paths at once.
     *
     * @param array<array-key, string> $paths
     */
    public function setImportPaths(array $paths): static
    {
        $this->importPaths = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new \TypeError('Sasso\Compiler::setImportPaths(): $paths must contain only strings');
            }

            $this->importPaths[] = $path;
        }

        return $this;
    }

    /**
     * Set a userland `Sasso\Importer` that resolves `@import`/`@use`/`@forward`
     * partials. It is consulted first; any configured load paths act as a
     * fallback when its `canonicalize()` returns `null`. Pass `null` to clear it.
     *
     * Throws if `$importer` is not an instance of `Sasso\Importer`.
     */
    public function setImporter(mixed $importer): static
    {
        if ($importer !== null && !$importer instanceof Importer) {
            throw new \TypeError(sprintf(
                'Sasso\Compiler::setImporter(): Argument #1 ($importer) must be of type ?Sasso\Importer, %s given',
                get_debug_type($importer),
            ));
        }

        $this->importer = $importer;

        return $this;
    }

    /**
     * Compile `source` with the configured options, returning CSS.
     *
     * @throws CompileException on a parse or evaluation error
     */
    public function compile(string $source): string
    {
        $this->assertValidOptions();

        $ffi = Library::default()->ffi();

        // Everything FFI::new() allocates here must outlive the sasso_compile()
        // call: PHP frees an FFI\CData as soon as its last reference drops, and
        // the struct only holds raw pointers into these buffers. $keepalive is
        // what stops that happening mid-call.
        $keepalive = [];

        $options = $ffi->new('SassoOptions');
        $keepalive[] = $options;
        $ffi->sasso_options_init(FFI::addr($options), FFI::sizeof($options));

        $options->style = $this->style;
        $options->syntax = $this->syntax;
        $options->unicode = $this->unicode ? 1 : 0;

        if ($this->url !== null) {
            $url = $this->cString($ffi, $this->url);
            $keepalive[] = $url;
            $options->url = $ffi->cast('const char *', $url);
        }

        // A custom importer replaces load paths entirely at the C ABI level, so
        // the fallback ext-sasso offers is emulated in the wrapper below rather
        // than by passing both to the library.
        if ($this->importer === null && $this->importPaths !== []) {
            $count = count($this->importPaths);
            $array = $ffi->new("const char *[{$count}]");
            $keepalive[] = $array;

            foreach ($this->importPaths as $index => $importPath) {
                $entry = $this->cString($ffi, $importPath);
                $keepalive[] = $entry;
                $array[$index] = $ffi->cast('const char *', $entry);
            }

            $options->load_paths = $ffi->cast('const char *const *', FFI::addr($array[0]));
            $options->load_paths_len = $count;
        }

        // Set by an importer callback that threw, so the original exception can
        // be rethrown instead of being flattened into a compile diagnostic.
        $callbackError = null;

        if ($this->importer !== null) {
            $importer = $this->buildImporter($ffi, $this->importer, $callbackError, $keepalive);
            $keepalive[] = $importer;
            $options->importer = $ffi->cast('const SassoImporter *', FFI::addr($importer));
        }

        $result = $ffi->sasso_compile($source, strlen($source), FFI::addr($options));

        // $keepalive has held every buffer the options struct points into for
        // the duration of the call above; only now is it safe to drop them.
        unset($keepalive);

        if ($callbackError !== null) {
            if ($result !== null) {
                $ffi->sasso_result_free($result);
            }

            throw $callbackError;
        }

        if ($result === null) {
            throw new CompileException('sasso_compile() returned no result.');
        }

        try {
            if ($result->ok === 1) {
                return $result->css_len > 0 ? FFI::string($result->css, $result->css_len) : '';
            }

            throw new CompileException(
                $result->error !== null ? FFI::string($result->error, $result->error_len) : 'Unknown sasso error.',
            );
        } finally {
            $ffi->sasso_result_free($result);
        }
    }

    /** Reject out-of-range constants, which ext-sasso validates at compile() time. */
    private function assertValidOptions(): void
    {
        if ($this->style !== self::STYLE_EXPANDED && $this->style !== self::STYLE_COMPRESSED) {
            throw new \ValueError(sprintf('Sasso\Compiler: unknown output style %d', $this->style));
        }

        if (!in_array($this->syntax, [self::SYNTAX_SCSS, self::SYNTAX_SASS, self::SYNTAX_CSS], true)) {
            throw new \ValueError(sprintf('Sasso\Compiler: unknown syntax %d', $this->syntax));
        }
    }

    /**
     * Wrap a userland Importer in the C callback struct.
     *
     * @param \Throwable|null $callbackError out-param: set when a callback throws
     * @param list<mixed>     $keepalive     out-param: holds the closures alive
     */
    private function buildImporter(
        FFI $ffi,
        Importer $importer,
        ?\Throwable &$callbackError,
        array &$keepalive,
    ): FFI\CData {
        $native = $ffi->new('SassoImporter');
        $native->user_data = null;

        // Canonical keys the fallback produced, so load() knows to read them
        // from disk instead of handing them to the userland importer.
        $fallbackKeys = [];

        // A PHP exception must never unwind through the Rust frames that called
        // us, so each callback captures and converts to a return code. The
        // captured throwable is rethrown after sasso_compile() returns.
        $canonicalize = function ($userData, $url, $context, $sink) use (
            $ffi,
            $importer,
            &$callbackError,
            &$fallbackKeys
        ): int {
            if ($callbackError !== null) {
                return ImporterStatus::NOT_FOUND;
            }

            try {
                $requested = self::toString($url) ?? '';
                $containing = self::toString($context->containing_url) ?: null;

                $resolved = $importer->canonicalize($requested, $context->from_import !== 0, $containing);

                // ext-sasso falls back to the configured load paths when the
                // userland importer declines; the C ABI would just fail here.
                if ($resolved === null) {
                    $resolved = $this->resolveThroughImportPaths($requested, $containing);

                    if ($resolved !== null) {
                        $fallbackKeys[$resolved] = true;
                    }
                }

                if ($resolved === null) {
                    return ImporterStatus::NOT_FOUND;
                }

                $ffi->sasso_importer_set_canonical($sink, $resolved, strlen($resolved));

                return ImporterStatus::OK;
            } catch (\Throwable $e) {
                $callbackError = $e;
                $message = $e->getMessage();
                $ffi->sasso_importer_set_error($sink, $message, strlen($message));

                return ImporterStatus::ERROR;
            }
        };

        $load = function ($userData, $canonical, $sink) use (
            $ffi,
            $importer,
            &$callbackError,
            &$fallbackKeys
        ): int {
            if ($callbackError !== null) {
                return ImporterStatus::NOT_FOUND;
            }

            try {
                $key = self::toString($canonical) ?? '';

                $loaded = isset($fallbackKeys[$key])
                    ? $this->loadFromDisk($key)
                    : $importer->load($key);

                if ($loaded === null) {
                    return ImporterStatus::NOT_FOUND;
                }

                if (!$loaded instanceof ImporterResult) {
                    throw new \TypeError(sprintf(
                        'Sasso\Importer::load() must return ?Sasso\ImporterResult, %s returned',
                        get_debug_type($loaded),
                    ));
                }

                $ffi->sasso_importer_set_result(
                    $sink,
                    $loaded->contents,
                    strlen($loaded->contents),
                    $loaded->syntax,
                    $loaded->sourceMapUrl,
                    $loaded->sourceMapUrl !== null ? strlen($loaded->sourceMapUrl) : 0,
                );

                return ImporterStatus::OK;
            } catch (\Throwable $e) {
                $callbackError = $e;
                $message = $e->getMessage();
                $ffi->sasso_importer_set_error($sink, $message, strlen($message));

                return ImporterStatus::ERROR;
            }
        };

        $keepalive[] = $canonicalize;
        $keepalive[] = $load;

        $native->canonicalize = $canonicalize;
        $native->load = $load;

        return $native;
    }

    /**
     * Emulate the built-in filesystem importer for URLs a custom importer
     * declined, following Sass's partial conventions.
     */
    private function resolveThroughImportPaths(string $url, ?string $containingUrl): ?string
    {
        $bases = [];
        if ($containingUrl !== null && is_file($containingUrl)) {
            $bases[] = dirname($containingUrl);
        }
        foreach ($this->importPaths as $importPath) {
            $bases[] = $importPath;
        }

        foreach ($bases as $base) {
            $path = $base . DIRECTORY_SEPARATOR . $url;
            $directory = dirname($path);
            $name = basename($path);

            if (preg_match('/\.(scss|sass|css)$/i', $name) === 1) {
                $candidates = [$path, $directory . DIRECTORY_SEPARATOR . '_' . $name];
            } else {
                $candidates = [];
                foreach (['scss', 'sass', 'css'] as $extension) {
                    $candidates[] = $directory . DIRECTORY_SEPARATOR . $name . '.' . $extension;
                    $candidates[] = $directory . DIRECTORY_SEPARATOR . '_' . $name . '.' . $extension;
                    $candidates[] = $path . DIRECTORY_SEPARATOR . '_index.' . $extension;
                    $candidates[] = $path . DIRECTORY_SEPARATOR . 'index.' . $extension;
                }
            }

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }

        return null;
    }

    /** Read a stylesheet the import-path fallback resolved. */
    private function loadFromDisk(string $path): ?ImporterResult
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return new ImporterResult($contents, match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'sass' => self::SYNTAX_SASS,
            'css' => self::SYNTAX_CSS,
            default => self::SYNTAX_SCSS,
        });
    }

    /**
     * Read a `const char *` coming out of the library as a PHP string.
     *
     * PHP's FFI marshals a plain `const char *` into a PHP string on its own,
     * but hands back CData for pointers it cannot classify that way (and for
     * struct fields). Both forms show up across these callbacks, so accept
     * either rather than assuming one.
     */
    private static function toString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof FFI\CData ? FFI::string($value) : (string) $value;
    }

    /** Allocate a NUL-terminated C string that PHP owns. */
    private function cString(FFI $ffi, string $value): FFI\CData
    {
        $length = strlen($value);
        // +1 for the terminator: FFI::new() zero-fills, so copying $length
        // bytes into a $length+1 buffer leaves it NUL-terminated.
        $size = $length + 1;
        $buffer = $ffi->new("char[{$size}]");
        FFI::memcpy($buffer, $value, $length);

        return $buffer;
    }
}
