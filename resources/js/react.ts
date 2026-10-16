import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import Beam, { type Incoming, type JoinOptions } from './beam';
import type { Call, CallState } from './call';
import { BeamError } from './errors';
import type { FileOffer } from './transfer';

export interface UseBeam {
    /** Pass as ref={local} and ref={remote} on the <video> elements. */
    local: RefObject<HTMLVideoElement | null>;
    remote: RefObject<HTMLVideoElement | null>;
    call: Call | null;
    state: CallState;
    duration: number;
    muted: boolean;
    cameraOn: boolean;
    sharing: boolean;
    quality: { rtt: number; packetLoss: number } | null;
    error: BeamError | null;
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
 * The class, in React's idiom: join in an effect, attach to the refs once the
 * call exists, hang up on cleanup, and every piece of call state as state.
 */
export function useBeam(token: string, options: JoinOptions = {}): UseBeam {
    const local = useRef<HTMLVideoElement | null>(null);
    const remote = useRef<HTMLVideoElement | null>(null);
    const callRef = useRef<Call | null>(null);
    const [call, setCall] = useState<Call | null>(null);
    const [state, setState] = useState<CallState>('connecting');
    const [duration, setDuration] = useState(0);
    const [muted, setMuted] = useState(false);
    const [cameraOn, setCameraOn] = useState(true);
    const [sharing, setSharing] = useState(false);
    const [quality, setQuality] = useState<{ rtt: number; packetLoss: number } | null>(null);
    const [error, setError] = useState<BeamError | null>(null);

    useEffect(() => {
        let cancelled = false;

        Beam.join(token, options).then((c) => {
            // StrictMode runs this effect, tears it down and runs it again. The
            // second run joined the same call, so letting go is release(), not
            // hangUp() — hanging up here would end the call the live mount is
            // showing, which is how a React app in development saw every call
            // end the instant it connected.
            if (cancelled) { void c.release(); return; }
            callRef.current = c;
            setCall(c);
            setState(c.state);
            setMuted(c.isMuted);
            setCameraOn(c.isCameraOn);
            c.on('state', setState);
            c.on('tick', ({ seconds }) => setDuration(seconds));
            c.on('muted', setMuted);
            c.on('camera', setCameraOn);
            c.on('sharing', setSharing);
            c.on('quality', setQuality);
            c.on('error', setError);
            if (remote.current) c.attach({ local: local.current ?? undefined, remote: remote.current });
        }).catch((e) => {
            if (cancelled) return;
            setError(e instanceof BeamError ? e : new BeamError('connection-failed', String(e)));
            setState('ended');
        });

        return () => {
            cancelled = true;
            const call = callRef.current;
            callRef.current = null;
            if (call) void call.release();
        };
    // A new token is a new call; options are read once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token]);

    const c = () => callRef.current;

    return {
        local, remote, call, state, duration, muted, cameraOn, sharing, quality, error,
        mute: useCallback(() => void c()?.mute(), []),
        unmute: useCallback(() => void c()?.unmute(), []),
        toggleMute: useCallback(() => void c()?.toggleMute(), []),
        enableCamera: useCallback(() => void c()?.cameraOn(), []),
        disableCamera: useCallback(() => void c()?.cameraOff(), []),
        switchCamera: useCallback((deviceId?: string) => c()?.switchCamera(deviceId) ?? Promise.resolve(), []),
        shareScreen: useCallback(() => c()?.shareScreen() ?? Promise.resolve(), []),
        stopSharing: useCallback(() => c()?.stopSharing() ?? Promise.resolve(), []),
        setSpeaker: useCallback((deviceId: string) => c()?.setSpeaker(deviceId) ?? Promise.resolve(), []),
        hangUp: useCallback((reason?: string) => c()?.hangUp(reason) ?? Promise.resolve(), []),
    };
}

/** Ring when someone calls, for as long as the component is mounted. */
export function useIncoming(handler: (incoming: Incoming) => void): void {
    const latest = useRef(handler);
    latest.current = handler;
    useEffect(() => Beam.onIncoming((incoming) => latest.current(incoming)), []);
}

/** Be offered files, for as long as the component is mounted. */
export function useFileOffers(handler: (offer: FileOffer) => void): void {
    const latest = useRef(handler);
    latest.current = handler;
    useEffect(() => Beam.onFileOffered((offer) => latest.current(offer)), []);
}

export { Beam };
export type { Call, CallState, Incoming, JoinOptions };
