<?php

declare(strict_types=1);

namespace Sasso;

/**
 * Importer callback return codes from sasso.h.
 *
 * Internal to the polyfill. These live outside Compiler so its reflected
 * constant list stays byte-identical to ext-sasso's — a consumer inspecting
 * `Compiler::getConstants()` should see the five STYLE and SYNTAX entries the
 * extension defines and nothing more.
 *
 * @internal
 */
final class ImporterStatus
{
    /** Handled: the host called set_canonical / set_result. */
    public const OK = 1;

    /** This importer does not handle the URL. */
    public const NOT_FOUND = 0;

    /** Handled but failed: the host called set_error. */
    public const ERROR = -1;
}
