import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Signaling, SignalingError, type EchoChannelLike, type EchoLike, type SignalMessage } from '../../resources/js/signaling';

/** A fake Echo private channel that records listeners and lets the test push messages in. */
function fakeEcho() {
    const handlers: Record<string, (p: any) => void> = {};
    let onSubscribed: (() => void) | null = null;
    let onError: ((e: unknown) => void) | null = null;
    const channel: EchoChannelLike & { whisper: ReturnType<typeof vi.fn> } = {
        listen: vi.fn((event, cb) => { handlers[event] = cb; return channel; }),
        listenForWhisper: vi.fn((event, cb) => { handlers['whisper:' + event] = cb; return channel; }),
        whisper: vi.fn(() => channel),
        subscribed: vi.fn((cb) => { onSubscribed = cb; return channel; }),
        error: vi.fn((cb) => { onError = cb; return channel; }),
    };
    const echo: EchoLike & { leave: ReturnType<typeof vi.fn> } = {
        private: vi.fn(() => channel),
        leave: vi.fn(),
    };
    return {
        echo, channel,
        confirmSubscribed: () => onSubscribed?.(),
        failSubscription: (e: unknown) => onError?.(e),
        deliverBroadcast: (m: SignalMessage) => handlers['.signal']?.(m),
        deliverWhisper: (m: SignalMessage) => handlers['whisper:signal']?.(m),
    };
}

const offer: RTCSessionDescriptionInit = { type: 'offer', sdp: 'v=0…' };

