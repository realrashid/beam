// Copied by `beam:install --views`. Yours now — Beam never renders this.
// Inertia page: return Inertia::render('Beam/Call', ['token' => $call->token]);
import { useBeam } from '@realrashid/beam/react';

const styles = {
    stage: { position: 'relative', height: '100dvh', display: 'grid', placeItems: 'center', background: '#0f1115', color: '#e6e8ec', font: '15px/1.4 system-ui, sans-serif' },
    remote: { width: '100%', height: '100%', objectFit: 'cover', background: '#000' },
    local: { position: 'absolute', right: 16, bottom: 96, width: 160, aspectRatio: '4 / 3', borderRadius: 8, background: '#000', objectFit: 'cover' },
    status: { position: 'absolute', top: 16, left: 16, padding: '6px 10px', borderRadius: 999, background: 'rgba(0,0,0,.55)', fontVariantNumeric: 'tabular-nums' },
    banner: { position: 'absolute', top: 56, left: 16, padding: '6px 10px', borderRadius: 6, background: '#b8860b', color: '#000' },
    controls: { position: 'absolute', bottom: 24, left: '50%', transform: 'translateX(-50%)', display: 'flex', gap: 8 },
    button: { padding: '10px 14px', border: 0, borderRadius: 999, background: '#2a2f3a', color: 'inherit', cursor: 'pointer' },
    pressed: { background: '#e6e8ec', color: '#0f1115' },
    hangup: { background: '#d64545' },
};

const clock = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

export default function Call({ token }) {
    const { local, remote, state, duration, muted, cameraOn, sharing, error, toggleMute, enableCamera, disableCamera, switchCamera, shareScreen, stopSharing, hangUp } = useBeam(token);
    const button = (pressed) => ({ ...styles.button, ...(pressed ? styles.pressed : {}) });

    return (
        <div style={styles.stage}>
            <video ref={remote} style={styles.remote} autoPlay playsInline />
            <video ref={local} style={styles.local} autoPlay muted playsInline />

            <div style={styles.status}>{state} · {clock(duration)}</div>
            {state === 'reconnecting' && <div style={styles.banner}>Reconnecting…</div>}
            {error && <div style={styles.banner}>{error.message}</div>}

            <div style={styles.controls}>
                <button type="button" style={button(muted)} aria-pressed={muted} onClick={toggleMute}>{muted ? 'Unmute' : 'Mute'}</button>
                <button type="button" style={button(!cameraOn)} aria-pressed={!cameraOn} onClick={() => (cameraOn ? disableCamera() : enableCamera())}>{cameraOn ? 'Camera off' : 'Camera on'}</button>
                <button type="button" style={styles.button} onClick={() => switchCamera()}>Switch camera</button>
                <button type="button" style={button(sharing)} aria-pressed={sharing} onClick={() => (sharing ? stopSharing() : shareScreen())}>{sharing ? 'Stop sharing' : 'Share screen'}</button>
                <button type="button" style={{ ...styles.button, ...styles.hangup }} onClick={() => hangUp()}>End</button>
            </div>
        </div>
    );
}
