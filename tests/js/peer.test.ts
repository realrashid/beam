import { describe, expect, it, vi } from 'vitest';
import { Peer } from '../../resources/js/peer';

/**
 * jsdom has no RTCPeerConnection. This fake records calls and lets a test
 * drive state transitions; behaviour that needs real ICE is proven in the rig.
 */
function fakePc() {
    const pc: any = {
        signalingState: 'stable',
        connectionState: 'new',
        iceConnectionState: 'new',
        remoteDescription: null as RTCSessionDescriptionInit | null,
        localDescription: null as RTCSessionDescriptionInit | null,
        addTrack: vi.fn((track: any) => ({ track, replaceTrack: vi.fn(async () => {}) })),
        createOffer: vi.fn(async (o?: RTCOfferOptions) => ({ type: 'offer', sdp: o?.iceRestart ? 'restart' : 'offer-sdp' })),
        createAnswer: vi.fn(async () => ({ type: 'answer', sdp: 'answer-sdp' })),
        setLocalDescription: vi.fn(async (d: any) => { pc.localDescription = d; pc.signalingState = d.type === 'offer' ? 'have-local-offer' : 'stable'; }),
        setRemoteDescription: vi.fn(async (d: any) => { pc.remoteDescription = d; pc.signalingState = d.type === 'offer' ? 'have-remote-offer' : 'stable'; }),
        addIceCandidate: vi.fn(async () => {}),
        close: vi.fn(),
        onicecandidate: null, ontrack: null, onnegotiationneeded: null, onconnectionstatechange: null, oniceconnectionstatechange: null,
    };
    return pc as RTCPeerConnection & typeof pc;
}
const make = (polite: boolean) => { const pc = fakePc(); return { pc, peer: new Peer({ iceServers: [] }, { polite, factory: () => pc }) }; };

