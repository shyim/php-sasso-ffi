<?php

declare(strict_types=1);

namespace Sasso;

/**
 * Maps the running PHP process onto a sasso release target triple and knows
 * where the downloaded native library lives.
 *
 * The detection deliberately follows the PHP binary rather than the host: a
 * 32-bit or x86_64 PHP on an arm64 machine (Rosetta, multi-arch containers)
 * must load a library matching the *process*, not the CPU.
 */
final class Platform
{
    /** Release the bundled bindings were generated against. */
    public const VERSION = '0.8.2';

    private const REPOSITORY = 'shyim/sasso';

    /**
     * Target triple => [archive extension, library file name].
     *
     * @var array<string, array{string, string}>
     */
    private const TARGETS = [
        'x86_64-apple-darwin'         => ['tar.xz', 'libsasso.dylib'],
        'aarch64-apple-darwin'        => ['tar.xz', 'libsasso.dylib'],
        'x86_64-unknown-linux-gnu'    => ['tar.xz', 'libsasso.so'],
        'aarch64-unknown-linux-gnu'   => ['tar.xz', 'libsasso.so'],
        'x86_64-unknown-linux-musl'   => ['tar.xz', 'libsasso.so'],
        'aarch64-unknown-linux-musl'  => ['tar.xz', 'libsasso.so'],
        'x86_64-pc-windows-msvc'      => ['zip', 'sasso.dll'],
    ];

    /**
     * SHA-256 of each release archive, verified after download.
     *
     * Taken from the GitHub release asset digests for v0.8.2
     * (https://github.com/shyim/sasso/releases/tag/v0.8.2).
     *
     * @var array<string, string>
     */
    private const CHECKSUMS = [
        'x86_64-apple-darwin'        => '74376699c45ea1502f1b07d046280302750ced29e8262f45eb802038b3fb441f',
        'aarch64-apple-darwin'       => '5f511055100a96c935a734c783b880567fd65e54a87a51c583b7ca8223cee9e7',
        'x86_64-unknown-linux-gnu'   => 'd96cd7364e662eaa85feb22d4ec1bbe252e04fb1874a05a20a1821e17573c1bf',
        'aarch64-unknown-linux-gnu'  => '1c3062951f8f9b0e82ec4bb9d524d8659f791ed4e8780c536bd13f9acc78f014',
        'x86_64-unknown-linux-musl'  => '928ec81dabcb91ba2255425df956de5f4f18311fea4c2fbcf72b186836fadce2',
        'aarch64-unknown-linux-musl' => '64ada4707cce41fea5d371872bd6f521085ff44f91580c336955dd33d068062a',
        'x86_64-pc-windows-msvc'     => '70d5c6d4abfcd8524ccacc92eb4a4f524a13b9b41926d860eab596a60a2001e6',
    ];

    /**
     * The target triple for the current PHP process.
     *
     * @throws UnsupportedPlatformException when no release covers this platform
     */
    public static function target(): string
    {
        $override = getenv('SASSO_TARGET');
        if (is_string($override) && $override !== '') {
            if (!isset(self::TARGETS[$override])) {
                throw new UnsupportedPlatformException(sprintf(
                    'SASSO_TARGET=%s is not a published sasso target. Known targets: %s.',
                    $override,
                    implode(', ', array_keys(self::TARGETS)),
                ));
            }

            return $override;
        }

        $os = self::operatingSystem();
        $arch = self::architecture();
        $target = $arch . '-' . $os;

        if (!isset(self::TARGETS[$target])) {
            throw new UnsupportedPlatformException(sprintf(
                'sasso %s publishes no native library for %s (detected from PHP_OS_FAMILY=%s, machine=%s, %d-bit). '
                . 'Supported targets: %s. Build libsasso yourself and point SASSO_LIBRARY at it to continue.',
                self::VERSION,
                $target,
                PHP_OS_FAMILY,
                php_uname('m'),
                PHP_INT_SIZE * 8,
                implode(', ', array_keys(self::TARGETS)),
            ));
        }

        return $target;
    }

