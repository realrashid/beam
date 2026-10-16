// @vitest-environment node
// Blob.arrayBuffer() and crypto.subtle are Node's here; jsdom's shims lack them, and the transfer code needs no DOM.
import { describe, expect, it, vi } from 'vitest';
import { Emitter } from '../../resources/js/events';
import { CHUNK_SIZE, chunks, FileOffer, HEADER_BYTES, HIGH_WATER, packHeader, sha256, Transfer, unpackHeader, type ChannelLike } from '../../resources/js/transfer';

/** A pair of fake channels wired to each other, with controllable back-pressure. */
function pipe() {
    const make = (label: string): ChannelLike & { peer?: ChannelLike; inbox: (string | ArrayBuffer)[]; open(): void } => {
        const ch: any = {
            label, readyState: 'connecting', binaryType: 'blob', bufferedAmount: 0, bufferedAmountLowThreshold: 0, inbox: [],
            onopen: null, onclose: null, onmessage: null, onbufferedamountlow: null, onerror: null,
            send(data: string | ArrayBuffer) { const p = ch.peer; queueMicrotask(() => p?.onmessage?.({ data } as MessageEvent)); },
            close() { ch.readyState = 'closed'; ch.onclose?.({} as Event); },
            open() { ch.readyState = 'open'; ch.onopen?.({} as Event); },
        };
        return ch;
    };
    const a = make('beam-file-x'), b = make('beam-file-x');
    a.peer = b; b.peer = a;
    return { a, b };
}
const signalingPair = () => {
    const bus = new Emitter<any>();
    const side = (self: string) => ({ send: vi.fn(async (m: any) => { queueMicrotask(() => bus.emit('message', { ...m, from: self })); }), on: (ev: string, fn: any) => bus.on(ev, (m: any) => { if (m.from !== self) fn(m); }) });
    return { sender: side('1'), receiver: side('2') };
};
const blobOf = (bytes: number) => new Blob([Uint8Array.from({ length: bytes }, (_, i) => i % 251)]);

describe('framing', () => {
    it('packs and unpacks a header, including offsets past 4 GiB', () => {
        expect(unpackHeader(packHeader(7, 12345))).toEqual({ seq: 7, offset: 12345 });
        expect(unpackHeader(packHeader(0, 5 * 2 ** 32 + 3))).toEqual({ seq: 0, offset: 5 * 2 ** 32 + 3 });
        expect(packHeader(1, 2).byteLength).toBe(HEADER_BYTES);
    });

    it('chunks a blob into 16 KiB pieces with a short last piece, from any offset', async () => {
        const parts: number[] = [];
        for await (const [offset, chunk] of chunks(blobOf(CHUNK_SIZE * 2 + 100), 0, CHUNK_SIZE, CHUNK_SIZE * 2)) parts.push(offset, chunk.byteLength);   // a 2-chunk block: framing must not care where blocks fall
        expect(parts).toEqual([0, CHUNK_SIZE, CHUNK_SIZE, CHUNK_SIZE, CHUNK_SIZE * 2, 100]);
        const resumed: number[] = [];
        for await (const [offset] of chunks(blobOf(CHUNK_SIZE * 2 + 100), CHUNK_SIZE)) resumed.push(offset);
        expect(resumed).toEqual([CHUNK_SIZE, CHUNK_SIZE * 2]);
    });

    it('hashes a blob and a buffer identically', async () => {
        const blob = blobOf(1000);
        expect(await sha256(blob)).toBe(await sha256(await blob.arrayBuffer()));
        expect(await sha256(blob)).toHaveLength(64);
    });
});

