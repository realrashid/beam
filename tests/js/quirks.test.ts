import { describe, expect, it, vi } from 'vitest';
import { Call } from '../../resources/js/call';
import { Emitter } from '../../resources/js/events';
import { Media } from '../../resources/js/media';
import { Peer } from '../../resources/js/peer';
import type { Claims } from '../../resources/js/token';

/**
 * The quirks in platform.ts, applied: each test pins the browser by editing
 * the mocked platform, then checks the one place the quirk changes behaviour.
 */
const fake = vi.hoisted(() => ({
    isSafari: false, isIOS: false, isAndroid: false, isFirefox: false,
    supportsDisplayMedia: () => true, supportsPush: () => true, supportsSetSinkId: () => true,
    preferredVideoCodec: (): 'H264' | 'VP8' | null => null, needsUserGestureForPlay: () => false,
}));
vi.mock('../../resources/js/platform', () => ({ platform: fake, detect: () => fake }));

const reset = () => Object.assign(fake, { isSafari: false, isIOS: false, isAndroid: false, isFirefox: false, preferredVideoCodec: () => null });

const claims = (over: Partial<Claims> = {}): Claims => ({
    v: 1, callId: 'call_1', channel: 'beam.call.call_1', role: 'caller', sub: 1, ice: [], exp: 9e9,
    meta: { heartbeat: 30, timeout: 30, reconnectTimeout: 30 }, ...over,
});
const videoTrack = (facing = 'user') => ({ kind: 'video', enabled: true, stop: vi.fn(), getSettings: () => ({ deviceId: 'cam1', facingMode: facing }) }) as unknown as MediaStreamTrack;
const stream = (...tracks: MediaStreamTrack[]) => ({
    getTracks: () => tracks, getVideoTracks: () => tracks.filter((t) => t.kind === 'video'), getAudioTracks: () => tracks.filter((t) => t.kind === 'audio'),
    addTrack: vi.fn(), removeTrack: vi.fn(),
}) as unknown as MediaStream;
const fakeSignaling = () => Object.assign(new Emitter<any>(), { leave: vi.fn(async () => {}), send: vi.fn(async () => {}) });
const fetch = vi.fn(async () => new Response(null, { status: 204 }));
const tick = () => new Promise((r) => setTimeout(r, 0));

describe('Safari codec preference (peer.ts)', () => {
    it('puts H.264 first when the platform asks for it, keeping every other codec', () => {
        fake.preferredVideoCodec = () => 'H264';
        const setCodecPreferences = vi.fn();
        let sender: any;
        const pc: any = {
            addTrack: vi.fn((track: any) => (sender = { track })),
            getTransceivers: () => [{ sender, setCodecPreferences }],
            onicecandidate: null, ontrack: null, onnegotiationneeded: null, onconnectionstatechange: null, oniceconnectionstatechange: null,
        };
        (globalThis as any).RTCRtpReceiver = { getCapabilities: () => ({ codecs: [{ mimeType: 'video/VP8' }, { mimeType: 'video/rtx' }, { mimeType: 'video/H264', sdpFmtpLine: 'a' }, { mimeType: 'video/H264', sdpFmtpLine: 'b' }] }) };

        new Peer({ iceServers: [] }, { polite: false, factory: () => pc }).addStream(stream(videoTrack()));

        expect(setCodecPreferences).toHaveBeenCalledTimes(1);
        expect(setCodecPreferences.mock.calls[0]![0].map((c: any) => c.mimeType)).toEqual(['video/H264', 'video/H264', 'video/VP8', 'video/rtx']);
        delete (globalThis as any).RTCRtpReceiver;
        reset();
    });

    it('leaves the order alone elsewhere, and survives a browser without setCodecPreferences', () => {
        const pc: any = { addTrack: vi.fn(() => ({})), getTransceivers: () => [{ sender: {}, setCodecPreferences: undefined }], onicecandidate: null, ontrack: null, onnegotiationneeded: null, onconnectionstatechange: null, oniceconnectionstatechange: null };
        expect(() => new Peer({ iceServers: [] }, { polite: false, factory: () => pc }).addStream(stream(videoTrack()))).not.toThrow();
        fake.preferredVideoCodec = () => 'H264';
        expect(() => new Peer({ iceServers: [] }, { polite: false, factory: () => pc }).addStream(stream(videoTrack()))).not.toThrow();
        reset();
    });
});

