import { Emitter } from './events';

/**
 * What crosses between the two browsers. ICE candidates travel as batches:
 * gathering emits ten to forty in its first second, and a message-per-candidate
 * would trip Pusher's ten-per-second cap on the whisper transport.
 */
export type SignalMessage =
    | { type: 'offer'; sdp: RTCSessionDescriptionInit; from: string }
    | { type: 'answer'; sdp: RTCSessionDescriptionInit; from: string }
    | { type: 'ice'; candidates: RTCIceCandidateInit[]; from: string }
    | { type: 'bye'; reason: string; from: string }
    /** "I am subscribed and listening." Sent on join; answered with another hello. Whoever offers waits for one. */
    | { type: 'hello'; from: string }
    // File transfer control travels here, not on the data channel: the channel does not exist until the offer is accepted.
    | { type: 'file-offer'; transferId: string; name: string; size: number; mime: string; from: string }
    | { type: 'file-accept'; transferId: string; from: string }
    | { type: 'file-decline'; transferId: string; from: string }
    | { type: 'file-cancel'; transferId: string; from: string }
    | { type: 'file-done'; transferId: string; sha256: string; from: string };

/** A signal PHP gzipped because the broadcaster would have refused its size; `from` stays readable. */
export interface GzEnvelope {
    type: 'gz';
    payload: string;
    from: string | number | null;
}

/** Omit that distributes over a union — a plain Omit keeps only the keys every member shares. */
type DistributiveOmit<T, K extends PropertyKey> = T extends unknown ? Omit<T, K> : never;

export type OutgoingSignal = DistributiveOmit<SignalMessage, 'from'>;

/**
 * Both transports ship; the token says which one the server configured.
 *
 *   controller  browser → POST /beam/signal → broadcast → browser. PHP sees
 *               every message, a failure is an HTTP status, no size or rate
 *               caps. The default.
 *   whisper     browser → broadcast → browser. Lower latency; Pusher caps
 *               client events at 10/s per connection and drops the excess
 *               without telling the sender.
 */
export type Transport = 'controller' | 'whisper';

/** The slice of a Laravel Echo private channel that Signaling uses. */
export interface EchoChannelLike {
    listen(event: string, callback: (payload: any) => void): this;
    listenForWhisper(event: string, callback: (payload: any) => void): this;
    whisper(event: string, data: Record<string, unknown>): this;
    subscribed(callback: () => void): this;
    error(callback: (error: unknown) => void): this;
}

/** The slice of Laravel Echo that Signaling uses. */
export interface EchoLike {
    private(channel: string): EchoChannelLike;
    leave(channel: string): void;
    /** Echo's socket id, once connected — sent as X-Socket-Id so broadcast()->toOthers() can exclude the sender. */
    socketId?(): string | undefined;
}

export interface SignalingOptions {
    echo: EchoLike;
    /** The private channel, without the `private-` prefix: `beam.call.call_9f3b`. */
    channel: string;
    /** This participant's id; own messages are ignored on receipt. */
    self: string;
    transport: Transport;
    /** Controller transport: where to POST. */
    endpoint?: string;
    /** Controller transport: the packed Beam token, proving this browser was handed this call. */
    token?: string;
    csrf?: string;
    /** Milliseconds to coalesce ICE candidates before sending one batch. */
    iceBatchMs?: number;
    /** How long to wait for the broadcaster to confirm the subscription before giving up. */
    subscribeTimeoutMs?: number;
    fetch?: typeof globalThis.fetch;
}

/**
 * Pusher and Reverb cap a client event near 10 KB and drop the excess without
 * telling the sender. An offer with two video senders and a data channel can
 * reach that, so anything close to it goes the long way round instead.
 */
export const WHISPER_MAX_BYTES = 9_000;

/** How many candidates to keep when a relay is refusing them; the newest are kept. */
export const CANDIDATE_BACKLOG = 200;

export class SignalingError extends Error {
    constructor(message: string, public readonly status?: number) {
        super(message);
        this.name = 'SignalingError';
    }
}

/** A server-side decision about the call: decline, end, close. */
export interface ControlMessage {
    action: 'decline' | 'end' | 'close';
    reason: string | null;
    by: { id: string | number; name: string } | null;
}