describe('Transfer ↔ FileOffer', () => {
    it('offers, is accepted, sends every chunk with progress, and both sides agree on the hash', async () => {
        const { sender, receiver } = signalingPair();
        const { a, b } = pipe();
        const file = blobOf(CHUNK_SIZE * 3 + 17);
        const transfer = new Transfer({ id: 'x', file, name: 'export.bin', mime: 'application/octet-stream', to: '2', signaling: sender, openChannel: () => { queueMicrotask(() => { a.open(); b.open(); }); return a; } });
        const progress: number[] = [];
        transfer.on('progress', (p) => progress.push(p.percent));

        let offer: FileOffer | null = null;
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') offer = new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => b }); });

        const sending = transfer.start();
        await vi.waitFor(() => expect(offer).not.toBeNull());
        const received = await offer!.accept();
        await sending;

        expect(transfer.state).toBe('done');
        expect(received.size).toBe(file.size);
        expect(await sha256(received)).toBe(await sha256(file));
        expect(progress.at(-1)).toBe(100);
        expect(progress).toEqual([...progress].sort((x, y) => x - y));   // monotonic
        expect(sender.send).toHaveBeenCalledWith(expect.objectContaining({ type: 'file-offer', size: file.size, name: 'export.bin' }));
        expect(receiver.send).toHaveBeenCalledWith(expect.objectContaining({ type: 'file-done' }));
    });

    it('a decline rejects the sender with reason declined and nothing is sent', async () => {
        const { sender, receiver } = signalingPair();
        const { a } = pipe();
        const transfer = new Transfer({ id: 'x', file: blobOf(10), name: 'f', mime: 'x', to: '2', signaling: sender, openChannel: () => a });
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') void new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => a }).decline(); });
        const onDeclined = vi.fn();
        transfer.on('declined', onDeclined);

        await expect(transfer.start()).rejects.toMatchObject({ reason: 'declined' });
        expect(transfer.state).toBe('declined');
        expect(onDeclined).toHaveBeenCalled();
        expect(a.inbox).toEqual([]);
    });

    it('refuses a file over the server limit before offering, on both sides', async () => {
        const { sender, receiver } = signalingPair();
        const { a } = pipe();
        const transfer = new Transfer({ id: 'x', file: blobOf(100), name: 'f', mime: 'x', to: '2', signaling: sender, openChannel: () => a, maxBytes: 50 });
        await expect(transfer.start()).rejects.toThrow(/allows 50/);
        expect(sender.send).not.toHaveBeenCalled();

        const offer = new FileOffer({ id: 'y', name: 'f', size: 100, mime: 'x', from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => a, maxBytes: 50 });
        await expect(offer.accept()).rejects.toThrow(/allows 50/);
        expect(receiver.send).toHaveBeenCalledWith(expect.objectContaining({ type: 'file-decline' }));
    });

    it('pauses at the high-water mark and continues on bufferedamountlow', async () => {
        const { sender, receiver } = signalingPair();
        const { a, b } = pipe();
        const file = blobOf(CHUNK_SIZE * 4);
        let sends = 0;
        const realSend = a.send.bind(a);
        a.send = (data) => { sends++; realSend(data); if (sends === 2) a.bufferedAmount = HIGH_WATER + 1; };
        const transfer = new Transfer({ id: 'x', file, name: 'f', mime: 'x', to: '2', signaling: sender, openChannel: () => { queueMicrotask(() => { a.open(); b.open(); }); return a; } });
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') void new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => b }).accept(); });

        const sending = transfer.start();
        await vi.waitFor(() => expect(sends).toBe(2));
        await new Promise((r) => setTimeout(r, 20));
        expect(sends).toBe(2);                              // paused
        a.bufferedAmount = 0;
        a.onbufferedamountlow?.({} as Event);              // drained
        await sending;
        expect(transfer.state).toBe('done');
    });

    it('resumes from the receiver\'s offset on a fresh channel after a drop, without re-sending what arrived', async () => {
        const { sender, receiver } = signalingPair();
        const first = pipe();
        const second = pipe();
        const file = blobOf(CHUNK_SIZE * 6);
        let opens = 0;
        const transfer = new Transfer({ id: 'x', file, name: 'f', mime: 'x', to: '2', signaling: sender, openChannel: () => { const p = opens++ === 0 ? first : second; queueMicrotask(() => { p.a.open(); p.b.open(); }); return p.a; } });
        let offer!: FileOffer;
        let channelCalls = 0;
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') offer = new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => (channelCalls++ === 0 ? first.b : second.b) }); });

        // Drop the first channel after two chunks reach the receiver.
        let delivered = 0;
        const origSend = first.a.send.bind(first.a);
        first.a.send = (data) => { origSend(data); if (typeof data !== 'string' && ++delivered === 2) queueMicrotask(() => { first.a.close(); first.b.close(); }); };

        const sending = transfer.start();
        await vi.waitFor(() => expect(offer).toBeDefined());
        const receiving = offer.accept();
        await vi.waitFor(() => expect(first.a.readyState).toBe('closed'));

        const chunksOnSecond: number[] = [];
        const origSecond = second.a.send.bind(second.a);
        second.a.send = (data) => { if (typeof data !== 'string') chunksOnSecond.push(unpackHeader((data as ArrayBuffer).slice(0, HEADER_BYTES)).offset); origSecond(data); };

        await transfer.resume();
        const blob = await receiving;
        await sending;

        expect(chunksOnSecond[0]).toBe(CHUNK_SIZE * 2);   // continued where the receiver was
        expect(blob.size).toBe(file.size);
        expect(await sha256(blob)).toBe(await sha256(file));
    });

    it('resume() on a channel that is still open opens nothing — an ICE restart keeps the SCTP association', async () => {
        const { sender, receiver } = signalingPair();
        const p = pipe();
        let opens = 0;
        // A full send buffer holds the pump mid-transfer, the way a dead relay does.
        const transfer = new Transfer({ id: 'x', file: blobOf(CHUNK_SIZE * 3), name: 'f', mime: 'x', to: '2', signaling: sender, openChannel: () => { opens++; p.a.bufferedAmount = 4 * 1024 * 1024; queueMicrotask(() => { p.a.open(); p.b.open(); }); return p.a; } });
        let offer!: FileOffer;
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') offer = new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => p.b }); });
        const sending = transfer.start();
        await vi.waitFor(() => expect(offer).toBeDefined());
        const receiving = offer.accept();
        await vi.waitFor(() => expect(transfer.state).toBe('transferring'));
        expect(p.a.readyState).toBe('open');

        await transfer.resume();
        await transfer.resume();
        expect(opens).toBe(1);

        p.a.bufferedAmount = 0;   // the buffer drains once the path is back
        expect((await receiving).size).toBe(CHUNK_SIZE * 3);
        await sending;
    });

    it('a chunk lost in transit is asked for again on done, and the file still arrives whole', async () => {
        const { sender, receiver } = signalingPair();
        const p = pipe();
        const file = blobOf(CHUNK_SIZE * 5);
        const transfer = new Transfer({ id: 'x', file, name: 'f', mime: 'x', to: '2', signaling: sender, openChannel: () => { queueMicrotask(() => { p.a.open(); p.b.open(); }); return p.a; } });
        let offer!: FileOffer;
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') offer = new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => p.b }); });

        // The third chunk vanishes the first time it is sent.
        const offsets: number[] = [];
        let dropped = false;
        const origSend = p.a.send.bind(p.a);
        p.a.send = (data) => {
            if (typeof data !== 'string') {
                const { offset } = unpackHeader((data as ArrayBuffer).slice(0, HEADER_BYTES));
                offsets.push(offset);
                if (offset === CHUNK_SIZE * 2 && !dropped) { dropped = true; return; }
            }
            origSend(data);
        };

        const sending = transfer.start();
        await vi.waitFor(() => expect(offer).toBeDefined());
        const blob = await offer.accept();
        await sending;

        expect(blob.size).toBe(file.size);
        expect(await sha256(blob)).toBe(await sha256(file));
        expect(offsets.filter((o) => o === CHUNK_SIZE * 2)).toHaveLength(2);   // asked for and re-sent once
        expect(offsets).toHaveLength(6);                                         // and nothing else was re-sent
        expect(transfer.state).toBe('done');
    });

    it('a channel that closes while the call is still connected is reopened at once', async () => {
        const { sender, receiver } = signalingPair();
        const first = pipe();
        const second = pipe();
        let opens = 0;
        const transfer = new Transfer({ id: 'x', file: blobOf(CHUNK_SIZE * 6), name: 'f', mime: 'x', to: '2', signaling: sender, connected: () => true, openChannel: () => { const c = opens++ === 0 ? first : second; queueMicrotask(() => { c.a.open(); c.b.open(); }); return c.a; } });
        let offer!: FileOffer;
        let channelCalls = 0;
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') offer = new FileOffer({ id: m.transferId, name: m.name, size: m.size, mime: m.mime, from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => (channelCalls++ === 0 ? first.b : second.b) }); });
        let delivered = 0;
        const origSend = first.a.send.bind(first.a);
        first.a.send = (data) => { origSend(data); if (typeof data !== 'string' && ++delivered === 2) queueMicrotask(() => { first.a.close(); first.b.close(); }); };

        const sending = transfer.start();
        await vi.waitFor(() => expect(offer).toBeDefined());
        const receiving = offer.accept();

        // No resume() from the outside: the close handler reopened because the call was up.
        const blob = await receiving;
        await sending;
        expect(opens).toBe(2);
        expect(blob.size).toBe(CHUNK_SIZE * 6);
    });
});

