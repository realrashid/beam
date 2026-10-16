import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Call } from '../../resources/js/call';
import { Emitter } from '../../resources/js/events';
import type { Claims } from '../../resources/js/token';

const claims = (over: Partial<Claims> = {}): Claims => ({
    v: 1, callId: 'call_1', channel: 'beam.call.call_1', role: 'caller', sub: 1, ice: [], exp: 9e9,
    meta: { heartbeat: 30, timeout: 2, timeoutDriver: 'browser' }, ...over,
});
const fakePeer = () => Object.assign(new Emitter<any>(), { close: vi.fn(), addStream: vi.fn(), state: 'new' });
const fakeSignaling = () => Object.assign(new Emitter<any>(), { leave: vi.fn(async () => {}), send: vi.fn(async () => {}) });
const fakeStream = () => ({ getTracks: () => [{ stop: vi.fn() }], getVideoTracks: () => [], getAudioTracks: () => [] }) as unknown as MediaStream;

function make(over: Partial<Claims> = {}) {
    const peer = fakePeer(), signaling = fakeSignaling(), fetch = vi.fn(async () => new Response(null, { status: 204 }));
    const call = new Call({ claims: claims(over), peer: peer as any, signaling: signaling as any, local: fakeStream(), http: { prefix: '/beam', csrf: 'c', fetch } });
    return { call, peer, signaling, fetch };
}
const posted = (fetch: ReturnType<typeof vi.fn>) => fetch.mock.calls.map((c) => (c as unknown as [string, RequestInit])[0]);

