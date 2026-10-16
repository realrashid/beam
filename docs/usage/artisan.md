# <img src="/icons/terminal.svg" class="h1-icon" alt=""> Artisan Commands

Three commands: one to install, one to diagnose, one that the `scheduler` timeout driver runs for you.

## `beam:install`

```bash
php artisan beam:install
php artisan beam:install --views
php artisan beam:install --migrations
php artisan beam:install --force
```

Publishes `config/beam.php`; writes a VAPID key pair to `.env` once (`--force` regenerates it — every existing device subscription stops working, so do that on purpose); warns when Broadcasting is not configured.

It does **not** publish the migration, because it does not need to: Beam loads `beam_push_subscriptions` from the package, so `php artisan migrate` creates the table with nothing copied into your project. `--migrations` publishes it for an application that wants to edit it — and the copy keeps the package's filename on purpose, so that it shadows the original rather than becoming a second migration for the same table.

`--views` copies a working call screen (`resources/views/beam/call.blade.php`) and an incoming-call modal (`resources/views/beam/incoming.blade.php`), plus `resources/js/Pages/Beam/Call.vue` or `Call.jsx` when `package.json` has Vue or React, and prints the routes to wire them. Existing files are left alone without `--force`.

### Publishing on its own

Everything `beam:install` does is also a tag, for a deploy that would rather be explicit:

```bash
php artisan vendor:publish --tag=beam-config       # config/beam.php
php artisan vendor:publish --tag=beam-lang         # lang/vendor/beam/en/beam.php — what the ring says
php artisan vendor:publish --tag=beam-views        # the call screen and the incoming modal
php artisan vendor:publish --tag=beam-migrations   # only to edit the table; see above
```

## `about`

Beam adds a section to Laravel's own `php artisan about`, which is the quickest way to see what an application is set to do — and the first thing worth pasting into an issue:

```
  Beam ..........................................................................
  Client ................................................................ 8f2c1a
  Push store ................................................. not configured
  Registry cache ............................................................ redis
  Signaling ............................................................ controller
  Timeouts .................................................................. queue
  TURN driver .............................................................. coturn
  Version ................................................................. v1.0.0
```

`beam:doctor` is the other half of that pair: `about` says what is configured, the doctor says whether it works.

## `beam:doctor`

```bash
php artisan beam:doctor
```

```
  ✓ Broadcasting: Broadcasting via Reverb at 127.0.0.1:8080, which answers.
  ✓ Cache: Live calls are held in the [redis] cache, shared across servers. Keep cache:clear out of your deploy: it ends every call in progress.
  ✓ STUN: 2 STUN servers configured and resolving.
  ! TURN: No TURN relay is configured: calls behind symmetric NAT and most mobile carriers — roughly one in five — will not connect.
    → Set BEAM_TURN to coturn, cloudflare, twilio or metered and fill in its keys. TURN costs bandwidth; docs/guide/turn.md.
  ! Authorization: No beam.call ability is defined, so any signed-in user may ring any other.
    → Gate::define('beam.call', fn ($caller, $callee) => …) in a service provider; docs/usage/authorization.md.
  ✓ Web Push keys: VAPID keys are present and well-formed; subject mailto:you@example.com.
  ✓ Service worker: The push worker is served from https://app.test/beam/sw.js with the header that allows scope /.
  ✓ Web app manifest: A web app manifest with display: standalone is linked; iPhones can be rung once the app is on the Home Screen (outside the EU).
  ✓ Client version: beam.js and PHP both speak protocol 1; the asset URL carries a version hash, so browsers fetch a new build after an update.
  ✓ HTTPS: APP_URL is https (app.test); cameras, screens and push are allowed.
  ✓ Client events: Signaling uses the controller transport; client events are not needed.
  ✓ Timeouts: Unanswered calls time out through the redis queue; keep a worker running.

  Nothing is blocking a call.
```

![beam:doctor on the test rig, with its relay stopped](../public/screenshots/doctor.png)

Twelve checks, one line each: `` fine, `!` worth knowing, `` a call cannot work until it is fixed — and the exit code is 1 when anything is ``, so it can gate a deploy. Each non-green line is followed by what to do.

Two of the checks — the service worker and the web app manifest — fetch the app's own `APP_URL`. Run the doctor from a terminal, or from a queued job; called from inside a web request served by `php artisan serve`, those two can time out waiting for a worker that is busy serving the request itself. Production servers do not have that problem.

An application can add its own line:

```php
use RealRashid\Beam\Commands\DoctorCommand;

DoctorCommand::add(RecordingBucketCheck::class);   // implements RealRashid\Beam\Support\Doctor\Check
```

## `beam:sweep`

```bash
php artisan beam:sweep
```

Declines rings nobody answered and ends calls nobody is in any more. With `call.timeout.driver` set to `scheduler`, Beam registers it on the scheduler every minute and you never run it by hand; with the `queue` or `browser` driver it is still there for a one-off tidy.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
