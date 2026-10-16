<?php

declare(strict_types=1);

/**
 * What ships in the Composer dist is decided by .gitattributes. driverjs once
 * carried export-ignore lines for files it did not have; the reverse mistake —
 * ignoring resources/dist or resources/boost — would ship an inert package.
 */
it('ships the client, the Boost files, the stubs and the migration in the dist archive', function () {
    $root = realpath(__DIR__.'/..');

    if (! is_dir($root.'/.git')) {
        $this->markTestSkipped('No repository yet: created at release (task 14.4). Runs for real from then on.');
    }

    $listing = shell_exec("git -C {$root} archive HEAD | tar -t");

    foreach (['resources/dist/beam.js', 'resources/boost/guidelines/', 'stubs/', 'lang/', 'database/migrations/'] as $path) {
        expect($listing)->toContain($path);
    }

    foreach (['tests/', 'docs/', 'plan/', '.github/'] as $path) {
        expect($listing)->not->toContain($path);
    }
});

it('keeps Web Push optional: an application that only calls between open tabs carries none of its tree', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);

    // web-push brings a JWT library, a PKI framework and an HTTP discovery
    // layer with it. Beam needs those only to ring a closed tab, so they are
    // suggested and guarded, not required.
    expect($composer['require'])->not->toHaveKey('minishlink/web-push')
        ->and($composer['require-dev'])->toHaveKey('minishlink/web-push')
        ->and($composer['suggest']['minishlink/web-push'])->toContain('closed tab');

    expect(file_get_contents(dirname(__DIR__).'/src/Push/WebPushSender.php'))->toContain('class_exists(WebPush::class)');
    expect(file_get_contents(dirname(__DIR__).'/src/Commands/InstallCommand.php'))->toContain('class_exists(VAPID::class)');
});

/**
 * The archive test above skips until the repository exists, so nothing caught
 * this: a bare `dist` line in .gitignore matches at every level, so it took
 * resources/dist with it and the first commit would have carried no client at
 * all — and `git diff --exit-code -- resources/dist/beam.js`, the CI guard,
 * passes for an untracked path. This asks git itself, in a throwaway repo, so
 * it holds before the package has one.
 */
it('does not gitignore the two files a Composer-only install depends on', function () {
    $root = realpath(__DIR__.'/..');
    $tmp = sys_get_temp_dir().'/beam-ignore-'.bin2hex(random_bytes(6));

    foreach (['resources/dist', 'dist', 'docs/.vitepress/dist'] as $dir) {
        mkdir($tmp.'/'.$dir, 0777, true);
    }

    copy($root.'/.gitignore', $tmp.'/.gitignore');
    shell_exec("git -C {$tmp} init -q 2>&1");

    $ask = function (string $path) use ($tmp): bool {
        touch($tmp.'/'.$path);
        exec('git -C '.escapeshellarg($tmp).' check-ignore -q '.escapeshellarg($path), $out, $status);

        return $status === 0;
    };

    // The compiled client and the service worker are the product for anyone who
    // installs with Composer and never runs a bundler.
    expect($ask('resources/dist/beam.js'))->toBeFalse('resources/dist/beam.js is gitignored: a Composer install would 404 on the client')
        ->and($ask('resources/dist/beam-sw.js'))->toBeFalse('resources/dist/beam-sw.js is gitignored: push notifications would have no worker');

    // Four CI jobs run `npm ci`, and actions/setup-node's npm cache needs it too.
    expect($ask('package-lock.json'))->toBeFalse('package-lock.json is gitignored: every Node CI job fails without it');

    // Still ignored, deliberately: the npm ESM build and the built docs site.
    expect($ask('dist/index.js'))->toBeTrue()
        ->and($ask('docs/.vitepress/dist/index.html'))->toBeTrue();

    shell_exec('rm -rf '.escapeshellarg($tmp));
});