describe('Call', () => {
    beforeEach(() => vi.useFakeTimers());

    it('starts ringing for the callee and connecting for the caller', () => {
        expect(make({ role: 'callee' }).call.state).toBe('ringing');
        expect(make({ role: 'caller' }).call.state).toBe('connecting');
    });

    it('follows the peer: connected, reconnecting, and a failure is one more attempt before giving up', async () => {
        const { call, peer } = make();
        (peer as any).restartIce = vi.fn(async () => ({ type: 'offer', sdp: 'r' }));
        (peer as any).polite = false;
        const states: string[] = [];
        call.on('state', (s) => states.push(s));
        const onEnded = vi.fn();
        call.on('ended', onEnded);

        peer.emit('state', 'connected');
        peer.emit('state', 'reconnecting');
        peer.emit('state', 'connected');
        peer.emit('state', 'failed');
        expect(states).toEqual(['connected', 'reconnecting', 'connected', 'reconnecting']);

        await vi.advanceTimersByTimeAsync(30_100);   // default reconnectTimeout
        expect(onEnded).toHaveBeenCalledWith(expect.objectContaining({ reason: 'connection-lost' }));
        expect(states.at(-1)).toBe('ended');
    });

    it('heartbeats on start, on every state change, and on the interval', async () => {
        const { call, peer, fetch } = make();
        await vi.advanceTimersByTimeAsync(0);
        expect(posted(fetch)).toEqual(['/beam/calls/call_1/heartbeat']);

        peer.emit('state', 'connected');
        await vi.advanceTimersByTimeAsync(0);
        expect(JSON.parse((fetch.mock.calls[1] as unknown as [string, RequestInit])[1].body as string)).toEqual({ state: 'connected' });

        await vi.advanceTimersByTimeAsync(30_000);
        expect(posted(fetch).filter((u) => u.endsWith('/heartbeat'))).toHaveLength(3);
        void call;
    });

    it('hangUp tells the peer, the channel and the server, and emits ended once', async () => {
        const { call, peer, signaling, fetch } = make();
        const onEnded = vi.fn();
        call.on('ended', onEnded);

        await call.hangUp();
        await call.hangUp();

        expect(signaling.leave).toHaveBeenCalledTimes(1);
        expect(peer.close).toHaveBeenCalledTimes(1);
        expect(posted(fetch)).toContain('/beam/calls/call_1/end');
        expect(onEnded).toHaveBeenCalledTimes(1);
        expect(call.state).toBe('ended');
    });

    it('a bye from the other side ends the call without telling the server again', async () => {
        const { call, signaling, fetch } = make();
        const onEnded = vi.fn();
        call.on('ended', onEnded);

        signaling.emit('message', { type: 'bye', reason: 'hangup', from: '2' });
        await vi.runOnlyPendingTimersAsync();

        expect(onEnded).toHaveBeenCalledWith(expect.objectContaining({ reason: 'hangup' }));
        expect(posted(fetch)).not.toContain('/beam/calls/call_1/end');
    });

    it('a server-side decline ends the call with reason declined', async () => {
        const { call, signaling } = make();
        const onEnded = vi.fn();
        call.on('ended', onEnded);

        signaling.emit('control', { action: 'decline', reason: 'Agent unavailable', by: null });
        await vi.runOnlyPendingTimersAsync();

        expect(onEnded).toHaveBeenCalledWith(expect.objectContaining({ reason: 'declined' }));
    });

    it('browser timeout driver: the caller reports a timeout if still connecting when the ring runs out', async () => {
        const { call, fetch } = make({ role: 'caller', meta: { timeout: 2, timeoutDriver: 'browser', heartbeat: 30 } });
        const onEnded = vi.fn();
        call.on('ended', onEnded);

        await vi.advanceTimersByTimeAsync(2_100);

        expect(posted(fetch)).toContain('/beam/calls/call_1/timeout');
        expect(onEnded).toHaveBeenCalledWith(expect.objectContaining({ reason: 'timeout' }));
    });

    it('browser timeout driver: does nothing once connected, and nothing at all for the queue driver', async () => {
        const a = make({ role: 'caller', meta: { timeout: 2, timeoutDriver: 'browser', heartbeat: 30 } });
        a.peer.emit('state', 'connected');
        await vi.advanceTimersByTimeAsync(2_100);
        expect(posted(a.fetch)).not.toContain('/beam/calls/call_1/timeout');

        const b = make({ role: 'caller', meta: { timeout: 2, timeoutDriver: 'queue', heartbeat: 30 } });
        await vi.advanceTimersByTimeAsync(2_100);
        expect(posted(b.fetch)).not.toContain('/beam/calls/call_1/timeout');
        expect(b.call.state).toBe('connecting');
    });

    it('duration counts from connected, display only', () => {
        const { call, peer } = make();
        expect(call.duration).toBe(0);
        peer.emit('state', 'connected');
        vi.advanceTimersByTime(5_000);
        expect(call.duration).toBe(5);
    });
});

