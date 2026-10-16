import { Emitter } from './events';
import { platform } from './platform';

export type PeerState = 'new' | 'connecting' | 'connected' | 'reconnecting' | 'failed' | 'closed';

export interface PeerOptions {
    /**
     * Perfect negotiation: when both sides offer at once (glare), the polite
     * side rolls back and accepts the other's offer; the impolite side ignores
     * the incoming one. The caller is impolite, the callee polite.
     */
    polite: boolean;
    /** Injected for unit tests; the browser's RTCPeerConnection otherwise. */
    factory?: (config: RTCConfiguration) => RTCPeerConnection;
}

/**
 * One RTCPeerConnection, with the parts every WebRTC tutorial gets wrong
 * handled once: candidates that arrive before the remote description are
 * queued and flushed; glare is resolved by the polite/impolite rule; the
 * connection's many states are mapped onto five the rest of Beam cares about.
 */
export class Peer extends Emitter<{
    state: [PeerState];
    track: [MediaStreamTrack, MediaStream];
    icecandidate: [RTCIceCandidateInit];
    negotiationneeded: [];
    datachannel: [RTCDataChannel];
    error: [Error];
}> {
    /** Decided at construction for calls; for rooms, on the first hello, by comparing ids. */
    polite: boolean;
    private readonly pc: RTCPeerConnection;
    private pendingCandidates: RTCIceCandidateInit[] = [];
    private _state: PeerState = 'new';
    private makingOffer = false;
    private ignoreOffer = false;
    private readonly senders = new Map<string, RTCRtpSender>();

    constructor(config: RTCConfiguration, options: PeerOptions) {
        super();
        this.polite = options.polite;
        this.pc = (options.factory ?? ((c) => new RTCPeerConnection(c)))(config);

        this.pc.onicecandidate = (event) => {
            // A null candidate marks the end of gathering; nothing to send.
            if (event.candidate) this.emit('icecandidate', event.candidate.toJSON());
        };
        this.pc.ontrack = (event) => {
            const stream = event.streams[0] ?? new MediaStream([event.track]);
            this.emit('track', event.track, stream);
        };
        this.pc.onnegotiationneeded = () => this.emit('negotiationneeded');
        this.pc.ondatachannel = (event) => this.emit('datachannel', event.channel);
        this.pc.onconnectionstatechange = () => this.syncState();
        this.pc.oniceconnectionstatechange = () => this.syncState();
    }

    get state(): PeerState {
        return this._state;
    }

    /** The underlying connection — for getStats() and for the rig's assertions. Not for negotiation. */
    get connection(): RTCPeerConnection {
        return this.pc;
    }

    addStream(stream: MediaStream): void {
        for (const track of stream.getTracks()) {
            const sender = this.pc.addTrack(track, stream);
            this.senders.set(track.kind, sender);
            if (track.kind === 'video') this.preferCodec(sender);
        }
    }

    /** Safari: H.264 first (platform.ts says why). Where setCodecPreferences is missing the browser's own order stands. */
    private preferCodec(sender: RTCRtpSender): void {
        const codec = platform.preferredVideoCodec();
        if (!codec) return;

        try {
            const transceiver = this.pc.getTransceivers?.().find((t) => t.sender === sender);
            const capabilities = typeof RTCRtpReceiver !== 'undefined' ? RTCRtpReceiver.getCapabilities?.('video') : null;
            if (!transceiver?.setCodecPreferences || !capabilities) return;

            const wanted = `video/${codec.toLowerCase()}`;
            const first = capabilities.codecs.filter((c) => c.mimeType.toLowerCase() === wanted);
            if (first.length === 0) return;

            transceiver.setCodecPreferences([...first, ...capabilities.codecs.filter((c) => !first.includes(c))]);
        } catch {
            // A preference, not a requirement: a browser that throws here still negotiates.
        }
    }

    /**
     * A data channel for one file. SCTP is already negotiated — Beam opens a
     * control channel before the first offer — so this one opens in-band, with
     * no renegotiation and nothing to wait for.
     */
    createDataChannel(label: string): RTCDataChannel {
        return this.pc.createDataChannel(label, { ordered: true });
    }

    /** Swap the outgoing video track (camera ↔ screen) without renegotiating. */
    async replaceVideoTrack(track: MediaStreamTrack | null): Promise<void> {
        const sender = this.senders.get('video');
        if (!sender) throw new Error('Beam: no video sender to replace — was a stream added?');
        await sender.replaceTrack(track);
    }

    /** Whether a sender for this kind exists — false on an audio-only join until the camera is added. */
    hasSender(kind: 'audio' | 'video'): boolean {
        return this.senders.has(kind);
    }

    /**
     * Add a track after the connection is up — the camera on a call that
     * started audio-only. A new sender raises negotiationneeded; Call renegotiates.
     */
    addTrack(track: MediaStreamTrack, stream: MediaStream): void {
        const existing = this.senders.get(track.kind);
        if (existing) {
            void existing.replaceTrack(track);
            return;
        }
        this.senders.set(track.kind, this.pc.addTrack(track, stream));
    }

    /** A second track of a kind already sent — the screen beside the camera. Returns the sender, for removeExtraTrack(). */
    addExtraTrack(track: MediaStreamTrack, stream: MediaStream): RTCRtpSender {
        return this.pc.addTrack(track, stream);
    }

    removeExtraTrack(sender: RTCRtpSender): void {
        try {
            this.pc.removeTrack(sender);
        } catch {
            // already gone with a closed connection
        }
    }

    async createOffer(options?: RTCOfferOptions): Promise<RTCSessionDescriptionInit> {
        this.makingOffer = true;
        try {
            const offer = await this.pc.createOffer(options);
            await this.pc.setLocalDescription(offer);
            if (this._state === 'new') this.setState('connecting');
            // localDescription carries what was actually set; return that.
            return this.describe(this.pc.localDescription ?? offer);
        } finally {
            this.makingOffer = false;
        }
    }

    /**
     * Accept a remote offer and answer it. Returns null when the offer was
     * ignored under glare (impolite side, offer collision) — the caller then
     * expects the remote to accept *our* offer instead.
     */
    async handleOffer(sdp: RTCSessionDescriptionInit): Promise<RTCSessionDescriptionInit | null> {
        const collision = this.makingOffer || this.pc.signalingState !== 'stable';
        this.ignoreOffer = !this.polite && collision;
        if (this.ignoreOffer) return null;

        // The polite side under collision: setRemoteDescription(offer) implicitly
        // rolls back its own pending offer (WebRTC 1.0 perfect negotiation).
        await this.pc.setRemoteDescription(sdp);
        await this.flushCandidates();
        const answer = await this.pc.createAnswer();
        await this.pc.setLocalDescription(answer);
        this.setState('connecting');

        return this.describe(this.pc.localDescription ?? answer);
    }

    async handleAnswer(sdp: RTCSessionDescriptionInit): Promise<void> {
        // An answer we did not ask for (a duplicate, or one for an offer that
        // was rolled back) would throw "Called in wrong state: stable". Ignore it.
        if (this.pc.signalingState !== 'have-local-offer') return;
        await this.pc.setRemoteDescription(sdp);
        // The collision, if there was one, is over: candidate errors are worth
        // hearing again rather than being swallowed for the rest of the call.
        this.ignoreOffer = false;
        await this.flushCandidates();
    }

    /** Queue until the remote description exists; candidates before it are an error in every browser. */
    async addIceCandidate(candidate: RTCIceCandidateInit): Promise<void> {
        if (!this.pc.remoteDescription) {
            this.pendingCandidates.push(candidate);
            return;
        }
        try {
            await this.pc.addIceCandidate(candidate);
        } catch (error) {
            // A candidate for an offer we ignored under glare is expected noise.
            if (!this.ignoreOffer) this.emit('error', error as Error);
        }
    }

    async addIceCandidates(candidates: RTCIceCandidateInit[]): Promise<void> {
        for (const candidate of candidates) await this.addIceCandidate(candidate);
    }

    /** ICE restart: a new offer with fresh candidates on the same connection. Phase 5 wires the trigger. */
    restartIce(): Promise<RTCSessionDescriptionInit> {
        this.setState('reconnecting');
        return this.createOffer({ iceRestart: true });
    }

    close(): void {
        if (this._state === 'closed') return;
        this.pc.onicecandidate = null;
        this.pc.ontrack = null;
        this.pc.onnegotiationneeded = null;
        this.pc.onconnectionstatechange = null;
        this.pc.oniceconnectionstatechange = null;
        this.pc.close();
        this.setState('closed');
        this.removeAll();
    }

    private async flushCandidates(): Promise<void> {
        const queued = this.pendingCandidates;
        this.pendingCandidates = [];
        for (const candidate of queued) await this.addIceCandidate(candidate);
    }

    private describe(d: RTCSessionDescriptionInit | RTCSessionDescription): RTCSessionDescriptionInit {
        return { type: d.type as RTCSdpType, sdp: d.sdp ?? '' };
    }

    /**
     * connectionState is the modern, aggregate signal; iceConnectionState is
     * older and still the one that reliably reports `disconnected` first on a
     * network drop. Read both, prefer the worse.
     */
    private syncState(): void {
        if (this._state === 'closed') return;
        const c = this.pc.connectionState;
        const i = this.pc.iceConnectionState;

        if (c === 'failed' || i === 'failed') return this.setState('failed');
        if (c === 'closed') return this.setState('closed');
        if (c === 'disconnected' || i === 'disconnected') return this.setState('reconnecting');
        if (c === 'connected' || i === 'connected' || i === 'completed') return this.setState('connected');
        if (c === 'connecting' || i === 'checking') return this.setState('connecting');
    }

    private setState(next: PeerState): void {
        if (this._state === next) return;
        this._state = next;
        this.emit('state', next);
    }
}
