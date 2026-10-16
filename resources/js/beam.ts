import { Call } from './call';
import { BeamError } from './errors';
import { Media } from './media';
import { Peer } from './peer';
import { platform as detectedPlatform, type Platform } from './platform';
import { Push } from './push';
import { FileOffer, Transfer } from './transfer';
import { Signaling, type EchoLike } from './signaling';
import { decodeToken, type Claims } from './token';

export interface BeamConfig {
    /** Defaults to window.Echo. */
    echo?: EchoLike;
    /** The authenticated user's id — for onIncoming(). Defaults to <meta name="beam-user">. */
    userId?: string | number;
    /** Defaults to <meta name="csrf-token">. */
    csrf?: string;
    /** Where Beam's routes live. Defaults to <meta name="beam-prefix"> or "/beam". */
    prefix?: string;
    /**
     * The scope to register the push worker at. '/' by default, so a
     * notification can ring from any page. Narrow it when the application has
     * its own service worker at '/' — or, better, importScripts Beam's from
     * yours and never register this one.
     */
    pushScope?: string;
    /** How long join() waits for the broadcaster to confirm a subscription before failing. Default 10 s. */
    subscribeTimeoutMs?: number;
    fetch?: typeof globalThis.fetch;
}

export interface JoinOptions {
    /** false: a data-only connection (file transfer outside a call) — no camera, no microphone. */
    media?: MediaStreamConstraints | false;
    /** 'relay' forces every packet through TURN — for proving a relay works, not for production. */
    iceTransportPolicy?: RTCIceTransportPolicy;
}

/** What Beam.onIncoming() hands you. Render whatever you like around it. */
export interface Incoming {
    callId: string;
    from: { id: string | number; name: string; avatar?: string | null };
    about: string | null;
    media: 'audio' | 'video';
    timeout: number;
    answer(options?: JoinOptions): Promise<Call>;
    decline(reason?: string): Promise<void>;
}

const meta = (name: string): string | undefined => document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.content;

export default class Beam {
    /** The protocol version this client speaks; must equal Token::VERSION in PHP. */
    static readonly PROTOCOL_VERSION = 1;

    /** The browser this page runs in and what it can do — every quirk Beam works around, answerable from a page too. */
    static get platform(): Platform {
        return detectedPlatform;
    }

    private static config: BeamConfig = {};
    private static presenceTimer: ReturnType<typeof setInterval> | null = null;
    private static readonly calls = new Set<Call>();
    private static fileHandlers = new Set<(offer: FileOffer) => void>();
    /** Live joins by call id, with how many callers share each — see join() and release(). */
    private static readonly joins = new Map<string, { call: Promise<Call>; holders: number }>();
    private static transferMax = 0;

    /**
     * Send a file to a user, browser to browser. Inside a call with them the
     * call's connection carries it; otherwise a data-only connection is set up
     * first (POST {prefix}/transfers, which the beam.call gate guards).
     */
    static send(file: File | Blob, name?: string): { to(userId: string | number): Transfer } {
        return {
            to: (userId) => {
                const id = 'xfer_' + Math.random().toString(36).slice(2, 10);
                const fileName = name ?? (file as File).name ?? 'file';
                const pending = Beam.callWith(userId).then((call) => call.whenConnected());
                // start() surfaces this failure through the first signal it sends,
                // which is where a caller is waiting for it. The handler here only
                // keeps a refused gate or an offline peer from becoming a floating
                // rejection in the console.
                pending.catch(() => {});
                let ready: Call | null = null;
                const transfer = new Transfer({
                    id, file, name: fileName, mime: file.type || 'application/octet-stream', to: String(userId),
                    signaling: {
                        send: async (m) => { ready ??= await pending; await ready.signaling.send(m); },
                        on: (event, fn) => { let off = () => {}; void pending.then((c) => { off = c.signaling.on(event, fn); }); return () => off(); },
                    },
                    openChannel: () => { if (!ready) throw new BeamError('connection-failed', 'Not connected yet.'); return ready.openChannel(`beam-file-${id}`); },
                    connected: () => ready?.state === 'connected',
                    // Read when start() runs, not now: the first send() of a page
                    // has no call to learn the limit from, so it comes from the
                    // meta tag @beamAssets prints.
                    maxBytes: () => Beam.maxTransferBytes(),
                });
                void pending.then((call) => { ready = call; call.transfers.set(id, transfer); Beam.transferMax = Number(call.claims.meta.transferMax ?? 0); }).catch(() => {});
                return transfer;
            },
        };
    }

    /** Someone offers you a file. Accept to receive it; nothing arrives otherwise. */
    static onFileOffered(handler: (offer: FileOffer) => void): () => void {
        Beam.fileHandlers.add(handler);
        return () => Beam.fileHandlers.delete(handler);
    }

