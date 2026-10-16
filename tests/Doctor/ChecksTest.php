<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\VAPID;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Support\Doctor\AssetVersionCheck;
use RealRashid\Beam\Support\Doctor\BroadcastingCheck;
use RealRashid\Beam\Support\Doctor\CacheCheck;
use RealRashid\Beam\Support\Doctor\GateCheck;
use RealRashid\Beam\Support\Doctor\HttpsCheck;
use RealRashid\Beam\Support\Doctor\QueueCheck;
use RealRashid\Beam\Support\Doctor\ServiceWorkerCheck;
use RealRashid\Beam\Support\Doctor\StunCheck;
use RealRashid\Beam\Support\Doctor\VapidCheck;
use RealRashid\Beam\Support\Gate as BeamGate;

describe('BroadcastingCheck', function () {
    it('fails on log and null, names the fix', function (string $driver) {
        config()->set('broadcasting.default', $driver);
        $r = (new BroadcastingCheck)->run();
        expect($r->status)->toBe('fail')->and($r->fix)->toContain('install:broadcasting');
    })->with(['log', 'null']);

    it('checks that Reverb answers on its socket', function () {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options', ['host' => '127.0.0.1', 'port' => 8091]);
        expect((new BroadcastingCheck(fn () => true))->run()->status)->toBe('ok')
            ->and((new BroadcastingCheck(fn () => false))->run())->toMatchObject(['status' => 'fail'])
            ->and((new BroadcastingCheck(fn () => false))->run()->message)->toContain('127.0.0.1:8091');
        config()->set('broadcasting.connections.reverb.options', ['host' => '', 'port' => null]);
        expect((new BroadcastingCheck(fn () => true))->run()->message)->toContain('REVERB_HOST');
    });

    it('accepts any other driver', function () {
        config()->set('broadcasting.default', 'pusher');
        expect((new BroadcastingCheck)->run()->status)->toBe('ok');
    });
});

describe('StunCheck', function () {
    it('warns with none, fails on an unresolvable host, ok otherwise', function () {
        config()->set('beam.ice.stun', []);
        expect((new StunCheck)->run()->status)->toBe('warn');
        config()->set('beam.ice.stun', ['stun:stun.example.test:3478', 'nonsense']);
        $r = (new StunCheck(fn (string $host) => $host === 'stun.example.test'))->run();
        expect($r->status)->toBe('fail')->and($r->message)->toContain('nonsense')->not->toContain('stun.example.test');
        config()->set('beam.ice.stun', ['stun:stun.example.test:3478']);
        expect((new StunCheck(fn () => true))->run()->message)->toBe('1 STUN server configured and resolving.');
    });
});

describe('GateCheck', function () {
    it('warns locally and fails in production when beam.call is undefined; ok when defined', function () {
        $check = app(GateCheck::class);
        expect($check->run()->status)->toBe('warn');
        app()->detectEnvironment(fn () => 'production');
        expect($check->run()->status)->toBe('fail');
        Gate::define(BeamGate::CALL, fn () => true);
        expect($check->run()->status)->toBe('ok');
        app()->detectEnvironment(fn () => 'testing');
    });
});

describe('VapidCheck', function () {
    $keys = VAPID::createVapidKeys();

    it('warns when unset, fails on a bad key or subject, ok when whole', function () use ($keys) {
        config()->set('beam.push.vapid', ['public' => null, 'private' => null, 'subject' => null]);
        expect((new VapidCheck)->run()->status)->toBe('warn');

        config()->set('beam.push.vapid', ['public' => 'short', 'private' => $keys['privateKey'], 'subject' => 'mailto:a@b.c']);
        expect((new VapidCheck)->run())->toMatchObject(['status' => 'fail'])->and((new VapidCheck)->run()->message)->toContain('BEAM_VAPID_PUBLIC');

        config()->set('beam.push.vapid', ['public' => $keys['publicKey'], 'private' => 'short', 'subject' => 'mailto:a@b.c']);
        expect((new VapidCheck)->run()->message)->toContain('BEAM_VAPID_PRIVATE');

        config()->set('beam.push.vapid', ['public' => $keys['publicKey'], 'private' => $keys['privateKey'], 'subject' => null]);
        expect((new VapidCheck)->run()->status)->toBe('warn');

        config()->set('beam.push.vapid', ['public' => $keys['publicKey'], 'private' => $keys['privateKey'], 'subject' => 'ftp://nope']);
        expect((new VapidCheck)->run()->status)->toBe('fail');

        config()->set('beam.push.vapid', ['public' => $keys['publicKey'], 'private' => $keys['privateKey'], 'subject' => 'mailto:a@b.c']);
        expect((new VapidCheck)->run()->status)->toBe('ok');
    });
});

