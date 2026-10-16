// @vitest-environment node
import { beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * The service worker is the one piece of Beam that runs with no page, no CSRF
 * token and no certainty of a session. Its Decline used to post to the
 * session-guarded route with no token of any kind, which Laravel answers with
 * 419 — invisible to every other test, because CSRF is short-circuited when the
 * framework is running tests, and the rig never taps a notification.
 */
const listeners: Record<string, ((event: any) => void) | undefined> = {};
const shown: any[] = [];
const opened: string[] = [];
let clients: any[] = [];
const fetchMock = vi.fn(async (_url: string, _init: RequestInit = {}) => new Response(null, { status: 204 }));

beforeAll(async () => {
    (globalThis as any).self = {
        addEventListener: (name: string, fn: (event: any) => void) => { listeners[name] = fn; },
        registration: { showNotification: (title: string, options: any) => { shown.push({ title, ...options }); return Promise.resolve(); } },
        clients: { matchAll: async () => clients, openWindow: async (url: string) => { opened.push(url); } },
        location: { origin: 'https://app.test' },
    };
    (globalThis as any).fetch = fetchMock;
    // The worker is a script, not a module: importing it registers its listeners.
    // @ts-expect-error -- no exports, which is the point
    await import('../../resources/js/sw');
});

const payload = {
    title: 'Ada is calling', body: 'Ticket #4', tag: 'call_9',
    data: { callId: 'call_9', token: 'packed-token', link: 'https://app.test/calls/call_9', declineUrl: '/beam/calls/call_9/decline-by-token' },
    actions: [{ action: 'answer', title: 'Answer' }, { action: 'decline', title: 'Decline' }],
};

const click = (action: string, data: any = payload.data) => {
    const waits: Promise<unknown>[] = [];
    listeners.notificationclick!({ action, notification: { data, close: vi.fn() }, waitUntil: (p: Promise<unknown>) => waits.push(p) });
    return Promise.all(waits);
};

describe('the push worker', () => {
    it('shows the call', () => {
        listeners.push!({ data: { json: () => payload }, waitUntil: (p: Promise<unknown>) => p });
        expect(shown[0]).toMatchObject({ title: 'Ada is calling', tag: 'call_9', requireInteraction: true });
    });

    it('declines with the token the push carried, not with a CSRF header it cannot have', async () => {
        await click('decline');

        expect(fetchMock).toHaveBeenCalledWith('/beam/calls/call_9/decline-by-token', expect.objectContaining({ method: 'POST', credentials: 'include' }));
        const body = JSON.parse((fetchMock.mock.calls.at(-1)![1] as RequestInit).body as string);
        expect(body).toEqual({ beam_token: 'packed-token', reason: 'declined' });
    });

    it('opens the app into the call when there is no tab, and tells an open one instead', async () => {
        await click('answer');
        expect(opened.at(-1)).toBe('https://app.test/calls/call_9?beam=packed-token&answer=1');

        const post = vi.fn();
        clients = [{ url: 'https://app.test/dashboard', focus: vi.fn(), postMessage: post }];
        await click('answer');
        expect(post).toHaveBeenCalledWith({ type: 'beam:answer', callId: 'call_9', token: 'packed-token' });
        clients = [];
    });

    it('opens the app plainly when a payload carries no link or token', async () => {
        await click('answer', { callId: 'call_x' });
        expect(opened.at(-1)).toBe('https://app.test/');      // never /undefined?beam=undefined
    });
});
