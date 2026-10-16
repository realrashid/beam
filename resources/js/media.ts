import { BeamError } from './errors';
import { Emitter } from './events';
import { platform } from './platform';

export type MediaKind = 'audio' | 'video';
export type DeviceKind = 'audioinput' | 'videoinput' | 'audiooutput';

export interface DeviceInfo {
    deviceId: string;
    label: string;
    kind: DeviceKind;
}

export interface MediaOptions {
    /** Injected for tests; the browser's otherwise. */
    mediaDevices?: MediaDevices;
    /** Injected for tests; localStorage otherwise. Null disables remembering. */
    storage?: Pick<Storage, 'getItem' | 'setItem'> | null;
}

const STORAGE_PREFIX = 'beam:device:';

/**
 * Cameras, microphones, speakers and screens — every getUserMedia edge that a
 * hand-rolled call gets wrong once: refusals as errors with a reason, devices
 * enumerated and switchable mid-call, the last choice remembered.
 */
export class Media extends Emitter<{ devicechange: [DeviceInfo[]]; error: [BeamError] }> {
    private readonly devicesApi: MediaDevices | undefined;
    private readonly storage: Pick<Storage, 'getItem' | 'setItem'> | null;

    constructor(options: MediaOptions = {}) {
        super();
        this.devicesApi = options.mediaDevices ?? (typeof navigator !== 'undefined' ? navigator.mediaDevices : undefined);
        this.storage = options.storage === undefined ? safeLocalStorage() : options.storage;

        this.devicesApi?.addEventListener?.('devicechange', () => {
            void this.devices().then((list) => this.emit('devicechange', list)).catch(() => {});
        });
    }

    /** getUserMedia, with every refusal turned into a BeamError that says why. */
    acquire(constraints: MediaStreamConstraints): Promise<MediaStream> {
        return this.open(constraints, true);
    }

    private async open(constraints: MediaStreamConstraints, useRemembered: boolean): Promise<MediaStream> {
        if (!this.devicesApi?.getUserMedia) {
            throw new BeamError('media-failed', 'This page cannot access the camera or microphone: it must be served over HTTPS (or localhost).');
        }

        try {
            return await this.devicesApi.getUserMedia(useRemembered ? this.withRemembered(constraints) : constraints);
        } catch (e) {
            const name = (e as DOMException)?.name;
            if (name === 'NotAllowedError' || name === 'SecurityError') throw new BeamError('permission-denied', 'The camera or microphone was refused.');
            if (name === 'NotFoundError' || name === 'DevicesNotFoundError') throw new BeamError('no-device', 'No camera or microphone was found.');
            if (name === 'OverconstrainedError' || name === 'NotReadableError') {
                // A remembered device that is gone or busy: try again without it.
                try {
                    return await this.devicesApi.getUserMedia(constraints);
                } catch (again) {
                    throw new BeamError('media-failed', `Could not open the camera or microphone: ${String(again)}`);
                }
            }
            throw new BeamError('media-failed', `Could not open the camera or microphone: ${String(e)}`);
        }
    }

    /** Inputs and outputs. Labels are empty until a permission has been granted once. */
    async devices(): Promise<DeviceInfo[]> {
        if (!this.devicesApi?.enumerateDevices) return [];

        const all = await this.devicesApi.enumerateDevices();

        return all
            .filter((d) => d.kind === 'audioinput' || d.kind === 'videoinput' || d.kind === 'audiooutput')
            .map((d) => ({ deviceId: d.deviceId, label: d.label, kind: d.kind as DeviceKind }));
    }

    /** Open one specific input; returns the new track for the caller to swap onto the peer. */
    async switchTo(kind: 'videoinput' | 'audioinput', deviceId: string): Promise<MediaStreamTrack> {
        const constraints: MediaStreamConstraints = kind === 'videoinput'
            ? { video: { deviceId: { exact: deviceId } }, audio: false }
            : { audio: { deviceId: { exact: deviceId } }, video: false };

        const stream = await this.acquire(constraints);
        const track = kind === 'videoinput' ? stream.getVideoTracks()[0] : stream.getAudioTracks()[0];

        if (!track) throw new BeamError('no-device', `No ${kind === 'videoinput' ? 'camera' : 'microphone'} with id ${deviceId}.`);

        this.remember(kind === 'videoinput' ? 'video' : 'audio', deviceId);

        return track;
    }

    /** Phones: the camera facing the other way, by facingMode rather than by id — enumerateDevices lists several rear lenses there. */
    async switchFacing(facing: 'user' | 'environment'): Promise<MediaStreamTrack> {
        const stream = await this.open({ video: { facingMode: { exact: facing } }, audio: false }, false);
        const track = stream.getVideoTracks()[0];

        if (!track) throw new BeamError('no-device', `No camera facing ${facing === 'user' ? 'you' : 'away from you'}.`);

        const id = track.getSettings?.().deviceId;
        if (id) this.remember('video', id);

        return track;
    }

    /** The screen, via getDisplayMedia. */
    async screen(options: DisplayMediaStreamOptions = { video: true, audio: false }): Promise<MediaStream> {
        // platform.ts: no Android or iOS browser offers a screen picker, whatever the API says.
        if (platform.isAndroid) throw new BeamError('screen-unavailable', 'Screen sharing is not available in Android browsers.');
        if (platform.isIOS) throw new BeamError('screen-unavailable', 'Screen sharing is not available on iOS.');
        if (!this.devicesApi?.getDisplayMedia) {
            throw new BeamError('screen-unavailable', 'Screen sharing is not available in this browser.');
        }

        try {
            return await this.devicesApi.getDisplayMedia(options);
        } catch (e) {
            const name = (e as DOMException)?.name;
            if (name === 'NotAllowedError') throw new BeamError('permission-denied', 'Screen sharing was refused.');
            throw new BeamError('screen-unavailable', `Could not share the screen: ${String(e)}`);
        }
    }

    remember(kind: MediaKind, deviceId: string): void {
        try {
            this.storage?.setItem(STORAGE_PREFIX + kind, deviceId);
        } catch {
            // storage may be full or forbidden; remembering is a convenience
        }
    }

    remembered(kind: MediaKind): string | null {
        try {
            return this.storage?.getItem(STORAGE_PREFIX + kind) ?? null;
        } catch {
            return null;
        }
    }

    stop(stream: MediaStream): void {
        stream.getTracks().forEach((t) => t.stop());
    }

    /** Prefer the remembered device, without failing if it is gone (ideal, not exact). */
    private withRemembered(constraints: MediaStreamConstraints): MediaStreamConstraints {
        const out: MediaStreamConstraints = { ...constraints };
        const video = this.remembered('video');
        const audio = this.remembered('audio');

        if (video && out.video && typeof out.video === 'object' && !('deviceId' in out.video)) out.video = { ...out.video, deviceId: { ideal: video } };
        else if (video && out.video === true) out.video = { deviceId: { ideal: video } };

        if (audio && out.audio && typeof out.audio === 'object' && !('deviceId' in out.audio)) out.audio = { ...out.audio, deviceId: { ideal: audio } };
        else if (audio && out.audio === true) out.audio = { deviceId: { ideal: audio } };

        return out;
    }
}

function safeLocalStorage(): Storage | null {
    try {
        return typeof localStorage !== 'undefined' ? localStorage : null;
    } catch {
        return null;
    }
}
