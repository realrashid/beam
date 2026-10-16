<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beam-user" content="{{ auth()->id() }}">
    <title>Beam rig — {{ $label }}</title>
    <style>
        body { font: 14px system-ui, sans-serif; margin: 24px; background: #111; color: #ddd; }
        video { width: 320px; height: 240px; background: #000; border: 2px solid #333; }
        #state { font-family: ui-monospace, monospace; }
    </style>
</head>
<body>
    <h1>{{ $label }}</h1>
    <video id="me" autoplay muted playsinline></video>
    <video id="them" autoplay playsinline></video>
    <p>state: <span id="state">new</span></p>
    <p>
        <button id="mute">Mute</button> <button id="unmute">Unmute</button>
        <button id="camera-off">Camera off</button> <button id="camera-on">Camera on</button>
        <button id="switch">Switch camera</button> <button id="share">Share screen</button> <button id="stop-share">Stop sharing</button>
        <button id="hangup">Hang up</button>
    </p>

    @include('rig-scripts')
    <script>
        window.__beamJoinedAt = performance.now();
        Beam.join(@js($token), { iceTransportPolicy: @js(request()->boolean('relay') ? 'relay' : 'all'), ...(@js(request()->query('media')) === 'audio' ? { media: { audio: true, video: false } } : {}) }).then((call) => {
            window.__remoteTracks = [];
            call.on('remotetrack', (track, stream) => { window.__remoteTracks.push({ kind: track.kind, stream: stream.id }); });
            window.__shareAlongside = () => call.shareScreen({ alongside: true });
            window.__enableVideo = () => call.enableVideo();
            window.__call = call;
            document.querySelector('h1').textContent += ' — ' + call.claims.meta.transport + ' — ' + (call.peer.polite ? 'polite' : 'impolite');
            call.attach({ local: '#me', remote: '#them' });
            window.__beamState = call.state;
            call.on('state', (s) => {
                window.__beamState = s;
                window.__beamEvents.push([performance.now() - window.__beamJoinedAt, s]);
                document.getElementById('state').textContent = s;
                if (s === 'connected' && !window.__beamConnectedAt) window.__beamConnectedAt = performance.now() - window.__beamJoinedAt;
            });
            call.on('error', (e) => { window.__beamError = e.reason + ': ' + e.message; });
            call.on('ended', ({ reason, duration }) => { window.__beamEnded = { reason, duration }; });
            call.on('quality', (q) => { window.__beamQuality = q; });
            // Rig hooks: files. __send(bytes) sends a deterministic blob to the other side; the receiver's outcome lands in __received.
            window.__transferProgress = [];
            window.__send = async (bytes, accept = true) => {
                const data = new Uint8Array(bytes); for (let i = 0; i < bytes; i++) data[i] = (i * 7 + 3) % 251;
                const t = Beam.send(new Blob([data]), 'rig.bin').to(call.remoteId ?? (window.__otherId));
                t.on('progress', (p) => window.__transferProgress.push(p.percent));
                t.on('state', (s) => { window.__transferState = s; });
                window.__transfer = t;
                const started = t.start();   // offer first; hashing 20 MB in Gecko takes seconds and the spec is waiting for the offer
                window.__sentSha = await crypto.subtle.digest('SHA-256', data).then((d) => [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, '0')).join(''));
                try { await started; window.__transferState = t.state; } catch (e) { window.__transferError = (e.reason ?? 'error') + ': ' + e.message; }
            };
            window.__autoAccept = true;
            Beam.onFileOffered(async (offer) => {
                window.__offer = { id: offer.id, name: offer.name, size: offer.size, from: offer.from };
                window.__offerObj = offer;   // rig only: lets a spec inspect the receiver mid-transfer
                if (!window.__autoAccept) { await offer.decline(); window.__declined = true; return; }
                offer.on('progress', (p) => { window.__receiveProgress = p.percent; });
                try {
                    const blob = await offer.accept();
                    const sha = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer()).then((d) => [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, '0')).join(''));
                    window.__received = { size: blob.size, sha256: sha, name: offer.name };
                } catch (e) { window.__receiveError = (e.reason ?? 'error') + ': ' + e.message; }
            });
            // Rig hooks: force a renegotiation offer (glare), force an ICE restart (reconnect).
            window.__forceOffer = async () => { const sdp = await call.peer.createOffer(); await call.signaling.send({ type: 'offer', sdp }); };
            window.__forceRestart = () => call.peer.connection.restartIce();
            for (const [id, fn] of [['mute', () => call.mute()], ['unmute', () => call.unmute()], ['camera-off', () => call.cameraOff()], ['camera-on', () => call.cameraOn()],
                ['switch', () => call.switchCamera()], ['share', () => call.shareScreen()], ['stop-share', () => call.stopSharing()], ['hangup', () => call.hangUp()]]) {
                document.getElementById(id).onclick = () => Promise.resolve(fn()).catch((e) => { window.__beamError = (e.reason ?? 'error') + ': ' + e.message; });
            }
        }).catch((e) => { window.__beamError = String(e); document.getElementById('state').textContent = 'error: ' + e; });
    </script>
</body>
</html>
