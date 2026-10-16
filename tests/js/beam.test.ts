import { afterEach, describe, expect, it, vi } from 'vitest';
import Beam from '../../resources/js/beam';

/**
 * Beam.onIncoming() is registered from inline scripts, before a Vite bundle
 * (a deferred module) has put Echo on window. It must wait, not throw.
 */
const fakeEcho = () => {
    const listen = vi.fn();
    return { echo: { private: vi.fn(() => ({ listen, listenForWhisper: vi.fn(), whisper: vi.fn(), subscribed: vi.fn() })), leave: vi.fn(), socketId: () => 's' }, listen };
};

describe('Beam.onIncoming', () => {
    afterEach(() => { delete (window as any).Echo; Beam.configure({ echo: undefined, fetch: undefined }); document.head.innerHTML = ''; });

    it('subscribes once Echo appears on window, and says nothing until then', async () => {
        document.head.innerHTML = '<meta name="beam-user" content="2"><meta name="beam-prefix" content="/beam">';
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        Beam.configure({ fetch: fetch as any });
        const { echo, listen } = fakeEcho();

        const off = Beam.onIncoming(() => {});
        await new Promise((r) => setTimeout(r, 120));
        expect(listen).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();           // no presence heartbeat while nothing can hear a ring

        (window as any).Echo = echo;
        await vi.waitFor(() => expect(listen).toHaveBeenCalledWith('.incoming', expect.any(Function)));
        expect(echo.private).toHaveBeenCalledWith('beam.user.2');
        expect(fetch).toHaveBeenCalledWith('/beam/presence', expect.anything());
        off();
        expect(echo.leave).toHaveBeenCalledWith('beam.user.2');
    });

    it('does nothing after off() even if Echo arrives later', async () => {
        document.head.innerHTML = '<meta name="beam-user" content="2"><meta name="beam-prefix" content="/beam">';
        Beam.configure({ fetch: vi.fn(async () => new Response(null, { status: 204 })) as any });
        const { echo, listen } = fakeEcho();
        const off = Beam.onIncoming(() => {});
        off();
        (window as any).Echo = echo;
        await new Promise((r) => setTimeout(r, 150));
        expect(listen).not.toHaveBeenCalled();
    });
});

/**
 * The client-side halves of the audit's "will bite real users" group: a second
 * join of the same call, a third participant on a room's channel, a file offer's
 * sender, and a broadcaster that never confirms a subscription.
 */
const echoThat = (behaviour: 'subscribes' | 'silent' | 'errors') => {
    const handlers: Record<string, (p: any) => void> = {};
    const channel: any = {
        listen: (event: string, fn: (p: any) => void) => { handlers[event] = fn; return channel; },
        listenForWhisper: (event: string, fn: (p: any) => void) => { handlers['whisper:' + event] = fn; return channel; },
        whisper: vi.fn(() => channel),
        subscribed: (fn: () => void) => { if (behaviour === 'subscribes') setTimeout(fn, 0); return channel; },
        error: (fn: (e: unknown) => void) => { if (behaviour === 'errors') setTimeout(() => fn(new Error('auth failed')), 0); return channel; },
    };
    return { echo: { private: () => channel, leave: vi.fn(), socketId: () => 'sock' }, handlers };
};

/**
 * jsdom has no WebRTC and no MediaStream. The stubs are the whole surface Peer
 * and Call touch on a data-only join.
 */
class StubStream {
    id = 'stub';
    getTracks() { return []; }
    getVideoTracks() { return []; }
    getAudioTracks() { return []; }
    addTrack() {}
    removeTrack() {}
}
class StubConnection {
    connectionState = 'new';
    iceConnectionState = 'new';
    signalingState = 'stable';
    onicecandidate: unknown = null; ontrack: unknown = null; onnegotiationneeded: unknown = null;
    ondatachannel: unknown = null; onconnectionstatechange: unknown = null; oniceconnectionstatechange: unknown = null;
    createDataChannel() { return { label: 'beam-control', readyState: 'connecting' }; }
    close() { this.connectionState = 'closed'; }
    getTransceivers() { return []; }
}
(globalThis as any).MediaStream ??= StubStream;
(globalThis as any).RTCPeerConnection ??= StubConnection;

