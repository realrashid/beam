import { BeamError } from './errors';
import { Emitter } from './events';
import { Media } from './media';
import { Peer } from './peer';
import { platform } from './platform';
import { Signaling } from './signaling';
import type { Claims } from './token';

export type CallState = 'ringing' | 'connecting' | 'connected' | 'reconnecting' | 'ended';

export type AttachTarget = string | HTMLVideoElement | { 0?: Element } | null | undefined;

export interface CallOptions {
    claims: Claims;
    peer: Peer;
    signaling: Signaling;
    local: MediaStream;
    media?: Media;
    /** How the browser reaches the server: the route prefix, CSRF, fetch (for tests). */
    http: { prefix: string; csrf?: string; fetch?: typeof globalThis.fetch };
    /** What release() does: Beam passes the holder count in. Without it, release() is hangUp(). */
    onRelease?: () => Promise<void>;
}

/**
 * One call, from this browser's side. Owns the peer, the signaling channel,
 * the local media, the heartbeat, and the state machine everything else reads.
 */
export class Call extends Emitter<{
    state: [CallState];
    connected: [];
    reconnecting: [];
    ended: [{ reason: string; duration: number }];
    error: [BeamError];
    remotetrack: [MediaStreamTrack, MediaStream];
    quality: [{ rtt: number; packetLoss: number }];
    /** Once a second while connected — for a timer display; the server's figure is the one to record. */
    tick: [{ seconds: number }];
    muted: [boolean];
    camera: [boolean];
    sharing: [boolean];
}> {
    readonly id: string;
    readonly role: Claims['role'];
    readonly claims: Claims;

    private _state: CallState;
    private _remoteStream: MediaStream | null = null;
    private remoteEl: HTMLVideoElement | null = null;
    private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
    private ringTimer: ReturnType<typeof setTimeout> | null = null;
    private connectedAt: number | null = null;
    private endedReason: string | null = null;
    /** The other participant's id, learned from the first signal they sent. */
    remoteId: string | null = null;
    private readonly media: Media;
    private cameraTrack: MediaStreamTrack | null = null;
    private screenStream: MediaStream | null = null;
    /** The screen's own sender when it is shared alongside the camera (or on an audio-only call). */
    private screenSender: RTCRtpSender | null = null;
    private recoveryTimer: ReturnType<typeof setTimeout> | null = null;
    /** While reconnecting, another ICE restart every few seconds: the first one may run while the network is still down. */
    private restartTimer: ReturnType<typeof setInterval> | null = null;
    static readonly RESTART_EVERY_MS = 5_000;
    /** Data channels the other side opened, by label, and waiters for ones not yet opened. */
    private channels = new Map<string, RTCDataChannel>();
    private channelWaiters = new Map<string, { resolve: (c: RTCDataChannel) => void; reject: (e: BeamError) => void }[]>();
    readonly transfers = new Map<string, import('./transfer').Transfer>();
    private qualityTimer: ReturnType<typeof setInterval> | null = null;
    private tickTimer: ReturnType<typeof setInterval> | null = null;
    private onVisible: (() => void) | null = null;
    private onPageHide: (() => void) | null = null;
    /** Consecutive heartbeats the server did not accept; the grant lapses after three. */
    private missedHeartbeats = 0;

    constructor(private readonly o: CallOptions) {
        super();
        this.id = o.claims.callId;
        this.role = o.claims.role;
        this.claims = o.claims;
        this._state = o.claims.role === 'callee' ? 'ringing' : 'connecting';
        this.media = o.media ?? new Media();
        this.cameraTrack = o.local.getVideoTracks()[0] ?? null;

        o.peer.on('track', (track, stream) => {
            // The first stream is the other side's camera and microphone, and feeds attach(). A track
            // of a kind the main stream lacks joins it (a browser may deliver audio and video on separate
            // streams, and the audio-to-video upgrade arrives later). A second video on its own stream —
            // a screen shared alongside the camera — is announced, not swapped in.
            const main = this._remoteStream;
            if (main === null || main.id === stream.id) {
                this._remoteStream = stream;
                if (this.remoteEl) this.play(this.remoteEl, stream);
            } else if (!main.getTracks().some((t) => t.kind === track.kind)) {
                main.addTrack(track);
                if (this.remoteEl) this.play(this.remoteEl, main);
            }
            this.emit('remotetrack', track, stream);
        });

        o.peer.on('state', (s) => {
            if (s === 'connected') {
                const recovered = this._state === 'reconnecting';
                this.endRecovery();
                this.setState('connected');
                if (recovered) this.transfers.forEach((t) => void t.resume());
            } else if (s === 'reconnecting' || s === 'failed') {
                // A dropped network is `reconnecting`, never `ended`: restart ICE
                // and give it reconnectTimeout seconds before giving up.
                this.setState('reconnecting');
                void this.beginRecovery();
            } else if (s === 'connecting' && this._state !== 'connected' && this._state !== 'reconnecting') {
                this.setState('connecting');
            }
        });

        // Renegotiation after the first connection — an ICE restart requested
        // with pc.restartIce(), a track added later — goes through the standard
        // offer/answer path. Before that, the hello handshake owns the offer.
        o.peer.on('negotiationneeded', () => {
            if (this.connectedAt === null || this._state === 'ended') return;
            void o.peer.createOffer().then((sdp) => o.signaling.send({ type: 'offer', sdp })).catch((e) => {
                this.emit('error', new BeamError('connection-failed', `Renegotiation failed: ${String(e)}`));
            });
        });

        o.peer.on('datachannel', (channel) => {
            channel.binaryType = 'arraybuffer';   // Firefox defaults to blob; a chunk queued before the transfer attaches would arrive as one
            this.channels.set(channel.label, channel);
            (this.channelWaiters.get(channel.label) ?? []).forEach(({ resolve }) => resolve(channel));
            this.channelWaiters.delete(channel.label);
        });

        o.signaling.on('message', (m) => {
            if (this.remoteId === null && m.from !== undefined) this.remoteId = String(m.from);
            if (m.type === 'bye') this.finish(m.reason || 'remote-hangup', false);
        });
        o.signaling.on('control', (c) => this.finish(c.action === 'decline' ? 'declined' : c.reason || c.action, false));
        o.signaling.on('error', (e) => this.emit('error', new BeamError('signaling-failed', e.message)));

        this.startHeartbeat();
        this.armBrowserTimeout();

        // pagehide only: a beforeunload listener makes the page ineligible for
        // the back/forward cache in Safari and Firefox, and it tells us nothing
        // pagehide does not.
        this.onPageHide = () => void this.hangUp('navigated');
        window.addEventListener('pagehide', this.onPageHide);

        // A backgrounded tab — iOS suspends its sockets and timers — comes back
        // with a dead ICE pair and a disconnect timer that never ran. Restart at once.
        this.onVisible = () => {
            if (document.visibilityState !== 'visible' || this._state === 'ended') return;
            const ice = (o.peer as { connection?: Pick<RTCPeerConnection, 'iceConnectionState'> }).connection?.iceConnectionState;
            if (ice === 'disconnected' || ice === 'failed') {
                this.setState('reconnecting');
                void this.beginRecovery(true);
            }
        };
        document.addEventListener('visibilitychange', this.onVisible);
    }

    get state(): CallState {
        return this._state;
    }

    /** Resolves once the call is connected; rejects if it ends first. */
    whenConnected(): Promise<this> {
        if (this._state === 'connected') return Promise.resolve(this);
        if (this._state === 'ended') return Promise.reject(new BeamError('connection-failed', 'The call has ended.'));
        return new Promise((resolve, reject) => {
            const off = this.on('connected', () => { off(); offEnd(); resolve(this); });
            const offEnd = this.on('ended', ({ reason }) => { off(); offEnd(); reject(new BeamError('connection-failed', `The call ended: ${reason}.`)); });
        });
    }

    /** Seconds since connected — display only; the server's figure is the one to record. */
    get duration(): number {
        return this.connectedAt === null ? 0 : Math.floor((Date.now() - this.connectedAt) / 1000);
    }

    get localStream(): MediaStream {
        return this.o.local;
    }

    /** The other side's camera and microphone stream — what attach() plays; a screen shared alongside arrives as its own stream via `remotetrack`. */
    get remoteStream(): MediaStream | null {
        return this._remoteStream;
    }

    /** The raw peer, for adapters and the rig. */
    get peer(): Peer {
        return this.o.peer;
    }

    /** The signaling channel — for the rig's assertions. */
    get signaling(): Signaling {
        return this.o.signaling;
    }

    /** The data channel the other side opened under this label — now, or when it arrives. */
    channel(label: string): Promise<RTCDataChannel> {
        const existing = this.channels.get(label);
        if (existing && existing.readyState !== 'closed' && existing.readyState !== 'closing') return Promise.resolve(existing);
        if (this._state === 'ended') return Promise.reject(new BeamError('connection-failed', 'The call ended before that channel opened.'));

        return new Promise((resolve, reject) => this.channelWaiters.set(label, [...(this.channelWaiters.get(label) ?? []), { resolve, reject }]));
    }

    /** Open a data channel for a transfer; Call renegotiates through negotiationneeded. */
    openChannel(label: string): RTCDataChannel {
        return this.o.peer.createDataChannel(label);
    }

    /** Point the streams at <video> elements: a selector, an element, or a jQuery object. */
    attach(targets: { local?: AttachTarget; remote: AttachTarget }): this {
        const local = resolveVideo(targets.local);
        const remote = resolveVideo(targets.remote);

        if (local) {
            local.muted = true;
            local.autoplay = true;
            local.setAttribute('playsinline', '');
            this.play(local, this.o.local);
        }
        if (remote) {
            remote.autoplay = true;
            remote.setAttribute('playsinline', '');
            this.remoteEl = remote;
            if (this._remoteStream) this.play(remote, this._remoteStream);
        }

        return this;
    }

    // ---- controls ---------------------------------------------------------

    get isMuted(): boolean {
        return this.o.local.getAudioTracks().every((t) => !t.enabled);
    }

    get isCameraOn(): boolean {
        return this.cameraTrack?.enabled ?? false;
    }

    get isSharing(): boolean {
        return this.screenStream !== null;
    }

    /** Disables the audio track — never stops it: re-enabling a stopped track means re-acquiring the mic. */
    mute(): this {
        return this.setAudio(false);
    }

    unmute(): this {
        return this.setAudio(true);
    }

    toggleMute(): boolean {
        this.setAudio(this.isMuted);
        return this.isMuted;
    }

    cameraOff(): this {
        return this.setCamera(false);
    }

    /** Re-enable the camera — or, on a call that started audio-only, add one: the audio-to-video upgrade. */
    cameraOn(): this {
        if (this.cameraTrack) return this.setCamera(true);
        void this.enableVideo().catch((e) => this.emit('error', e instanceof BeamError ? e : new BeamError('media-failed', String(e))));
        return this;
    }

    /**
     * Upgrade an audio call to video: open the camera, add it to the local stream and the
     * connection, and renegotiate. The other side gets a `remotetrack` and its remote video lights up.
     */
    async enableVideo(): Promise<void> {
        if (this.cameraTrack) {
            this.setCamera(true);
            return;
        }
        const stream = await this.media.acquire({ video: true, audio: false });
        const track = stream.getVideoTracks()[0];
        if (!track) throw new BeamError('no-device', 'No camera was found.');

        this.o.local.addTrack(track);
        this.cameraTrack = track;

        // Sent now unless the screen is standing in the single video sender —
        // that is the share-instead-of-camera case, and stopSharing() puts the
        // camera back into it. When the screen has its own sender (shared
        // alongside, or on a call that started audio-only) the camera gets one
        // too, rather than being opened locally and never sent.
        if (!this.isSharing || this.screenSender !== null) this.o.peer.addTrack(track, this.o.local);

        this.emit('camera', true);
    }

    /** Open another camera — the given one, or the next one along — and swap it in without renegotiating. */
    async switchCamera(deviceId?: string): Promise<void> {
        let track: MediaStreamTrack;

        if (!deviceId && (platform.isAndroid || platform.isIOS)) {
            // A phone's "other camera" is the one facing the other way, not the next id along.
            const facing = this.cameraTrack?.getSettings?.().facingMode === 'environment' ? 'user' : 'environment';
            track = await this.media.switchFacing(facing);
        } else {
            const cameras = (await this.media.devices()).filter((d) => d.kind === 'videoinput');
            if (cameras.length === 0) throw new BeamError('no-device', 'No camera was found.');

            let target = deviceId;
            if (!target) {
                const current = this.cameraTrack?.getSettings?.().deviceId;
                const index = cameras.findIndex((c) => c.deviceId === current);
                target = cameras[(index + 1) % cameras.length]!.deviceId;
            }

            track = await this.media.switchTo('videoinput', target);
        }

        const wasOn = this.isCameraOn;
        this.replaceCamera(track);
        track.enabled = wasOn;

        if (!this.isSharing) await this.o.peer.replaceVideoTrack(track);
    }

    /**
     * Send the screen instead of the camera — or, with `alongside: true`, as a second video track
     * beside it, which the other side receives as another `remotetrack` with its own stream. Either
     * way the camera comes back when sharing stops, including when the browser's own "Stop sharing" is clicked.
     */
    async shareScreen(options?: DisplayMediaStreamOptions & { alongside?: boolean }): Promise<void> {
        if (this.isSharing) return;

        const { alongside = false, ...constraints } = options ?? {};
        const screen = await this.media.screen(Object.keys(constraints).length ? constraints : undefined);
        const track = screen.getVideoTracks()[0];
        if (!track) throw new BeamError('screen-unavailable', 'The screen stream carried no video.');

        this.screenStream = screen;
        if (alongside || !this.o.peer.hasSender('video')) {
            this.screenSender = this.o.peer.addExtraTrack(track, screen);   // a second video (or the only one, on an audio call): renegotiates
        } else {
            await this.o.peer.replaceVideoTrack(track);
        }
        track.addEventListener('ended', () => void this.stopSharing());
        this.emit('sharing', true);
    }

    async stopSharing(): Promise<void> {
        if (!this.screenStream) return;

        const screen = this.screenStream;
        this.screenStream = null;
        this.media.stop(screen);
        if (this.screenSender) {
            this.o.peer.removeExtraTrack(this.screenSender);
            this.screenSender = null;
        } else {
            await this.o.peer.replaceVideoTrack(this.cameraTrack);
        }
        this.emit('sharing', false);
    }

    /** Route the remote audio to a specific output, where the browser allows it. */
    async setSpeaker(deviceId: string): Promise<void> {
        const el = this.remoteEl as (HTMLVideoElement & { setSinkId?: (id: string) => Promise<void> }) | null;
        if (!el) throw new BeamError('media-failed', 'Attach the remote video before choosing a speaker.');
        if (typeof el.setSinkId !== 'function') throw new BeamError('unsupported', 'This browser cannot choose an audio output.');   // platform.supportsSetSinkId(): Safari
        await el.setSinkId(deviceId);
    }

    private setAudio(enabled: boolean): this {
        this.o.local.getAudioTracks().forEach((t) => { t.enabled = enabled; });
        this.emit('muted', !enabled);
        return this;
    }

    private setCamera(enabled: boolean): this {
        if (this.cameraTrack) this.cameraTrack.enabled = enabled;
        this.emit('camera', enabled);
        return this;
    }

    private replaceCamera(track: MediaStreamTrack): void {
        if (this.cameraTrack) {
            this.o.local.removeTrack(this.cameraTrack);
            this.cameraTrack.stop();
        }
        this.o.local.addTrack(track);
        this.cameraTrack = track;
    }

    /**
     * Let go of this call. The last holder hangs it up — two components that
     * joined the same token (React's StrictMode does exactly that) each release,
     * and only the second one ends the call.
     */
    release(): Promise<void> {
        return this.o.onRelease ? this.o.onRelease() : this.hangUp('navigated');
    }

    async hangUp(reason = 'hangup'): Promise<void> {
        if (this._state === 'ended') return;
        await this.finish(reason, true);
    }

    private async finish(reason: string, tellServer: boolean): Promise<void> {
        if (this._state === 'ended') return;
        this.endedReason = reason;
        const duration = this.duration;

        this.stopTimers();
        await this.o.signaling.leave(reason).catch(() => {});
        this.o.peer.close();
        if (this.screenStream) this.media.stop(this.screenStream);
        this.o.local.getTracks().forEach((t) => t.stop());

        if (tellServer) {
            await this.post(`calls/${this.id}/end`, { reason }).catch(() => {});
        }

        // Nothing is going to open now: a transfer waiting for its channel gets
        // an answer rather than a promise that never settles.
        this.channelWaiters.forEach((waiters) => waiters.forEach(({ reject }) => reject(new BeamError('connection-failed', `The call ended (${reason}) before that channel opened.`))));
        this.channelWaiters.clear();

        this.setState('ended');
        this.emit('ended', { reason, duration });
        this.removeAll();
    }

    // ---- resilience --------------------------------------------------------

    /**
     * The impolite side offers again with fresh candidates (an ICE restart);
     * the polite side answers as usual. Whichever side notices first starts the
     * give-up clock. A Wi-Fi to mobile handover resolves inside it.
     */
    private async beginRecovery(force = false): Promise<void> {
        if (this._state === 'ended') return;

        if (this.recoveryTimer === null) {
            const seconds = Number(this.o.claims.meta.reconnectTimeout ?? 30);
            this.recoveryTimer = setTimeout(() => void this.finish('connection-lost', true), seconds * 1000);
        }

        // Only the impolite side offers on a drop — except a tab that just woke up, whose restart the other side may have already missed.
        if (this.o.peer.polite && !force) return;

        // A restart offered while the network (or the relay) is still down gathers nothing and is never
        // retried by the browser — Chromium keeps probing TURN for a while, Gecko does not. Offer again
        // every few seconds until the pair is back or reconnectTimeout gives up.
        if (this.restartTimer === null) {
            this.restartTimer = setInterval(() => {
                if (this._state !== 'reconnecting') return;
                void this.restart();
            }, Call.RESTART_EVERY_MS);
        }

        await this.restart();
    }

    private async restart(): Promise<void> {
        try {
            const sdp = await this.o.peer.restartIce();
            await this.o.signaling.send({ type: 'offer', sdp });
        } catch (e) {
            this.emit('error', new BeamError('connection-failed', `ICE restart failed: ${String(e)}`));
        }
    }

    private endRecovery(): void {
        if (this.recoveryTimer !== null) clearTimeout(this.recoveryTimer);
        this.recoveryTimer = null;
        if (this.restartTimer !== null) clearInterval(this.restartTimer);
        this.restartTimer = null;
    }

    /** Every 2 s while connected: round-trip time and packet loss from the selected candidate pair and the inbound video. */
    private startQuality(): void {
        if (this.qualityTimer !== null) return;
        this.qualityTimer = setInterval(() => void this.sampleQuality(), 2000);
    }

    private stopQuality(): void {
        if (this.qualityTimer !== null) clearInterval(this.qualityTimer);
        this.qualityTimer = null;
    }

    private async sampleQuality(): Promise<void> {
        if (this._state !== 'connected' || this.listenerCount('quality') === 0) return;

        let stats: RTCStatsReport;
        try {
            stats = await this.o.peer.connection.getStats();
        } catch {
            return;
        }

        let rtt: number | null = null;
        let lost = 0;
        let received = 0;

        stats.forEach((report: any) => {
            if (report.type === 'candidate-pair' && (report.nominated || report.state === 'succeeded') && typeof report.currentRoundTripTime === 'number') {
                rtt = report.currentRoundTripTime * 1000;
            }
            if (report.type === 'inbound-rtp' && (report.kind === 'video' || report.mediaType === 'video')) {
                lost += Number(report.packetsLost ?? 0);
                received += Number(report.packetsReceived ?? 0);
            }
            // Firefox reports RTT on remote-inbound-rtp instead.
            if (rtt === null && report.type === 'remote-inbound-rtp' && typeof report.roundTripTime === 'number') {
                rtt = report.roundTripTime * 1000;
            }
        });

        if (rtt === null && received === 0) return;

        this.emit('quality', { rtt: rtt ?? 0, packetLoss: received + lost > 0 ? lost / (received + lost) : 0 });
    }

    private setState(next: CallState): void {
        if (this._state === next || this._state === 'ended') return;
        this._state = next;
        if (next === 'connected' && this.connectedAt === null) this.connectedAt = Date.now();
        if (next === 'connected') {
            this.startQuality();
            if (this.tickTimer === null) this.tickTimer = setInterval(() => { if (this._state === 'connected') this.emit('tick', { seconds: this.duration }); }, 1000);
        }
        this.emit('state', next);
        if (next === 'connected') this.emit('connected');
        if (next === 'reconnecting') this.emit('reconnecting');
        void this.heartbeat();
    }

    /** Every `meta.heartbeat` seconds: still here, in this state. Keeps the server's registry alive. */
    private startHeartbeat(): void {
        const every = Math.max(5, Number(this.o.claims.meta.heartbeat ?? 30)) * 1000;
        this.heartbeatTimer = setInterval(() => void this.heartbeat(), every);
        void this.heartbeat();
    }

    /**
     * Still here, in this state. The server's grant lasts three heartbeats, so
     * three refusals in a row are a call that is about to be refused its own
     * signals — retried once quickly, and said out loud rather than swallowed.
     */
    private heartbeat(): Promise<void> {
        if (this._state === 'ended') return Promise.resolve();

        return this.post(`calls/${this.id}/heartbeat`, { state: this._state })
            .then(() => { this.missedHeartbeats = 0; })
            .catch(() => {
                this.missedHeartbeats++;

                if (this.missedHeartbeats === 1) {
                    setTimeout(() => { if (this._state !== 'ended') void this.heartbeat(); }, 2_000);
                } else if (this.missedHeartbeats === 3) {
                    this.emit('error', new BeamError('signaling-failed', 'The server has not accepted a heartbeat for this call; it may stop relaying signals.'));
                }
            });
    }

    /** The browser timeout driver: the caller's page reports that nobody picked up. */
    private armBrowserTimeout(): void {
        if (this.role !== 'caller' || this.o.claims.meta.timeoutDriver !== 'browser') return;
        const seconds = Number(this.o.claims.meta.timeout ?? 30);
        this.ringTimer = setTimeout(() => {
            if (this._state === 'connecting') {
                void this.post(`calls/${this.id}/timeout`).catch(() => {});
                void this.finish('timeout', false);
            }
        }, seconds * 1000);
    }

    private stopTimers(): void {
        if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
        if (this.ringTimer) clearTimeout(this.ringTimer);
        this.heartbeatTimer = null;
        this.ringTimer = null;
        this.endRecovery();
        this.stopQuality();
        if (this.tickTimer) clearInterval(this.tickTimer);
        this.tickTimer = null;
        if (this.onVisible) document.removeEventListener('visibilitychange', this.onVisible);
        this.onVisible = null;
        if (this.onPageHide) window.removeEventListener('pagehide', this.onPageHide);
        this.onPageHide = null;
    }

    private play(el: HTMLVideoElement, stream: MediaStream): void {
        if (el.srcObject !== stream) el.srcObject = stream;
        const playing = el.play() as Promise<void> | undefined;
        playing?.catch?.((e: unknown) => {
            if ((e as DOMException)?.name !== 'NotAllowedError') {
                this.emit('error', new BeamError('media-failed', `Playback failed: ${String(e)}`));
                return;
            }
            // The autoplay policy — always on Safari and iOS, on Chromium until the origin has been
            // touched (platform.needsUserGestureForPlay): play again on the next tap, click or key.
            this.emit('error', new BeamError('autoplay-blocked', 'Autoplay was blocked; playback resumes on the next tap.'));
            const gestures = ['click', 'touchend', 'keydown'] as const;
            const retry = () => {
                gestures.forEach((name) => document.removeEventListener(name, retry, true));
                (el.play() as Promise<void> | undefined)?.catch?.(() => {
                    this.emit('error', new BeamError('autoplay-blocked', 'Playback needs a tap: call play() on the video from a click handler.'));
                });
            };
            gestures.forEach((name) => document.addEventListener(name, retry, { capture: true, once: true }));
        });
    }

    private async post(path: string, body: Record<string, unknown> = {}): Promise<void> {
        const fetchFn = this.o.http.fetch ?? globalThis.fetch;
        const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (this.o.http.csrf) headers['X-CSRF-TOKEN'] = this.o.http.csrf;
        const res = await fetchFn(`${this.o.http.prefix}/${path}`, { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify(body), keepalive: true });
        if (!res.ok) throw new BeamError('signaling-failed', `${path} refused (${res.status})`);
    }
}

function resolveVideo(target: AttachTarget): HTMLVideoElement | null {
    if (!target) return null;
    if (typeof target === 'string') return document.querySelector<HTMLVideoElement>(target);
    if (target instanceof HTMLVideoElement) return target;
    const first = (target as { 0?: Element })[0];
    return first instanceof HTMLVideoElement ? first : null;
}
