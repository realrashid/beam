<?php

declare(strict_types=1);

namespace RealRashid\Beam\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use RealRashid\Beam\Call;

/**
 * "Someone is calling you" for a closed tab. Every string it shows comes from
 * lang/en/beam.php, so an application can publish it and ring in its own
 * language. An ordinary Notification: the
 * built-in channel is webpush; an application with a native client routes it
 * anywhere it already routes notifications through routeNotificationForBeam().
 *
 * ShouldQueue, but sent inline unless beam.push.queue says otherwise — a ring
 * that waits on a worker is a ring that may never arrive if the worker is
 * behind. PendingCall decides which; see its notify().
 */
final class IncomingCallNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Call $call,
        /** The callee's packed token — so tapping the notification can join. */
        public readonly string $calleeToken,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (method_exists($notifiable, 'routeNotificationForBeam')) {
            return (array) $notifiable->routeNotificationForBeam($this);
        }

        return ['webpush'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => __('beam::beam.notification.calling', ['name' => $this->call->caller->name]),
            'body' => $this->call->about ?? __('beam::beam.notification.'.($this->call->media === 'video' ? 'video' : 'audio')),
            'tag' => $this->call->id,
            'icon' => $this->call->caller->avatar,
            'requireInteraction' => true,
            'renotify' => true,
            'data' => [
                'callId' => $this->call->id,
                'token' => $this->calleeToken,
                'link' => $this->link(),
                'from' => $this->call->caller->toArray(),
                'about' => $this->call->about,
                'media' => $this->call->media,
                'timeout' => $this->call->timeout,
                // The worker's own decline: proved by the token below, because a
                // service worker has neither a CSRF token nor a certain session.
                'declineUrl' => route('beam.calls.decline.token', ['id' => $this->call->id], false),
            ],
            'actions' => [
                ['action' => 'answer', 'title' => __('beam::beam.notification.answer')],
                ['action' => 'decline', 'title' => __('beam::beam.notification.decline')],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['callId' => $this->call->id, 'from' => $this->call->caller->toArray(), 'about' => $this->call->about, 'media' => $this->call->media];
    }

    /** The application's page for this call, if it defined one; otherwise the app root. */
    private function link(): string
    {
        try {
            return $this->call->link();
        } catch (\Throwable) {
            return url('/');
        }
    }
}
