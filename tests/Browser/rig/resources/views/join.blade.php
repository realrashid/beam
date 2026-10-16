<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beam-user" content="{{ auth()->id() }}">
    <title>Beam rig — join</title>
    <style>
        body { font: 14px system-ui, sans-serif; margin: 24px; background: #111; color: #ddd; }
        video { width: 320px; height: 240px; background: #000; border: 2px solid #333; }
    </style>
</head>
<body>
    <h1>calling — user {{ auth()->id() }}</h1>
    <video id="me" autoplay muted playsinline></video>
    <video id="them" autoplay playsinline></video>
    <p>state: <span id="state">new</span></p>
    <button id="hangup">Hang up</button>

    @include('rig-scripts')
    <script>
        Beam.join(@js($token)).then((call) => {
            window.__call = call; window.__beamMessages = []; window.__t0 = performance.now();
                call.signaling.on('message', (m) => window.__beamMessages.push([Math.round(performance.now() - window.__t0), m.type, String(m.from)]));
                call.signaling.on('control', (c) => window.__beamMessages.push([Math.round(performance.now() - window.__t0), 'control:' + c.action, '']));
            call.attach({ local: '#me', remote: '#them' });
            window.__beamState = call.state;
            call.on('state', (s) => { window.__beamState = s; document.getElementById('state').textContent = s; });
            call.on('ended', ({ reason, duration }) => { window.__beamEnded = { reason, duration }; });
            document.getElementById('hangup').onclick = () => call.hangUp();
        }).catch((e) => { window.__beamError = String(e); });
    </script>
</body>
</html>