describe('the offer is the contract', () => {
    it('stops a sender that streams more than the size it offered, instead of finding out at the hash', async () => {
        const { sender, receiver } = signalingPair();
        const { a, b } = pipe();

        // The receiver was told 16 KiB. A sender bug — or a peer aimed at this
        // tab's memory — pushes ten times that.
        const offer = new FileOffer({ id: 'z', name: 'f.bin', size: CHUNK_SIZE, mime: 'application/octet-stream', from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => b });
        const accepted = offer.accept();
        a.open(); b.open();
        await new Promise((r) => setTimeout(r, 0));

        for (let i = 0; i < 10; i++) {
            const frame = new Uint8Array(HEADER_BYTES + CHUNK_SIZE);
            frame.set(new Uint8Array(packHeader(i, i * CHUNK_SIZE)), 0);
            a.send(frame.buffer);
        }

        await expect(accepted).rejects.toThrow(/more than the 16384 bytes it offered/);
        expect(sender.send).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'file-done' }));
    });

    it('takes a file that fills the offered size exactly', async () => {
        const { sender, receiver } = signalingPair();
        const { a, b } = pipe();
        const file = blobOf(CHUNK_SIZE * 2);
        const transfer = new Transfer({ id: 'exact', file, name: 'f.bin', mime: 'application/octet-stream', to: '2', signaling: sender, openChannel: () => { queueMicrotask(() => { a.open(); b.open(); }); return a; } });
        const offer = new FileOffer({ id: 'exact', name: 'f.bin', size: file.size, mime: 'application/octet-stream', from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => b });

        receiver.on('message', (m: any) => { if (m.type === 'file-offer') void offer.accept().then((blob) => expect(blob.size).toBe(file.size)); });

        await transfer.start();
        expect(transfer.state).toBe('done');
    });
});

