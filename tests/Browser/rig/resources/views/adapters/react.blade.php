<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beam-user" content="{{ auth()->id() }}">
    <title>Beam rig — React</title>
    <style>body{font:14px system-ui;margin:24px;background:#111;color:#ddd} video{width:320px;height:240px;background:#000}</style>
    <script type="importmap">{ "imports": { "react": "/vendor/react-esm.js", "react-dom/client": "/vendor/react-esm.js", "@realrashid/beam/react": "/vendor/dist/react.js", "@realrashid/beam": "/vendor/dist/index.js" } }</script>
</head>
<body>
    <div id="app"></div>
    @include('rig-echo')
    <script type="module">
        import React, { useEffect } from 'react';
        import { createRoot } from 'react-dom/client';
        import { useBeam } from '@realrashid/beam/react';

        function Screen() {
            const beam = useBeam(@js($token));
            useEffect(() => { window.__beamState = beam.state; }, [beam.state]);
            useEffect(() => { window.__call = beam.call; }, [beam.call]);
            useEffect(() => { if (beam.error) window.__beamError = beam.error.reason + ': ' + beam.error.message; }, [beam.error]);
            return React.createElement('div', null,
                React.createElement('h1', null, 'Inertia + React'),
                React.createElement('video', { id: 'me', ref: beam.local, autoPlay: true, muted: true, playsInline: true }),
                React.createElement('video', { id: 'them', ref: beam.remote, autoPlay: true, playsInline: true }),
                React.createElement('p', null, 'state: ', React.createElement('span', { id: 'state' }, beam.state), ' · ', React.createElement('span', { id: 'duration' }, String(beam.duration))),
                React.createElement('button', { id: 'mute', onClick: beam.toggleMute }, beam.muted ? 'Unmute' : 'Mute'),
                React.createElement('button', { id: 'hangup', onClick: () => beam.hangUp() }, 'End'),
            );
        }
        createRoot(document.getElementById('app')).render(React.createElement(Screen));
    </script>
</body>
</html>