    private static offer(call: Call, m: { transferId: string; name: string; size: number; mime: string; from: string }): void {
        // The token carries both participants. The sender is whichever of them
        // sent this — not `meta.from`, which is the caller, and which on the
        // caller's own screen would name them as the sender of their own file.
        const parties = [call.claims.meta.from, call.claims.meta.to] as ({ id?: string | number; name?: string } | undefined)[];
        const sender = parties.find((p) => p !== undefined && String(p.id) === String(m.from));

        const offer = new FileOffer({
            id: m.transferId, name: m.name, size: m.size, mime: m.mime,
            from: { id: m.from, name: sender?.name ?? String(m.from) },
            signaling: call.signaling,
            channel: () => call.channel(`beam-file-${m.transferId}`),
            maxBytes: Number(call.claims.meta.transferMax ?? 0) || undefined,
        });
        Beam.fileHandlers.forEach((h) => h(offer));
    }

    /** The live call with this user, or a data-only one set up for a transfer. */
    private static async callWith(userId: string | number): Promise<Call> {
        for (const call of Beam.calls) {
            const peerId = call.remoteId ?? (call.role === 'caller' ? (call.claims.meta.to as { id?: string | number } | undefined)?.id : (call.claims.meta.from as { id?: string | number } | undefined)?.id);
            if (String(peerId) === String(userId) && call.state !== 'ended') return call;
        }
        const http = Beam.http();
        const res = await (http.fetch ?? globalThis.fetch)(`${http.prefix}/transfers`, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(http.csrf ? { 'X-CSRF-TOKEN': http.csrf } : {}) },
            body: JSON.stringify({ to: userId }),
        });
        if (!res.ok) throw new BeamError('signaling-failed', `Could not open a connection to user ${userId} (${res.status}).`);
        const { token } = (await res.json()) as { token: string };
        return Beam.join(token, { media: false });
    }

    /** Ring a closed tab: Beam.push.subscribe() once per device, from a click. */
    static get push(): Push {
        const http = Beam.http();
        return new Push({ prefix: http.prefix, csrf: http.csrf, fetch: http.fetch, scope: Beam.config.pushScope });
    }

    /** Optional: only needed when Echo is not on window, or the meta tags are absent. */
    static configure(config: BeamConfig): void {
        Beam.config = { ...Beam.config, ...config };
    }

    /** The largest file the server will carry: from the call in hand, or from the page. */
    private static maxTransferBytes(): number | undefined {
        return Beam.transferMax || Number(meta('beam-transfer-max') ?? 0) || undefined;
    }

    private static echo(): EchoLike {
        const echo = Beam.findEcho();
        if (!echo) throw new BeamError('signaling-failed', 'Beam needs Laravel Echo: put it on window.Echo or call Beam.configure({ echo }).');
        return echo;
    }

    private static findEcho(): EchoLike | undefined {
        return Beam.config.echo ?? (window as unknown as { Echo?: EchoLike }).Echo;
    }

    /**
     * Echo, once it exists. A Vite bundle is a deferred module, so an inline
     * script that registers onIncoming() runs before resources/js/echo.js has
     * put Echo on window; waiting until the document has loaded covers that.
     */
    private static whenEcho(): Promise<EchoLike> {
        return new Promise((resolve, reject) => {
            const found = Beam.findEcho();
            if (found) return resolve(found);
            const deadline = Date.now() + 10_000;
            const poll = () => {
                const echo = Beam.findEcho();
                if (echo) return resolve(echo);
                if (Date.now() > deadline) return reject(new BeamError('signaling-failed', 'Beam needs Laravel Echo: put it on window.Echo or call Beam.configure({ echo }).'));
                setTimeout(poll, 50);
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poll, { once: true });
            else poll();
        });
    }

    private static http() {
        return {
            prefix: (Beam.config.prefix ?? meta('beam-prefix') ?? '/beam').replace(/\/$/, ''),
            csrf: Beam.config.csrf ?? meta('csrf-token'),
            fetch: Beam.config.fetch,
        };
    }

    /**
     * Join with a token. Call it from a click: browsers refuse to open a camera
     * without a user gesture, and that is their rule, not ours.
     *
     * Joining the same call twice gives you the same Call rather than a second
     * connection: React's StrictMode mounts an effect twice in development, and
     * an Answer button can be double-clicked. Each caller that is finished with
     * it calls release(); the last one to let go hangs up.
     */
    static join(packedToken: string, options: JoinOptions = {}): Promise<Call> {
        const { claims } = decodeToken(packedToken);
        const existing = Beam.joins.get(claims.callId);

        if (existing) {
            existing.holders++;

            return existing.call;
        }

        const call = Beam.open(packedToken, options);
        Beam.joins.set(claims.callId, { call, holders: 1 });
        void call.then(
            (c) => c.on('ended', () => Beam.joins.delete(claims.callId)),
            () => Beam.joins.delete(claims.callId),
        );

        return call;
    }

    /**
     * Let go of a call this caller joined. The last holder hangs up; an earlier
     * one (React's discarded first mount) leaves the live one alone.
     */
    /**
     * The same as call.release(), which is the form to reach for — a call is the
     * thing you are holding. This one stays for code that has the call in a
     * variable and would rather not reach through it.
     */
    static async release(call: Call): Promise<void> {
        const entry = Beam.joins.get(call.id);

        if (entry) {
            entry.holders--;
            if (entry.holders > 0) return;
        }

        await call.hangUp('navigated');
    }

    private static async open(packedToken: string, options: JoinOptions = {}): Promise<Call> {
        const { token, claims } = decodeToken(packedToken);
        const self = String(claims.sub);
        const http = Beam.http();

        const media = new Media();
        const dataOnly = options.media === false || claims.meta.media === 'none';
        const local = dataOnly ? new MediaStream() : await media.acquire(options.media || Beam.constraintsFor(claims));

        const signaling = new Signaling({
            echo: Beam.echo(),
            channel: claims.channel,
            self,
            transport: claims.meta.transport ?? 'controller',
            token,
            csrf: http.csrf,
            endpoint: `${http.prefix}/signal`,
            subscribeTimeoutMs: Beam.config.subscribeTimeoutMs,
            fetch: http.fetch,
        });

        // The caller offers (impolite); the callee waits (polite). In a room
        // nobody is the caller, so the two ids decide on the first hello.
        const peer = new Peer({ iceServers: claims.ice, iceTransportPolicy: options.iceTransportPolicy ?? 'all' }, { polite: claims.role !== 'caller' });
        let politenessSettled = claims.role !== 'participant';
        let offered = false;
        let offerSeen = false;

        peer.on('icecandidate', (candidate) => signaling.sendCandidate(candidate));

        // SCTP is negotiated in the first offer, by opening a control channel
        // before it. Every later data channel (a file) then opens in-band, with
        // no renegotiation — and a data-only connection has an m-line to offer.
        let controlOpened = false;
        const offer = async () => {
            if (!controlOpened) {
                controlOpened = true;
                peer.createDataChannel('beam-control');
            }
            const sdp = await peer.createOffer();
            await signaling.send({ type: 'offer', sdp });
        };

        // Each side says hello on join. A broadcast has no history, so whichever
        // hello was sent first is lost; the one that lands starts the handshake.
        // Join order is not fixed — a callee often answers before the caller's
        // page has finished loading — so the rules have to hold both ways:
        //   impolite: offer on the first hello it hears, and never again;
        //   polite:   reply hello to a hello until an offer has arrived.
        // Two hellos may cross; two offers never happen.
        // Assigned below, and referred to here so a failure during negotiation
        // reaches the page: this handler is async, and a rejected promise
        // returned to an emitter is an unhandled rejection, not an error event.
        let call: Call | null = null;
        const failed = (reason: string, e: unknown) => {
            const error = new BeamError('connection-failed', `${reason}: ${String(e)}`);
            if (call) call.emit('error', error);
            else console.error('Beam:', error.message);
        };

        signaling.on('message', async (message) => {
            try {
                switch (message.type) {
                    case 'hello':
                        if (!politenessSettled) {
                            peer.polite = self < String(message.from);
                            politenessSettled = true;
                        }
                        if (peer.polite) {
                            if (!offerSeen) await signaling.send({ type: 'hello' });
                        } else if (!offered) {
                            offered = true;
                            await offer();
                        }
                        break;
                    case 'offer': {
                        offerSeen = true;
                        const answer = await peer.handleOffer(message.sdp);
                        if (answer) await signaling.send({ type: 'answer', sdp: answer });
                        break;
                    }
                    case 'answer':
                        await peer.handleAnswer(message.sdp);
                        break;
                    case 'ice':
                        await peer.addIceCandidates(message.candidates);
                        break;
                }
            } catch (e) {
                failed(`Negotiation failed on a ${message.type}`, e);
            }
        });

        peer.on('error', (e) => failed('The connection reported', e));

        // A room is two people today. A third with a valid token is ignored by
        // the signaling channel rather than allowed to renegotiate this pair's
        // connection; the page hears about it and can say so.
        signaling.on('intruder', (who) => {
            if (call) call.emit('error', new BeamError('room-full', `Another participant (${who}) is already in this room; a room carries two people. Their signals are being ignored.`));
        });

        if (!dataOnly) peer.addStream(local);
        const live: Call = new Call({ claims, peer, signaling, local, http, media, onRelease: () => Beam.release(live) });
        call = live;
        Beam.calls.add(call);
        call.on('ended', () => Beam.calls.delete(live));
        signaling.on('message', (m) => { if (m.type === 'file-offer') Beam.offer(live, m); });

        try {
            await signaling.join();
        } catch (e) {
            Beam.calls.delete(call);
            peer.close();
            local.getTracks().forEach((t) => t.stop());
            throw e;
        }
        await signaling.send({ type: 'hello' });

        return call;
    }

    /**
     * Ring when someone calls. Listens on the private channel Echo already has
     * for the authenticated user. Returns an unsubscribe function.
     */
    static onIncoming(handler: (incoming: Incoming) => void): () => void {
        const userId = Beam.config.userId ?? meta('beam-user');
        if (userId === undefined || userId === '') {
            throw new BeamError('signaling-failed', 'Beam.onIncoming() needs the user id: add <meta name="beam-user"> (via @beamAssets) or call Beam.configure({ userId }).');
        }

        const channelName = `beam.user.${userId}`;
        const http = Beam.http();
        let echo: EchoLike | null = null;
        let off = false;

        const onIncoming = (payload: { callId: string; from: Incoming['from']; about: string | null; media: 'audio' | 'video' | 'none'; timeout: number; token: string }) => {
            if (payload.media === 'none') {
                // A file transfer, not a call: connect data-only and quietly. The file offer itself asks for consent.
                void Beam.join(payload.token, { media: false }).catch(() => {});
                return;
            }
            let settled = false;
            handler({
                callId: payload.callId,
                from: payload.from,
                about: payload.about,
                media: payload.media,
                timeout: payload.timeout,
                answer: (options) => {
                    settled = true;
                    return Beam.join(payload.token, options);
                },
                decline: async (reason = 'declined') => {
                    if (settled) return;
                    settled = true;
                    await Beam.post(http, `calls/${payload.callId}/decline`, { reason });
                },
            });
        };

        // Subscribe once Echo is there (a Vite bundle arrives after inline scripts), and only then say "this tab is listening".
        const beat = () => void Beam.post(http, 'presence', {}).catch(() => {});
        Beam.whenEcho().then((found) => {
            if (off) return;
            echo = found;
            echo.private(channelName).listen('.incoming', onIncoming);
            beat();
            if (Beam.presenceTimer === null) Beam.presenceTimer = setInterval(beat, 30_000);
        }).catch((e: BeamError) => { if (!off) console.error(e.message); });

        // A tap on the notification while this tab is open: the worker hands the token over.
        const fromWorker = (event: MessageEvent) => {
            if (event.data?.type === 'beam:answer' && typeof event.data.token === 'string') {
                handler({
                    callId: event.data.callId, from: { id: '', name: '' }, about: null, media: 'video', timeout: 0,
                    answer: (options) => Beam.join(event.data.token, options),
                    decline: async () => { await Beam.post(http, `calls/${event.data.callId}/decline`, { reason: 'declined' }); },
                });
            }
        };
        navigator.serviceWorker?.addEventListener('message', fromWorker);

        return () => {
            off = true;
            echo?.leave(channelName);
            navigator.serviceWorker?.removeEventListener('message', fromWorker);
            if (Beam.presenceTimer !== null) clearInterval(Beam.presenceTimer);
            Beam.presenceTimer = null;
        };
    }

    /**
     * A page opened from the notification carries ?beam=<token>&answer=1.
     * Returns the Call, or null when the page was opened any other way.
     */
    static async answerFromNotification(options: JoinOptions = {}): Promise<Call | null> {
        const params = new URLSearchParams(window.location.search);
        const token = params.get('beam');
        if (!token || params.get('answer') !== '1') return null;

        // A join token is a credential, and the address bar is the one place a
        // credential is copied by accident: history, a shared link, the Referer
        // of anything the page loads, the application's own access log. It has
        // been read; it does not need to stay there.
        try {
            params.delete('beam');
            params.delete('answer');
            const query = params.toString();
            window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
        } catch {
            // an old browser, or a page that is not allowed to rewrite its URL
        }

        return Beam.join(token, options);
    }

    private static constraintsFor(claims: Claims): MediaStreamConstraints {
        return { audio: true, video: claims.meta.media !== 'audio' };
    }

    private static async post(http: ReturnType<typeof Beam.http>, path: string, body: Record<string, unknown>): Promise<void> {
        const fetchFn = http.fetch ?? globalThis.fetch;
        const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (http.csrf) headers['X-CSRF-TOKEN'] = http.csrf;
        const res = await fetchFn(`${http.prefix}/${path}`, { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify(body) });
        if (!res.ok) throw new BeamError('signaling-failed', `${path} refused (${res.status})`);
    }
}

export type { Call, CallState } from './call';
export type { FileOffer, Transfer, TransferState } from './transfer';
export type { BeamError, BeamErrorReason } from './errors';
export type { Claims } from './token';
