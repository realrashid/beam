<?php

declare(strict_types=1);

use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Contracts\PushSender;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Notifications\IncomingCallNotification;
use RealRashid\Beam\Push\Presence;
use RealRashid\Beam\Push\Subscriptions;
use RealRashid\Beam\Tests\Fixtures\User;

/** Records sends and answers per endpoint. */
function fakeSender(array $answers = []): object
{
    return new class($answers) implements PushSender
    {
        public array $sent = [];

        public function __construct(private array $answers) {}

        public function send(array $subscription, array $payload): array
        {
            $this->sent[] = ['endpoint' => $subscription['endpoint'], 'payload' => $payload];

            return $this->answers[$subscription['endpoint']] ?? ['ok' => true, 'status' => 201, 'expired' => false, 'reason' => null];
        }
    };
}

beforeEach(function () {
    Event::fake([IncomingCall::class]);
    config()->set('beam.push.vapid', ['public' => 'PUB', 'private' => 'PRIV', 'subject' => 'mailto:x@y.z']);
});

it('ring() notifies the callee through webpush with a payload the service worker can act on', function () {
    Notification::fake();
    $agent = makeUser('Agent');
    $customer = makeUser('Customer');

    $call = Beam::call($customer)->from($agent)->video()->about('Order #1042')->ring();

    Notification::assertSentTo($customer, IncomingCallNotification::class, function (IncomingCallNotification $n, array $channels) use ($call, $customer) {
        $payload = $n->toWebPush($customer);

        return $channels === ['webpush']
            && $payload['title'] === 'Agent is calling'
            && $payload['body'] === 'Order #1042'
            && $payload['tag'] === $call->id
            && $payload['data']['callId'] === $call->id
            && $payload['data']['token'] !== $call->token   // the callee's token, never the caller's
            && $payload['actions'][0]['action'] === 'answer'
            && $payload['requireInteraction'] === true;
    });
});

it('routes wherever the notifiable says, so a native app gets it too', function () {
    Notification::fake();
    $customer = new class extends User
    {
        public function routeNotificationForBeam(): array
        {
            return ['webpush', 'mail'];
        }
    };
    $customer = $customer::forceCreate(['name' => 'C', 'email' => 'c@x.test', 'password' => bcrypt('x')]);

    Beam::call($customer)->from(makeUser('Agent'))->ring();

    Notification::assertSentTo($customer, IncomingCallNotification::class, fn ($n, array $channels) => $channels === ['webpush', 'mail']);
});

it('skips the push when a tab is already listening', function () {
    Notification::fake();
    $customer = makeUser('Customer');
    app(Presence::class)->touch($customer->id);

    Beam::call($customer)->from(makeUser('Agent'))->ring();

    Notification::assertNothingSent();
});

it('skips the push when no VAPID keys exist and the app routes nowhere else', function () {
    Notification::fake();
    config()->set('beam.push.vapid', ['public' => null, 'private' => null]);

    Beam::call(makeUser('Customer'))->from(makeUser('Agent'))->ring();

    Notification::assertNothingSent();
});

it('the channel sends to every device, forgets dead ones, and logs the rest with secrets scrubbed', function () {
    $customer = makeUser('Customer');
    $store = app(Subscriptions::class)->store();
    foreach (['ok', 'gone', 'broken'] as $e) {
        $store->add($customer->id, ['endpoint' => "https://push.test/{$e}", 'keys' => ['p256dh' => 'P', 'auth' => 'SECRET-AUTH']]);
    }
    $sender = fakeSender([
        'https://push.test/gone' => ['ok' => false, 'status' => 410, 'expired' => true, 'reason' => 'Gone'],
        'https://push.test/broken' => ['ok' => false, 'status' => 500, 'expired' => false, 'reason' => 'boom credential=leak'],
    ]);
    app()->instance(PushSender::class, $sender);
    Log::spy();

    $call = Beam::call($customer)->from(makeUser('Agent'))->ring();

    expect(collect($sender->sent)->pluck('endpoint')->all())->toBe(['https://push.test/ok', 'https://push.test/gone', 'https://push.test/broken'])
        ->and($sender->sent[0]['payload']['data']['callId'])->toBe($call->id)
        ->and(collect($store->for($customer->id))->pluck('endpoint')->all())->toBe(['https://push.test/ok', 'https://push.test/broken']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'Web Push') && $ctx['reason'] === 'boom credential=[redacted]')->once();
});

it('sends the ring\'s push inline by default, and through the queue when the application asks', function () {
    Queue::fake();
    $sender = fakeSender();
    app()->instance(PushSender::class, $sender);
    $agent = makeUser('Agent');
    $customer = makeUser('Customer');
    app(Subscriptions::class)->store()->add($customer->id, ['endpoint' => 'https://push.test/d1', 'keys' => ['p256dh' => 'P', 'auth' => 'A']]);

    // Inline: the push is on its way before ring() returns, because a ring that
    // waits for a worker is a ring that may arrive after the caller gave up.
    Beam::call($customer)->from($agent)->ring();

    Queue::assertNotPushed(SendQueuedNotifications::class);   // ExpireCall is the ring's own timeout, and is meant to be there
    expect($sender->sent)->toHaveCount(1);

    // And an application that would rather pay nothing at ring time says so.
    config()->set('beam.push.queue', true);
    Beam::call($customer)->from($agent)->ring();

    Queue::assertPushed(SendQueuedNotifications::class);
    expect($sender->sent)->toHaveCount(1);   // nothing else went out in this request
});

it('lets an application ring the phone even when a tab is listening', function () {
    $sender = fakeSender();
    app()->instance(PushSender::class, $sender);
    $agent = makeUser('Agent');
    $customer = makeUser('Customer');
    app(Subscriptions::class)->store()->add($customer->id, ['endpoint' => 'https://push.test/phone', 'keys' => ['p256dh' => 'P', 'auth' => 'A']]);
    app(Presence::class)->touch($customer->id);   // a tab is open somewhere

    Beam::call($customer)->from($agent)->ring();
    expect($sender->sent)->toBeEmpty();           // suppressed, as by default

    // An idle tab on a locked work laptop is not the same as the person being
    // there, so whether it suppresses is the application's call.
    config()->set('beam.push.suppress_when_online', false);
    Beam::call($customer)->from($agent)->ring();

    expect($sender->sent)->toHaveCount(1);
});
