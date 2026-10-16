<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * On iPhone, Web Push exists only for a Home Screen web app, and that needs a
 * web app manifest with display: standalone. Without it pushManager is
 * undefined and the phone never rings. (In the EU, iOS opens installed web
 * apps in Safari and there is no push at all — nothing to check, only to say.)
 */
final class ManifestCheck implements Check
{
    public function name(): string
    {
        return 'Web app manifest';
    }

    public function run(): Result
    {
        if (empty(config('beam.push.vapid.public'))) {
            return Result::ok('Web Push is not configured; a manifest is not needed yet.');
        }

        $url = (string) config('app.url');

        try {
            $html = Http::timeout(5)->get($url)->body();
        } catch (Throwable $e) {
            return Result::warn("Could not fetch {$url} to look for a manifest: {$e->getMessage()}", 'Make APP_URL reachable from this server, or check the manifest by hand.');
        }

        if (! preg_match('/<link[^>]+rel=["\']manifest["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m) && ! preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']manifest["\']/i', $html, $m)) {
            return Result::warn('No web app manifest is linked from the app, so iPhones cannot receive push notifications.', 'Add <link rel="manifest" href="/manifest.json"> with "display": "standalone"; users add the app to their Home Screen. docs/usage/push.md');
        }

        try {
            $manifest = Http::timeout(5)->get(str_starts_with($m[1], 'http') ? $m[1] : rtrim($url, '/').'/'.ltrim($m[1], '/'))->json();
        } catch (Throwable $e) {
            return Result::warn("The manifest at {$m[1]} could not be read: {$e->getMessage()}");
        }

        $display = is_array($manifest) ? ($manifest['display'] ?? null) : null;

        if (! in_array($display, ['standalone', 'fullscreen'], true)) {
            return Result::warn('The web app manifest does not set display to standalone, so iOS will not treat the app as installable and will not deliver push.', 'Set "display": "standalone" in the manifest.');
        }

        return Result::ok('A web app manifest with display: '.$display.' is linked; iPhones can be rung once the app is on the Home Screen (outside the EU).');
    }
}
