<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

/**
 * Only matters on the whisper transport. Reverb: REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM
 * (default members) says it. Pusher: it cannot be checked remotely — client
 * events are a dashboard toggle — so the doctor can only say so.
 */
final class EchoClientEventsCheck implements Check
{
    public function name(): string
    {
        return 'Client events';
    }

    public function run(): Result
    {
        if (config('beam.signaling.transport', 'controller') !== 'whisper') {
            return Result::ok('Signaling uses the controller transport; client events are not needed.');
        }

        $driver = (string) config('broadcasting.default');

        if ($driver === 'reverb') {
            // reverb.apps.apps[].accept_client_events_from — env REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM, default members.
            $apps = (array) config('reverb.apps.apps', []);
            $from = (string) (($apps[0]['accept_client_events_from'] ?? null) ?: 'members');

            return $from === 'none'
                ? Result::fail('Reverb refuses client events (REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none), so the whisper transport cannot signal.', 'Set it to members, or use BEAM_SIGNALING=controller.')
                : Result::ok("Reverb accepts client events from {$from}.");
        }

        if ($driver === 'pusher') {
            return Result::warn('Pusher only relays client events once they are enabled in the app\'s dashboard, and caps them at 10 per second per connection.', 'Enable "client events" in the Pusher dashboard, or use BEAM_SIGNALING=controller, which has no such cap.');
        }

        return Result::warn("Broadcasting driver [{$driver}]: make sure it delivers client events, or use BEAM_SIGNALING=controller.");
    }
}
