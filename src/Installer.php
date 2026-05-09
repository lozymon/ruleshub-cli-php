<?php

declare(strict_types=1);

namespace RulesHub\Cli;

use Composer\IO\IOInterface;
use RuntimeException;
use ZipArchive;

/**
 * Downloads the native ruleshub binary matching the host platform from
 * GitHub Releases, verifies its SHA256 against the published SHA256SUMS,
 * and places it at packages-php/cli/bin/ruleshub (overwriting the PHP
 * placeholder shim shipped in the package).
 *
 * Called from Plugin::onPackageEvent when ruleshub/cli is installed or
 * updated. The version this wrapper installs is bumped on every release —
 * see BINARY_VERSION below. To pin a specific version for testing, set
 * the RULESHUB_VERSION env var before running `composer install`.
 */
final class Installer
{
    private const REPO = 'lozymon/ruleshub';
    private const BINARY_VERSION = '0.1.0-alpha.3';

    public static function install(IOInterface $io): void
    {
        try {
            self::run($io);
        } catch (\Throwable $e) {
            $io->writeError('<error>ruleshub installer failed: ' . $e->getMessage() . '</error>');
            $io->writeError('  the placeholder bin/ruleshub will print an error if executed.');
            $io->writeError('  re-run via: composer reinstall ruleshub/cli');
            // Don't propagate — failing here would abort the user's whole
            // `composer install` for an entire project just because of a
            // network blip.
        }
    }

    private static function run(IOInterface $io): void
    {
        $version = getenv('RULESHUB_VERSION') ?: self::BINARY_VERSION;
        $target = self::detectTarget();
        $isWindows = str_contains($target, 'windows');
        $ext = $isWindows ? 'zip' : 'tar.gz';

        $archive = sprintf('ruleshub-%s-%s.%s', $version, $target, $ext);
        $base = sprintf('https://github.com/%s/releases/download/cli-v%s', self::REPO, $version);
        $archiveUrl = "{$base}/{$archive}";
        $sumsUrl = "{$base}/SHA256SUMS";

        $io->write("<info>ruleshub: installing v{$version} for {$target}…</info>");

        $tmpDir = sys_get_temp_dir() . '/ruleshub-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0o755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("could not create temp dir: {$tmpDir}");
        }

        try {
            $archivePath = $tmpDir . '/' . $archive;
            self::download($archiveUrl, $archivePath, $io);

            $sumsPath = $tmpDir . '/SHA256SUMS';
            self::download($sumsUrl, $sumsPath, $io);

            $expected = self::expectedHashFromSums($sumsPath, $archive);
            $actual = hash_file('sha256', $archivePath);
            if ($actual !== $expected) {
                throw new RuntimeException("checksum mismatch for {$archive}");
            }

            self::extract($archivePath, $tmpDir, $isWindows);

            $extractedDir = "{$tmpDir}/ruleshub-{$version}-{$target}";
            $extractedBin = "{$extractedDir}/ruleshub" . ($isWindows ? '.exe' : '');
            if (!is_file($extractedBin)) {
                throw new RuntimeException("expected binary not found in archive: {$extractedBin}");
            }

            // The PHP launcher at bin/ruleshub stays put forever; the native
            // binary lives at bin/ruleshub-bin (or .exe). The launcher exec's it.
            $packageDir = self::packageDir();
            $binPath = "{$packageDir}/bin/ruleshub-bin" . ($isWindows ? '.exe' : '');

            if (!@copy($extractedBin, $binPath)) {
                throw new RuntimeException("failed to write binary to {$binPath}");
            }
            chmod($binPath, 0o755);

            $io->write("<info>ruleshub: installed v{$version} at {$binPath}</info>");
        } finally {
            self::rmrf($tmpDir);
        }
    }

    private static function detectTarget(): string
    {
        $os = PHP_OS_FAMILY;
        $arch = strtolower((string) php_uname('m'));

        return match (true) {
            $os === 'Linux'   && in_array($arch, ['x86_64', 'amd64'], true)   => 'x86_64-unknown-linux-musl',
            $os === 'Linux'   && in_array($arch, ['aarch64', 'arm64'], true)  => 'aarch64-unknown-linux-musl',
            $os === 'Darwin'  && in_array($arch, ['x86_64', 'amd64'], true)   => 'x86_64-apple-darwin',
            $os === 'Darwin'  && in_array($arch, ['arm64', 'aarch64'], true)  => 'aarch64-apple-darwin',
            $os === 'Windows' && in_array($arch, ['amd64', 'x86_64'], true)   => 'x86_64-pc-windows-msvc',
            default => throw new RuntimeException("unsupported platform: {$os}/{$arch}"),
        };
    }

    private static function packageDir(): string
    {
        return dirname(__DIR__);
    }

    private static function download(string $url, string $dest, IOInterface $io): void
    {
        $io->write("  → downloading {$url}");
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: ruleshub-composer-installer\r\n",
                'follow_location' => 1,
                'timeout' => 60,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $err = error_get_last();
            throw new RuntimeException("download failed for {$url}: " . ($err['message'] ?? 'unknown'));
        }
        if (file_put_contents($dest, $body) === false) {
            throw new RuntimeException("write failed for {$dest}");
        }
    }

    private static function expectedHashFromSums(string $sumsPath, string $archive): string
    {
        $contents = file_get_contents($sumsPath);
        if ($contents === false) {
            throw new RuntimeException('could not read SHA256SUMS');
        }
        $pattern = '/^([0-9a-f]{64})\s+' . preg_quote($archive, '/') . '$/m';
        if (!preg_match($pattern, $contents, $m)) {
            throw new RuntimeException("no checksum found for {$archive} in SHA256SUMS");
        }
        return $m[1];
    }

    private static function extract(string $archivePath, string $destDir, bool $isWindows): void
    {
        if ($isWindows) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException("failed to open zip: {$archivePath}");
            }
            $zip->extractTo($destDir);
            $zip->close();
            return;
        }

        $cmd = sprintf(
            'tar -xzf %s -C %s',
            escapeshellarg($archivePath),
            escapeshellarg($destDir),
        );
        $rc = 0;
        passthru($cmd, $rc);
        if ($rc !== 0) {
            throw new RuntimeException("tar extraction failed (rc={$rc})");
        }
    }

    private static function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