export class Signaling extends Emitter<{ message: [SignalMessage]; control: [ControlMessage]; error: [SignalingError]; intruder: [string] }> {
    private channel: EchoChannelLike | null = null;
    /** The one participant this connection talks to: whoever spoke first. */
    private peerId: string | null = null;
    private pendingCandidates: RTCIceCandidateInit[] = [];
    private flushTimer: ReturnType<typeof setTimeout> | null = null;
    private left = false;

    constructor(private readonly options: SignalingOptions) {
        super();
    }

    get transport(): Transport {
        return this.options.transport;
    }

    /** Who this connection is bound to, once anyone has spoken. */
    get peer(): string | null {
        return this.peerId;
    }

    /** Subscribe to the channel; resolves once the broadcaster confirms the subscription. */
    join(): Promise<void> {
        return new Promise((resolve, reject) => {
            const channel = this.options.echo.private(this.options.channel);
            this.channel = channel;

            // A broadcaster that is down, misconfigured, or behind a proxy that
            // never upgrades the socket confirms nothing and errors on nothing:
            // without a deadline the page waits for ever, camera on, with no
            // way to tell anyone what went wrong.
            const deadline = setTimeout(() => {
                reject(new SignalingError(
                    `Could not subscribe to ${this.options.channel}: the broadcaster did not confirm the subscription. Check Broadcasting (php artisan beam:doctor) and that Echo is connected.`,
                ));
            }, this.options.subscribeTimeoutMs ?? 10_000);
            const settle = (fn: () => void) => { clearTimeout(deadline); fn(); };

            const receive = (raw: SignalMessage | GzEnvelope) => {
                // A signal the broadcaster would have refused for its size: PHP
                // gzipped it (beam.signaling.max_payload) and it inflates here.
                // Its `from` rides outside the envelope, so a third participant's
                // message is dropped without being inflated at all.
                if (raw && (raw as GzEnvelope).type === 'gz') {
                    const envelope = raw as GzEnvelope;
                    if (String(envelope.from) === String(this.options.self)) return;
                    if (this.peerId !== null && String(envelope.from) !== this.peerId) {
                        this.emit('intruder', String(envelope.from));

                        return;
                    }
                    void this.inflate(envelope).then((message) => { if (message) deliver(message); });

                    return;
                }

                deliver(raw as SignalMessage);
            };

            const deliver = (payload: SignalMessage) => {
                // PHP sends `from` as the user's id, which is usually an integer;
                // `self` is a string. Compare as strings or a sender processes
                // its own messages — its own ICE candidates, its own offer.
                if (!payload || payload.from === undefined) return;
                const from = String(payload.from);
                if (from === String(this.options.self)) return;

                // One connection talks to one peer. A room is two people today,
                // and a third arriving on the channel would otherwise renegotiate
                // the pair's connection out from under them — or end it with a
                // `bye` that was never about them. Whoever speaks first is the
                // peer; anyone else is announced and ignored.
                this.peerId ??= from;
                if (from !== this.peerId) {
                    this.emit('intruder', from);
                    return;
                }

                this.emit('message', payload);
            };

            // Both transports are always listened to: the server relays on
            // `.signal`, a peer's whisper arrives as a client event. Listening
            // to both costs nothing and means a peer on the other transport
            // (mid-rollout, misconfigured) is still heard.
            channel.listen('.signal', receive);
            channel.listenForWhisper('signal', receive);
            channel.listen('.control', (payload: ControlMessage) => this.emit('control', payload));
            channel.error((error) => settle(() => reject(new SignalingError(`Could not subscribe to ${this.options.channel}: ${String(error)}`))));
            channel.subscribed(() => settle(() => resolve()));
        });
    }

    /** Send an offer, answer or bye through the configured transport. */
    async send(message: OutgoingSignal): Promise<void> {
        if (this.left) return;

        const full = { ...message, from: this.options.self } as SignalMessage;

        if (this.options.transport === 'whisper' && !this.tooBigToWhisper(full)) {
            this.channel?.whisper('signal', full);
            return;
        }

        const fetchFn = this.options.fetch ?? globalThis.fetch;
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (this.options.csrf) headers['X-CSRF-TOKEN'] = this.options.csrf;
        if (this.options.token) headers['X-Beam-Token'] = this.options.token;
        const socketId = this.options.echo.socketId?.();
        if (socketId) headers['X-Socket-Id'] = socketId;

        let response: Response;
        try {
            response = await fetchFn(this.options.endpoint ?? '/beam/signal', {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: JSON.stringify({ channel: this.options.channel, message: full }),
            });
        } catch (cause) {
            const error = new SignalingError(`Signal could not be sent: ${String(cause)}`);
            this.emit('error', error);
            throw error;
        }

        if (!response.ok) {
            const error = new SignalingError(`Signal relay refused (${response.status})`, response.status);
            this.emit('error', error);
            throw error;
        }
    }

