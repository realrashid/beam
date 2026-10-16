import { BeamError } from './errors';
import { Emitter } from './events';
import type { Signaling } from './signaling';

export type TransferState = 'offered' | 'accepted' | 'transferring' | 'done' | 'declined' | 'cancelled' | 'failed';

export const CHUNK_SIZE = 16 * 1024;          // one data-channel message; safe across browsers
export const BLOCK_SIZE = 1024 * 1024;        // how much of the file is read from disk at a time
export const HEADER_BYTES = 12;                // [u32 seq][u32 offsetHi][u32 offsetLo]
export const HIGH_WATER = 1024 * 1024;         // pause sending above this bufferedAmount
export const LOW_WATER = 64 * 1024;            // bufferedAmountLowThreshold

/** Every chunk carries where it belongs, so a resumed transfer cannot misplace one. */
export function packHeader(seq: number, offset: number): ArrayBuffer {
    const buf = new ArrayBuffer(HEADER_BYTES);
    const view = new DataView(buf);
    view.setUint32(0, seq);
    view.setUint32(4, Math.floor(offset / 2 ** 32));
    view.setUint32(8, offset % 2 ** 32);
    return buf;
}

export function unpackHeader(buf: ArrayBuffer): { seq: number; offset: number } {
    const view = new DataView(buf);
    return { seq: view.getUint32(0), offset: view.getUint32(4) * 2 ** 32 + view.getUint32(8) };
}

/**
 * Yields [offset, chunk] pairs from `from` to the end. The file is read a
 * block at a time and framed from memory: one disk read per MiB, not one per
 * 16 KiB message — the difference between a stall and a transfer.
 */
export async function* chunks(file: Blob, from = 0, size = CHUNK_SIZE, block = BLOCK_SIZE): AsyncGenerator<[number, ArrayBuffer]> {
    for (let start = from; start < file.size; start += block) {
        const buffer = await file.slice(start, Math.min(start + block, file.size)).arrayBuffer();
        for (let inner = 0; inner < buffer.byteLength; inner += size) {
            yield [start + inner, buffer.slice(inner, Math.min(inner + size, buffer.byteLength))];
        }
    }
}

