<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beam-user" content="{{ auth()->id() }}">
    <title>Beam rig — dashboard of user {{ auth()->id() }}</title>
    <style>
        body { font: 14px system-ui, sans-serif; margin: 24px; background: #111; color: #ddd; }
        video { width: 320px; height: 240px; background: #000; border: 2px solid #333; }
        #incoming { display: none; padding: 12px; border: 2px solid #5eead4; }
    </style>
</head>
<body>
    <h1>dashboard — user {{ auth()->id() }}</h1>
    <div id="incoming">
        <p><b id="from"></b> is calling — <span id="about"></span></p>
        <button id="answer">Answer</button>
        <button id="decline">Decline</button>
    </div>
    <p><button id="subscribe">Ring me when closed</button> <span id="push-status"></span></p>
    <video id="me" autoplay muted playsinline></video>
    <video id="them" autoplay playsinline></video>
    <p>state: <span id="state">idle</span></p>

    @include('rig-scripts')
    <script>
        window.__incoming = null;
        document.getElementById('subscribe').onclick = () => Beam.push.subscribe()
            .then((s) => { document.getElementById('push-status').textContent = 'subscribed'; window.__pushSubscribed = s.endpoint; })
            .catch((e) => { document.getElementById('push-status').textContent = e.reason + ': ' + e.message; window.__pushError = e.reason + ': ' + e.message; });
        Beam.onIncoming((incoming) => {
            window.__incoming = { callId: incoming.callId, from: incoming.from, about: incoming.about, media: incoming.media };
            document.getElementById('from').textContent = incoming.from.name;
            document.getElementById('about').textContent = incoming.about ?? '';
            document.getElementById('incoming').style.display = 'block';
            document.getElementById('answer').onclick = () => incoming.answer().then((call) => {
                window.__call = call; window.__beamMessages = []; window.__t0 = performance.now();
                call.signaling.on('message', (m) => window.__beamMessages.push([Math.round(performance.now() - window.__t0), m.type, String(m.from)]));
                call.signaling.on('control', (c) => window.__beamMessages.push([Math.round(performance.now() - window.__t0), 'control:' + c.action, '']));
                call.attach({ local: '#me', remote: '#them' });
                window.__beamState = call.state;
                call.on('state', (s) => { window.__beamState = s; document.getElementById('state').textContent = s; });
                call.on('ended', ({ reason, duration }) => { window.__beamEnded = { reason, duration }; });
                document.getElementById('incoming').style.display = 'none';
            }).catch((e) => { window.__beamError = String(e); });
            document.getElementById('decline').onclick = () => incoming.decline('busy').then(() => { window.__declined = true; document.getElementById('incoming').style.display = 'none'; });
        });
    </script>
</body>
</html>
