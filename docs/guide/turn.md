# <img src="/icons/relay.svg" class="h1-icon" alt=""> TURN Costs Money

This page is early on purpose. Everything else in Beam is free to run; a relay is not, and the first call that needs one will be a customer's, not yours.

## Why a call needs a relay

Two browsers on the open internet connect directly once STUN has told each its public address. Behind symmetric NAT, a corporate firewall or most mobile carriers, there is no direct path: every packet has to go through a **TURN** server that both sides can reach. In practice that is roughly **one call in five** — and never one of the calls you place while testing, because two browsers on the same network always find a direct path. The failing ones are a customer's, on a phone or behind an office firewall.

Without a TURN driver those calls fail, and they fail quietly: the browsers exchange offers, gather candidates, and never connect. `beam:doctor` warns about it; this page says it first.

## What it costs

A relayed call sends every byte of audio and video through your relay, in both directions. Budget roughly:

- Audio only: ~50–100 kbit/s per direction.
- Video (640×480): ~500 kbit/s – 1 Mbit/s per direction.
- Screen share: similar to video; more for busy screens.

So a relayed one-hour video call is in the region of 500 MB–1 GB of relay bandwidth. Cloud relays bill per gigabyte; a self-hosted coturn costs a small VM and its egress.

## The drivers

Beam ships four, and adding your own is a class:

| Driver | What it is | Set up |
|---|---|---|
| [`coturn`](/turn/coturn) | Self-hosted, open source, one shared secret | a VM, two ports, `COTURN_URLS` and `COTURN_SECRET` |
| [`cloudflare`](/turn/cloudflare) | Cloudflare Realtime TURN | a key id and an API token |
| [`twilio`](/turn/twilio) | Twilio Network Traversal Service | account SID and auth token |
| [`metered`](/turn/metered) | Metered.ca TURN | app name and API key |

Every driver mints a **per-participant, time-limited credential** on the server at `ring()` time and folds it into the token. A relay's secret or API key never reaches a browser, and `Redactor` keeps credentials out of your logs.

```dotenv
BEAM_TURN=coturn
COTURN_URLS=turn:turn.yourapp.com:3478,turns:turn.yourapp.com:5349
COTURN_SECRET=change-me
```

Switching providers is one line. Nothing in your PHP or JavaScript mentions a relay.

## Check it before a customer does

```bash
php artisan beam:doctor
```

The TURN line mints a credential through your driver and connects to every relay host it names. If minting fails, the driver's keys are wrong; if a host does not answer, its ports are closed. Beam also fires `TurnUnavailable` when a driver throws during a ring, and the call still goes ahead on STUN alone — degraded, not dead.

## When you can skip it

If every caller is on the same corporate network, or you are running a demo on one Wi-Fi, STUN alone connects. Everywhere else, put a relay in before you go live.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
