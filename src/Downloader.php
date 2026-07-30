<?php

declare(strict_types=1);

namespace Sasso;

/**
 * Fetches and unpacks the native library for one target.
 *
 * Used from two places: the Composer plugin at install time, and the runtime
 * as a lazy fallback when the plugin never ran (`--no-plugins`, a vendor
 * directory built on another platform, a PHAR).
 */
final class Downloader
{
    /** @param (callable(string): void)|null $log receives human-readable progress lines */
    public function __construct(
        private readonly mixed $log = null,
    ) {
    }

    /**
     * Ensure the library for $target exists on disk and return its path.
     *
     * Already-installed libraries are returned untouched, so this is cheap to
     * call on every install and safe to call concurrently-ish: the extraction
     * happens in a temporary directory and is moved into place atomically.
     */
    public function install(string $target, string $version = Platform::VERSION, bool $force = false): string
    {
        $destination = Platform::libraryPath($target);

        if (!$force && is_file($destination)) {
            return $destination;
        }

        $url = Platform::archiveUrl($target, $version);
        $this->log(sprintf('Downloading sasso %s for %s', $version, $target));

        $archive = $this->download($url, Platform::archiveName($target, $version));

        try {
            $expected = Platform::archiveChecksum($target, $version);
            if ($expected !== null) {
                $actual = hash_file('sha256', $archive);
                if (!hash_equals($expected, (string) $actual)) {
                    throw new DownloadException(sprintf(
                        "Checksum mismatch for %s.\n  expected sha256 %s\n  actual   sha256 %s\n"
                        . 'Refusing to install a library that does not match the pinned release.',
                        $url,
                        $expected,
                        (string) $actual,
                    ));
                }
            }

            return $this->extract($archive, $target, $destination);
        } finally {
            @unlink($archive);
        }
    }

    /** Download $url to a temporary file and return its path. */
    private function download(string $url, string $name): string
    {
        $temp = $this->temporaryPath($name);

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 120,
                'user_agent' => 'sasso-php/' . Platform::VERSION,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $source = @fopen($url, 'rb', false, $context);
        if ($source === false) {
            throw new DownloadException(sprintf(
                'Could not download %s: %s',
                $url,
                $this->lastErrorMessage(),
            ));
        }

        $sink = @fopen($temp, 'wb');
        if ($sink === false) {
            fclose($source);
            throw new DownloadException(sprintf('Could not write to %s.', $temp));
        }

        $copied = @stream_copy_to_stream($source, $sink);
        fclose($source);
        fclose($sink);

        if ($copied === false || $copied === 0) {
            @unlink($temp);
            throw new DownloadException(sprintf('Downloaded no data from %s.', $url));
        }

        return $temp;
    }

    /**
     * Unpack the single library file out of $archive to $destination.
     *
     * Extraction goes to a scratch directory first so a failure part-way
     * through never leaves a truncated library where the loader will find it.
     */
    private function extract(string $archive, string $target, string $destination): string
    {
        $library = Platform::libraryName($target);
        $scratch = $this->temporaryPath('sasso-extract-' . $target);

        if (!@mkdir($scratch, 0o777, true) && !is_dir($scratch)) {
            throw new DownloadException(sprintf('Could not create temporary directory %s.', $scratch));
        }

        try {
            if (str_ends_with($archive, '.zip')) {
                $this->extractZip($archive, $scratch);
            } else {
                $this->extractTarXz($archive, $scratch);
            }

            $extracted = $this->locate($scratch, $library);

            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
                throw new DownloadException(sprintf('Could not create %s.', $directory));
            }

            // rename() over an existing file is atomic on POSIX; on Windows it
            // fails, so clear the old library out of the way first.
            if (is_file($destination)) {
                @unlink($destination);
            }

            if (!@rename($extracted, $destination)) {
                if (!@copy($extracted, $destination)) {
                    throw new DownloadException(sprintf('Could not install the library to %s.', $destination));
                }
            }

            @chmod($destination, 0o755);
            $this->log(sprintf('Installed %s', $destination));

            return $destination;
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    private function extractZip(string $archive, string $into): void
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                throw new DownloadException(sprintf('Could not open the downloaded archive %s.', $archive));
            }

            $ok = $zip->extractTo($into);
            $zip->close();

            if (!$ok) {
                throw new DownloadException(sprintf('Could not extract %s.', $archive));
            }

            return;
        }

        // ext-zip is not always present; PowerShell ships with Windows, which
        // is the only platform whose asset is a zip.
        $this->run(sprintf(
            'powershell -NoProfile -NonInteractive -Command "Expand-Archive -LiteralPath %s -DestinationPath %s -Force"',
            escapeshellarg($archive),
            escapeshellarg($into),
        ), $archive);
    }

    private function extractTarXz(string $archive, string $into): void
    {
        // PharData cannot read xz, so tar(1) is the primary path. It is present
        // on every macOS and on Linux images that can run Composer at all.
        $this->run(sprintf(
            'tar -xJf %s -C %s',
            escapeshellarg($archive),
            escapeshellarg($into),
        ), $archive);
    }

    private function run(string $command, string $archive): void
    {
        $output = [];
        $status = 0;
        @exec($command . ' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new DownloadException(sprintf(
                "Could not extract %s.\n  command: %s\n  output: %s",
                $archive,
                $command,
                trim(implode("\n", $output)) ?: '(none)',
            ));
        }
    }

    /** Find $name anywhere under $directory (archives nest under a versioned folder). */
    private function locate(string $directory, string $name): string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() === $name) {
                return $file->getPathname();
            }
        }

        throw new DownloadException(sprintf(
            'The downloaded archive did not contain %s. The release layout may have changed.',
            $name,
        ));
    }

    private function temporaryPath(string $name): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sasso-' . bin2hex(random_bytes(6)) . '-' . $name;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function lastErrorMessage(): string
    {
        $error = error_get_last();

        return isset($error['message']) ? trim($error['message']) : 'unknown error';
    }

    private function log(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
