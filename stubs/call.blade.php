{{-- Copied by `beam:install --views`. Yours now — Beam never renders this. --}}
{{-- Expects $token: the packed call token from Beam::call(...)->ring()->token or $room->token($user). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Call</title>
    {{-- Beam signals over Laravel Echo: app.js sets window.Echo up (php artisan install:broadcasting writes resources/js/echo.js). --}}
    @vite(['resources/js/app.js'])
    @beamAssets
    <style>
        body { margin: 0; font: 15px/1.4 system-ui, sans-serif; background: #0f1115; color: #e6e8ec; }
        .beam-stage { position: relative; height: 100dvh; display: grid; place-items: center; }
        .beam-remote { width: 100%; height: 100%; object-fit: cover; background: #000; }
        .beam-local { position: absolute; right: 16px; bottom: 96px; width: 160px; aspect-ratio: 4 / 3; border-radius: 8px; background: #000; object-fit: cover; }
        .beam-status { position: absolute; top: 16px; left: 16px; padding: 6px 10px; border-radius: 999px; background: rgba(0,0,0,.55); font-variant-numeric: tabular-nums; }
        .beam-controls { position: absolute; bottom: 24px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; }
        .beam-controls button { padding: 10px 14px; border: 0; border-radius: 999px; background: #2a2f3a; color: inherit; cursor: pointer; }
        .beam-controls button[data-beam="hangup"] { background: #d64545; }
        .beam-controls button[aria-pressed="true"] { background: #e6e8ec; color: #0f1115; }
        .beam-banner { position: absolute; top: 56px; left: 16px; padding: 6px 10px; border-radius: 6px; background: #b8860b; color: #000; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <div class="beam-stage">
        <video id="beam-remote" class="beam-remote" autoplay playsinline></video>
        <video id="beam-local" class="beam-local" autoplay muted playsinline></video>

        <div class="beam-status"><span data-beam="state">connecting</span> · <span data-beam="duration">0:00</span></div>
        <div class="beam-banner" data-beam="banner" hidden>Reconnecting…</div>

        <div class="beam-controls">
            <button type="button" data-beam="mute" aria-pressed="false">Mute</button>
            <button type="button" data-beam="camera" aria-pressed="false">Camera off</button>
            <button type="button" data-beam="switch">Switch camera</button>
            <button type="button" data-beam="share" aria-pressed="false">Share screen</button>
            <button type="button" data-beam="hangup">End</button>
        </div>
    </div>

    <script>
        (async () => {
            const $ = (name) => document.querySelector(`[data-beam="${name}"]`);
            const clock = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

            let call;
            try {
                call = await Beam.join(@js($token));
            } catch (e) {
                $('state').textContent = e.reason === 'permission-denied' ? 'camera or microphone refused' : (e.message || 'could not join');
                return;
            }

            call.attach({ local: '#beam-local', remote: '#beam-remote' });

            call.on('state', (state) => { $('state').textContent = state; $('banner').hidden = state !== 'reconnecting'; });
            call.on('tick', ({ seconds }) => { $('duration').textContent = clock(seconds); });
            call.on('muted', (muted) => { $('mute').setAttribute('aria-pressed', String(muted)); $('mute').textContent = muted ? 'Unmute' : 'Mute'; });
            call.on('camera', (on) => { $('camera').setAttribute('aria-pressed', String(!on)); $('camera').textContent = on ? 'Camera off' : 'Camera on'; });
            call.on('sharing', (sharing) => { $('share').setAttribute('aria-pressed', String(sharing)); $('share').textContent = sharing ? 'Stop sharing' : 'Share screen'; });
            call.on('error', (e) => { if (e.reason === 'autoplay-blocked') $('state').textContent = 'tap anywhere to hear the call'; });
            call.on('ended', ({ reason, duration }) => { $('state').textContent = `ended (${reason}, ${clock(duration)})`; document.querySelectorAll('.beam-controls button').forEach((b) => { b.disabled = true; }); });

            $('mute').onclick = () => call.toggleMute();
            $('camera').onclick = () => (call.isCameraOn ? call.cameraOff() : call.cameraOn());
            $('switch').onclick = () => call.switchCamera().catch(() => {});
            $('share').onclick = () => (call.isSharing ? call.stopSharing() : call.shareScreen()).catch((e) => { $('state').textContent = e.message; });
            $('hangup').onclick = () => call.hangUp();

            if (!Beam.platform.supportsDisplayMedia()) $('share').hidden = true;
        })();
    </script>
</body>
</html>
