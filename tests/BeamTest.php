<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate as LaravelGate;
use RealRashid\Beam\BeamManager;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Call;
use RealRashid\Beam\Contracts\TimeoutDriver;
use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Events\CallRinging;
use RealRashid\Beam\Events\TurnUnavailable;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Exceptions\ErrorCode;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\PendingCall;
use RealRashid\Beam\Support\CallRegistry;

it('resolves the same manager through the facade, the helper and the container', function () {
    expect(beam())->toBeInstanceOf(BeamManager::class)
        ->and(beam())->toBe(app('beam'))
        ->and(Beam::getFacadeRoot())->toBe(beam())
        ->and(beam(makeUser()))->toBeInstanceOf(PendingCall::class);
});

it('uses the null TURN driver out of the box and mints STUN-only ICE', function () {
    expect(beam()->turn()->name())->toBe('none')
        ->and(beam()->ice(1)->hasTurn())->toBeFalse()
        ->and(beam()->ice(1)->stun)->not->toBeEmpty();
});

it('refuses an unknown TURN driver with a message naming the valid ones', function () {
    config()->set('beam.ice.turn.default', 'carrier-pigeon');

    expect(fn () => beam()->turn())
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::InvalidConfiguration)
            ->and($e->getMessage())->toContain('none'));
});

it('lets an application register its own TURN driver', function () {
    $custom = new class implements TurnDriver
    {
        public function credentialsFor(int|string $userId): array
        {
            return [['urls' => ['turn:mine:3478'], 'username' => "u{$userId}", 'credential' => 'x']];
        }

        public function name(): string
        {
            return 'mine';
        }
    };
    app()->instance($custom::class, $custom);
    beam()->extendTurn('mine', $custom::class);
    config()->set('beam.ice.turn.default', 'mine');

    expect(beam()->ice(9)->hasTurn())->toBeTrue()
        ->and(beam()->ice(9)->turn[0]['username'])->toBe('u9');
});

it('registers a TURN driver from a closure, with the container and its own config block', function () {
    // The shape Storage::extend() and Cache::extend() take — the only one that
    // lets a driver be built with the application's own dependencies.
    config()->set('beam.ice.turn', ['default' => 'ourvendor', 'drivers' => ['ourvendor' => ['pool' => 'eu-west']]]);

    beam()->extendTurn('ourvendor', function ($app, array $config) {
        expect($app)->toBeInstanceOf(Container::class);

        return new class($config['pool']) implements TurnDriver
        {
            public function __construct(private string $pool) {}

            public function credentialsFor(int|string $userId): array
            {
                return [['urls' => ["turn:{$this->pool}.relay.test:3478"], 'username' => (string) $userId, 'credential' => 'c']];
            }

            public function name(): string
            {
                return 'ourvendor';
            }
        };
    });

    expect(beam()->turn()->name())->toBe('ourvendor')
        ->and(beam()->ice(7)->turn[0]['urls'][0])->toBe('turn:eu-west.relay.test:3478');
});

it('registers a timeout driver from a closure too', function () {
    config()->set('beam.call.timeout.driver', 'ours');

    beam()->extendTimeout('ours', fn ($app, array $config) => new class implements TimeoutDriver
    {
        public function schedule(Call $call): void {}

        public function watch(Call $call): void {}

        public function name(): string
        {
            return 'ours';
        }
    });

    expect(beam()->timeouts()->name())->toBe('ours');
});

