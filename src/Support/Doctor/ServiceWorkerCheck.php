<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The push worker is served from {prefix}/sw.js and must control scope "/",
 * which the browser allows only with Service-Worker-Allowed: /. A proxy that
 * strips the header, or a cache that serves an old worker, breaks ringing
 * silently.
 */
final class ServiceWorkerCheck implements Check
{
    public function name(): string
    {
        return 'Service worker';
    }

    public function run(): Result
    {
        if (empty(config('beam.push.vapid.public'))) {
            return Result::ok('Web Push is not configured; the worker is not needed yet.');
        }

        $url = route('beam.sw');

        try {
            $response = Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            return Result::warn("Could not fetch {$url}: {$e->getMessage()}", 'Make APP_URL reachable from this server, or check the worker by hand in a browser.');
        }

        if ($response->status() !== 200) {
            return Result::fail("{$url} answered {$response->status()} instead of 200.", 'Make sure the beam.sw route is not behind auth or a firewall; it must be public.');
        }

        if ($response->header('Service-Worker-Allowed') !== '/') {
            return Result::fail("{$url} is served without Service-Worker-Allowed: /, so the browser will refuse to register it at scope /.", 'Something between PHP and the browser strips the header — a proxy, CDN or static-file handler. Serve the route through Laravel.');
        }

        if (! str_contains((string) $response->header('Cache-Control'), 'no-store')) {
            return Result::warn("{$url} is cacheable; an old worker may keep running after an update.", 'Keep the route served by Laravel, which sends Cache-Control: no-store.');
        }

        return Result::ok('The push worker is served from '.$url.' with the header that allows scope /.');
    }
}