describe('autoplay (call.ts)', () => {
    it('a blocked play() is reported as autoplay-blocked and retried on the next tap', async () => {
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: false });
        const call = new Call({ claims: claims(), peer: peer as any, signaling: fakeSignaling() as any, local: stream(), http: { prefix: '/beam', fetch } });
        const errors: string[] = [];
        call.on('error', (e) => errors.push(e.reason));

        const el = document.createElement('video');
        let calls = 0;
        el.play = vi.fn(() => (++calls === 1 ? Promise.reject(Object.assign(new Error('blocked'), { name: 'NotAllowedError' })) : Promise.resolve()));
        peer.emit('track', videoTrack(), stream(videoTrack()));
        call.attach({ remote: el });
        await tick();
        expect(errors).toEqual(['autoplay-blocked']);

        document.dispatchEvent(new Event('click'));
        await tick();
        expect(el.play).toHaveBeenCalledTimes(2);
        expect(errors).toEqual(['autoplay-blocked']);   // the retry worked: no second error
        await call.hangUp();
    });

    it('any other play() failure is media-failed, once', async () => {
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: false });
        const call = new Call({ claims: claims(), peer: peer as any, signaling: fakeSignaling() as any, local: stream(), http: { prefix: '/beam', fetch } });
        const errors: string[] = [];
        call.on('error', (e) => errors.push(e.reason));
        const el = document.createElement('video');
        el.play = vi.fn(() => Promise.reject(Object.assign(new Error('boom'), { name: 'AbortError' })));
        peer.emit('track', videoTrack(), stream(videoTrack()));
        call.attach({ remote: el });
        await tick();
        document.dispatchEvent(new Event('click'));
        await tick();
        expect(errors).toEqual(['media-failed']);
        expect(el.play).toHaveBeenCalledTimes(1);
        await call.hangUp();
    });
});

describe('a tab coming back to the foreground (call.ts)', () => {
    const visible = (state: 'visible' | 'hidden') => Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });

    it('restarts ICE at once when the pair is dead — even on the polite side', async () => {
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: true, connection: { iceConnectionState: 'disconnected' }, restartIce: vi.fn(async () => ({ type: 'offer', sdp: 'restart' })) });
        const signaling = fakeSignaling();
        const call = new Call({ claims: claims(), peer: peer as any, signaling: signaling as any, local: stream(), http: { prefix: '/beam', fetch } });
        peer.emit('state', 'connected');

        visible('visible');
        document.dispatchEvent(new Event('visibilitychange'));
        await tick();

        expect(call.state).toBe('reconnecting');
        expect(peer.restartIce).toHaveBeenCalledTimes(1);
        expect(signaling.send).toHaveBeenCalledWith({ type: 'offer', sdp: { type: 'offer', sdp: 'restart' } });
        await call.hangUp();
    });

    it('does nothing when the pair is fine, when the tab is hidden, or after the call ended', async () => {
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: false, connection: { iceConnectionState: 'connected' }, restartIce: vi.fn(async () => ({ type: 'offer', sdp: 'restart' })) });
        const call = new Call({ claims: claims(), peer: peer as any, signaling: fakeSignaling() as any, local: stream(), http: { prefix: '/beam', fetch } });
        peer.emit('state', 'connected');

        visible('visible');
        document.dispatchEvent(new Event('visibilitychange'));
        peer.connection.iceConnectionState = 'disconnected';
        visible('hidden');
        document.dispatchEvent(new Event('visibilitychange'));
        await tick();
        expect(peer.restartIce).not.toHaveBeenCalled();
        expect(call.state).toBe('connected');

        await call.hangUp();
        visible('visible');
        document.dispatchEvent(new Event('visibilitychange'));
        await tick();
        expect(peer.restartIce).not.toHaveBeenCalled();
    });
});