export async function sha256(data: Blob | ArrayBuffer): Promise<string> {
    const bytes = data instanceof Blob ? await data.arrayBuffer() : data;
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Wait for the send buffer to fall below the low-water mark. The event alone
 * is not enough: if the buffer drained between the check and arming the
 * handler, bufferedamountlow never fires and the transfer would hang — so the
 * level is re-read after arming, and re-checked on a timer while a drop is
 * being recovered.
 */
function drained(channel: ChannelLike): Promise<void> {
    return new Promise<void>((resolve) => {
        let timer: ReturnType<typeof setInterval> | null = null;
        const done = () => {
            channel.onbufferedamountlow = null;
            if (timer) clearInterval(timer);
            resolve();
        };
        channel.onbufferedamountlow = done;
        if (channel.bufferedAmount <= LOW_WATER || channel.readyState !== 'open') return done();
        timer = setInterval(() => { if (channel.bufferedAmount <= LOW_WATER || channel.readyState !== 'open') done(); }, 100);
    });
}

/** The slice of RTCDataChannel the sender and receiver use; a test supplies a fake. */
export interface ChannelLike {
    readonly label: string;
    readyState: RTCDataChannelState;
    binaryType: BinaryType;
    bufferedAmount: number;
    bufferedAmountLowThreshold: number;
    send(data: string | ArrayBuffer): void;
    close(): void;
    onopen: ((ev: Event) => void) | null;
    onclose: ((ev: Event) => void) | null;
    onmessage: ((ev: MessageEvent) => void) | null;
    onbufferedamountlow: ((ev: Event) => void) | null;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- RTCDataChannel's is an RTCErrorEvent; a fake's is an Event
    onerror: ((ev: any) => void) | null;
}

export interface TransferOptions {
    id: string;
    file: File | Blob;
    name: string;
    mime: string;
    to: string;
    signaling: Pick<Signaling, 'send' | 'on'>;
    /** Opens (or re-opens after a reconnect) the data channel for this transfer. */
    openChannel: () => ChannelLike;
    /** Whether the call is connected right now — a channel that closes while it is gets reopened at once. */
    connected?: () => boolean;
    /** The server's limit, or a function for one that is not known yet — the first send() of a page learns it from the call it opens. */
    maxBytes?: number | (() => number | undefined);
}

/**
 * The sending side. start() offers the file over signaling; once accepted, a
 * data channel carries 16 KiB chunks with back-pressure, and the receiver's
 * file-done — with a matching SHA-256 — resolves it. A dropped connection is
 * resumed from the receiver's offset on a fresh channel.
 */
export class Transfer extends Emitter<{
    state: [TransferState];
    accepted: [];
    progress: [{ sent: number; total: number; percent: number; bytesPerSecond: number }];
    done: [];
    declined: [];
    cancelled: [];
    error: [BeamError];
}> {
    readonly id: string;
    readonly file: File | Blob;
    readonly name: string;
    readonly size: number;
    readonly to: string;

    private _state: TransferState = 'offered';
    private channel: ChannelLike | null = null;
    private sent = 0;
    private seq = 0;
    private startedAt = 0;
    private lastProgressAt = 0;
    private hash: Promise<string>;
    private finished!: (value: void) => void;
    private failed!: (reason: BeamError) => void;
    private readonly outcome: Promise<void>;
    private offSignaling: (() => void) | null = null;
    private sending = false;
    /** A resume offset that arrived while a pump was running; the next pump starts there. */
    private again: number | null = null;

    constructor(private readonly o: TransferOptions) {
        super();
        this.id = o.id;
        this.file = o.file;
        this.name = o.name;
        this.size = o.file.size;
        this.to = o.to;
        // Hashing starts now so it is ready when the last chunk is. A file the
        // browser cannot read — a plain-http page, where crypto.subtle does not
        // exist, or a File whose disk entry is gone — is a failed transfer with
        // a reason on it, not an unhandled rejection nobody sees.
        this.hash = sha256(o.file).catch((e: unknown) => {
            this.fail(new BeamError('media-failed', `This file could not be read: ${String(e)}`), 'failed');

            return '';
        });
        this.outcome = new Promise((resolve, reject) => { this.finished = resolve; this.failed = reject; });
        this.outcome.catch(() => {});
    }

    get state(): TransferState {
        return this._state;
    }

    get bytesSent(): number {
        return this.sent;
    }

    /** Offer the file; resolves when the receiver reports done, rejects on decline, cancel or failure. */
    async start(): Promise<void> {
        const max = typeof this.o.maxBytes === 'function' ? this.o.maxBytes() : this.o.maxBytes;

        if (max && this.size > max) {
            const error = new BeamError('media-failed', `This file is ${this.size} bytes; the server allows ${max}.`);
            this.fail(error, 'failed');
            return this.outcome;
        }

        this.offSignaling = this.o.signaling.on('message', (m) => {
            if (!('transferId' in m) || m.transferId !== this.id) return;
            if (m.type === 'file-accept') { this.setState('accepted'); this.emit('accepted'); void this.openAndSend(0); }
            if (m.type === 'file-decline') this.fail(new BeamError('declined', 'The file was declined.'), 'declined');
            if (m.type === 'file-cancel') this.fail(new BeamError('declined', 'The receiver cancelled the transfer.'), 'cancelled');
            if (m.type === 'file-done') void this.verify(m.sha256);
        });

        await this.o.signaling.send({ type: 'file-offer', transferId: this.id, name: this.name, size: this.size, mime: this.o.mime });

        return this.outcome;
    }

    cancel(): void {
        if (this._state === 'done' || this._state === 'failed') return;
        void this.o.signaling.send({ type: 'file-cancel', transferId: this.id }).catch(() => {});
        this.fail(new BeamError('declined', 'Cancelled.'), 'cancelled');
    }

    /** After a reconnect: a fresh channel, and the receiver says where to continue from. */
    async resume(): Promise<void> {
        if (this._state !== 'transferring' && this._state !== 'accepted') return;
        // An ICE restart keeps the SCTP association: the channel is still open on both sides and
        // its buffer drains by itself. A second channel would sit unanswered — the receiver is
        // still listening on the first. Only a closed channel needs a fresh one.
        if (this.channel && this.channel.readyState === 'open') return;
        await this.openAndSend(null);
    }

    private async openAndSend(from: number | null): Promise<void> {
        const channel = this.o.openChannel();
        this.channel = channel;
        channel.binaryType = 'arraybuffer';
        channel.bufferedAmountLowThreshold = LOW_WATER;

        const opened = new Promise<void>((resolve, reject) => {
            if (channel.readyState === 'open') return resolve();
            channel.onopen = () => resolve();
            channel.onerror = () => reject(new BeamError('connection-failed', 'The data channel failed to open.'));
        });

        channel.onmessage = (ev) => {
            if (typeof ev.data !== 'string') return;
            const msg = JSON.parse(ev.data) as { type: string; offset?: number; until?: number };
            if (msg.type === 'resume' && typeof msg.offset === 'number') void this.pump(msg.offset, typeof msg.until === 'number' ? msg.until : undefined);
        };
        channel.onclose = () => {
            // A closed channel mid-transfer is a dropped connection, not a failure; resume() reopens it —
            // now, if the call is still up (a transport reset closes channels), or when it comes back.
            if (this._state !== 'transferring' && this._state !== 'accepted') return;
            this.sending = false;
            if (this.channel === channel && this.o.connected?.()) void this.resume();
        };

        try {
            await opened;
        } catch (e) {
            this.fail(e as BeamError, 'failed');
            return;
        }

        if (from === null) {
            channel.send(JSON.stringify({ type: 'resume?' }));
        } else {
            await this.pump(from);
        }
    }

    /** Send [from, until) — the whole rest of the file unless the receiver named one hole — then say done. */
    private async pump(from: number, until?: number): Promise<void> {
        const channel = this.channel;
        if (!channel) return;
        if (this.sending) {
            // One pump at a time; the receiver's newest request runs once this one ends.
            this.again = this.again === null ? from : Math.min(this.again, from);
            return;
        }
        this.sending = true;
        this.setState('transferring');
        if (this.startedAt === 0) this.startedAt = Date.now();
        this.sent = from;

        try {
            for await (const [offset, chunk] of chunks(this.file, from)) {
                if (until !== undefined && offset >= until) break;
                if (channel.readyState !== 'open') return;   // dropped: resume() will pick up from the receiver's offset

                if (channel.bufferedAmount > HIGH_WATER) {
                    await drained(channel);
                    if (channel.readyState !== 'open') return;
                }

                const frame = new Uint8Array(HEADER_BYTES + chunk.byteLength);
                frame.set(new Uint8Array(packHeader(this.seq++, offset)), 0);
                frame.set(new Uint8Array(chunk), HEADER_BYTES);
                channel.send(frame.buffer);

                this.sent = Math.max(this.sent, offset + chunk.byteLength);
                const now = Date.now();
                if (this.sent === this.size || now - this.lastProgressAt >= 100) {
                    this.lastProgressAt = now;
                    const elapsed = Math.max(1, now - this.startedAt) / 1000;
                    this.emit('progress', { sent: this.sent, total: this.size, percent: Math.floor((this.sent / this.size) * 100), bytesPerSecond: Math.round(this.sent / elapsed) });
                }
            }

            channel.send(JSON.stringify({ type: 'done', sha256: await this.hash }));
        } catch {
            // send() on a channel that closed under us: the drop is handled by onclose and resume().
        } finally {
            this.sending = false;
            if (this.again !== null) {
                const next = this.again;
                this.again = null;
                void this.pump(next);
            }
        }
    }

    private async verify(theirs: string): Promise<void> {
        const ours = await this.hash;
        if (theirs !== ours) {
            this.fail(new BeamError('connection-failed', 'The received file does not match what was sent (integrity).'), 'failed');
            return;
        }
        this.setState('done');
        this.emit('done');
        this.cleanup();
        this.finished();
    }

    private fail(error: BeamError, state: TransferState): void {
        if (this._state === 'done' || this._state === 'failed' || this._state === 'declined' || this._state === 'cancelled') return;
        this.setState(state);
        if (state === 'declined') this.emit('declined');
        if (state === 'cancelled') this.emit('cancelled');
        if (state === 'failed') this.emit('error', error);
        this.cleanup();
        this.failed(error);
    }

    private cleanup(): void {
        this.offSignaling?.();
        this.offSignaling = null;
        try { this.channel?.close(); } catch { /* already closed */ }
        this.channel = null;
    }

    private setState(next: TransferState): void {
        if (this._state === next) return;
        this._state = next;
        this.emit('state', next);
    }
}

export interface FileOfferOptions {
    id: string;
    name: string;
    size: number;
    mime: string;
    from: { id: string | number; name: string };
    /** `on` so a cancelled transfer reaches the receiver: the sender says so over signaling, not over the channel. */
    signaling: Pick<Signaling, 'send' | 'on'>;
    /** Resolves with the data channel the sender opens for this transfer (label beam-file-{id}). */
    channel: () => Promise<ChannelLike>;
    maxBytes?: number;
    /** How long to wait for the sender's channel after accepting. Default 30 s. */
    channelTimeoutMs?: number;
}

/**
 * The receiving side: what Beam.onFileOffered() hands you. Nothing arrives
 * until accept(); decline() tells the sender so.
 */
export class FileOffer extends Emitter<{ progress: [{ received: number; total: number; percent: number }] }> {
    readonly id: string;
    readonly name: string;
    readonly size: number;
    readonly mime: string;
    readonly from: FileOfferOptions['from'];
    private received = 0;
    private lastProgressAt = 0;
    private parts: Map<number, ArrayBuffer> = new Map();
    private settled = false;

    constructor(private readonly o: FileOfferOptions) {
        super();
        this.id = o.id;
        this.name = o.name;
        this.size = o.size;
        this.mime = o.mime;
        this.from = o.from;
    }

    /** Accept and receive: resolves with the assembled Blob once every byte is in and the hash matches. */
    async accept(): Promise<Blob> {
        if (this.settled) throw new BeamError('declined', 'This offer was already answered.');
        this.settled = true;

        if (this.o.maxBytes && this.size > this.o.maxBytes) {
            await this.o.signaling.send({ type: 'file-decline', transferId: this.id });
            throw new BeamError('media-failed', `This file is ${this.size} bytes; the server allows ${this.o.maxBytes}.`);
        }

        await this.o.signaling.send({ type: 'file-accept', transferId: this.id });

        return new Promise<Blob>((resolve, reject) => {
            // A sender that cancels says so over signaling, and a sender whose
            // tab dies says nothing at all. Without either of these the accept
            // promise simply never settles, and the page waits at whatever
            // progress it had reached.
            const off = this.o.signaling.on('message', (m) => {
                if ('transferId' in m && m.transferId === this.id && m.type === 'file-cancel') {
                    stop();
                    reject(new BeamError('declined', 'The sender cancelled the transfer.'));
                }
            });

            const deadline = setTimeout(() => {
                stop();
                reject(new BeamError('connection-failed', 'The sender never opened a channel for this file.'));
            }, this.o.channelTimeoutMs ?? 30_000);

            const stop = () => {
                off();
                clearTimeout(deadline);
                this.parts.clear();
            };

            const attach = (channel: ChannelLike) => {
                clearTimeout(deadline);   // it arrived; from here the transfer's own signals decide
                channel.binaryType = 'arraybuffer';
                channel.onmessage = async (ev) => {
                    // Firefox defaults binaryType to blob and a chunk can already be queued when
                    // attach() runs; read it either way (Chromium and Safari default to arraybuffer).
                    const data: string | ArrayBuffer = typeof Blob !== 'undefined' && ev.data instanceof Blob ? await ev.data.arrayBuffer() : ev.data;
                    if (typeof data === 'string') {
                        const msg = JSON.parse(data) as { type: string; sha256?: string };
                        if (msg.type === 'resume?') channel.send(JSON.stringify({ type: 'resume', ...this.hole() }));
                        if (msg.type === 'done') {
                            // A chunk in flight when a transport died is gone; whatever race lost it, the
                            // fix is the same: ask for the first hole, no more, and wait for the next done.
                            const hole = this.hole();
                            if (hole.offset < this.size) {
                                channel.send(JSON.stringify({ type: 'resume', ...hole }));
                                return;
                            }
                            const blob = new Blob([...this.parts.keys()].sort((a, b) => a - b).map((k) => this.parts.get(k)!), { type: this.mime });
                            const ours = await sha256(blob);
                            if (blob.size !== this.size || ours !== msg.sha256) {
                                stop();
                                await this.o.signaling.send({ type: 'file-cancel', transferId: this.id }).catch(() => {});
                                reject(new BeamError('connection-failed', 'The file arrived damaged (integrity).'));
                                return;
                            }
                            await this.o.signaling.send({ type: 'file-done', transferId: this.id, sha256: ours });
                            off();
                            clearTimeout(deadline);
                            resolve(blob);
                        }
                        return;
                    }
                    const buf = data;
                    const { offset } = unpackHeader(buf.slice(0, HEADER_BYTES));
                    const chunk = buf.slice(HEADER_BYTES);

                    // The offer is the contract. A peer that keeps sending past the
                    // size it named — a bug, or a browser aimed at this tab's memory —
                    // is stopped here rather than at the hash, by which time the
                    // memory is already gone.
                    if (offset + chunk.byteLength > this.size || this.received + chunk.byteLength > this.size) {
                        channel.onmessage = null;
                        channel.onclose = null;
                        stop();
                        void this.o.signaling.send({ type: 'file-cancel', transferId: this.id }).catch(() => {});
                        reject(new BeamError('connection-failed', `This transfer sent more than the ${this.size} bytes it offered.`));
                        return;
                    }

                    if (!this.parts.has(offset)) {
                        this.parts.set(offset, chunk);
                        this.received += chunk.byteLength;
                        const now = Date.now();
                        if (this.received === this.size || now - this.lastProgressAt >= 100) {
                            this.lastProgressAt = now;
                            this.emit('progress', { received: this.received, total: this.size, percent: Math.floor((this.received / this.size) * 100) });
                        }
                    }
                };
                // The sender re-opens a channel after a drop; keep listening for it.
                channel.onclose = () => { if (this.received < this.size) void this.o.channel().then(attach).catch(() => {}); };
            };
            this.o.channel().then(attach).catch((e) => { stop(); reject(e); });
        });
    }

    /** The first stretch of the file not yet here: [offset, until). offset === size means nothing is missing. */
    private hole(): { offset: number; until: number } {
        let pos = 0;
        for (const offset of [...this.parts.keys()].sort((a, b) => a - b)) {
            if (offset !== pos) return { offset: pos, until: offset };
            pos = offset + this.parts.get(offset)!.byteLength;
        }
        return { offset: pos, until: this.size };
    }

    async decline(): Promise<void> {
        if (this.settled) return;
        this.settled = true;
        await this.o.signaling.send({ type: 'file-decline', transferId: this.id });
    }
}
