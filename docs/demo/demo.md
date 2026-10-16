# <img src="/icons/play.svg" class="h1-icon" alt=""> Switchboard — Demo Application

## Introducing Switchboard: A Support Desk Built on Beam

Switchboard is a ready-to-run Laravel application that demonstrates every feature of **Beam** in a realistic support desk — agents, customers, tickets, and a "Call customer" button that rings an open tab through Broadcasting or a closed one through Web Push. It lives in [its own repository](https://github.com/realrashid/switchboard): clone it, bring up Reverb and coturn with one `docker compose up`, and place a call between two browsers — or between your laptop and your phone on the same Wi-Fi — in minutes.



![The agent's call screen in Switchboard, connected to the customer](/screenshots/switchboard-call.png)

After the call, the ticket keeps what happened — the duration the server measured and the file that crossed:

![The ticket's history after a call](/screenshots/switchboard-history.png)

---

## Features at a Glance

- **Tickets and calls** — An agent opens a ticket and presses *Call customer*. `Beam::call($ticket->customer)->video()->about(…)->ring()` rings the customer, and the ticket page becomes the call screen copied in by `beam:install --views`.

- **An open tab rings, a closed tab rings** — The customer's dashboard includes the incoming-call modal; a customer who pressed *Ring me when I'm away* once is rung through Web Push with **Answer** and **Decline** on the notification itself.

- **A PDF during the call** — The agent drags a file onto the call and it goes browser to browser; the customer accepts, a progress bar fills, and `FileTransferred` writes a line in the ticket.

- **Screen sharing** — One button; the camera comes back when sharing stops.

- **Call history from events** — `CallEnded`, `CallDeclined` and `FileTransferred` listeners write ticket events with the duration the server measured. Beam stores nothing; Switchboard stores what a support desk needs.

- **A real relay** — `docker-compose.yml` brings up the app, Reverb and coturn, so a phone on cellular connects through TURN exactly as it would in production. `beam:doctor` is wired to the admin page.

- **Standalone repository** — Switchboard installs `realrashid/beam` like any other dependency. If you keep a local checkout of the package beside it, a Composer `path` repository symlinks your working copy into `vendor/` instead.

---

## Getting Started with Switchboard

1. [Clone the Repository](/demo/get-started#step-1-clone-the-repository)
2. [Install Dependencies](/demo/get-started#step-2-install-dependencies)
3. [Bring Up the Services](/demo/get-started#step-3-bring-up-the-services)
4. [Place a Call](/demo/get-started#step-4-place-a-call)
5. [Try It on a Phone](/demo/get-started#step-5-try-it-on-a-phone)

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
