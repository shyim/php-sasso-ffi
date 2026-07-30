<?php

declare(strict_types=1);

namespace Sasso;

/**
 * A userland resolver for `@import` / `@use` / `@forward`, mirroring dart-sass's
 * two-phase importer protocol.
 *
 * Implement this in PHP and pass an instance to `Compiler::setImporter()` to
 * control where partials come from (a database, a virtual filesystem, an
 * archive, …). Resolution happens in two phases:
 *
 * 1. `canonicalize()` maps a (possibly relative, extension-less) URL — exactly
 *    as written in the source, e.g. `"base"` for `@import "base"` — to a stable
 *    canonical string that identifies the partial. It MUST NOT load the file.
 *    Two URLs that canonicalize to the same string are the SAME partial (it is
 *    the module-cache / dedup key). Return `null` if this importer cannot
 *    resolve the URL, and any configured import paths are tried as a fallback.
 * 2. `load()` is then given a canonical string this importer returned and
 *    fetches its source as a `Sasso\ImporterResult` (or `null` if it can no
 *    longer be found).
 *
 * ```php
 * class ArrayImporter implements Sasso\Importer {
 *     public function __construct(private array $files) {}
 *     public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string {
 *         return isset($this->files[$url]) ? "array:$url" : null;
 *     }
 *     public function load(string $canonicalUrl): ?Sasso\ImporterResult {
 *         $key = substr($canonicalUrl, strlen('array:'));
 *         return new Sasso\ImporterResult($this->files[$key]);
 *     }
 * }
 * ```
 */
interface Importer
{
    /**
     * Map a URL to its canonical identity, or `null` if not handled. MUST NOT
     * load the file.
     *
     * `$fromImport` is `true` for `@import` (which also allows import-only
     * files), `false` for `@use`/`@forward`; `$containingUrl` is the canonical
     * URL of the stylesheet making the request (or `null`), against which
     * relative URLs resolve.
     */
    public function canonicalize(string $url, bool $fromImport, ?string $containingUrl = null): ?string;

    /**
     * Load the source for a canonical string previously returned by
     * `canonicalize()`. Returns a `Sasso\ImporterResult`, or `null` if it can no
     * longer be found.
     */
    public function load(string $canonicalUrl): ?ImporterResult;
}
