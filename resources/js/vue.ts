import { onBeforeUnmount, onMounted, ref, shallowRef, unref, watch, type MaybeRef, type Ref, type ShallowRef } from 'vue';
import Beam, { type Incoming, type JoinOptions } from './beam';
import type { Call, CallState } from './call';
import { BeamError } from './errors';
import type { FileOffer } from './transfer';

export interface UseBeam {
    /** Bind to <video ref="local"> and <video ref="remote">. */
    local: Ref<HTMLVideoElement | null>;
    remote: Ref<HTMLVideoElement | null>;
    call: ShallowRef<Call | null>;
    state: Ref<CallState>;
    duration: Ref<number>;
    muted: Ref<boolean>;
    cameraOn: Ref<boolean>;
    sharing: Ref<boolean>;
    quality: Ref<{ rtt: number; packetLoss: number } | null>;
    error: Ref<BeamError | null>;
    mute(): void;
    unmute(): void;
    toggleMute(): void;
    enableCamera(): void;
    disableCamera(): void;
    switchCamera(deviceId?: string): Promise<void>;
    shareScreen(): Promise<void>;
    stopSharing(): Promise<void>;
    /** Where the browser allows it (platform.supportsSetSinkId); rejects with reason `unsupported` elsewhere. */
    setSpeaker(deviceId: string): Promise<void>;
    hangUp(reason?: string): Promise<void>;
}

/**
 * The class, in Vue's idiom: join on mount, attach when the video refs exist,
 * hang up on unmount, and every piece of call state as a ref. About forty lines.
 */
export function useBeam(token: MaybeRef<string>, options: JoinOptions = {}): UseBeam {
    const local = ref<HTMLVideoElement | null>(null);
    const remote = ref<HTMLVideoElement | null>(null);
    const call = shallowRef<Call | null>(null);
    const state = ref<CallState>('connecting');
    const duration = ref(0);
    const muted = ref(false);
    const cameraOn = ref(true);
    const sharing = ref(false);
    const quality = ref<{ rtt: number; packetLoss: number } | null>(null);
    const error = ref<BeamError | null>(null);

    const attach = () => {
        if (call.value && remote.value) call.value.attach({ local: local.value ?? undefined, remote: remote.value });
    };

    let gone = false;

    onMounted(async () => {
        try {
            const c = await Beam.join(unref(token), options);
            // Unmounted while the camera was still opening: let go of it rather
            // than leaving a call nobody is looking at.
            if (gone) { void c.release(); return; }
            call.value = c;
            state.value = c.state;
            muted.value = c.isMuted;
            cameraOn.value = c.isCameraOn;
            c.on('state', (s) => { state.value = s; });
            c.on('tick', ({ seconds }) => { duration.value = seconds; });
            c.on('muted', (m) => { muted.value = m; });
            c.on('camera', (on) => { cameraOn.value = on; });
            c.on('sharing', (s) => { sharing.value = s; });
            c.on('quality', (q) => { quality.value = q; });
            c.on('error', (e) => { error.value = e; });
            attach();
        } catch (e) {
            error.value = e instanceof BeamError ? e : new BeamError('connection-failed', String(e));
            state.value = 'ended';
        }
    });

    watch([local, remote], attach);
    onBeforeUnmount(() => {
        gone = true;
        const c = call.value;
        call.value = null;
        if (c) void c.release();
    });

    const guard = <T>(fn: (c: Call) => T): T | undefined => (call.value ? fn(call.value) : undefined);

    return {
        local, remote, call, state, duration, muted, cameraOn, sharing, quality, error,
        mute: () => void guard((c) => c.mute()),
        unmute: () => void guard((c) => c.unmute()),
        toggleMute: () => void guard((c) => c.toggleMute()),
        enableCamera: () => void guard((c) => c.cameraOn()),
        disableCamera: () => void guard((c) => c.cameraOff()),
        switchCamera: (deviceId?: string) => guard((c) => c.switchCamera(deviceId)) ?? Promise.resolve(),
        shareScreen: () => guard((c) => c.shareScreen()) ?? Promise.resolve(),
        stopSharing: () => guard((c) => c.stopSharing()) ?? Promise.resolve(),
        setSpeaker: (deviceId: string) => guard((c) => c.setSpeaker(deviceId)) ?? Promise.resolve(),
        hangUp: (reason?: string) => guard((c) => c.hangUp(reason)) ?? Promise.resolve(),
    };
}

/** Ring when someone calls, for as long as the component lives. */
export function useIncoming(handler: (incoming: Incoming) => void): void {
    let off: (() => void) | null = null;
    onMounted(() => { off = Beam.onIncoming(handler); });
    onBeforeUnmount(() => off?.());
}

/** Be offered files, for as long as the component lives. */
export function useFileOffers(handler: (offer: FileOffer) => void): void {
    let off: (() => void) | null = null;
    onMounted(() => { off = Beam.onFileOffered(handler); });
    onBeforeUnmount(() => off?.());
}

export { Beam };
export type { Call, CallState, Incoming, JoinOptions };
