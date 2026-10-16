<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push;

use GuzzleHttp\Client as GuzzleClient;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use RealRashid\Beam\Contracts\PushSender;
use RealRashid\Beam\Exceptions\BeamException;
use Throwable;

final class WebPushSender implements PushSender
{
    private ?WebPush $client = null;

    public function send(array $subscription, array $payload): array
    {
        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create(['endpoint' => $subscription['endpoint'], 'keys' => $subscription['keys']]),
                json_encode($payload, JSON_THROW_ON_ERROR),
                ['TTL' => 60, 'urgency' => 'high', 'topic' => substr((string) ($payload['tag'] ?? 'beam'), 0, 32)],
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'expired' => false, 'reason' => $e->getMessage()];
        }

        $status = $report->getResponse()?->getStatusCode();

        return [
            'ok' => $report->isSuccess(),
            'status' => $status,
            'expired' => $report->isSubscriptionExpired(),
            'reason' => $report->isSuccess() ? null : $report->getReason(),
        ];
    }

    /** Guzzle with a short leash, when it is installed; otherwise web-push discovers its own. */
    private function boundedClient(): ?ClientInterface
    {
        if (! class_exists(GuzzleClient::class)) {
            return null;
        }

        $seconds = (float) config('beam.push.timeout', 4);

        return new GuzzleClient(['timeout' => $seconds, 'connect_timeout' => min(2.0, $seconds)]);
    }

    private function client(): WebPush
    {
        if ($this->client !== null) {
            return $this->client;
        }

        // Web Push is a feature, not a dependency: an application that only makes
        // calls between open tabs should not carry a JWT library and its tree.
        if (! class_exists(WebPush::class)) {
            throw BeamException::pushUnavailable('Web Push needs the minishlink/web-push package: composer require minishlink/web-push.');
        }

        $vapid = (array) config('beam.push.vapid', []);

        if (empty($vapid['public']) || empty($vapid['private'])) {
            throw BeamException::pushUnavailable('BEAM_VAPID_PUBLIC and BEAM_VAPID_PRIVATE are not set. Run `php artisan beam:install` to generate them.');
        }

        return $this->client = new WebPush(
            ['VAPID' => [
                'subject' => $vapid['subject'] ?: 'mailto:'.(string) config('mail.from.address', 'hello@example.com'),
                'publicKey' => $vapid['public'],
                'privateKey' => $vapid['private'],
            ]],
            [],
            // Without a client of our own, web-push discovers one and uses its
            // defaults — which for Guzzle is no timeout at all. A push service
            // that stops answering would then hold the request that placed the
            // call until PHP gave up on it.
            $this->boundedClient(),
        );
    }
}