describe('Call controls', () => {
    const t = (kind: 'audio' | 'video', id: string = kind) => ({ kind, id, enabled: true, stop: vi.fn(), addEventListener: vi.fn(), getSettings: () => ({ deviceId: id }) }) as unknown as MediaStreamTrack;
    function rig() {
        const audio = t('audio'), video = t('video', 'cam1');
        const tracks = [audio, video];
        const local = { getTracks: () => tracks, getAudioTracks: () => tracks.filter((x) => x.kind === 'audio'), getVideoTracks: () => tracks.filter((x) => x.kind === 'video'), addTrack: vi.fn((x) => tracks.push(x)), removeTrack: vi.fn((x) => tracks.splice(tracks.indexOf(x), 1)) } as unknown as MediaStream;
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), replaceVideoTrack: vi.fn(async () => {}), hasSender: () => true, addExtraTrack: vi.fn(), removeExtraTrack: vi.fn() });
        const signaling = Object.assign(new Emitter<any>(), { leave: vi.fn(async () => {}) });
        const screenTrack = t('video', 'screen');
        const media = {
            devices: vi.fn(async () => [{ deviceId: 'cam1', label: 'a', kind: 'videoinput' }, { deviceId: 'cam2', label: 'b', kind: 'videoinput' }]),
            switchTo: vi.fn(async (_k: string, id: string) => t('video', id)),
            screen: vi.fn(async () => ({ getVideoTracks: () => [screenTrack], getTracks: () => [screenTrack] })),
            stop: vi.fn(),
        };
        const call = new Call({ claims: claims(), peer: peer as any, signaling: signaling as any, local, media: media as any, http: { prefix: '/beam', fetch: vi.fn(async () => new Response(null, { status: 204 })) } });
        return { call, audio, video, peer, media, screenTrack, tracks };
    }

    it('mute disables the audio track without stopping it; unmute re-enables; toggle reports', () => {
        const { call, audio } = rig();
        const onMuted = vi.fn();
        call.on('muted', onMuted);
        call.mute();
        expect(audio.enabled).toBe(false);
        expect(audio.stop).not.toHaveBeenCalled();
        expect(call.isMuted).toBe(true);
        call.unmute();
        expect(audio.enabled).toBe(true);
        expect(call.toggleMute()).toBe(true);
        expect(onMuted.mock.calls.map((c) => c[0])).toEqual([true, false, true]);
    });

    it('camera off/on toggles the video track', () => {
        const { call, video } = rig();
        call.cameraOff();
        expect(video.enabled).toBe(false);
        expect(call.isCameraOn).toBe(false);
        call.cameraOn();
        expect(call.isCameraOn).toBe(true);
    });

    it('switchCamera with no argument moves to the next camera, swaps it on the peer, keeps the on/off state', async () => {
        const { call, peer, media, video } = rig();
        call.cameraOff();
        await call.switchCamera();
        expect(media.switchTo).toHaveBeenCalledWith('videoinput', 'cam2');
        expect(video.stop).toHaveBeenCalled();
        expect(peer.replaceVideoTrack).toHaveBeenCalledWith(expect.objectContaining({ id: 'cam2', enabled: false }));
        expect(call.isCameraOn).toBe(false);
    });

    it('shareScreen swaps the screen in, stopSharing restores the camera, and the browser\'s own stop restores it too', async () => {
        const { call, peer, media, screenTrack } = rig();
        const onSharing = vi.fn();
        call.on('sharing', onSharing);

        await call.shareScreen();
        expect(call.isSharing).toBe(true);
        expect(peer.replaceVideoTrack).toHaveBeenLastCalledWith(screenTrack);

        await call.stopSharing();
        expect(call.isSharing).toBe(false);
        expect(peer.replaceVideoTrack).toHaveBeenLastCalledWith(expect.objectContaining({ id: 'cam1' }));
        expect(media.stop).toHaveBeenCalledTimes(1);
        expect(onSharing.mock.calls.map((c) => c[0])).toEqual([true, false]);

        await call.shareScreen();
        const ended = (screenTrack.addEventListener as ReturnType<typeof vi.fn>).mock.calls.find((c) => c[0] === 'ended')?.[1];
        ended();
        await vi.waitFor(() => expect(call.isSharing).toBe(false));
    });

    it('switching the camera while sharing does not interrupt the share', async () => {
        const { call, peer } = rig();
        await call.shareScreen();
        await call.switchCamera('cam2');
        expect(peer.replaceVideoTrack).toHaveBeenCalledTimes(1);   // only the screen
        await call.stopSharing();
        expect(peer.replaceVideoTrack).toHaveBeenLastCalledWith(expect.objectContaining({ id: 'cam2' }));
    });

    it('setSpeaker needs an attached remote element and a browser that supports it', async () => {
        const { call } = rig();
        await expect(call.setSpeaker('spk1')).rejects.toMatchObject({ reason: 'media-failed' });
        const el = document.createElement('video') as HTMLVideoElement & { setSinkId?: (id: string) => Promise<void> };
        el.play = vi.fn(async () => {});
        call.attach({ remote: el });
        await expect(call.setSpeaker('spk1')).rejects.toThrow(/cannot choose/);
        el.setSinkId = vi.fn(async () => {});
        await call.setSpeaker('spk1');
        expect(el.setSinkId).toHaveBeenCalledWith('spk1');
    });
});

