# <img src="/icons/server.svg" class="h1-icon" alt=""> coturn

The open-source relay, on a machine you run. No per-minute bill — a small VM, its egress, and two ports. Beam speaks coturn's `use-auth-secret` scheme, so the server and your application share one secret and never talk to each other.

## The server

```bash
apt install coturn
```

```ini
# /etc/turnserver.conf
listening-port=3478
tls-listening-port=5349
realm=turn.yourapp.com
use-auth-secret
static-auth-secret=change-me-to-something-long
cert=/etc/letsencrypt/live/turn.yourapp.com/fullchain.pem
pkey=/etc/letsencrypt/live/turn.yourapp.com/privkey.pem
min-port=49152
max-port=65535
no-cli
```

Open **3478 UDP and TCP**, **5349 TCP** (TLS, for networks that block everything else), and the relay port range **49152–65535 UDP**. Set `external-ip` when the machine sits behind NAT (a cloud VM with a public IP mapped to a private one).

Or the container, the way Beam's own test rig runs it:

```bash
docker run -d --network host coturn/coturn \
  --use-auth-secret --static-auth-secret=change-me --realm=turn.yourapp.com \
  --listening-port=3478 --no-tls --no-cli
```

## The application

```dotenv
BEAM_TURN=coturn
COTURN_URLS=turn:turn.yourapp.com:3478?transport=udp,turn:turn.yourapp.com:3478?transport=tcp,turns:turn.yourapp.com:5349
COTURN_SECRET=change-me-to-something-long
```

`COTURN_URLS` is comma-separated; list UDP first, then TCP, then TLS, and the browser tries them in order. `beam.ice.turn.drivers.coturn.ttl` (3600) is how long a minted credential lives.

## How the credential works

For each participant Beam computes:

```
username   = "<unix time + ttl>:<user id>"
credential = base64( HMAC-SHA1( static-auth-secret, username ) )
```

coturn recomputes the HMAC with its own copy of the secret and refuses a username whose time has passed. No request leaves your server to mint one, so `ring()` costs nothing extra and works when coturn is down (the call then proceeds on STUN, and `beam:doctor`'s reachability line tells you).

## Check it

```bash
php artisan beam:doctor
```

The TURN line mints a credential and opens a TCP connection to each host and port in `COTURN_URLS`. For a stronger check, place a call with `iceTransportPolicy: 'relay'` — `Beam.join(token, { iceTransportPolicy: 'relay' })` — from two networks and confirm it connects; that is exactly what the repository's `turn.spec.ts` does against a coturn container.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
