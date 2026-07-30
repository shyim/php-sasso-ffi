<?php

declare(strict_types=1);

namespace Sasso\Composer;

use Sasso\Downloader;
use Sasso\Exception as SassoException;
use Sasso\Platform;

/**
 * Shared install logic for the Composer command and the bin/sasso-install CLI.
 *
 * Composer only loads plugins from installed packages, not from the root
 * package of a checkout. The bin script covers that gap (and CI / --no-plugins).
 */
final class BinaryInstaller
{
    /** @param (callable(string): void)|null $log */
    public function __construct(
        private readonly mixed $log = null,
    ) {
    }

    /**
     * @param  list<string> $requested empty = detect host; may include "all"
     * @return list<string> absolute paths installed
     */
    public function install(array $requested = [], bool $force = false): array
    {
        $targets = $this->resolveTargets($requested);
        $downloader = new Downloader($this->log);
        $paths = [];

        foreach ($targets as $target) {
            $paths[] = $downloader->install($target, Platform::VERSION, $force);
            $this->log(sprintf('%s ready at %s', $target, end($paths)));
        }

        return $paths;
    }

    /**
     * @param  list<string> $requested
     * @return list<string>
     */
    public function resolveTargets(array $requested): array
    {
        if ($requested === []) {
            return [Platform::target()];
        }

        if (in_array('all', $requested, true)) {
            return Platform::knownTargets();
        }

        $known = Platform::knownTargets();
        foreach ($requested as $target) {
            if (!in_array($target, $known, true)) {
                throw new SassoException(sprintf(
                    "Unknown target \"%s\". Available targets:\n  %s",
                    $target,
                    implode("\n  ", $known),
                ));
            }
        }

        return array_values($requested);
    }

    private function log(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
