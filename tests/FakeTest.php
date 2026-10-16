<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate as LaravelGate;
use PHPUnit\Framework\AssertionFailedError;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Facades\Beam;

it('records rings instead of broadcasting them, and the assertions read them back', function () {
    Event::fake([IncomingCall::class]);
    Beam::fake();
    $agent = makeUser('Agent');
    $customer = makeUser('Customer');

    Beam::call($customer)->from($agent)->video()->about('Order #1042')->timeout(45)->ring();

    Beam::assertRang($customer)->from($agent)->withVideo()->about('Order #1042')->timeout(45);
    Beam::assertNotRang($agent);
    Event::assertNotDispatched(IncomingCall::class);
});

it('fails the assertion when nobody was rung, or the wrong details were used', function () {
    Beam::fake();
    $agent = makeUser('Agent');
    $customer = makeUser('Customer');

    expect(fn () => Beam::assertRang($customer))->toThrow(AssertionFailedError::class);
    Beam::assertNothingRang();

    Beam::call($customer)->from($agent)->audio()->ring();

    expect(fn () => Beam::assertRang($customer)->withVideo())->toThrow(AssertionFailedError::class);
    expect(fn () => Beam::assertNotRang($customer))->toThrow(AssertionFailedError::class);
    expect(fn () => Beam::assertNothingRang())->toThrow(AssertionFailedError::class);
});

it('still runs the gate, so a test can expect FORBIDDEN', function () {
    Beam::fake();
    LaravelGate::define('beam.call', fn () => false);

    expect(fn () => Beam::call(makeUser('Customer'))->from(makeUser('Agent'))->ring())->toThrow(BeamException::class);
    Beam::assertNothingRang();
});

it('records rooms', function () {
    Beam::fake();

    Beam::room('consult-88')->allow(makeUser())->create();

    Beam::assertRoomCreated('consult-88');
    expect(fn () => Beam::assertRoomCreated('other'))->toThrow(AssertionFailedError::class);
});
