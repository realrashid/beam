<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Exceptions\BeamException;

/** A BeamException thrown from a route answers with the status its reason implies, as JSON. */
it('renders a gate refusal as 403, a missing call as 404, an expired token as 401, and a vendor outage as 503', function (callable $throw, int $status, string $reason) {
    Route::get('/boom', fn () => throw $throw());

    $this->getJson('/boom')->assertStatus($status)->assertJson(['reason' => $reason]);
    $this->get('/boom')->assertStatus($status);
})->with([
    'forbidden' => [fn () => BeamException::forbidden(), 403, 'FORBIDDEN'],
    'not found' => [fn () => BeamException::notFound('call_x'), 404, 'NOT_FOUND'],
    'expired' => [fn () => BeamException::expired(), 401, 'EXPIRED'],
    'turn' => [fn () => BeamException::turnUnavailable('twilio', 'timed out'), 503, 'TURN_UNAVAILABLE'],
]);

it('leaves a misconfiguration to the normal error page for a browser, but answers JSON to an API client', function () {
    Route::get('/boom', fn () => throw BeamException::invalidConfiguration('bad'));

    $this->getJson('/boom')->assertStatus(500)->assertJson(['reason' => 'INVALID_CONFIGURATION']);
    $this->get('/boom')->assertStatus(500)->assertDontSee('"reason"');
});
