# <img src="/icons/relay.svg" class="h1-icon" alt=""> TURN Overview

A TURN driver mints, for each participant of each call, a short-lived credential for a relay — and folds it into the token. Four ship; adding one is a class. Which you choose is one line of `.env`, and nothing else in your application knows.

| Driver | `BEAM_TURN` | You need | Credential | Page |
|---|---|---|---|---|
| coturn (self-hosted) | `coturn` | a server, `COTURN_URLS`, `COTURN_SECRET` | computed locally: HMAC-SHA1 of `expiry:user` with the shared secret — no HTTP call | [coturn](/turn/coturn) |
| Cloudflare Realtime TURN | `cloudflare` | `CF_TURN_KEY_ID`, `CF_TURN_API_TOKEN` | one API call per ring | [Cloudflare](/turn/cloudflare) |
| Twilio NTS | `twilio` | `TWILIO_SID`, `TWILIO_TOKEN` | one API call per ring | [Twilio](/turn/twilio) |
| Metered | `metered` | `METERED_APP`, `METERED_KEY` | one API call per ring | [Metered](/turn/metered) |

## What every driver guarantees

- **Per participant, per call.** The caller's token and the callee's token carry different credentials, each valid for the driver's `ttl` (an hour by default). A leaked token is a relay credential for one person for one hour, not your account.
- **Never in JavaScript.** The relay's secret or API key lives in `.env`; `Redactor` strips credentials from anything Beam logs.
- **Degrades, does not die.** A vendor API that times out (5 s, two attempts) or refuses fires `TurnUnavailable` on your server and the call proceeds on STUN alone. Alert on that event.
- **Checked by the doctor.** `php artisan beam:doctor` mints a credential through your driver and connects to every relay host it names.

## Your own driver

```php
use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Facades\Beam;

final class XirsysDriver implements TurnDriver
{
    public function name(): string { return 'xirsys'; }

    /** @return array<int, array{urls: array<int, string>|string, username?: string, credential?: string}> */
    public function credentialsFor(int|string $userId): array { /* … */ }
}

// A service provider
Beam::extendTurn('xirsys', fn ($app, array $config) => new XirsysDriver(
    $app->make(HttpClient::class), $config['ident'], $config['secret'],
));
```

The closure receives the container and this driver's own config block — `beam.ice.turn.drivers.xirsys` — the way `Storage::extend()` and `Cache::extend()` do, so a driver can be built with whatever the application already has bound. A class string works too (`Beam::extendTurn('xirsys', XirsysDriver::class)`), resolved from the container, or through a static `fromConfig(array $config): self` if it has one, which is how the four shipped drivers are built.

```php
// config/beam.php
'turn' => [
    'default' => env('BEAM_TURN', 'xirsys'),
    'drivers' => [
        'xirsys' => ['ident' => env('XIRSYS_IDENT'), 'secret' => env('XIRSYS_SECRET')],
    ],
],
```

## Injecting the configured driver

The three driver contracts are bound in the container, so a class of yours can ask
for the one the configuration selected instead of reaching through the facade:

```php
use RealRashid\Beam\Contracts\TurnDriver;

final class RelayHealth
{
    public function __construct(private TurnDriver $turn) {}
}
```

`TurnDriver` resolves `Beam::turn()`, `TimeoutDriver` resolves `Beam::timeouts()`,
and `PushStore` resolves the configured push subscription store. They are `bind`,
not `singleton`, so changing `beam.ice.turn.default` at runtime — a test swapping
drivers, for instance — is picked up by the next resolution. Binding your own
implementation over one of the contracts wins for anything that injects it,
while `Beam::turn()` keeps returning the configured driver.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
