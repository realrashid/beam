<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beam-user" content="{{ auth()->id() }}">
    <title>Beam rig — jQuery</title>
    <style>body{font:14px system-ui;margin:24px;background:#111;color:#ddd} video{width:320px;height:240px;background:#000}</style>
</head>
<body>
    <h1>jQuery, no build step</h1>
    <video id="me" autoplay muted playsinline></video>
    <video id="them" autoplay playsinline></video>
    <p>state: <span id="state">new</span></p>
    <button id="hangup">End</button>

    <script src="/vendor/jquery.js"></script>
    @include('rig-scripts')
    <script>
        $(function () {
            $.beam.join(@js($token)).then(function (call) {
                window.__call = call;
                call.attach({ local: $('#me'), remote: $('#them') });
                window.__beamState = call.state;
                $(call).on('state', function (e, s) { window.__beamState = s; $('#state').text(s); });
                $(call).on('ended', function (e, info) { window.__beamEnded = info; });
                $('#hangup').on('click', function () { call.hangUp(); });
            }).catch(function (e) { window.__beamError = String(e); });
        });
    </script>
</body>
</html>
