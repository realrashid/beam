<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RealRashid\Beam\Support\Doctor\ManifestCheck;

it('is not needed until push is configured', function () {
    expect((new ManifestCheck)->run()->status)->toBe('ok');
});

it('warns when no manifest is linked, when display is wrong, and passes when it is standalone', function () {
    config()->set('beam.push.vapid.public', 'PUB');

    // Http::fake() accumulates stubs across calls, so each case gets its own host.
    config()->set('app.url', 'https://none.test');
    Http::fake(['none.test' => Http::response('<html><head></head></html>')]);
    $none = (new ManifestCheck)->run();
    expect($none->status)->toBe('warn')->and($none->message)->toContain('iPhones')->and($none->fix)->toContain('standalone');

    config()->set('app.url', 'https://browser.test');
    Http::fake(['browser.test/manifest.json' => Http::response(['display' => 'browser']), 'browser.test' => Http::response('<link rel="manifest" href="/manifest.json">')]);
    expect((new ManifestCheck)->run()->status)->toBe('warn');

    config()->set('app.url', 'https://ok.test');
    Http::fake(['ok.test/manifest.json' => Http::response(['display' => 'standalone']), 'ok.test' => Http::response('<link href="/manifest.json" rel="manifest">')]);
    $ok = (new ManifestCheck)->run();
    expect($ok->status)->toBe('ok')->and($ok->message)->toContain('EU');
});
