<?php

/**
 * Boots the rig for Playwright's webServer: Reverb on :8091 as a child
 * process, then the app on :8090 in the foreground. Reverb is stopped when
 * this process is.
 */
$app = getenv('BEAM_RIG_DIR') ?: __DIR__.'/../../../beam-rig';

if (! is_file($app.'/artisan')) {
    fwrite(STDERR, "The rig app is missing at {$app}. Build it with: npm run rig:setup\n");
    exit(1);
}
$app = realpath($app);

$reverb = proc_open(
    [PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', '--port=8091'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', sys_get_temp_dir().'/beam-rig-reverb.log', 'a']],
    $pipes,
    $app,
);

$stop = function () use ($reverb): void {
    if (is_resource($reverb)) {
        proc_terminate($reverb);
    }
};
register_shutdown_function($stop);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use ($stop) {
        $stop();
        exit(0);
    });
    pcntl_signal(SIGINT, function () use ($stop) {
        $stop();
        exit(0);
    });
}

// php -S is single-process: two browsers heartbeating and signaling queue up
// behind each other and every request looks slow. Workers make it concurrent.
putenv('PHP_CLI_SERVER_WORKERS=8');
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/artisan').' serve --host=127.0.0.1 --port=8090', $code);
$stop();
exit($code);
