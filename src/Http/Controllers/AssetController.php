<?php

declare(strict_types=1);

namespace RealRashid\Beam\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the compiled client and the service worker from the Composer package,
 * so an application with no build step still gets both.
 */
final class AssetController
{
    private static ?string $version = null;

    public function client(): BinaryFileResponse
    {
        return response()->file(self::dist('beam.js'), [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Busted by ?v= in the tag @beamAssets prints.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * A worker served from {prefix}/sw.js may only control {prefix}/* unless
     * the server widens its scope — this header does, and the client
     * registers it with scope '/'. Never cached: a stale worker is the
     * hardest bug a user can report.
     */
    public function serviceWorker(): BinaryFileResponse
    {
        return response()->file(self::dist('beam-sw.js'), [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public static function dist(string $file): string
    {
        return __DIR__.'/../../../resources/dist/'.$file;
    }

    /**
     * A short hash of the compiled client, for cache busting. Held for the life
     * of the process: @beamAssets prints it on every page, and the file it
     * hashes cannot change without a deploy.
     */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $path = self::dist('beam.js');

        return self::$version = is_file($path) ? substr((string) md5_file($path), 0, 12) : 'dev';
    }

    /** Forget the memoised version — for tests that replace the built client. */
    public static function forgetVersion(): void
    {
        self::$version = null;
    }
}