describe('Signaling', () => {
    beforeEach(() => vi.useRealTimers());

    it('join() resolves when the broadcaster confirms the subscription', async () => {
        const f = fakeEcho();
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller' });
        const joined = s.join();
        expect(f.echo.private).toHaveBeenCalledWith('beam.call.c1');
        f.confirmSubscribed();
        await expect(joined).resolves.toBeUndefined();
    });

    it('join() rejects with a SignalingError when the subscription fails', async () => {
        const f = fakeEcho();
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller' });
        const joined = s.join();
        f.failSubscription('403');
        await expect(joined).rejects.toBeInstanceOf(SignalingError);
    });

    it('controller transport POSTs the message with from, token and CSRF, and never whispers', async () => {
        const f = fakeEcho();
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', token: 'tok', csrf: 'csrf', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;

        await s.send({ type: 'offer', sdp: offer });

        expect(f.channel.whisper).not.toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/beam/signal');
        expect(init.method).toBe('POST');
        expect((init.headers as Record<string, string>)['X-Beam-Token']).toBe('tok');
        expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('csrf');
        expect(JSON.parse(init.body as string)).toEqual({ channel: 'beam.call.c1', message: { type: 'offer', sdp: offer, from: 'u1' } });
    });

    it('controller transport: a refused relay rejects and emits error — the failure is visible', async () => {
        const f = fakeEcho();
        const fetch = vi.fn(async () => new Response('nope', { status: 403 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;
        const onError = vi.fn();
        s.on('error', onError);

        await expect(s.send({ type: 'offer', sdp: offer })).rejects.toMatchObject({ status: 403 });
        expect(onError).toHaveBeenCalledTimes(1);
    });

    it('controller transport: a network failure rejects and emits error', async () => {
        const f = fakeEcho();
        const fetch = vi.fn(async () => { throw new TypeError('Failed to fetch'); });
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;
        const onError = vi.fn();
        s.on('error', onError);

        await expect(s.send({ type: 'offer', sdp: offer })).rejects.toBeInstanceOf(SignalingError);
        expect(onError).toHaveBeenCalledTimes(1);
    });

    it('whisper transport whispers the message with from, and never POSTs', async () => {
        const f = fakeEcho();
        const fetch = vi.fn();
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'whisper', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;

        await s.send({ type: 'answer', sdp: { type: 'answer', sdp: 'v=0' } });

        expect(fetch).not.toHaveBeenCalled();
        expect(f.channel.whisper).toHaveBeenCalledWith('signal', { type: 'answer', sdp: { type: 'answer', sdp: 'v=0' }, from: 'u1' });
    });

    it('receives from either transport and ignores its own messages', async () => {
        const f = fakeEcho();
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller' });
        const joined = s.join(); f.confirmSubscribed(); await joined;
        const got = vi.fn();
        s.on('message', got);

        f.deliverBroadcast({ type: 'offer', sdp: offer, from: 'u2' });
        f.deliverWhisper({ type: 'bye', reason: 'x', from: 'u2' });
        f.deliverBroadcast({ type: 'offer', sdp: offer, from: 'u1' });   // own — ignored
        f.deliverWhisper({ type: 'bye', reason: 'x', from: 'u1' });      // own — ignored

        expect(got).toHaveBeenCalledTimes(2);
        expect(got.mock.calls[0]?.[0]).toMatchObject({ type: 'offer', from: 'u2' });
        expect(got.mock.calls[1]?.[0]).toMatchObject({ type: 'bye', from: 'u2' });
    });

    it('ignores its own messages even when the server sends from as a number', async () => {
        const f = fakeEcho();
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: '1', transport: 'controller' });
        const joined = s.join(); f.confirmSubscribed(); await joined;
        const got = vi.fn();
        s.on('message', got);

        f.deliverBroadcast({ type: 'ice', candidates: [], from: 1 as unknown as string });   // PHP integer id
        f.deliverBroadcast({ type: 'ice', candidates: [], from: 2 as unknown as string });

        expect(got).toHaveBeenCalledTimes(1);
        expect(got.mock.calls[0]?.[0]).toMatchObject({ from: 2 });
    });

    it('sends X-Socket-Id on the controller transport when Echo has one, so toOthers() excludes the sender', async () => {
        const f = fakeEcho();
        (f.echo as EchoLike).socketId = () => '123.456';
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;

        await s.send({ type: 'hello' });

        const init = (fetch.mock.calls[0] as unknown as [string, RequestInit])[1];
        expect((init.headers as Record<string, string>)['X-Socket-Id']).toBe('123.456');
        expect(JSON.parse(init.body as string).message).toEqual({ type: 'hello', from: 'u1' });
    });

    it('coalesces ICE candidates into one batch after iceBatchMs', async () => {
        vi.useFakeTimers();
        const f = fakeEcho();
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', fetch, iceBatchMs: 100 });
        const joined = s.join(); f.confirmSubscribed(); await joined;

        for (let i = 0; i < 25; i++) s.sendCandidate({ candidate: 'candidate:' + i, sdpMid: '0' });
        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(100);

        expect(fetch).toHaveBeenCalledTimes(1);
        const body = JSON.parse((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string);
        expect(body.message.type).toBe('ice');
        expect(body.message.candidates).toHaveLength(25);
    });

    it('leave() flushes candidates, sends bye, unsubscribes, and is idempotent', async () => {
        const f = fakeEcho();
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;
        s.sendCandidate({ candidate: 'c', sdpMid: '0' });

        await s.leave('hangup');
        await s.leave('hangup');

        const types = fetch.mock.calls.map((c) => JSON.parse(((c as unknown as [string, RequestInit])[1]).body as string).message.type);
        expect(types).toEqual(['ice', 'bye']);
        expect(f.echo.leave).toHaveBeenCalledTimes(1);
        await expect(s.send({ type: 'bye', reason: 'again' })).resolves.toBeUndefined();
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('leave() still unsubscribes when the bye cannot be delivered', async () => {
        const f = fakeEcho();
        const fetch = vi.fn(async () => new Response(null, { status: 500 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', fetch });
        const joined = s.join(); f.confirmSubscribed(); await joined;

        await expect(s.leave()).resolves.toBeUndefined();
        expect(f.echo.leave).toHaveBeenCalledTimes(1);
    });

    it('sends an oversize message through the controller rather than losing it to a client-event cap', async () => {
        const f = fakeEcho();
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'whisper', endpoint: '/beam/signal', token: 't', fetch: fetch as any });
        await Promise.all([s.join(), Promise.resolve(f.confirmSubscribed())]);

        // Small enough to whisper: Pusher and Reverb take it.
        await s.send({ type: 'offer', sdp: { type: 'offer', sdp: 'v=0' } });
        expect(f.channel.whisper).toHaveBeenCalledTimes(1);
        expect(fetch).not.toHaveBeenCalled();

        // An offer with two video senders and a data channel reaches the 10 KB
        // cap, and a broadcaster drops the excess without telling the sender.
        await s.send({ type: 'offer', sdp: { type: 'offer', sdp: 'v=0'.padEnd(12_000, 'a') } });

        expect(f.channel.whisper).toHaveBeenCalledTimes(1);          // not whispered
        expect(fetch).toHaveBeenCalledWith('/beam/signal', expect.anything());
        expect(warn.mock.calls[0]![0]).toMatch(/12\d{3} bytes/);
        warn.mockRestore();
    });

    it('keeps candidates a refused relay would have lost, and tries again', async () => {
        vi.useFakeTimers();
        const f = fakeEcho();
        let refuse = true;
        const fetch = vi.fn(async () => new Response(null, { status: refuse ? 429 : 204 }));
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller', endpoint: '/beam/signal', fetch: fetch as any, iceBatchMs: 10 });
        s.on('error', () => {});   // the refusal is reported; the test is about what happens next

        s.sendCandidate({ candidate: 'a' });
        s.sendCandidate({ candidate: 'b' });
        await vi.advanceTimersByTimeAsync(20);

        expect(fetch).toHaveBeenCalledTimes(1);   // refused: a lost batch can cost the connection

        refuse = false;
        await vi.advanceTimersByTimeAsync(1_100);

        expect(fetch).toHaveBeenCalledTimes(2);
        const body = JSON.parse(((fetch.mock.calls[1] as unknown as [string, RequestInit])[1].body as string));
        expect(body.message.candidates).toEqual([{ candidate: 'a' }, { candidate: 'b' }]);   // both, not a fresh empty batch
        vi.useRealTimers();
    });

    it('inflates a signal PHP gzipped, and tells the page when the browser cannot', async () => {
        const { gzipSync } = await import('node:zlib');
        const f = fakeEcho();
        const s = new Signaling({ echo: f.echo, channel: 'beam.call.c1', self: 'u1', transport: 'controller' });
        await Promise.all([s.join(), Promise.resolve(f.confirmSubscribed())]);

        const messages: SignalMessage[] = [];
        const errors: SignalingError[] = [];
        s.on('message', (m) => messages.push(m));
        s.on('error', (e) => errors.push(e));

        // An offer with two video sections is over Pusher's and Reverb's 10 KB
        // event cap, so PHP sends it compressed.
        const offer = { type: 'offer', sdp: { type: 'offer', sdp: 'v=0\r\n'.padEnd(12_000, 'a=x\r\n') }, from: 'u2' };
        const envelope = { type: 'gz', payload: gzipSync(Buffer.from(JSON.stringify(offer))).toString('base64'), from: 'u2' };

        f.deliverBroadcast(envelope as any);
        await vi.waitFor(() => expect(messages).toHaveLength(1));

        expect(messages[0]).toEqual(offer);   // the SDP byte for byte, CRLFs and all
        expect(errors).toHaveLength(0);

        // A third participant's compressed signal is dropped without inflating it:
        // `from` rides outside the envelope for exactly that.
        f.deliverBroadcast({ ...envelope, from: 'u3' } as any);
        await new Promise((r) => setTimeout(r, 20));
        expect(messages).toHaveLength(1);

        // And where DecompressionStream is missing, the page is told rather than
        // left with a call that never connects.
        const real = globalThis.DecompressionStream;
        // @ts-expect-error -- removing a global for the length of one assertion
        delete globalThis.DecompressionStream;
        f.deliverBroadcast(envelope as any);
        await vi.waitFor(() => expect(errors).toHaveLength(1));
        expect(errors[0]!.message).toMatch(/cannot inflate it/);
        globalThis.DecompressionStream = real;
    });
});