describe('a transfer that will never finish says so', () => {
    it('settles the receiver when the sender cancels, instead of leaving a promise open for ever', async () => {
        const { sender, receiver } = signalingPair();
        const { a, b } = pipe();
        const file = blobOf(CHUNK_SIZE * 4);

        const transfer = new Transfer({ id: 'c1', file, name: 'f.bin', mime: 'application/octet-stream', to: '2', signaling: sender, openChannel: () => { queueMicrotask(() => { a.open(); b.open(); }); return a; } });
        const offer = new FileOffer({ id: 'c1', name: 'f.bin', size: file.size, mime: 'application/octet-stream', from: { id: '1', name: 'A' }, signaling: receiver, channel: async () => b });

        let received: Promise<Blob> | null = null;
        receiver.on('message', (m: any) => { if (m.type === 'file-offer') received = offer.accept(); });

        const outcome = transfer.start();
        await new Promise((r) => setTimeout(r, 0));
        transfer.cancel();

        await expect(outcome).rejects.toMatchObject({ reason: 'declined' });
        await expect(received!).rejects.toThrow(/sender cancelled/i);
    });

    it('gives up when the sender never opens a channel', async () => {
        const { receiver } = signalingPair();
        const offer = new FileOffer({
            id: 'c2', name: 'f.bin', size: 10, mime: 'application/octet-stream', from: { id: '1', name: 'A' },
            signaling: receiver, channel: () => new Promise(() => {}), channelTimeoutMs: 20,
        });

        await expect(offer.accept()).rejects.toThrow(/never opened a channel/);
    });
});

it('fails with a reason when the file cannot be hashed at all', async () => {
    const { sender } = signalingPair();
    const { a } = pipe();

    // A plain-http page (no crypto.subtle), or a File whose disk entry has gone.
    const unreadable = { size: 64, type: 'application/octet-stream' } as unknown as Blob;
    const transfer = new Transfer({ id: 'h1', file: unreadable, name: 'f.bin', mime: 'application/octet-stream', to: '2', signaling: sender, openChannel: () => a });

    await expect(transfer.start()).rejects.toMatchObject({ reason: 'media-failed' });
    expect(transfer.state).toBe('failed');
});