describe('ring()', function () {
    beforeEach(fn () => Event::fake([IncomingCall::class, CallRinging::class, TurnUnavailable::class]));

    it('rings: registers both, broadcasts incoming to the callee with the callee token, fires CallRinging, returns the caller token', function () {
        $agent = makeUser('Agent');
        $customer = makeUser('Customer');

        $call = Beam::call($customer)->from($agent)->video()->about('Order #1042')->timeout(45)->ring();

        expect($call->id)->toStartWith('call_')
            ->and($call->caller->id)->toBe($agent->id)
            ->and($call->callee->id)->toBe($customer->id)
            ->and($call->media)->toBe('video')
            ->and($call->about)->toBe('Order #1042')
            ->and($call->timeout)->toBe(45);

        $registry = app(CallRegistry::class);
        expect($registry->isParticipant($call->channel(), $agent->id))->toBeTrue()
            ->and($registry->isParticipant($call->channel(), $customer->id))->toBeTrue()
            ->and(Beam::find($call->id)->callee->name)->toBe('Customer');

        $callerClaims = Token::parse(unpackToken($call->token)['token'], app(Encrypter::class));
        expect($callerClaims->role)->toBe('caller')->and($callerClaims->userId)->toBe($agent->id)
            ->and($callerClaims->meta['about'])->toBe('Order #1042')->and($callerClaims->meta['from']['name'])->toBe('Agent')
            ->and($callerClaims->meta['timeout'])->toBe(45);

        Event::assertDispatched(IncomingCall::class, function (IncomingCall $e) use ($customer, $agent) {
            $calleeClaims = Token::parse(unpackToken($e->calleeToken)['token'], app(Encrypter::class));

            return $e->broadcastOn()->name === 'private-beam.user.'.$customer->id
                && $e->broadcastWith()['from']['name'] === 'Agent'
                && $calleeClaims->role === 'callee' && $calleeClaims->userId === $customer->id
                && $calleeClaims->userId !== $agent->id;   // never the caller's token
        });
        Event::assertDispatched(CallRinging::class, fn (CallRinging $e) => $e->call->id === $call->id);
    });

    it('defaults the caller to the authenticated user and the timeout to config', function () {
        $agent = makeUser('Agent');
        $customer = makeUser('Customer');
        config()->set('beam.call.default_timeout', 20);

        $call = $this->actingAs($agent)->app->make(BeamManager::class)->call($customer)->audio()->ring();

        expect($call->caller->id)->toBe($agent->id)->and($call->media)->toBe('audio')->and($call->timeout)->toBe(20);
    });

    it('refuses to ring with no caller at all', function () {
        expect(fn () => Beam::call(makeUser())->ring())
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::InvalidConfiguration));
        Event::assertNotDispatched(IncomingCall::class);
    });

    it('refuses when the beam.call gate says no, and broadcasts nothing', function () {
        $customer = makeUser('Customer');
        $stranger = makeUser('Stranger');
        LaravelGate::define('beam.call', fn ($caller, $callee) => false);

        expect(fn () => Beam::call($customer)->from($stranger)->ring())
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Forbidden));
        Event::assertNotDispatched(IncomingCall::class);
        Event::assertNotDispatched(CallRinging::class);
        expect(app(CallRegistry::class)->get('call_x'))->toBeNull();
    });

    it('goes ahead on STUN and fires TurnUnavailable when the TURN driver fails', function () {
        $broken = new class implements TurnDriver
        {
            public function credentialsFor(int|string $userId): array
            {
                throw new RuntimeException('vendor down');
            }

            public function name(): string
            {
                return 'broken';
            }
        };
        app()->instance($broken::class, $broken);
        beam()->extendTurn('broken', $broken::class);
        config()->set('beam.ice.turn.default', 'broken');

        $call = Beam::call(makeUser('Customer'))->from(makeUser('Agent'))->ring();

        expect(unpackToken($call->token)['claims']['ice'])->toHaveCount(1);   // STUN only
        Event::assertDispatched(TurnUnavailable::class, fn (TurnUnavailable $e) => $e->driver === 'broken' && $e->error->getMessage() === 'vendor down');
        Event::assertDispatched(IncomingCall::class);
    });

    it('supports when(), unless() and macro()', function () {
        Beam::macro('supportCall', fn ($customer) => $this->call($customer)->audio()->timeout(45)->about('Support'));
        $agent = makeUser('Agent');

        $call = Beam::supportCall(makeUser('Customer'))->from($agent)->when(true, fn (PendingCall $c) => $c->video())->unless(true, fn (PendingCall $c) => $c->about('x'))->ring();

        expect($call->media)->toBe('video')->and($call->about)->toBe('Support')->and($call->timeout)->toBe(45);
    });

    it('find() returns null once a call has ended, and findOrFail() throws NOT_FOUND', function () {
        $call = Beam::call(makeUser('Customer'))->from(makeUser('Agent'))->ring();
        Event::fake();
        $call->end();

        // find() is Laravel's find(): null, never a throw. findOrFail() is the one that throws.
        expect(Beam::find($call->id))->toBeNull();
        expect(fn () => Beam::findOrFail($call->id))
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::NotFound));
    });
});

describe('room()', function () {
    it('creates a room, issues tokens only to allowed users, and finds it again by name', function () {
        $doctor = makeUser('Doctor');
        $patient = makeUser('Patient');
        $stranger = makeUser('Stranger');

        $room = Beam::room('consult-88')->allow($doctor, $patient)->audio()->screenShare(false)->expiresAt(now()->addHour())->create();

        expect($room->allowed)->toBe([$doctor->id, $patient->id])->and($room->media)->toBe('audio')->and($room->screenShare)->toBeFalse();
        expect(fn () => $room->token($patient))->not->toThrow(BeamException::class);
        expect(fn () => $room->token($stranger))->toThrow(BeamException::class);

        $found = Beam::room('consult-88')->find();
        expect($found->allowed)->toBe([$doctor->id, $patient->id])->and($found->expiresAt)->not->toBeNull();
    });

    it('refuses a room name that could not be a channel segment', function () {
        expect(fn () => Beam::room('has spaces'))->toThrow(BeamException::class);
    });

    it('find() returns null for a room never created, and findOrFail() throws NOT_FOUND', function () {
        expect(Beam::room('ghost')->find())->toBeNull();
        expect(fn () => Beam::room('ghost')->findOrFail())
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::NotFound));

        // And closing a room that is not there is not an error: there is nobody to tell.
        Beam::room('ghost')->close();
    });
});
