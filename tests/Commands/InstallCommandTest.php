<?php

declare(strict_types=1);

it('publishes the config and writes VAPID keys to .env once, and leaves the migration alone', function () {
    $env = $this->app->environmentFilePath();
    file_put_contents($env, "APP_NAME=Rig\n");

    $this->artisan('beam:install')->assertSuccessful();

    $written = file_get_contents($env);
    expect($written)->toMatch('/^BEAM_VAPID_PUBLIC=.{40,}$/m')->toMatch('/^BEAM_VAPID_PRIVATE=.{20,}$/m')
        ->and(file_exists(config_path('beam.php')))->toBeTrue()
        // Not published by default: the table is created from the package's own
        // copy, so a duplicate in the application is a file to maintain for
        // nothing. --migrations is for someone who wants to edit it.
        ->and(glob(database_path('migrations/*create_beam_push_subscriptions_table.php')))->toBeEmpty();

    $this->artisan('beam:install')->expectsOutputToContain('already present')->assertSuccessful();
    expect(file_get_contents($env))->toBe($written);

    $this->artisan('beam:install', ['--force' => true])->assertSuccessful();
    expect(file_get_contents($env))->not->toBe($written)->toMatch('/^BEAM_VAPID_PUBLIC=/m');

    @unlink($env);
    @unlink(config_path('beam.php'));
    array_map('unlink', glob(database_path('migrations/*create_beam_push_subscriptions_table.php')) ?: []);
});

it('copies the Blade call screen and incoming modal with --views, skips existing files without --force, and adds Vue and React pages when package.json has them', function () {
    file_put_contents($this->app->environmentFilePath(), "APP_NAME=Rig\n");
    $views = resource_path('views/beam');
    $pages = resource_path('js/Pages/Beam');
    $cleanup = function () use ($views, $pages) {
        array_map('unlink', array_merge(glob("{$views}/*") ?: [], glob("{$pages}/*") ?: []));
        @rmdir($views);
        @rmdir($pages);
        @unlink(base_path('package.json'));
        @unlink($this->app->environmentFilePath());
        @unlink(config_path('beam.php'));
        array_map('unlink', glob(database_path('migrations/*create_beam_push_subscriptions_table.php')) ?: []);
    };

    $this->artisan('beam:install', ['--views' => true])
        ->expectsOutputToContain('Created resources/views/beam/call.blade.php')
        ->expectsOutputToContain('Created resources/views/beam/incoming.blade.php')
        ->expectsOutputToContain("view('beam.call'")
        ->assertSuccessful();

    expect(file_get_contents("{$views}/call.blade.php"))->toContain('@beamAssets')->toContain('Yours now')
        ->and(file_get_contents("{$views}/incoming.blade.php"))->toContain('Beam.onIncoming')
        ->and(glob("{$pages}/*") ?: [])->toBeEmpty();

    file_put_contents("{$views}/call.blade.php", 'mine');
    $this->artisan('beam:install', ['--views' => true])
        ->expectsOutputToContain('resources/views/beam/call.blade.php exists; left as it is')
        ->assertSuccessful();
    expect(file_get_contents("{$views}/call.blade.php"))->toBe('mine');

    file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['vue' => '^3'], 'devDependencies' => ['react' => '^19']]));
    $this->artisan('beam:install', ['--views' => true, '--force' => true])
        ->expectsOutputToContain('Created resources/js/Pages/Beam/Call.vue')
        ->expectsOutputToContain('Created resources/js/Pages/Beam/Call.jsx')
        ->expectsOutputToContain("Inertia::render('Beam/Call'")
        ->assertSuccessful();
    expect(file_get_contents("{$views}/call.blade.php"))->toContain('@beamAssets')
        ->and(file_get_contents("{$pages}/Call.vue"))->toContain("from '@realrashid/beam/vue'")
        ->and(file_get_contents("{$pages}/Call.jsx"))->toContain("from '@realrashid/beam/react'");

    $cleanup();
});

it('publishes the migration with --migrations, under the name the migrator needs', function () {
    file_put_contents($this->app->environmentFilePath(), "APP_NAME=Rig\n");

    $this->artisan('beam:install', ['--migrations' => true])->assertSuccessful();

    $published = glob(database_path('migrations/*create_beam_push_subscriptions_table.php'));

    expect($published)->toHaveCount(1)
        // The name has to match the package's exactly. The migrator keys files by
        // migration name and the application's path is appended last, so an
        // identical name shadows the package's copy and the table is created
        // once. A re-dated copy would be a second migration for the same table.
        ->and(basename($published[0]))->toBe('2026_01_01_000000_create_beam_push_subscriptions_table.php');

    @unlink($this->app->environmentFilePath());
    @unlink(config_path('beam.php'));
    array_map('unlink', $published);
});
