import { BeamError } from './errors';

export type PushStatus = 'granted' | 'denied' | 'default' | 'unsupported';

export interface PushOptions {
    /** Where Beam's routes live; Beam.configure() sets it. */
    prefix: string;
    csrf?: string;
    fetch?: typeof globalThis.fetch;
    /** Where the service worker is served from; defaults to {prefix}/sw.js. */
    workerUrl?: string;
    /**
     * The scope to register it at. '/' by default, which is what lets one
     * worker ring from any page — but a registration at a scope replaces
     * whatever was there, so an application with its own worker at '/' should
     * either give Beam a narrower scope or, better, add
     * `importScripts('/beam/sw.js')` to its own worker and never call this.
     */
    scope?: string;
}

function base64UrlToBytes(input: string): Uint8Array {
    const padded = (input + '='.repeat((4 - (input.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}

/** Whether an existing subscription was made with the key the server is handing out now. */
function sameKey(subscription: PushSubscription, key: Uint8Array): boolean {
    const theirs = subscription.options?.applicationServerKey;
    if (!theirs) return true;   // nothing to compare: leave it alone

    const bytes = new Uint8Array(theirs);
    return bytes.length === key.length && bytes.every((b, i) => b === key[i]);
}

/**
 * Beam.push — ring a closed tab. subscribe() once per device, from a click:
 * registers the service worker, asks for permission, subscribes with the
 * server's public key, and stores the subscription through Beam's route.
 */
export class Push {
    constructor(private readonly o: PushOptions) {}

    async status(): Promise<PushStatus> {
        if (typeof window === 'undefined' || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
        return Notification.permission as PushStatus;
    }

    async subscribe(): Promise<PushSubscription> {
        if ((await this.status()) === 'unsupported') {
            throw new BeamError('media-failed', 'This browser cannot receive push notifications. On iPhone the app must be added to the Home Screen first.');
        }

        const registration = await navigator.serviceWorker.register(this.o.workerUrl ?? `${this.o.prefix}/sw.js`, { scope: this.o.scope ?? '/' });
        await navigator.serviceWorker.ready;

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new BeamError('permission-denied', 'Notifications were refused, so a closed tab cannot ring.');
        }

        const key = await this.publicKey();
        const applicationServerKey = base64UrlToBytes(key);

        // A subscription is tied to the key it was made with. Reusing one from
        // before a VAPID rotation means every push is refused with a 403 for the
        // life of that device, and nothing on the page can tell.
        let existing = await registration.pushManager.getSubscription();
        if (existing && !sameKey(existing, applicationServerKey)) {
            await existing.unsubscribe().catch(() => {});
            existing = null;
        }

        const subscription = existing
            ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationServerKey as BufferSource });

        await this.post(`${this.o.prefix}/push/subscribe`, 'POST', subscription.toJSON() as Record<string, unknown>);

        return subscription;
    }

    async unsubscribe(): Promise<void> {
        if ((await this.status()) === 'unsupported') return;
        const registration = await navigator.serviceWorker.getRegistration('/');
        const subscription = await registration?.pushManager.getSubscription();
        if (!subscription) return;

        await this.post(`${this.o.prefix}/push/subscribe`, 'DELETE', { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
    }

    private async publicKey(): Promise<string> {
        const res = await (this.o.fetch ?? globalThis.fetch)(`${this.o.prefix}/push/public-key`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new BeamError('signaling-failed', 'Web Push is not configured on the server: run php artisan beam:install.');
        return (await res.json()).key as string;
    }

    private async post(url: string, method: 'POST' | 'DELETE', body: Record<string, unknown>): Promise<void> {
        const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (this.o.csrf) headers['X-CSRF-TOKEN'] = this.o.csrf;
        const res = await (this.o.fetch ?? globalThis.fetch)(url, { method, headers, credentials: 'same-origin', body: JSON.stringify(body) });
        if (!res.ok) throw new BeamError('signaling-failed', `${url} refused (${res.status})`);
    }
}
