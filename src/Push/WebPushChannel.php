<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use RealRashid\Beam\Contracts\PushSender;
use RealRashid\Beam\Support\Redactor;

/**
 * The `webpush` notification channel. Sends to every device the user
 * subscribed; a device that is gone (410, 404) is forgotten on the spot.
 */
final class WebPushChannel
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly PushSender $sender,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush') || ! method_exists($notifiable, 'getKey')) {
            return;
        }

        $userId = $notifiable->getKey();
        $store = $this->subscriptions->store();
        $payload = $notification->toWebPush($notifiable);

        foreach ($store->for($userId) as $subscription) {
            $result = $this->sender->send($subscription, $payload);

            if ($result['ok']) {
                continue;
            }

            if ($result['expired'] || in_array($result['status'], [404, 410], true)) {
                $store->remove($userId, $subscription['endpoint']);

                continue;
            }

            Log::warning('Beam: a Web Push could not be delivered.', Redactor::scrub([
                'user' => $userId,
                'endpoint' => $subscription['endpoint'],
                'status' => $result['status'],
                'reason' => $result['reason'],
            ]));
        }
    }
}
