---
layout: home

hero:
  name: Beam
  text: Real-time calls and file transfer for Laravel
  tagline: Audio and video calls, screen sharing, and files that go browser to browser — with a call or without one. PHP authorises, rings and mints TURN credentials; the browser joins with one token and two lines.
  image:
    src: /mark.svg
    alt: Beam
  actions:
    - theme: brand
      text: Get Started
      link: /guide/introduction
    - theme: alt
      text: See the Demo
      link: /demo/demo

features:
  - icon:
      src: /icons/phone.svg
      width: 26
      height: 26
    title: Calls, one line each side
    details: Beam::call($user)->video()->ring() in PHP. Beam.join(token) in the browser. Ringing, answer, decline and timeout handled; the token is the whole contract.
    link: /usage/calls
    linkText: Calls
  - icon:
      src: /icons/file.svg
      width: 26
      height: 26
    title: Files, browser to browser
    details: Beam.send(file).to($userId) — during a call, or with no call at all. Resumes after a drop, SHA-256 on both sides, never touches your storage.
    link: /usage/files
    linkText: File transfer
  - icon:
      src: /icons/bell.svg
      width: 26
      height: 26
    title: Rings a closed tab
    details: An open tab rings through Broadcasting; a closed one through Web Push, with Answer and Decline on the notification — or your own FCM and APNs channels.
    link: /usage/push
    linkText: Push
  - icon:
      src: /icons/relay.svg
      width: 26
      height: 26
    title: TURN, done properly
    details: One call in five cannot connect without a relay. coturn, Cloudflare, Twilio or Metered, one line of .env — per-participant credentials minted on the server.
    link: /guide/turn
    linkText: Why it costs money
  - icon:
      src: /icons/layers.svg
      width: 26
      height: 26
    title: Every frontend, the same two lines
    details: Blade with no build step, jQuery, Livewire, Inertia + Vue and Inertia + React. Adapters of forty lines around one framework-free class.
    link: /frontend/overview
    linkText: Frontends
  - icon:
      src: /icons/doctor.svg
      width: 26
      height: 26
    title: Headless, and a doctor
    details: No call UI ships — beam:install --views copies a starting point that is yours. beam:doctor checks twelve things and says what to fix.
    link: /guide/headless
    linkText: Headless by design
---
