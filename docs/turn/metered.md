# <img src="/icons/cloud.svg" class="h1-icon" alt=""> Metered

Metered.ca's TURN service: a free tier, then per gigabyte. The lightest to set up.

## Set up

Create an app at metered.ca; note its **app name** (the subdomain) and **API key**.

```dotenv
BEAM_TURN=metered
METERED_APP=yourapp
METERED_KEY=…
```

## What Beam does

```
GET https://{app}.metered.live/api/v1/turn/credentials?apiKey={key}
```

The response is a list of ICE servers with a username and credential, which go into the participant's token. Metered does not take a TTL on this endpoint; its credentials expire on Metered's own schedule.

5-second timeout, one retry; on failure `TurnUnavailable` fires and the call proceeds on STUN alone. `beam:doctor` makes the same request and connects to the relay hosts it names.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
