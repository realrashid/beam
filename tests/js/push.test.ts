import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Push } from '../../resources/js/push';

function browser(permission: NotificationPermission = 'granted', existing: object | null = null) {
    const subscription = { endpoint: 'https://push.test/e', toJSON: () => ({ endpoint: 'https://push.test/e', keys: { p256dh: 'P', auth: 'A' } }), unsubscribe: vi.fn(async () => true) };
    const registration = { pushManager: { getSubscription: vi.fn(async () => existing), subscribe: vi.fn(async () => subscription) } };
    (globalThis as any).window = globalThis;
    Object.defineProperty(globalThis, 'navigator', { value: { serviceWorker: { register: vi.fn(async () => registration), ready: Promise.resolve(registration), getRegistration: vi.fn(async () => registration) } }, configurable: true });
    (globalThis as any).PushManager = function () {};
    (globalThis as any).Notification = { permission, requestPermission: vi.fn(async () => permission) };
    return { registration, subscription };
}

describe('Beam.push', () => {
    beforeEach(() => { delete (globalThis as any).PushManager; });

    it('reports unsupported where there is no push, and the permission state otherwise', async () => {
        Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true });
        expect(await new Push({ prefix: '/beam' }).status()).toBe('unsupported');
        browser('default');
        expect(await new Push({ prefix: '/beam' }).status()).toBe('default');
    });

    it('subscribe: registers the worker at scope /, asks permission, subscribes with the server key, stores it', async () => {
        const { registration } = browser('granted');
        const fetch = vi.fn(async (url: string) => url.endsWith('/public-key') ? new Response(JSON.stringify({ key: 'BAAA' }), { status: 200 }) : new Response(null, { status: 204 }));
        const push = new Push({ prefix: '/beam', csrf: 'c', fetch: fetch as any });

        const sub = await push.subscribe();

        expect(sub.endpoint).toBe('https://push.test/e');
        expect((navigator as any).serviceWorker.register).toHaveBeenCalledWith('/beam/sw.js', { scope: '/' });
        expect(registration.pushManager.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }));
        const stored = fetch.mock.calls.find((c) => String(c[0]).endsWith('/push/subscribe')) as unknown as [string, RequestInit];
        expect(stored[1].method).toBe('POST');
        expect(JSON.parse(stored[1].body as string)).toEqual({ endpoint: 'https://push.test/e', keys: { p256dh: 'P', auth: 'A' } });
    });

    it('subscribe: a refused permission is a BeamError with a reason', async () => {
        browser('denied');
        await expect(new Push({ prefix: '/beam', fetch: vi.fn() as any }).subscribe()).rejects.toMatchObject({ reason: 'permission-denied' });
    });

    it('subscribe: says what to run when the server has no keys', async () => {
        browser('granted');
        const fetch = vi.fn(async () => new Response(null, { status: 503 }));
        await expect(new Push({ prefix: '/beam', fetch: fetch as any }).subscribe()).rejects.toThrow(/beam:install/);
    });

    it('unsubscribe: tells the server, then the browser; a no-op with nothing to remove', async () => {
        const { subscription } = browser('granted', null);
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        await new Push({ prefix: '/beam', fetch: fetch as any }).unsubscribe();
        expect(fetch).not.toHaveBeenCalled();

        browser('granted', subscription);
        await new Push({ prefix: '/beam', fetch: fetch as any }).unsubscribe();
        expect((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].method).toBe('DELETE');
        expect(subscription.unsubscribe).toHaveBeenCalled();
    });

    it('subscribe: replaces a subscription made with a different VAPID key, and keeps one that matches', async () => {
        const bytesOf = (b64url: string) => Uint8Array.from(atob(b64url.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
        const fetch = vi.fn(async (url: string) => String(url).endsWith('/public-key') ? new Response(JSON.stringify({ key: 'BAAA' }), { status: 200 }) : new Response(null, { status: 204 }));

        // A device subscribed before the keys were rotated: every push to it is
        // refused with a 403 for ever, and nothing on the page can tell.
        const stale = { endpoint: 'https://push.test/stale', options: { applicationServerKey: new Uint8Array([9, 9, 9]).buffer }, toJSON: () => ({ endpoint: 'https://push.test/stale', keys: {} }), unsubscribe: vi.fn(async () => true) };
        const { registration } = browser('granted', stale);

        await new Push({ prefix: '/beam', fetch: fetch as any }).subscribe();

        expect(stale.unsubscribe).toHaveBeenCalled();
        expect(registration.pushManager.subscribe).toHaveBeenCalled();

        // One made with the key the server is handing out now is left alone.
        const current = { endpoint: 'https://push.test/current', options: { applicationServerKey: bytesOf('BAAA').buffer }, toJSON: () => ({ endpoint: 'https://push.test/current', keys: {} }), unsubscribe: vi.fn(async () => true) };
        const second = browser('granted', current);

        await new Push({ prefix: '/beam', fetch: fetch as any }).subscribe();

        expect(current.unsubscribe).not.toHaveBeenCalled();
        expect(second.registration.pushManager.subscribe).not.toHaveBeenCalled();
    });

    it('subscribe: registers at the scope it is given, for an application that already has a worker at /', async () => {
        browser('granted');
        const fetch = vi.fn(async (url: string) => String(url).endsWith('/public-key') ? new Response(JSON.stringify({ key: 'BAAA' }), { status: 200 }) : new Response(null, { status: 204 }));

        await new Push({ prefix: '/beam', scope: '/calls/', fetch: fetch as any }).subscribe();

        expect((navigator as any).serviceWorker.register).toHaveBeenCalledWith('/beam/sw.js', { scope: '/calls/' });
    });
});
