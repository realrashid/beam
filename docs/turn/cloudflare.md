# <img src="/icons/cloud.svg" class="h1-icon" alt=""> Cloudflare

Cloudflare Realtime TURN: anycast relays close to every user, billed per gigabyte, no server to run. Beam asks Cloudflare for a credential at `ring()` time.

## Set up

In the Cloudflare dashboard, **Calls → TURN → Create**. Copy the **Key ID** and the **API token**.

```dotenv
BEAM_TURN=cloudflare
CF_TURN_KEY_ID=…
CF_TURN_API_TOKEN=…
```

## What Beam does

```
POST https://rtc.live.cloudflare.com/v1/turn/keys/{key_id}/credentials/generate-ice-servers
Authorization: Bearer {token}
{"ttl": 3600}
```

The response's `iceServers` — Cloudflare's STUN and TURN URLs with a username and credential valid for `ttl` seconds — go into the participant's token. `beam.ice.turn.drivers.cloudflare.ttl` sets the lifetime.

The request has a 5-second timeout and one retry. If Cloudflare does not answer, `TurnUnavailable` fires with the reason and the call proceeds on STUN alone. `beam:doctor` makes the same request and connects to the relay hosts it names.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
