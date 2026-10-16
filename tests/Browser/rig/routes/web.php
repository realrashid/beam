<?php

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Contracts\PushSender;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Events\CallAnswered;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Events\CallRinging;
use RealRashid\Beam\Events\FileAccepted;
use RealRashid\Beam\Events\FileDeclined;
use RealRashid\Beam\Events\FileOffered;
use RealRashid\Beam\Events\FileTransferred;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Push\Subscriptions;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;

/*
 * The rig: the smallest Laravel app that can prove two browsers connect
 * through Beam. Test-only conveniences (login-as) exist because this app
 * never leaves the test suite.
 */

Route::get('/', fn () => 'beam rig');

Route::get('/login-as/{id}', function (int $id) {
    abort_unless(app()->environment('local', 'testing'), 404);

    // Keyed by id, not found-or-created by email: specs hardcode "/call/2" to
    // mean "User 2", which is only true if its primary key really is 2 — and
    // auto-increment only gives it that key if /login-as/1 happens to run
    // first. Whichever test logs in as 2 first otherwise gets id 1.
    $user = User::firstOrCreate(
        ['id' => $id],
        ['name' => "User {$id}", 'email' => "user{$id}@rig.test", 'password' => bcrypt('secret')],
    );

    Auth::login($user);
    request()->session()->regenerate();

    return response()->json(['id' => $user->id, 'name' => $user->name]);
});

// The rig records the last few Beam events so a spec can assert the server saw them.
foreach ([CallRinging::class, CallAnswered::class, CallDeclined::class, CallEnded::class] as $event) {
    Event::listen($event, function (object $e) use ($event) {
        $log = Cache::get('rig:events', []);
        $log[] = ['event' => class_basename($event), 'call' => $e->call->id, 'reason' => $e->reason ?? null, 'duration' => $e->duration ?? null, 'by' => $e->by?->id ?? null];
        Cache::put('rig:events', array_slice($log, -20), 3600);
    });
}
foreach ([FileOffered::class, FileAccepted::class, FileDeclined::class, FileTransferred::class] as $event) {
    Event::listen($event, function (object $e) use ($event) {
        $log = Cache::get('rig:events', []);
        $log[] = ['event' => class_basename($event), 'transferId' => $e->transferId, 'from' => $e->from->id, 'to' => $e->to->id, 'file' => ['name' => $e->file->name, 'size' => $e->file->size]];
        Cache::put('rig:events', array_slice($log, -20), 3600);
    });
}
Route::get('/test/events', fn () => response()->json(Cache::get('rig:events', [])));

// The rig records Web Push sends instead of delivering them: headless browsers have no push service.
app()->instance(PushSender::class, new class implements PushSender
{
    public function send(array $subscription, array $payload): array
    {
        $log = Cache::get('rig:pushes', []);
        $log[] = ['endpoint' => $subscription['endpoint'], 'callId' => $payload['data']['callId'] ?? null, 'title' => $payload['title'] ?? null];
        Cache::put('rig:pushes', array_slice($log, -20), 3600);

        return ['ok' => true, 'status' => 201, 'expired' => false, 'reason' => null];
    }
});
Route::get('/test/pushes', fn () => response()->json(Cache::get('rig:pushes', [])));
Route::post('/test/pushes/clear', function () {
    Cache::forget('rig:pushes');
    Cache::forget('beam:online:2');

    return response()->noContent();
});
// Subscriptions persist across runs; a spec that counts pushes starts with none.
Route::post('/test/subscriptions/clear', function () {
    app(Subscriptions::class)->store()->forget(2);

    return response()->noContent();
});
Route::post('/test/events/clear', function () {
    Cache::forget('rig:events');

    return response()->noContent();
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    // Ring another user; hand the caller's token back as JSON.
    Route::post('/call/{userId}', function (int $userId) {
        $call = Beam::call(User::findOrFail($userId))->video()->about('Ticket #42')->timeout((int) request()->query('timeout', 30))->ring();

        return response()->json(['callId' => $call->id, 'token' => $call->token]);
    });

    Route::get('/join', fn () => view('join', ['token' => request()->query('token')]));

    // The same two lines in three frontends: each page joins the given room through its adapter.
    Route::get('/adapters/{frontend}/{name}', function (string $frontend, string $name) {
        abort_unless(in_array($frontend, ['jquery', 'vue', 'react'], true), 404);
        $user = request()->user();
        $channel = Channels::room($name);
        $ttl = 3600;
        app(CallRegistry::class)->allow($channel, $user->getAuthIdentifier(), $ttl);
        app(CallRegistry::class)->put($name, ['name' => $name, 'allowed' => [], 'media' => 'video', 'screenShare' => true, 'createdAt' => time()], $ttl);
        $token = new Token(callId: $name, channel: $channel, role: 'participant', userId: $user->getAuthIdentifier(), ice: beam()->ice($user->getAuthIdentifier()), expiresAt: time() + $ttl,
            meta: ['transport' => config('beam.signaling.transport'), 'media' => 'video', 'heartbeat' => 30]);

        return view("adapters.{$frontend}", ['token' => $token->pack(app(Encrypter::class))]);
    });

    // Phase 12: the screens beam:install --views copied into this app (stubs.spec.ts runs the command first).
    Route::get('/stub/call/{name}', function (string $name) {
        $user = request()->user();
        $channel = Channels::room($name);
        $ttl = 3600;
        app(CallRegistry::class)->allow($channel, $user->getAuthIdentifier(), $ttl);
        app(CallRegistry::class)->put($name, ['name' => $name, 'allowed' => [], 'media' => 'video', 'screenShare' => true, 'createdAt' => time()], $ttl);
        $token = new Token(callId: $name, channel: $channel, role: 'participant', userId: $user->getAuthIdentifier(), ice: beam()->ice($user->getAuthIdentifier()), expiresAt: time() + $ttl,
            meta: ['transport' => config('beam.signaling.transport'), 'media' => 'video', 'heartbeat' => 30]);

        return view('beam.call', ['token' => $token->pack(app(Encrypter::class))]);
    });
    Route::get('/stub/dashboard', fn () => view('stub-dashboard'));

    // Phase 1: a hard-coded room. Phase 2 makes the token the whole contract;
    // Phase 3 replaces this with Beam::call() / Beam::room().
    Route::get('/room/{name}', function (string $name) {
        $user = request()->user();
        $channel = Channels::room($name);
        $ttl = (int) config('beam.token.ttl', 3600);

        app(CallRegistry::class)->allow($channel, $user->getAuthIdentifier(), $ttl);
        app(CallRegistry::class)->put($name, ['name' => $name, 'allowed' => [], 'media' => 'video', 'screenShare' => true, 'createdAt' => time()], $ttl);

        // Phase 2: the token is the whole contract. The page below contains no
        // channel name, no ICE server, no role and no transport of its own.
        $token = new Token(
            callId: $name,
            channel: $channel,
            role: 'participant',
            userId: $user->getAuthIdentifier(),
            ice: beam()->ice($user->getAuthIdentifier()),
            expiresAt: time() + $ttl,
            meta: ['transport' => request()->query('transport', config('beam.signaling.transport')), 'media' => 'video', 'heartbeat' => 30, 'reconnectTimeout' => (int) request()->query('reconnect', config('beam.call.reconnect_timeout', 30))],
        );

        // The label must not leak what the token carries: the connect spec asserts
        // the page contains no channel name, ICE server or transport of its own.
        return view('room', ['label' => "room {$name} — user {$user->getAuthIdentifier()}", 'token' => $token->pack(app(Encrypter::class))]);
    })->name('room');
});
