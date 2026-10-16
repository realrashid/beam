<script src="/vendor/pusher.js"></script>
<script src="/vendor/echo.js"></script>
<script>
    window.__beamState = 'new';
    window.Echo = new Echo.default({
        broadcaster: 'reverb',
        key: @js(config('broadcasting.connections.reverb.key')),
        wsHost: @js(config('broadcasting.connections.reverb.options.host')),
        wsPort: @js((int) config('broadcasting.connections.reverb.options.port')),
        wssPort: @js((int) config('broadcasting.connections.reverb.options.port')),
        forceTLS: false,
        enabledTransports: ['ws', 'wss'],
    });
</script>
