<?php

declare(strict_types=1);

namespace Sasso\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sasso\Platform;
use Sasso\UnsupportedPlatformException;

final class PlatformTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SASSO_TARGET');
        putenv('SASSO_DOWNLOAD_BASE_URL');
    }

    public function testDetectsATargetThisReleasePublishes(): void
    {
        $this->assertContains(Platform::target(), Platform::knownTargets());
    }

    public function testTargetCanBeOverriddenByEnvironment(): void
    {
        putenv('SASSO_TARGET=x86_64-unknown-linux-gnu');

        $this->assertSame('x86_64-unknown-linux-gnu', Platform::target());
    }

    public function testUnknownTargetOverrideIsRejected(): void
    {
        putenv('SASSO_TARGET=vax-unknown-ultrix');

        $this->expectException(UnsupportedPlatformException::class);

        Platform::target();
    }

    #[DataProvider('targets')]
    public function testArchiveNamingMatchesTheReleaseAssets(string $target, string $library, string $archive): void
    {
        $this->assertSame($library, Platform::libraryName($target));
        $this->assertSame($archive, Platform::archiveName($target));
        $this->assertSame(
            'https://github.com/shyim/sasso/releases/download/v0.8.2/' . $archive,
            Platform::archiveUrl($target),
        );
    }

    public static function targets(): iterable
    {
        yield ['x86_64-apple-darwin', 'libsasso.dylib', 'sasso-v0.8.2-x86_64-apple-darwin-c-api.tar.xz'];
        yield ['aarch64-apple-darwin', 'libsasso.dylib', 'sasso-v0.8.2-aarch64-apple-darwin-c-api.tar.xz'];
        yield ['x86_64-unknown-linux-gnu', 'libsasso.so', 'sasso-v0.8.2-x86_64-unknown-linux-gnu-c-api.tar.xz'];
        yield ['aarch64-unknown-linux-gnu', 'libsasso.so', 'sasso-v0.8.2-aarch64-unknown-linux-gnu-c-api.tar.xz'];
        yield ['x86_64-unknown-linux-musl', 'libsasso.so', 'sasso-v0.8.2-x86_64-unknown-linux-musl-c-api.tar.xz'];
        yield ['aarch64-unknown-linux-musl', 'libsasso.so', 'sasso-v0.8.2-aarch64-unknown-linux-musl-c-api.tar.xz'];
        yield ['x86_64-pc-windows-msvc', 'sasso.dll', 'sasso-v0.8.2-x86_64-pc-windows-msvc-c-api.zip'];
    }


    public function testEveryPublishedTargetHasAPinnedChecksum(): void
    {
        foreach (Platform::knownTargets() as $target) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                (string) Platform::archiveChecksum($target),
                $target . ' must ship a pinned sha256',
            );
        }
    }

    public function testChecksumIsNotClaimedForOtherVersionsOrMirrors(): void
    {
        $this->assertNull(Platform::archiveChecksum('aarch64-apple-darwin', '0.9.0'));

        putenv('SASSO_DOWNLOAD_BASE_URL=https://mirror.example.com/sasso');
        $this->assertNull(Platform::archiveChecksum('aarch64-apple-darwin'));
    }

    public function testMirrorOverrideChangesTheDownloadUrl(): void
    {
        putenv('SASSO_DOWNLOAD_BASE_URL=https://mirror.example.com/sasso/');

        $this->assertSame(
            'https://mirror.example.com/sasso/sasso-v0.8.2-aarch64-apple-darwin-c-api.tar.xz',
            Platform::archiveUrl('aarch64-apple-darwin'),
        );
    }

    public function testBinariesAreKeptPerTarget(): void
    {
        $this->assertStringEndsWith(
            'bin' . DIRECTORY_SEPARATOR . 'aarch64-apple-darwin' . DIRECTORY_SEPARATOR . 'libsasso.dylib',
            Platform::libraryPath('aarch64-apple-darwin'),
        );

        $this->assertNotSame(
            Platform::libraryPath('aarch64-apple-darwin'),
            Platform::libraryPath('x86_64-apple-darwin'),
        );
    }
}