describe('ServiceWorkerCheck', function () {
    it('is quiet without push, then checks status, the scope header and caching', function () {
        config()->set('beam.push.vapid.public', null);
        expect((new ServiceWorkerCheck)->run()->status)->toBe('ok');

        config()->set('beam.push.vapid.public', 'x');
        // Http::fake() accumulates; one sequence answers the four fetches in order.
        Http::fake([route('beam.sw') => Http::sequence()
            ->push('', 404)
            ->push('self.addEventListener', 200, ['Cache-Control' => 'no-store'])
            ->push('self.addEventListener', 200, ['Service-Worker-Allowed' => '/', 'Cache-Control' => 'public, max-age=3600'])
            ->push('self.addEventListener', 200, ['Service-Worker-Allowed' => '/', 'Cache-Control' => 'no-store'])]);
        expect((new ServiceWorkerCheck)->run()->status)->toBe('fail');
        expect((new ServiceWorkerCheck)->run()->message)->toContain('Service-Worker-Allowed');
        expect((new ServiceWorkerCheck)->run()->status)->toBe('warn');
        expect((new ServiceWorkerCheck)->run()->status)->toBe('ok');
    });
});

describe('AssetVersionCheck', function () {
    it('reads the marker from the shipped build and agrees with Token::VERSION', function () {
        $r = (new AssetVersionCheck)->run();
        expect($r->status)->toBe('ok')->and($r->message)->toContain('protocol '.Token::VERSION);
    });
});

describe('HttpsCheck', function () {
    it('accepts https and localhost, warns on http locally, fails on http in production', function () {
        config()->set('app.url', 'https://app.test');
        expect((new HttpsCheck)->run()->status)->toBe('ok');
        config()->set('app.url', 'http://localhost:8000');
        expect((new HttpsCheck)->run()->status)->toBe('ok');
        config()->set('app.url', 'http://app.test');
        expect((new HttpsCheck)->run()->status)->toBe('warn');
        app()->detectEnvironment(fn () => 'production');
        expect((new HttpsCheck)->run()->status)->toBe('fail');
        app()->detectEnvironment(fn () => 'testing');
    });
});

describe('QueueCheck', function () {
    it('only cares on the queue driver, and warns on the sync connection', function () {
        config()->set('beam.call.timeout.driver', 'browser');
        expect((new QueueCheck)->run()->status)->toBe('ok');
        config()->set('beam.call.timeout.driver', 'queue');
        config()->set('queue.default', 'sync');
        expect((new QueueCheck)->run()->status)->toBe('warn');
        config()->set('queue.default', 'redis');
        expect((new QueueCheck)->run()->message)->toContain('redis queue');
    });
});

describe('CacheCheck', function () {
    // Beam holds every live call and every participant in the cache, so the
    // store deciding to forget — or being local to one of several servers — is
    // a call that cannot be joined, with nothing anywhere saying why.
    it('fails on a store that keeps nothing between requests', function (string $store) {
        config()->set('cache.default', $store);
        $r = app(CacheCheck::class)->run();

        expect($r->status)->toBe('fail')->and($r->message)->toContain($store)->and($r->fix)->toContain('CACHE_STORE');
    })->with(['array', 'null']);

    it('warns about a store that only this machine can see', function () {
        config()->set('cache.default', 'file');
        $r = app(CacheCheck::class)->run();

        expect($r->status)->toBe('warn')->and($r->message)->toContain('local to this machine')->and($r->fix)->toContain('redis');
    });

    it('passes a shared store, and says what cache:clear would cost', function () {
        config()->set('cache.default', 'shared');
        config()->set('cache.stores.shared', ['driver' => 'array']);   // a name the check treats as shared; the round trip is real
        $r = app(CacheCheck::class)->run();

        expect($r->status)->toBe('ok')->and($r->message)->toContain('cache:clear');
    });

    it('fails when the store cannot be used at all, rather than throwing', function () {
        config()->set('cache.default', 'nowhere');   // named in .env, never configured

        $r = app(CacheCheck::class)->run();

        expect($r->status)->toBe('fail')
            ->and($r->message)->toContain('could not be written to')
            ->and($r->fix)->toContain('credentials');
    });
});
