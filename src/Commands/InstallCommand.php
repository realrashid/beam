<?php

declare(strict_types=1);

namespace RealRashid\Beam\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * beam:install — config, the push migration when the store is database, and
 * VAPID keys written to .env once. --views copies a call screen and an
 * incoming-call modal into the application (and a Vue or React page when
 * package.json says the app has one); from then on they are the app's.
 */
final class InstallCommand extends Command
{
    protected $signature = 'beam:install
        {--views : Copy a call screen and an incoming-call modal into the app}
        {--migrations : Publish the push-subscription migration, to edit it}
        {--force : Overwrite published files and existing VAPID keys}';

    protected $description = 'Publish Beam\'s config and migration, generate VAPID keys for Web Push, and optionally copy starter views';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'beam-config', '--force' => (bool) $this->option('force')]);

        // Not published by default: the migration is loaded from the package, so
        // `migrate` creates the table without a copy in the application. --migrations
        // is for someone who wants to edit it (and the copy must keep its filename —
        // see the provider).
        if ($this->option('migrations') && config('beam.push.store', 'database') === 'database') {
            $this->call('vendor:publish', ['--tag' => 'beam-migrations', '--force' => (bool) $this->option('force')]);
        }

        $this->writeVapidKeys();

        if (config('broadcasting.default', 'null') === 'null' || config('broadcasting.default') === 'log') {
            $this->components->warn('Broadcasting is not configured; Beam signals over it. Run php artisan install:broadcasting (Reverb), or set up Pusher or Ably.');
        }

        $copied = $this->option('views') ? $this->copyViews() : [];

        $this->newLine();
        $this->components->info('Beam is installed.');
        foreach ($copied as $file) {
            $this->line("  Created <comment>{$file}</comment>");
        }
        if ($copied !== []) {
            $this->routesSnippet($copied);
        }
        $this->line('  Next: <comment>php artisan migrate</comment>, then <comment>php artisan beam:doctor</comment>.');
        $this->line('  Docs: https://realrashid.github.io/beam/');

        return self::SUCCESS;
    }

    /**
     * @return list<string> paths relative to the application root
     */
    private function copyViews(): array
    {
        $stubs = dirname(__DIR__, 2).'/stubs';
        $targets = [
            'call.blade.php' => resource_path('views/beam/call.blade.php'),
            'incoming.blade.php' => resource_path('views/beam/incoming.blade.php'),
        ];

        $packageJson = base_path('package.json');
        $deps = is_file($packageJson) ? (array) (json_decode((string) file_get_contents($packageJson), true) ?? []) : [];
        $has = fn (string $dep): bool => isset($deps['dependencies'][$dep]) || isset($deps['devDependencies'][$dep]);

        if ($has('vue')) {
            $targets['call.vue'] = resource_path('js/Pages/Beam/Call.vue');
        }
        if ($has('react')) {
            $targets['Call.jsx'] = resource_path('js/Pages/Beam/Call.jsx');
        }

        $copied = [];
        foreach ($targets as $stub => $target) {
            $relative = str_replace(base_path().'/', '', $target);

            if (is_file($target) && ! $this->option('force')) {
                $this->components->warn("{$relative} exists; left as it is (use --force to overwrite).");

                continue;
            }

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            copy("{$stubs}/{$stub}", $target);
            $copied[] = $relative;
        }

        return $copied;
    }

    /**
     * @param  list<string>  $copied
     */
    private function routesSnippet(array $copied): void
    {
        $this->newLine();
        $this->line('  Wire the screen to a call, for example in routes/web.php:');
        $this->newLine();
        $this->line("    Route::post('/call/{user}', function (User \$user) {");
        $this->line('        $call = Beam::call($user)->video()->ring();');
        $this->line('');
        $this->line("        return view('beam.call', ['token' => \$call->token]);");
        $this->line("    })->middleware('auth');");
        $this->newLine();
        $this->line("  And @include('beam.incoming') on any page a user keeps open, under @beamAssets in <head>.");

        if (array_filter($copied, fn (string $f) => str_ends_with($f, '.vue') || str_ends_with($f, '.jsx'))) {
            $this->line("  For Inertia: return Inertia::render('Beam/Call', ['token' => \$call->token]);");
        }
    }

    private function writeVapidKeys(): void
    {
        $env = $this->laravel->environmentFilePath();

        if (! is_file($env)) {
            $this->components->warn('No .env file; VAPID keys were not written. Generate them with Minishlink\WebPush\VAPID::createVapidKeys().');

            return;
        }

        $contents = (string) file_get_contents($env);

        if (preg_match('/^BEAM_VAPID_PUBLIC=.+$/m', $contents) && ! $this->option('force')) {
            $this->components->info('VAPID keys already present in .env; left as they are.');

            return;
        }

        if (! class_exists(VAPID::class)) {
            $this->components->info('Web Push is optional and its package is not installed; no VAPID keys were generated. Calls between open tabs need nothing more. To ring a closed tab: composer require minishlink/web-push, then php artisan beam:install --force.');

            return;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->components->warn("VAPID keys could not be generated ({$e->getMessage()}); Web Push will stay off until BEAM_VAPID_PUBLIC and BEAM_VAPID_PRIVATE are set.");

            return;
        }
        $contents = preg_replace('/^BEAM_VAPID_(PUBLIC|PRIVATE)=.*$\n?/m', '', $contents);
        $contents = rtrim((string) $contents)."\n\nBEAM_VAPID_PUBLIC={$keys['publicKey']}\nBEAM_VAPID_PRIVATE={$keys['privateKey']}\n";

        file_put_contents($env, $contents);
        $this->components->info('VAPID keys generated and written to .env.');
    }
}