    /** All target triples this release publishes. */
    public static function knownTargets(): array
    {
        return array_keys(self::TARGETS);
    }

    /** File name of the shared library inside a target's archive. */
    public static function libraryName(string $target): string
    {
        if (!isset(self::TARGETS[$target])) {
            throw new UnsupportedPlatformException(sprintf('Unknown sasso target "%s".', $target));
        }

        return self::TARGETS[$target][1];
    }

    /** Name of the published release asset for a target. */
    public static function archiveName(string $target, string $version = self::VERSION): string
    {
        if (!isset(self::TARGETS[$target])) {
            throw new UnsupportedPlatformException(sprintf('Unknown sasso target "%s".', $target));
        }

        return sprintf('sasso-v%s-%s-c-api.%s', $version, $target, self::TARGETS[$target][0]);
    }

    /** Download URL of the release asset for a target. */
    public static function archiveUrl(string $target, string $version = self::VERSION): string
    {
        $base = getenv('SASSO_DOWNLOAD_BASE_URL');
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . self::archiveName($target, $version);
        }

        return sprintf(
            'https://github.com/%s/releases/download/v%s/%s',
            self::REPOSITORY,
            $version,
            self::archiveName($target, $version),
        );
    }

    /**
     * Expected SHA-256 of a target's archive, or null when unknown.
     *
     * A pinned checksum only describes the version these bindings ship with;
     * a user-supplied version or mirror is downloaded without one.
     */
    public static function archiveChecksum(string $target, string $version = self::VERSION): ?string
    {
        if ($version !== self::VERSION) {
            return null;
        }

        if (is_string(getenv('SASSO_DOWNLOAD_BASE_URL')) && getenv('SASSO_DOWNLOAD_BASE_URL') !== '') {
            return null;
        }

        return self::CHECKSUMS[$target] ?? null;
    }

    /**
     * Directory holding the extracted library for a target.
     *
     * Layout is bin/<target>/<library>, so one checkout can hold several
     * targets side by side (useful when a shared vendor/ is mounted into
     * containers of differing architecture).
     */
    public static function binaryDirectory(string $target): string
    {
        return self::packageRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $target;
    }

    /** Full path the library for a target is installed to. */
    public static function libraryPath(string $target): string
    {
        return self::binaryDirectory($target) . DIRECTORY_SEPARATOR . self::libraryName($target);
    }

    /** Root of this package (where bin/ is created). */
    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function operatingSystem(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'apple-darwin',
            'Linux' => self::isMusl() ? 'unknown-linux-musl' : 'unknown-linux-gnu',
            'Windows' => 'pc-windows-msvc',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    /**
     * Whether this Linux process is linked against musl (Alpine and friends).
     *
     * Checks for musl's dynamic linker first (cheap, reliable on Alpine), then
     * falls back to scanning ldd output when the linker path is non-standard.
     */
    private static function isMusl(): bool
    {
        $machine = strtolower(php_uname('m'));
        $arch = match ($machine) {
            'x86_64', 'amd64', 'x64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => $machine,
        };

        if (is_file('/lib/ld-musl-' . $arch . '.so.1')) {
            return true;
        }

        // Some images put the linker under /usr/lib or only expose musl via ldd.
        if (!function_exists('exec')) {
            return false;
        }

        $output = [];
        $status = 0;
        @exec('ldd /bin/sh 2>&1', $output, $status);

        return $status === 0 && str_contains(implode("\n", $output), 'musl');
    }


    private static function architecture(): string
    {
        // A 32-bit PHP has no sasso build, and reporting the 64-bit host arch
        // would produce a library it cannot load. Fail on the real constraint.
        if (PHP_INT_SIZE !== 8) {
            throw new UnsupportedPlatformException(
                'sasso requires a 64-bit PHP build; this process is 32-bit.'
            );
        }

        $machine = strtolower(php_uname('m'));

        return match ($machine) {
            'x86_64', 'amd64', 'x64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => $machine,
        };
    }
}