    /**
     * Queue an ICE candidate. Candidates are coalesced for `iceBatchMs`
     * (default 100 ms) and sent as one `ice` message.
     */
    sendCandidate(candidate: RTCIceCandidateInit): void {
        if (this.left) return;
        this.pendingCandidates.push(candidate);

        if (this.flushTimer === null) {
            // send() already emits `error` when a batch is refused; an unhandled
            // rejection on top of it is just noise in the console.
            this.flushTimer = setTimeout(() => void this.flushCandidates().catch(() => {}), this.options.iceBatchMs ?? 100);
        }
    }

    /** Send whatever candidates are queued now. */
    async flushCandidates(): Promise<void> {
        if (this.flushTimer !== null) {
            clearTimeout(this.flushTimer);
            this.flushTimer = null;
        }
        if (this.pendingCandidates.length === 0) return;

        const candidates = this.pendingCandidates;
        this.pendingCandidates = [];

        try {
            await this.send({ type: 'ice', candidates });
        } catch (e) {
            // A batch lost to a rate limit or a blip can cost the connection —
            // the other side never learns about a path it needed. Keep them for
            // the next flush (the newest, if a relay is refusing everything)
            // and try again in a second rather than in a hundred milliseconds.
            this.pendingCandidates = [...candidates, ...this.pendingCandidates].slice(-CANDIDATE_BACKLOG);

            if (! this.left && this.flushTimer === null) {
                this.flushTimer = setTimeout(() => void this.flushCandidates().catch(() => {}), 1_000);
            }

            throw e;
        }
    }

    /**
     * Inflate a gzipped signal. DecompressionStream is in every browser Beam
     * supports (Chromium 80, Gecko 113, WebKit 16.4); where it is missing the
     * page is told rather than left with a call that never connects.
     */
    private async inflate(envelope: GzEnvelope): Promise<SignalMessage | null> {
        if (typeof envelope.payload !== 'string' || typeof DecompressionStream === 'undefined') {
            this.emit('error', new SignalingError('A signal arrived compressed and this browser cannot inflate it. Update the browser, or raise beam.signaling.max_payload if your broadcaster accepts a larger event.'));

            return null;
        }

        try {
            const bytes = Uint8Array.from(atob(envelope.payload), (c) => c.charCodeAt(0));
            const gunzip = new DecompressionStream('gzip');
            const writer = gunzip.writable.getWriter();
            void writer.write(bytes);
            void writer.close();

            const reader = gunzip.readable.getReader();
            const chunks: Uint8Array[] = [];
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                if (value) chunks.push(value);
            }

            const size = chunks.reduce((n, c) => n + c.byteLength, 0);
            const joined = new Uint8Array(size);
            let at = 0;
            for (const chunk of chunks) { joined.set(chunk, at); at += chunk.byteLength; }

            return JSON.parse(new TextDecoder().decode(joined)) as SignalMessage;
        } catch (cause) {
            this.emit('error', new SignalingError(`A compressed signal could not be read: ${String(cause)}`));

            return null;
        }
    }

    /**
     * Whether this message is too large for a client event. The controller
     * transport carries it instead — the peer listens to both, so one oversize
     * offer taking the long way round is invisible to it.
     */
    private tooBigToWhisper(full: SignalMessage): boolean {
        if (!this.options.endpoint) return false;

        const size = JSON.stringify(full).length;
        if (size <= WHISPER_MAX_BYTES) return false;

        console.warn(`Beam: this ${full.type} is ${size} bytes, which a broadcaster would drop as a client event (the cap is near 10 KB). Sending it through ${this.options.endpoint} instead. Consider BEAM_SIGNALING=controller.`);

        return true;
    }

    /** Say goodbye, then unsubscribe. Safe to call twice. */
    async leave(reason = 'hangup'): Promise<void> {
        if (this.left) return;

        await this.flushCandidates().catch(() => {});
        await this.send({ type: 'bye', reason }).catch(() => {});

        this.left = true;
        this.options.echo.leave(this.options.channel);
        this.channel = null;
        this.removeAll();
    }
}