describe('Call resilience', () => {
    beforeEach(() => vi.useFakeTimers());
    const claimsWith = (over: Record<string, unknown>) => claims({ meta: { heartbeat: 30, timeoutDriver: 'queue', reconnectTimeout: 5, ...over } });
    function rig(role: 'caller' | 'callee' = 'caller') {
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: role !== 'caller', restartIce: vi.fn(async () => ({ type: 'offer', sdp: 'restart' })), connection: { getStats: vi.fn(async () => new Map()) } });
        const signaling = Object.assign(new Emitter<any>(), { leave: vi.fn(async () => {}), send: vi.fn(async () => {}) });
        const call = new Call({ claims: claimsWith({}) as any, peer: peer as any, signaling: signaling as any, local: fakeStream(), http: { prefix: '/beam', fetch: vi.fn(async () => new Response(null, { status: 204 })) } });
        (call as any).claims.role = role;
        return { call, peer, signaling };
    }

    it('a dropped connection is reconnecting, and the impolite side restarts ICE with a fresh offer', async () => {
        const { call, peer, signaling } = rig('caller');
        peer.emit('state', 'connected');
        peer.emit('state', 'reconnecting');
        await vi.advanceTimersByTimeAsync(0);
        expect(call.state).toBe('reconnecting');
        expect(peer.restartIce).toHaveBeenCalledTimes(1);
        expect(signaling.send).toHaveBeenCalledWith({ type: 'offer', sdp: { type: 'offer', sdp: 'restart' } });
    });

    it('keeps offering a restart every few seconds while still reconnecting, and stops once connected', async () => {
        const { call, peer } = rig('caller');
        (call as any).claims.meta.reconnectTimeout = 30;   // this rig gives up at 5 s; here the give-up clock must outlast two retries
        peer.emit('state', 'connected');
        peer.emit('state', 'reconnecting');
        await vi.advanceTimersByTimeAsync(0);
        expect(peer.restartIce).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(5_000);   // the relay was still down for the first one
        expect(peer.restartIce).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(5_000);
        expect(peer.restartIce).toHaveBeenCalledTimes(3);

        peer.emit('state', 'connected');
        await vi.advanceTimersByTimeAsync(15_000);
        expect(peer.restartIce).toHaveBeenCalledTimes(3);   // no more once the pair is back
        expect(call.state).toBe('connected');
    });

    it('the polite side waits for the restart offer rather than sending its own', async () => {
        const { call, peer } = rig('callee');
        peer.emit('state', 'connected');
        peer.emit('state', 'reconnecting');
        await vi.advanceTimersByTimeAsync(0);
        expect(call.state).toBe('reconnecting');
        expect(peer.restartIce).not.toHaveBeenCalled();
    });

    it('recovers to connected and cancels the give-up clock', async () => {
        const { call, peer } = rig();
        const onEnded = vi.fn();
        call.on('ended', onEnded);
        peer.emit('state', 'connected');
        peer.emit('state', 'reconnecting');
        peer.emit('state', 'connecting');   // the restart offer in flight: still reconnecting to the app
        expect(call.state).toBe('reconnecting');
        peer.emit('state', 'connected');
        expect(call.state).toBe('connected');
        await vi.advanceTimersByTimeAsync(6_000);
        expect(onEnded).not.toHaveBeenCalled();
    });

    it('gives up after reconnectTimeout with reason connection-lost', async () => {
        const { call, peer } = rig();
        const onEnded = vi.fn();
        call.on('ended', onEnded);
        peer.emit('state', 'connected');
        peer.emit('state', 'reconnecting');
        await vi.advanceTimersByTimeAsync(5_100);
        expect(onEnded).toHaveBeenCalledWith(expect.objectContaining({ reason: 'connection-lost' }));
        expect(call.state).toBe('ended');
    });

    it('emits quality from the selected pair and inbound video, only while connected and only when someone listens', async () => {
        const { call, peer } = rig();
        peer.connection.getStats = vi.fn(async () => new Map([
            ['p', { type: 'candidate-pair', nominated: true, currentRoundTripTime: 0.042 }],
            ['v', { type: 'inbound-rtp', kind: 'video', packetsLost: 5, packetsReceived: 95 }],
        ]));
        peer.emit('state', 'connected');
        await vi.advanceTimersByTimeAsync(2_100);
        expect(peer.connection.getStats).not.toHaveBeenCalled();   // nobody listening

        const onQuality = vi.fn();
        call.on('quality', onQuality);
        await vi.advanceTimersByTimeAsync(2_000);
        expect(onQuality).toHaveBeenCalledWith({ rtt: 42, packetLoss: 0.05 });
    });
});

