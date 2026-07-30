<?php

declare(strict_types=1);

namespace Sasso;

/**
 * The source an [`Importer::load`] produced — dart-sass's `ImporterResult`.
 *
 * ```php
 * $r = new Sasso\ImporterResult(
 *     '.a { color: red; }',
 *     Sasso\Compiler::SYNTAX_SCSS, // optional, defaults to SCSS
 * );
 * ```
 */
class ImporterResult
{
    /** The stylesheet source text. */
    public string $contents;

    /**
     * The syntax `contents` is parsed with (a `Compiler::SYNTAX_*` constant;
     * defaults to `SYNTAX_SCSS`).
     */
    public int $syntax;

    /**
     * The URL recorded for this source in generated source maps; `null` falls
     * back to the canonical URL.
     */
    public ?string $sourceMapUrl;

    /**
     * Construct an importer result from `contents`, an optional `SYNTAX_*`
     * constant (default SCSS), and an optional source-map URL.
     */
    public function __construct(string $contents, ?int $syntax = null, ?string $sourceMapUrl = null)
    {
        $this->contents = $contents;
        $this->syntax = $syntax ?? Compiler::SYNTAX_SCSS;
        $this->sourceMapUrl = $sourceMapUrl;
    }
}
