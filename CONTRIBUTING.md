# Contributing

Thanks for taking the time. Contributions are welcome.

## Before you open a PR

```bash
composer install && npm install
composer test          # Pest
composer format        # Pint
composer analyse       # PHPStan
npm run typecheck && npm test && npm run build
npm run test:browser   # the two-browser rig — needs Reverb running from tests/Browser/app
```

## Both sides of the coin

Every change is checked from every side that applies: it works *and* it refuses
correctly; the PHP test *and* the browser spec; caller *and* callee; every config
option, not only the default. `PLAN.md` spells this out. A green Pest run alone has
twice hidden a bug only a browser showed, so a change that touches the client, the
token or signaling is not done until the rig says so.

## The token is the boundary

`src/` owns authorisation, secrets and who may call whom. `resources/js/` owns media,
peer connections and browser quirks. The token is the only thing that crosses. Do not
move responsibilities across that line; `STRUCTURE.md` explains why.

## The committed client

`resources/dist/beam.js` is committed so a Composer-only install works with no Node.
Run `npm run build` before committing a change to `resources/js/`; CI fails if the
committed file is stale.

## The browser rig

The Playwright specs in `tests/Browser/` drive a real Laravel app that is built outside this repository:

```bash
npm ci && npm run build
npm run rig:setup                       # creates ../beam-rig from a fresh Laravel skeleton plus tests/Browser/rig
npx playwright install --with-deps chromium
npx playwright test --project=chromium  # or firefox / webkit; add a spec name to run one
```

`tests/Browser/README.md` explains the pieces. Please do not commit the generated app.
