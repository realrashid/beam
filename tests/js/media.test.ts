import { describe, expect, it, vi } from 'vitest';
import { BeamError } from '../../resources/js/errors';
import { Media } from '../../resources/js/media';

const track = (kind: 'audio' | 'video', id: string = kind) => ({ kind, id, stop: vi.fn(), enabled: true }) as unknown as MediaStreamTrack;
const stream = (...tracks: MediaStreamTrack[]) => ({
    getTracks: () => tracks, getVideoTracks: () => tracks.filter((t) => t.kind === 'video'), getAudioTracks: () => tracks.filter((t) => t.kind === 'audio'),
}) as unknown as MediaStream;
const domErr = (name: string) => Object.assign(new Error(name), { name });
const memStorage = () => { const m = new Map<string, string>(); return { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => void m.set(k, v) }; };

function fakeDevices(over: Partial<MediaDevices> = {}) {
    return {
        getUserMedia: vi.fn(async () => stream(track('audio'), track('video'))),
        getDisplayMedia: vi.fn(async () => stream(track('video', 'screen'))),
        enumerateDevices: vi.fn(async () => [
            { deviceId: 'cam1', label: 'Front', kind: 'videoinput' }, { deviceId: 'cam2', label: 'Back', kind: 'videoinput' },
            { deviceId: 'mic1', label: 'Mic', kind: 'audioinput' }, { deviceId: 'spk1', label: 'Speaker', kind: 'audiooutput' },
            { deviceId: 'x', label: 'weird', kind: 'somethingelse' },
        ]),
        addEventListener: vi.fn(),
        ...over,
    } as unknown as MediaDevices;
}

describe('Media', () => {
    it('acquires a stream', async () => {
        const m = new Media({ mediaDevices: fakeDevices(), storage: null });
        const s = await m.acquire({ audio: true, video: true });
        expect(s.getTracks()).toHaveLength(2);
    });

    it('maps a refused permission to permission-denied', async () => {
        const m = new Media({ mediaDevices: fakeDevices({ getUserMedia: vi.fn(async () => { throw domErr('NotAllowedError'); }) }), storage: null });
        await expect(m.acquire({ video: true })).rejects.toMatchObject({ reason: 'permission-denied' });
    });

    it('maps a missing device to no-device, and anything else to media-failed', async () => {
        await expect(new Media({ mediaDevices: fakeDevices({ getUserMedia: vi.fn(async () => { throw domErr('NotFoundError'); }) }), storage: null }).acquire({ video: true }))
            .rejects.toMatchObject({ reason: 'no-device' });
        await expect(new Media({ mediaDevices: fakeDevices({ getUserMedia: vi.fn(async () => { throw domErr('AbortError'); }) }), storage: null }).acquire({ video: true }))
            .rejects.toMatchObject({ reason: 'media-failed' });
    });

    it('says why when there is no mediaDevices at all (an insecure origin)', async () => {
        const m = new Media({ mediaDevices: undefined as unknown as MediaDevices, storage: null });
        await expect(m.acquire({ video: true })).rejects.toThrow(/HTTPS/);
    });

    it('lists only inputs and outputs', async () => {
        const list = await new Media({ mediaDevices: fakeDevices(), storage: null }).devices();
        expect(list.map((d) => d.deviceId)).toEqual(['cam1', 'cam2', 'mic1', 'spk1']);
    });

    it('remembers and prefers the last device, as ideal not exact', async () => {
        const devices = fakeDevices();
        const m = new Media({ mediaDevices: devices, storage: memStorage() });
        m.remember('video', 'cam2');
        expect(m.remembered('video')).toBe('cam2');

        await m.acquire({ audio: true, video: true });
        expect((devices.getUserMedia as ReturnType<typeof vi.fn>).mock.calls[0]?.[0]).toEqual({ audio: true, video: { deviceId: { ideal: 'cam2' } } });
    });

    it('retries without the remembered device when it is gone or busy', async () => {
        const getUserMedia = vi.fn().mockRejectedValueOnce(domErr('NotReadableError')).mockResolvedValueOnce(stream(track('video')));
        const m = new Media({ mediaDevices: fakeDevices({ getUserMedia }), storage: memStorage() });
        m.remember('video', 'gone');
        await expect(m.acquire({ video: true })).resolves.toBeTruthy();
        expect(getUserMedia).toHaveBeenCalledTimes(2);
        expect(getUserMedia.mock.calls[1]?.[0]).toEqual({ video: true });
    });

    it('switchTo opens exactly that device, remembers it, and returns the track', async () => {
        const devices = fakeDevices({ getUserMedia: vi.fn(async () => stream(track('video', 'cam2'))) });
        const m = new Media({ mediaDevices: devices, storage: memStorage() });
        const t = await m.switchTo('videoinput', 'cam2');
        expect(t.id).toBe('cam2');
        expect((devices.getUserMedia as ReturnType<typeof vi.fn>).mock.calls[0]?.[0]).toEqual({ video: { deviceId: { exact: 'cam2' } }, audio: false });
        expect(m.remembered('video')).toBe('cam2');
    });

    it('screen() maps a missing API to screen-unavailable and a refusal to permission-denied', async () => {
        await expect(new Media({ mediaDevices: fakeDevices({ getDisplayMedia: undefined }), storage: null }).screen()).rejects.toMatchObject({ reason: 'screen-unavailable' });
        await expect(new Media({ mediaDevices: fakeDevices({ getDisplayMedia: vi.fn(async () => { throw domErr('NotAllowedError'); }) }), storage: null }).screen()).rejects.toMatchObject({ reason: 'permission-denied' });
        await expect(new Media({ mediaDevices: fakeDevices(), storage: null }).screen()).resolves.toBeTruthy();
    });

    it('survives a storage that throws', () => {
        const m = new Media({ mediaDevices: fakeDevices(), storage: { getItem: () => { throw new Error('quota'); }, setItem: () => { throw new Error('quota'); } } });
        expect(() => m.remember('video', 'x')).not.toThrow();
        expect(m.remembered('video')).toBeNull();
    });

    it('is a BeamError every time', async () => {
        const m = new Media({ mediaDevices: fakeDevices({ getUserMedia: vi.fn(async () => { throw domErr('NotAllowedError'); }) }), storage: null });
        await expect(m.acquire({ video: true })).rejects.toBeInstanceOf(BeamError);
    });
});
