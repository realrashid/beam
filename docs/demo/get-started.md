# <img src="/icons/rocket.svg" class="h1-icon" alt=""> Get Started with Switchboard

## Step 1: Clone the Repository

```bash
git clone https://github.com/realrashid/switchboard.git
cd switchboard
```

## Step 2: Install Dependencies

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

## Step 3: Bring Up the Services

Switchboard ships a `docker-compose.yml` with Reverb (signaling) and coturn (the relay). Its `.env.example` already points at both.

```bash
docker compose up -d
php artisan migrate --seed
php artisan beam:install
php artisan beam:doctor
php artisan serve
```

The seeder creates an agent (`agent@switchboard.test`) and a customer (`customer@switchboard.test`), both with the password `password`, and a ticket between them. The doctor should print twelve green lines; the one you might see yellow is *Authorization* until you read [its page](/usage/authorization).

## Step 4: Place a Call

1. In one browser, sign in as the agent, open the ticket, press **Call customer**.
2. In another browser (or a private window), sign in as the customer and stay on the dashboard — it rings. Answer.
3. Talk to yourself. Mute, share your screen, drop a PDF on the call. Hang up.
4. Back on the ticket: the call and the file are in its history, with the duration.

## Step 5: Try It on a Phone

Cameras need HTTPS away from localhost. Two ways:

- **[Laravel Herd](https://herd.laravel.com)** or **[lerd](https://github.com/realrashid/lerd)**: `herd secure switchboard` / `lerd secure` and open `https://switchboard.test` — then, on a phone on the same Wi-Fi, use your machine's LAN address with the same certificate installed on the phone.
- **[mkcert](https://github.com/FiloSottile/mkcert)**: `mkcert -install && mkcert 192.168.1.20` (your LAN address), and serve with a TLS proxy such as Caddy pointing at `php artisan serve`. Install mkcert's root on the phone.

Set `APP_URL` to the https address, `REVERB_HOST` to your LAN address, and restart. Sign in as the customer on the phone, press **Ring me when I'm away**, close the browser — and call from the laptop. The phone rings.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