describe('Peer', () => {
    it('createOffer sets the local description and moves to connecting', async () => {
        const { pc, peer } = make(false);
        const states: string[] = [];
        peer.on('state', (s) => states.push(s));
        const offer = await peer.createOffer();
        expect(offer).toEqual({ type: 'offer', sdp: 'offer-sdp' });
        expect(pc.setLocalDescription).toHaveBeenCalledTimes(1);
        expect(states).toEqual(['connecting']);
    });

    it('handleOffer answers and sets both descriptions', async () => {
        const { pc, peer } = make(true);
        const answer = await peer.handleOffer({ type: 'offer', sdp: 'x' });
        expect(answer).toEqual({ type: 'answer', sdp: 'answer-sdp' });
        expect(pc.setRemoteDescription).toHaveBeenCalledWith({ type: 'offer', sdp: 'x' });
        expect(pc.setLocalDescription).toHaveBeenCalledTimes(1);
    });

    it('ignores an answer it did not ask for instead of throwing', async () => {
        const { pc, peer } = make(false);
        pc.signalingState = 'stable';
        await expect(peer.handleAnswer({ type: 'answer', sdp: 'stray' })).resolves.toBeUndefined();
        expect(pc.setRemoteDescription).not.toHaveBeenCalled();
    });

    it('queues candidates that arrive before the remote description and flushes them after', async () => {
        const { pc, peer } = make(true);
        await peer.addIceCandidate({ candidate: 'early-1', sdpMid: '0' });
        await peer.addIceCandidate({ candidate: 'early-2', sdpMid: '0' });
        expect(pc.addIceCandidate).not.toHaveBeenCalled();

        pc.signalingState = 'have-local-offer';   // we offered; the answer is expected
        await peer.handleAnswer({ type: 'answer', sdp: 'y' });
        expect(pc.addIceCandidate).toHaveBeenCalledTimes(2);

        await peer.addIceCandidate({ candidate: 'late', sdpMid: '0' });
        expect(pc.addIceCandidate).toHaveBeenCalledTimes(3);
    });

    it('glare: the impolite side ignores an incoming offer while making its own', async () => {
        const { pc, peer } = make(false);
        pc.signalingState = 'have-local-offer';   // we already offered
        const result = await peer.handleOffer({ type: 'offer', sdp: 'theirs' });
        expect(result).toBeNull();
        expect(pc.setRemoteDescription).not.toHaveBeenCalled();
    });

    it('glare: the polite side accepts the incoming offer (implicit rollback) and answers', async () => {
        const { pc, peer } = make(true);
        pc.signalingState = 'have-local-offer';
        const result = await peer.handleOffer({ type: 'offer', sdp: 'theirs' });
        expect(result).toEqual({ type: 'answer', sdp: 'answer-sdp' });
        expect(pc.setRemoteDescription).toHaveBeenCalledWith({ type: 'offer', sdp: 'theirs' });
    });

    it('a candidate error is swallowed only after an ignored offer, otherwise emitted', async () => {
        const { pc, peer } = make(false);
        pc.remoteDescription = { type: 'answer', sdp: 'y' };
        pc.addIceCandidate.mockRejectedValueOnce(new Error('bad candidate'));
        const onError = vi.fn();
        peer.on('error', onError);
        await peer.addIceCandidate({ candidate: 'c', sdpMid: '0' });
        expect(onError).toHaveBeenCalledTimes(1);

        pc.signalingState = 'have-local-offer';
        await peer.handleOffer({ type: 'offer', sdp: 'ignored' });     // sets ignoreOffer
        pc.addIceCandidate.mockRejectedValueOnce(new Error('stray'));
        await peer.addIceCandidate({ candidate: 'stray', sdpMid: '0' });
        expect(onError).toHaveBeenCalledTimes(1);                      // not emitted again
    });

    it('maps connection states onto PeerState, preferring the worse of the two signals', () => {
        const { pc, peer } = make(true);
        const states: string[] = [];
        peer.on('state', (s) => states.push(s));

        pc.iceConnectionState = 'checking'; pc.oniceconnectionstatechange();
        pc.connectionState = 'connected'; pc.iceConnectionState = 'connected'; pc.onconnectionstatechange();
        pc.iceConnectionState = 'disconnected'; pc.oniceconnectionstatechange();   // a network drop
        pc.iceConnectionState = 'connected'; pc.oniceconnectionstatechange();
        pc.connectionState = 'failed'; pc.onconnectionstatechange();

        expect(states).toEqual(['connecting', 'connected', 'reconnecting', 'connected', 'failed']);
    });

    it('emits track with the stream, and icecandidate without the end-of-gathering null', () => {
        const { pc, peer } = make(true);
        const onTrack = vi.fn(), onCand = vi.fn();
        peer.on('track', onTrack);
        peer.on('icecandidate', onCand);
        const stream = { id: 's' } as any, track = { kind: 'video' } as any;
        pc.ontrack({ track, streams: [stream] });
        pc.onicecandidate({ candidate: { toJSON: () => ({ candidate: 'c', sdpMid: '0' }) } });
        pc.onicecandidate({ candidate: null });
        expect(onTrack).toHaveBeenCalledWith(track, stream);
        expect(onCand).toHaveBeenCalledTimes(1);
        expect(onCand).toHaveBeenCalledWith({ candidate: 'c', sdpMid: '0' });
    });

    it('replaceVideoTrack swaps on the video sender and refuses when none exists', async () => {
        const { pc, peer } = make(true);
        await expect(peer.replaceVideoTrack(null)).rejects.toThrow(/no video sender/);
        const stream = { getTracks: () => [{ kind: 'audio' }, { kind: 'video' }] } as any;
        peer.addStream(stream);
        expect(pc.addTrack).toHaveBeenCalledTimes(2);
        await peer.replaceVideoTrack({ kind: 'video', id: 'screen' } as any);
        const videoSender = pc.addTrack.mock.results[1]?.value;
        expect(videoSender.replaceTrack).toHaveBeenCalledWith({ kind: 'video', id: 'screen' });
    });

    it('close() closes the connection, reaches closed, and detaches every handler', () => {
        const { pc, peer } = make(true);
        peer.close();
        peer.close();
        expect(pc.close).toHaveBeenCalledTimes(1);
        expect(peer.state).toBe('closed');
        expect(pc.onicecandidate).toBeNull();
        expect(pc.ontrack).toBeNull();
    });
});

describe('Peer tracks added later', () => {
    it('addTrack creates a sender for a new kind and replaces the track for an existing one', () => {
        const { pc, peer } = make(false);
        const audio = { kind: 'audio' } as MediaStreamTrack;
        const stream = { getTracks: () => [audio] } as unknown as MediaStream;
        peer.addStream(stream);
        expect(peer.hasSender('audio')).toBe(true);
        expect(peer.hasSender('video')).toBe(false);

        const video = { kind: 'video' } as MediaStreamTrack;
        peer.addTrack(video, stream);
        expect(peer.hasSender('video')).toBe(true);
        expect(pc.addTrack).toHaveBeenCalledTimes(2);

        const video2 = { kind: 'video' } as MediaStreamTrack;
        peer.addTrack(video2, stream);
        expect(pc.addTrack).toHaveBeenCalledTimes(2);   // replaced on the existing sender, no new sender
    });

    it('addExtraTrack adds a second sender of the same kind, and removeExtraTrack takes it away', () => {
        const { pc, peer } = make(false);
        (pc as any).removeTrack = vi.fn();
        const stream = { getTracks: () => [{ kind: 'video' }] } as unknown as MediaStream;
        peer.addStream(stream);
        const sender = peer.addExtraTrack({ kind: 'video' } as MediaStreamTrack, stream);
        expect(pc.addTrack).toHaveBeenCalledTimes(2);
        peer.removeExtraTrack(sender);
        expect((pc as any).removeTrack).toHaveBeenCalledWith(sender);
    });
});
