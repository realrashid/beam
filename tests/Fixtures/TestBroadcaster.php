<?php

declare(strict_types=1);

namespace RealRashid\Beam\Tests\Fixtures;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The `log` and `null` broadcasters have an empty auth(), so every
 * /broadcasting/auth request returns 200 and the channel rules never run.
 * This one verifies channels the way Reverb and Pusher do — through the
 * framework's own verifyUserCanAccessChannel — and broadcasts nothing.
 */
final class TestBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function auth($request)
    {
        /** @var Request $request */
        $channelName = (string) $request->input('channel_name');

        if ($this->isGuardedChannel($channelName) && ! $this->retrieveUser($request, $channelName)) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $this->normalizeChannelName($channelName));
    }

    public function validAuthenticationResponse($request, $result)
    {
        return ['auth' => 'ok'];
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        //
    }
}
