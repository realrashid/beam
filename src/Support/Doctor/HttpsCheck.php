<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

/**
 * getUserMedia, getDisplayMedia and Web Push exist only in a secure context:
 * https, or localhost. An http APP_URL in production is a call that cannot
 * open a camera.
 */
final class HttpsCheck implements Check
{
    public function name(): string
    {
        return 'HTTPS';
    }

    public function run(): Result
    {
        $url = (string) config('app.url', '');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');

        if ($scheme === 'https') {
            return Result::ok("APP_URL is https ({$host}); cameras, screens and push are allowed.");
        }

        if ($local) {
            return Result::ok("APP_URL is {$url}: browsers treat localhost as secure, so cameras and push work here.");
        }

        $message = "APP_URL is [{$url}]: browsers refuse the camera, microphone, screen and push on plain http away from localhost.";
        $fix = 'Serve the app over HTTPS and set APP_URL=https://… (Herd, Valet and lerd do this locally; a real certificate in production).';

        return app()->environment('production') ? Result::fail($message, $fix) : Result::warn($message, $fix);
    }
}