/** A packed token, the way PHP's Token::pack() makes one. */
const packed = (claims: Record<string, unknown>) => {
    const body = JSON.stringify({ token: 'opaque', claims: { v: 1, callId: 'call_1', channel: 'beam.call.call_1', role: 'callee', sub: '2', ice: [], exp: Math.floor(Date.now() / 1000) + 600, meta: {}, ...claims } });
    return btoa(body).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

describe('Beam.join', () => {
    afterEach(() => { delete (window as any).Echo; Beam.configure({ echo: undefined, fetch: undefined }); });

    it('hands the same call to a second join, and only the last holder hangs up', async () => {
        const { echo } = echoThat('subscribes');
        Beam.configure({ echo: echo as any, fetch: vi.fn(async () => new Response(null, { status: 204 })) as any });
        const token = packed({ callId: 'call_shared', channel: 'beam.call.call_shared', meta: { media: 'none' } });

        // React's StrictMode mounts twice; so does a double-clicked Answer button.
        const [first, second] = await Promise.all([Beam.join(token, { media: false }), Beam.join(token, { media: false })]);
        expect(first).toBe(second);

        const hangUp = vi.spyOn(first, 'hangUp');

        // call.release() is the form to reach for; Beam.release(call) is the same thing.
        await first.release();
        expect(hangUp).not.toHaveBeenCalled();      // the other mount still has it

        await Beam.release(second);
        expect(hangUp).toHaveBeenCalledWith('navigated');
    });

    it('gives up when the broadcaster never confirms the subscription, instead of waiting for ever', async () => {
        const { echo } = echoThat('silent');
        Beam.configure({ echo: echo as any, fetch: vi.fn(async () => new Response(null, { status: 204 })) as any });

        Beam.configure({ subscribeTimeoutMs: 20 });

        await expect(Beam.join(packed({ callId: 'call_quiet', channel: 'beam.call.call_quiet', meta: { media: 'none' } }), { media: false }))
            .rejects.toThrow(/did not confirm the subscription/);

        Beam.configure({ subscribeTimeoutMs: undefined });
    });

    it('ignores a third participant on a room channel and tells the page', async () => {
        const { echo, handlers } = echoThat('subscribes');
        Beam.configure({ echo: echo as any, fetch: vi.fn(async () => new Response(null, { status: 204 })) as any });

        const call = await Beam.join(packed({ callId: 'consult-9', channel: 'beam.room.consult-9', role: 'participant', sub: '1', meta: { media: 'none' } }), { media: false });
        const errors: any[] = [];
        call.on('error', (e) => errors.push(e));

        handlers['.signal']!({ type: 'hello', from: '2' });                  // the other participant: this is the peer
        handlers['.signal']!({ type: 'bye', from: '3', reason: 'hangup' });  // a third with a valid token: not this call's business

        expect(errors.map((e) => e.reason)).toContain('room-full');
        expect(call.state).not.toBe('ended');                               // their bye did not end the pair's call
        await call.hangUp();
    });

    it('refuses an oversize file on the first send of a page, before it has a call to ask', async () => {
        document.head.innerHTML = '<meta name="beam-prefix" content="/beam"><meta name="beam-transfer-max" content="1024">';
        const fetch = vi.fn(async () => new Response(JSON.stringify({ callId: 'call_x', token: 't' }), { status: 200 }));
        Beam.configure({ echo: echoThat('subscribes').echo as any, fetch: fetch as any });

        // The limit used to arrive with the first call's token, so the first
        // send of a page skipped the check and learned it from a 413 instead.
        const transfer = Beam.send(new Blob([new Uint8Array(4096)]), 'big.bin').to(9);

        await expect(transfer.start()).rejects.toMatchObject({ reason: 'media-failed' });
        expect(transfer.state).toBe('failed');
        document.head.innerHTML = '';
    });

    it('takes the token out of the address bar once it has been read', async () => {
        const { echo } = echoThat('subscribes');
        Beam.configure({ echo: echo as any, fetch: vi.fn(async () => new Response(null, { status: 204 })) as any });
        const token = packed({ callId: 'call_tapped', channel: 'beam.call.call_tapped', meta: { media: 'none' } });

        // What the service worker opens when someone taps Answer.
        window.history.replaceState({}, '', `/support/inbox?ref=email&beam=${token}&answer=1`);

        const call = await Beam.answerFromNotification({ media: false });

        expect(call).not.toBeNull();
        // A join token is a credential: history, a shared link and the access
        // log should not keep it.
        expect(window.location.search).toBe('?ref=email');
        expect(window.location.pathname).toBe('/support/inbox');

        await call!.hangUp();
        expect(await Beam.answerFromNotification({ media: false })).toBeNull();   // nothing left to answer
    });

    it('names a file offer after whoever sent it, not after whoever placed the call', async () => {
        const { echo, handlers } = echoThat('subscribes');
        Beam.configure({ echo: echo as any, fetch: vi.fn(async () => new Response(null, { status: 204 })) as any });

        // The caller's own token: meta.from is the caller (this user), meta.to the callee.
        const call = await Beam.join(packed({
            callId: 'call_files', channel: 'beam.call.call_files', role: 'caller', sub: '1',
            meta: { media: 'none', from: { id: 1, name: 'Ada (me)' }, to: { id: 2, name: 'Grace' } },
        }), { media: false });

        const offers: any[] = [];
        const off = Beam.onFileOffered((o) => offers.push(o));
        handlers['.signal']!({ type: 'file-offer', transferId: 't1', name: 'scan.pdf', size: 10, mime: 'application/pdf', from: '2' });

        expect(offers[0].from).toEqual({ id: '2', name: 'Grace' });
        off();
        await call.hangUp();
    });
});
