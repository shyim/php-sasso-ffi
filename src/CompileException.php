<?php

declare(strict_types=1);

namespace Sasso;

/**
 * Thrown when SCSS/Sass compilation fails (a parse or evaluation error).
 *
 * The message is sasso's diagnostic — a byte-exact snippet when a `url`/path
 * is configured, otherwise the legacy `Error: <msg> (line:col)` one-liner.
 *
 * Matches ext-sasso, which extends \Exception and exposes no extra accessors:
 * the position is already part of the message, so adding sourceLine()/
 * sourceColumn() here would be API the extension does not have and code
 * written against the polyfill would break under the real thing.
 */
class CompileException extends \Exception
{
}