describe('phones (media.ts, call.ts)', () => {
    const devices = () => ({
        getUserMedia: vi.fn(async (c: any) => stream(videoTrack(c?.video?.facingMode?.exact ?? 'user'))),
        getDisplayMedia: vi.fn(async () => stream(videoTrack())),
        enumerateDevices: vi.fn(async () => []),
        addEventListener: vi.fn(),
    }) as unknown as MediaDevices;

    it('Android and iOS refuse screen sharing by name, whatever getDisplayMedia says', async () => {
        fake.isAndroid = true;
        await expect(new Media({ mediaDevices: devices(), storage: null }).screen()).rejects.toMatchObject({ reason: 'screen-unavailable', message: 'Screen sharing is not available in Android browsers.' });
        reset();
        fake.isIOS = true;
        await expect(new Media({ mediaDevices: devices(), storage: null }).screen()).rejects.toMatchObject({ reason: 'screen-unavailable', message: 'Screen sharing is not available on iOS.' });
        reset();
        await expect(new Media({ mediaDevices: devices(), storage: null }).screen()).resolves.toBeTruthy();
    });

    it('switchFacing asks for the facing exactly, ignores the remembered camera, and remembers the new one', async () => {
        const api = devices();
        const storage = { getItem: vi.fn(() => 'old-cam'), setItem: vi.fn() };
        const track = await new Media({ mediaDevices: api, storage }).switchFacing('environment');
        expect((api.getUserMedia as any).mock.calls[0][0]).toEqual({ video: { facingMode: { exact: 'environment' } }, audio: false });
        expect(track.getSettings().facingMode).toBe('environment');
        expect(storage.setItem).toHaveBeenCalledWith('beam:device:video', 'cam1');
    });

    it('switchCamera with no id flips the facing on a phone, and walks the device list elsewhere', async () => {
        const make = () => {
            const media = { switchFacing: vi.fn(async (f: string) => videoTrack(f)), switchTo: vi.fn(async () => videoTrack()), devices: vi.fn(async () => [{ deviceId: 'cam1', kind: 'videoinput', label: '' }, { deviceId: 'cam2', kind: 'videoinput', label: '' }]) };
            const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: false, replaceVideoTrack: vi.fn(async () => {}) });
            const call = new Call({ claims: claims(), peer: peer as any, signaling: fakeSignaling() as any, local: stream(videoTrack('user')), media: media as any, http: { prefix: '/beam', fetch } });
            return { call, media, peer };
        };

        fake.isAndroid = true;
        const phone = make();
        await phone.call.switchCamera();
        expect(phone.media.switchFacing).toHaveBeenCalledWith('environment');
        expect(phone.media.switchTo).not.toHaveBeenCalled();
        await phone.call.switchCamera('cam2');   // an explicit id is honoured on a phone too
        expect(phone.media.switchTo).toHaveBeenCalledWith('videoinput', 'cam2');
        await phone.call.hangUp();
        reset();

        const desktop = make();
        await desktop.call.switchCamera();
        expect(desktop.media.switchTo).toHaveBeenCalledWith('videoinput', 'cam2');
        expect(desktop.media.switchFacing).not.toHaveBeenCalled();
        await desktop.call.hangUp();
    });

    it('setSpeaker rejects with unsupported where setSinkId is missing', async () => {
        const peer = Object.assign(new Emitter<any>(), { close: vi.fn(), polite: false });
        const call = new Call({ claims: claims(), peer: peer as any, signaling: fakeSignaling() as any, local: stream(), http: { prefix: '/beam', fetch } });
        const el = document.createElement('video') as HTMLVideoElement & { setSinkId?: (id: string) => Promise<void> };
        el.play = vi.fn(async () => {});
        delete (el as { setSinkId?: unknown }).setSinkId;
        call.attach({ remote: el });
        await expect(call.setSpeaker('spk1')).rejects.toMatchObject({ reason: 'unsupported' });
        await call.hangUp();
    });
});
