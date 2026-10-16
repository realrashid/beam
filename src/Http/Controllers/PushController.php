<?php

declare(strict_types=1);

namespace RealRashid\Beam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RealRashid\Beam\Events\PushSubscribed;
use RealRashid\Beam\Push\EndpointRule;
use RealRashid\Beam\Push\Presence;
use RealRashid\Beam\Push\Subscriptions;

final class PushController
{
    public function __construct(private readonly Subscriptions $subscriptions, private readonly Presence $presence) {}

    /** The public VAPID key — the only key the browser ever sees. */
    public function publicKey(): JsonResponse
    {
        $key = (string) config('beam.push.vapid.public', '');

        return $key === ''
            ? response()->json(['message' => 'Web Push is not configured on this server.', 'reason' => 'PUSH_UNAVAILABLE'], 503)
            : response()->json(['key' => $key]);
    }

    public function subscribe(Request $request): Response
    {
        $data = $request->validate([
            // https because a push service is always https, and EndpointRule
            // because this URL is one an authenticated user gets to aim at your
            // server. See the rule for what it does and does not stop.
            'endpoint' => ['required', 'url:https', 'max:1000', new EndpointRule],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $subscription = ['endpoint' => $data['endpoint'], 'keys' => $data['keys'], 'user_agent' => substr((string) $request->userAgent(), 0, 500)];
        $userId = $request->user()->getAuthIdentifier();

        $this->subscriptions->store()->add($userId, $subscription);
        event(new PushSubscribed($userId, ['endpoint' => $subscription['endpoint'], 'keys' => $subscription['keys']]));

        return response()->noContent();
    }

    public function unsubscribe(Request $request): Response
    {
        // Deliberately lenient: whatever is stored should be removable, including
        // a row from before these rules tightened.
        $data = $request->validate(['endpoint' => ['required', 'url', 'max:1000']]);

        $this->subscriptions->store()->remove($request->user()->getAuthIdentifier(), $data['endpoint']);

        return response()->noContent();
    }

    /** An open tab listening for calls says so every 30 s; ring() then skips the push. Best effort. */
    public function presence(Request $request): Response
    {
        $this->presence->touch($request->user()->getAuthIdentifier());

        return response()->noContent();
    }
}
