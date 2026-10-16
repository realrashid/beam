/// <reference lib="webworker" />
/**
 * Beam's service worker — served at {prefix}/sw.js with Service-Worker-Allowed: /.
 * A push shows the incoming call; tapping Answer opens the app into it, tapping
 * Decline declines it without opening anything.
 */
const sw = self as unknown as ServiceWorkerGlobalScope;

interface IncomingPayload {
    title: string;
    body: string;
    tag: string;
    icon?: string | null;
    requireInteraction?: boolean;
    renotify?: boolean;
    data: { callId: string; token: string; link: string; declineUrl: string; [key: string]: unknown };
    actions?: { action: string; title: string }[];
}

sw.addEventListener('push', (event: PushEvent) => {
    let payload: IncomingPayload | null = null;
    try {
        payload = event.data?.json() ?? null;
    } catch {
        payload = null;
    }
    if (!payload || !payload.data?.callId) return;

    event.waitUntil(
        sw.registration.showNotification(payload.title, {
            body: payload.body,
            tag: payload.tag,
            icon: payload.icon ?? undefined,
            requireInteraction: payload.requireInteraction ?? true,
            data: payload.data,
            // `actions` and `renotify` are not in every TS lib yet; the browsers that support them read them.
            ...({ actions: payload.actions ?? [], renotify: payload.renotify ?? true } as NotificationOptions),
        }),
    );
});

sw.addEventListener('notificationclick', (event: NotificationEvent) => {
    const data = event.notification.data as IncomingPayload['data'];
    event.notification.close();

    if (event.action === 'decline') {
        // The token, not a CSRF header: a worker cannot read one, and the page
        // that could is not open. The endpoint is outside the `web` group and
        // checks the token instead.
        event.waitUntil(fetch(data.declineUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ beam_token: data.token, reason: 'declined' }),
        }).catch(() => {}));
        return;
    }

    // Answer, or a plain tap: open the app into the call. An open tab is focused and told; otherwise a new one opens.
    // A payload without a link or token (a test push, an app's own notification through this worker) opens the app root, plainly.
    const target = new URL(typeof data.link === 'string' && data.link !== '' ? data.link : '/', sw.location.origin);
    if (typeof data.token === 'string' && data.token !== '') {
        target.searchParams.set('beam', data.token);
        target.searchParams.set('answer', '1');
    }

    event.waitUntil(
        sw.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((c) => new URL(c.url).origin === sw.location.origin);
            if (existing) {
                existing.postMessage({ type: 'beam:answer', callId: data.callId, token: data.token });
                return existing.focus();
            }
            return sw.clients.openWindow(target.toString());
        }),
    );
});

sw.addEventListener('notificationclose', () => {
    // Ignoring the ring lets timeout() fire CallDeclined as usual.
});
