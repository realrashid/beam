<?php

declare(strict_types=1);

namespace RealRashid\Beam\Contracts;

/**
 * Delivers one Web Push message. The real one wraps minishlink/web-push;
 * tests and the rig swap in a recorder.
 *
 * @phpstan-type Subscription array{endpoint: string, keys: array{p256dh: string, auth: string}}
 */
interface PushSender
{
    /**
     * @param  Subscription  $subscription
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int|null, expired: bool, reason: string|null}
     */
    public function send(array $subscription, array $payload): array;
}
