<?php

declare(strict_types=1);

namespace RealRashid\Beam;

use Composer\InstalledVersions;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RealRashid\Beam\Commands\DoctorCommand;
use RealRashid\Beam\Commands\InstallCommand;
use RealRashid\Beam\Commands\SweepCommand;
use RealRashid\Beam\Contracts\PushSender;
use RealRashid\Beam\Contracts\PushStore;
use RealRashid\Beam\Contracts\Signaler;
use RealRashid\Beam\Contracts\TimeoutDriver;
use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Http\Controllers\AssetController;
use RealRashid\Beam\Push\Presence;
use RealRashid\Beam\Push\Subscriptions;
use RealRashid\Beam\Push\WebPushChannel;
use RealRashid\Beam\Push\WebPushSender;
use RealRashid\Beam\Signaling\BroadcastSignaler;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Support\Gate;
use RealRashid\Beam\Support\Redactor;
use Throwable;

/**
 * @author Rashid Ali <realrashid05@gmail.com>
 */
class BeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam.php', 'beam');

        $this->app->singleton(BeamManager::class, fn ($app) => new BeamManager($app, $app['config']));
        $this->app->alias(BeamManager::class, 'beam');
        $this->app->singleton(CallRegistry::class);
        $this->app->singleton(Gate::class);
        $this->app->bind(Signaler::class, BroadcastSignaler::class);
        $this->app->singleton(Subscriptions::class);
        $this->app->singleton(Presence::class);
        $this->app->bind(PushSender::class, WebPushSender::class);

        // The driver contracts, bound so an application can typehint one —
        // which is what a contract is for. Each resolves whatever the config
        // selected, through the manager.
        $this->app->bind(TurnDriver::class, fn ($app) => $app->make(BeamManager::class)->turn());
        $this->app->bind(TimeoutDriver::class, fn ($app) => $app->make(BeamManager::class)->timeouts());
        $this->app->bind(PushStore::class, fn ($app) => $app->make(Subscriptions::class)->store());
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'beam');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'beam');
        Blade::directive('beamAssets', fn () => "<?php echo view('beam::assets', ['version' => \RealRashid\Beam\Http\Controllers\AssetController::version()])->render(); ?>");

        // The application's TrimStrings middleware trims every string input,
        // and an SDP must end with CRLF: trimmed, Chrome refuses it with
        // "Failed to parse SessionDescription". Found by the rig on the
        // controller transport; the relay test asserts the SDP is byte-identical.
        TrimStrings::except(['message.sdp.sdp']);

        $this->registerRoutes();
        $this->registerChannels();
        $this->registerAboutCommand();

        Notification::resolved(fn (ChannelManager $manager) => $manager->extend('webpush', fn ($app) => $app->make(WebPushChannel::class)));

        if ($this->app['config']->get('beam.push.store', 'database') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Registered for web requests too: an admin page may Artisan::call('beam:doctor').
        $this->commands([SweepCommand::class, InstallCommand::class, DoctorCommand::class]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam.php' => config_path('beam.php'),
            ], 'beam-config');

            // The migration is loaded from the package, so nothing needs to be
            // published for `migrate` to create the table. Publishing is for an
            // application that wants to edit it.
            //
            // The published copy MUST keep this filename. The migrator keys
            // files by migration name and the application's path is appended
            // last, so an identically named copy shadows the package's and the
            // table is created once. publishesMigrations() would re-date the
            // copy in any application with database.migrations.update_date_on_publish
            // (the default) — two names, two runs, "table already exists".
            // tests/Push/MigrationPublishTest.php holds this.
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'beam-migrations');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/beam'),
            ], 'beam-lang');

            // The starter screens, for people who would rather publish than run
            // `beam:install --views`. Either way they are the application's from
            // then on; Beam never renders them.
            $this->publishes([
                __DIR__.'/../stubs/call.blade.php' => resource_path('views/beam/call.blade.php'),
                __DIR__.'/../stubs/incoming.blade.php' => resource_path('views/beam/incoming.blade.php'),
            ], 'beam-views');
        }

        if ($this->app['config']->get('beam.call.timeout.driver') === 'scheduler') {
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('beam:sweep')->everyMinute());
        }
    }

    /**
     * `php artisan about` is where someone looks first, and where they paste
     * from into an issue: what this application is configured to do. The doctor
     * says whether it works; this says what it is.
     */
    protected function registerAboutCommand(): void
    {
        AboutCommand::add('Beam', fn () => array_filter([
            'Version' => static::version(),
            'Signaling' => (string) config('beam.signaling.transport', 'controller'),
            'TURN driver' => (string) config('beam.ice.turn.default', 'none'),
            'Timeouts' => (string) config('beam.call.timeout.driver', 'queue'),
            'Push store' => config('beam.push.vapid.public') ? (string) config('beam.push.store', 'database') : 'not configured',
            'Registry cache' => (string) config('cache.default', 'file'),
            'Client' => AssetController::version(),
        ]));
    }

    /** The installed version, for `php artisan about`. */
    public static function version(): string
    {
        return InstalledVersions::isInstalled('realrashid/beam')
            ? (string) InstalledVersions::getPrettyVersion('realrashid/beam')
            : 'dev';
    }

    protected function registerRoutes(): void
    {
        $config = $this->app['config']->get('beam.routes', []);
        $prefix = $config['prefix'] ?? 'beam';
        $middleware = (array) ($config['middleware'] ?? ['web', 'auth']);

        if (! empty($config['throttle'])) {
            $middleware[] = 'throttle:'.$config['throttle'];
        }

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__.'/../routes/web.php');

        // What a browser reaches without a session: the client, the worker, and
        // the decline the worker posts when a notification is tapped. They get
        // there by being registered without the application's middleware rather
        // than by subtracting `auth` from it — withoutMiddleware() compares
        // resolved names, so it never matches `auth:web` or `auth:sanctum`, and
        // those applications would serve a login redirect where the browser
        // expects JavaScript. tests/Http/AssetControllerTest.php holds this.
        Route::prefix($prefix)
            ->middleware((array) ($config['asset_middleware'] ?? []))
            ->group(__DIR__.'/../routes/sessionless.php');
    }

    /**
     * Channel authorisation comes from state, not from the token: Echo's auth
     * request carries only socket_id and channel_name, and Beam is handed the
     * application's existing Echo instance. The registry says who is in what.
     */
    protected function registerChannels(): void
    {
        // Deferred until something resolves the broadcast manager — a broadcast,
        // or an Echo auth request. Broadcast::channel() goes through
        // BroadcastManager::__call to the driver, so registering at boot would
        // construct a Pusher or Reverb client on every request, including the
        // ones that never broadcast. (Laravel's own channels.php is loaded at
        // boot; deferring costs nothing and is the better of the two.)
        $this->callAfterResolving(BroadcastManager::class, fn () => $this->defineChannels());
    }

    /**
     * Channel authorisation comes from state, not from the token: Echo's auth
     * request carries only socket_id and channel_name. The registry says who is
     * in what.
     */
    protected function defineChannels(): void
    {
        // In an application whose Broadcasting driver is configured but not
        // installed (BROADCAST_CONNECTION=reverb with no laravel/reverb, say)
        // this throws — and a package must not take the host down with a stack
        // trace pointing at itself. Register what we can, say what went wrong,
        // let beam:doctor explain.
        try {
            Broadcast::channel('beam.user.{id}', fn (Authenticatable $user, string $id) => (string) $user->getAuthIdentifier() === $id);

            Broadcast::channel('beam.call.{id}', fn (Authenticatable $user, string $id) => $this->app->make(CallRegistry::class)
                ->isParticipant(Channels::call($id), $user->getAuthIdentifier()));

            Broadcast::channel('beam.room.{name}', fn (Authenticatable $user, string $name) => $this->app->make(CallRegistry::class)
                ->isParticipant(Channels::room($name), $user->getAuthIdentifier()));
        } catch (Throwable $e) {
            Log::warning('Beam could not register its Broadcasting channels; calls will not connect until Broadcasting works. Run `php artisan beam:doctor`.', Redactor::scrub([
                'error' => $e->getMessage(),
            ]));
        }
    }
}