describe('Call tick', () => {
    beforeEach(() => vi.useFakeTimers());
    it('ticks once a second with the seconds since connected, and stops at the end', async () => {
        const { call, peer } = make({ meta: { heartbeat: 30, timeoutDriver: 'queue' } });   // no browser ring timeout in this fixture
        const ticks: number[] = [];
        call.on('tick', ({ seconds }) => ticks.push(seconds));
        await vi.advanceTimersByTimeAsync(2_000);
        expect(ticks).toEqual([]);              // not connected yet
        peer.emit('state', 'connected');
        await vi.advanceTimersByTimeAsync(3_050);
        expect(ticks).toEqual([1, 2, 3]);
        await call.hangUp();
        await vi.advanceTimersByTimeAsync(3_000);
        expect(ticks).toEqual([1, 2, 3]);
    });
});

describe('Call upgrades', () => {
    beforeEach(() => vi.useFakeTimers());
    const videoTrack = () => ({ kind: 'video', enabled: true, stop: vi.fn(), getSettings: () => ({}) }) as unknown as MediaStreamTrack;
    const rig = (opts: { videoAtJoin: boolean; videoSender: boolean }) => {
        const tracks: MediaStreamTrack[] = opts.videoAtJoin ? [videoTrack()] : [];
        const local = { getTracks: () => tracks, getVideoTracks: () => tracks.filter((t) => t.kind === 'video'), getAudioTracks: () => [], addTrack: vi.fn((t: MediaStreamTrack) => tracks.push(t)), removeTrack: vi.fn() } as unknown as MediaStream;
        const peer = Object.assign(new Emitter<any>(), {
            close: vi.fn(), polite: false,
            hasSender: vi.fn((kind: string) => (kind === 'video' ? opts.videoSender : true)),
            addTrack: vi.fn(), addExtraTrack: vi.fn(() => ({ id: 'screen-sender' })), removeExtraTrack: vi.fn(), replaceVideoTrack: vi.fn(async () => {}),
        });
        const screenTrack = Object.assign(videoTrack(), { addEventListener: vi.fn() });
        const media = { acquire: vi.fn(async () => ({ getVideoTracks: () => [videoTrack()] })), screen: vi.fn(async () => ({ getVideoTracks: () => [screenTrack], getTracks: () => [screenTrack] })), stop: vi.fn(), devices: vi.fn(async () => []) };
        const call = new Call({ claims: claims(), peer: peer as any, signaling: fakeSignaling() as any, local, media: media as any, http: { prefix: '/beam', fetch: vi.fn(async () => new Response(null, { status: 204 })) } });
        return { call, peer, media, local };
    };

    it('enableVideo on an audio-only call opens the camera, adds it to the connection, and fires camera', async () => {
        const { call, peer, media, local } = rig({ videoAtJoin: false, videoSender: false });
        const onCamera = vi.fn();
        call.on('camera', onCamera);
        expect(call.isCameraOn).toBe(false);

        await call.enableVideo();

        expect(media.acquire).toHaveBeenCalledWith({ video: true, audio: false });
        expect((local as any).addTrack).toHaveBeenCalledTimes(1);
        expect(peer.addTrack).toHaveBeenCalledTimes(1);
        expect(onCamera).toHaveBeenCalledWith(true);
        expect(call.isCameraOn).toBe(true);
        await call.enableVideo();                        // a second call is a no-op re-enable
        expect(media.acquire).toHaveBeenCalledTimes(1);
    });

    it('cameraOn() on an audio-only call is the upgrade; on a video call it re-enables the track', async () => {
        const upgrade = rig({ videoAtJoin: false, videoSender: false });
        upgrade.call.cameraOn();
        await vi.advanceTimersByTimeAsync(0);
        expect(upgrade.media.acquire).toHaveBeenCalledTimes(1);

        const plain = rig({ videoAtJoin: true, videoSender: true });
        plain.call.cameraOff();
        plain.call.cameraOn();
        expect(plain.media.acquire).not.toHaveBeenCalled();
        expect(plain.call.isCameraOn).toBe(true);
    });

    it('shareScreen alongside adds a second sender, stopSharing removes it, and the camera is untouched', async () => {
        const { call, peer } = rig({ videoAtJoin: true, videoSender: true });
        await call.shareScreen({ alongside: true });
        expect(peer.addExtraTrack).toHaveBeenCalledTimes(1);
        expect(peer.replaceVideoTrack).not.toHaveBeenCalled();
        expect(call.isSharing).toBe(true);
        await call.stopSharing();
        expect(peer.removeExtraTrack).toHaveBeenCalledWith({ id: 'screen-sender' });
        expect(peer.replaceVideoTrack).not.toHaveBeenCalled();
        expect(call.isSharing).toBe(false);
    });

    it('shareScreen on an audio-only call has no video sender to replace, so the screen is added', async () => {
        const { call, peer } = rig({ videoAtJoin: false, videoSender: false });
        await call.shareScreen();
        expect(peer.addExtraTrack).toHaveBeenCalledTimes(1);
        expect(peer.replaceVideoTrack).not.toHaveBeenCalled();
    });

    it('a second remote stream is announced through remotetrack and does not replace the main remote stream', () => {
        const { call, peer } = rig({ videoAtJoin: true, videoSender: true });
        const el = document.createElement('video');
        el.play = vi.fn(async () => {});
        call.attach({ remote: el });
        const seen: string[] = [];
        call.on('remotetrack', (_t, stream) => seen.push(stream.id));
        const camera = { id: 'cam-stream', getTracks: () => [{ kind: 'audio' }, { kind: 'video' }], addTrack: vi.fn() } as unknown as MediaStream;
        const screen = { id: 'screen-stream', getTracks: () => [{ kind: 'video' }] } as unknown as MediaStream;
        peer.emit('track', { kind: 'video' }, camera);
        peer.emit('track', { kind: 'video' }, screen);
        expect(seen).toEqual(['cam-stream', 'screen-stream']);
        expect(el.srcObject).toBe(camera);
        expect((camera as any).addTrack).not.toHaveBeenCalled();   // the main stream already has video: the screen stays its own stream

        // A browser that hands audio and video over on separate streams: the missing kind joins the main stream.
        const audioOnly = { id: 'a-stream', getTracks: () => [{ kind: 'audio' }], addTrack: vi.fn() } as unknown as MediaStream;
        const videoTrackSeparate = { kind: 'video' };
        const { call: call2, peer: peer2 } = rig({ videoAtJoin: true, videoSender: true });
        peer2.emit('track', { kind: 'audio' }, audioOnly);
        peer2.emit('track', videoTrackSeparate, { id: 'v-stream', getTracks: () => [videoTrackSeparate] } as unknown as MediaStream);
        expect((audioOnly as any).addTrack).toHaveBeenCalledWith(videoTrackSeparate);
        expect(call2.remoteStream).toBe(audioOnly);
    });
});
