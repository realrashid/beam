<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beam-user" content="{{ auth()->id() }}">
    <title>Beam rig — Vue</title>
    <style>body{font:14px system-ui;margin:24px;background:#111;color:#ddd} video{width:320px;height:240px;background:#000}</style>
    <script type="importmap">{ "imports": { "vue": "/vendor/vue.esm-browser.js", "@realrashid/beam/vue": "/vendor/dist/vue.js", "@realrashid/beam": "/vendor/dist/index.js" } }</script>
</head>
<body>
    <div id="app"></div>
    @include('rig-echo')
    <script type="module">
        import { createApp, h, watch } from 'vue';
        import { useBeam } from '@realrashid/beam/vue';

        createApp({
            setup() {
                const beam = useBeam(@js($token));
                watch(beam.state, (s) => { window.__beamState = s; });
                watch(beam.call, (c) => { window.__call = c; });
                watch(beam.error, (e) => { window.__beamError = e && (e.reason + ': ' + e.message); });
                return () => h('div', [
                    h('h1', 'Inertia + Vue'),
                    h('video', { id: 'me', ref: beam.local, autoplay: true, muted: true, playsinline: true }),
                    h('video', { id: 'them', ref: beam.remote, autoplay: true, playsinline: true }),
                    h('p', ['state: ', h('span', { id: 'state' }, beam.state.value), ' · ', h('span', { id: 'duration' }, String(beam.duration.value))]),
                    h('button', { id: 'mute', onClick: beam.toggleMute }, beam.muted.value ? 'Unmute' : 'Mute'),
                    h('button', { id: 'hangup', onClick: () => beam.hangUp() }, 'End'),
                ]);
            },
        }).mount('#app');
    </script>
</body>
</html>
