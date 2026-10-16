# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Beam, please report it privately rather
than opening a public issue.

Email **realrashid05@gmail.com** with a description of the issue, steps to reproduce,
and the package, PHP, Laravel and browser versions you tested against.

## Scope

Beam sits between your application's authorisation and two browsers' media. The most
valuable reports are about those boundaries:

- A token for one call authorising signals or a Broadcasting channel for another
- A user who is not a participant relaying a signal into a live call
- A TURN credential, VAPID private key or app secret reaching JavaScript, a broadcast
  payload, or a log line
- The completion, decline, timeout or heartbeat endpoints being callable for a call
  the caller is not part of
- A push subscription being registered or removed for another user
- A push endpoint that reaches the application's own network despite the checks on it

## By Design, Not a Vulnerability

- Media travels browser to browser over DTLS-SRTP; Beam adds no encryption beyond
  what WebRTC provides, and does not claim to.
- With no `beam.call` gate defined, any authenticated user may call any other. That
  is the documented default; `beam:doctor` warns about it in production.
- TURN relays media for a fifth of calls. Whoever runs the relay sees encrypted
  packets, not their contents.
- A push endpoint must be `https` and must not name a private or reserved address,
  `localhost`, or a single-label host, and `push.endpoints` narrows it to named
  services. That stops a subscription aimed at your own network; it is not a
  complete defence against a public hostname that resolves inward, and the
  allow-list is the answer for applications that need one.
