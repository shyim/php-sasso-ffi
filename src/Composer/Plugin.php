<?php

declare(strict_types=1);

namespace Sasso\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Sasso\Downloader;
use Sasso\Exception as SassoException;
use Sasso\Platform;

/**
 * Fetches the native sasso library matching the host during install/update.
 *
 * The download is a convenience, not a hard requirement: the runtime can fetch
 * on demand too, so a failure here warns rather than aborting the whole
 * Composer run. That keeps `composer install` working on an unsupported
 * platform or behind a blocked network, where the user may only need the
 * package present for autoloading.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Remove the binaries this plugin put in the package directory; the
        // package itself is Composer's to delete.
        $root = Platform::packageRoot() . DIRECTORY_SEPARATOR . 'bin';
        if (!is_dir($root)) {
            return;
        }

        foreach (Platform::knownTargets() as $target) {
            $library = Platform::libraryPath($target);
            if (is_file($library)) {
                @unlink($library);
                @rmdir(dirname($library));
            }
        }

        @rmdir($root);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'installBinary',
            ScriptEvents::POST_UPDATE_CMD => 'installBinary',
        ];
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    /** Download the library for the current platform, warning on failure. */
    public function installBinary(): void
    {
        if (getenv('SASSO_SKIP_DOWNLOAD') === '1') {
            $this->io->write('<info>sasso:</info> skipping binary download (SASSO_SKIP_DOWNLOAD=1)');

            return;
        }

        try {
            $target = Platform::target();
        } catch (SassoException $e) {
            $this->io->writeError('<warning>sasso: ' . $e->getMessage() . '</warning>');

            return;
        }

        if (is_file(Platform::libraryPath($target))) {
            if ($this->io->isVerbose()) {
                $this->io->write(sprintf('<info>sasso:</info> %s already installed', $target));
            }

            return;
        }

        try {
            (new Downloader(fn (string $m) => $this->io->write('<info>sasso:</info> ' . $m)))->install($target);
        } catch (SassoException $e) {
            $this->io->writeError('<warning>sasso: ' . $e->getMessage() . '</warning>');
            $this->io->writeError(
                '<warning>sasso: the library will be downloaded on first use instead; '
                . 'run `composer sasso:install` to retry.</warning>'
            );
        }
    }
}
