# <img src="/icons/cloud.svg" class="h1-icon" alt=""> Twilio

Twilio's Network Traversal Service: global relays, billed per gigabyte, tied to a Twilio account you may already have.

## Set up

From the Twilio console, the **Account SID** and **Auth Token**.

```dotenv
BEAM_TURN=twilio
TWILIO_SID=AC…
TWILIO_TOKEN=…
```

## What Beam does

```
POST https://api.twilio.com/2010-04-01/Accounts/{sid}/Tokens.json
Basic auth: sid:token
Ttl=3600
```

The response's `ice_servers` — STUN and TURN URLs with a temporary username and credential — go into the participant's token. `beam.ice.turn.drivers.twilio.ttl` sets the lifetime, capped at Twilio's maximum of 86400.

5-second timeout, one retry; on failure `TurnUnavailable` fires and the call proceeds on STUN alone. `beam:doctor` makes the same request and connects to the relay hosts it names.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
