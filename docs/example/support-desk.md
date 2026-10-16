# <img src="/icons/phone.svg" class="h1-icon" alt=""> Support Desk

In this example, we'll build the most common use of Beam: a support desk where an agent opens a ticket, calls the customer with one click, shares a screen, sends a file inside the call, and the ticket keeps a record of what happened. Plain Blade, no build step for Beam — this is the [Switchboard](/demo/demo) demo, trimmed to its essentials.

## Step 1: Decide Who May Call Whom

```php
<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // An agent may ring a customer who has an open ticket. Nobody else may ring anyone.
        Gate::define('beam.call', fn (User $caller, User $callee) =>
            $caller->isAgent() && Ticket::open()->where('customer_id', $callee->id)->exists());
    }
}
```

## Step 2: Ring From the Ticket

```php
<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RealRashid\Beam\Facades\Beam;

class CallController extends Controller
{
    public function ring(Request $request, Ticket $ticket): View
    {
        abort_unless($request->user()->isAgent(), 403);

        $call = Beam::call($ticket->customer)
            ->video()
            ->about("Ticket #{$ticket->id}: {$ticket->subject}")   // what the customer sees before answering
            ->timeout(45)
            ->ring();

        return view('calls.show', ['ticket' => $ticket, 'token' => $call->token]);
    }
}
```

`ring()` checks the gate, mints the tokens, broadcasts to the customer's open tabs, sends a push to their closed ones, and schedules the timeout. The view gets a token and nothing else.

## Step 3: The Agent's Call Screen

```blade
{{-- resources/views/calls/show.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="grid gap-6 md:grid-cols-[1fr_300px]">
    <div class="relative overflow-hidden rounded-xl bg-slate-900 text-slate-100">
        <video id="beam-remote" autoplay playsinline class="aspect-video w-full bg-black"></video>
        <video id="beam-local" autoplay muted playsinline class="absolute right-3 top-3 w-40 rounded-lg bg-black"></video>
        <div class="absolute left-3 top-3 rounded-full bg-black/60 px-3 py-1 text-sm"><span data-beam="state">ringing</span> · <span data-beam="duration">0:00</span></div>
        <div data-beam="banner" hidden class="absolute left-3 top-12 rounded bg-amber-500 px-3 py-1 text-sm text-black">Reconnecting…</div>

        <div class="flex flex-wrap justify-center gap-2 p-3">
            <button data-beam="mute" class="rounded-full bg-slate-700 px-4 py-2 text-sm">Mute</button>
            <button data-beam="camera" class="rounded-full bg-slate-700 px-4 py-2 text-sm">Camera off</button>
            <button data-beam="share" class="rounded-full bg-slate-700 px-4 py-2 text-sm">Share screen</button>
            <label class="cursor-pointer rounded-full bg-slate-700 px-4 py-2 text-sm"> Send a file <input id="file" type="file" hidden></label>
            <button data-beam="hangup" class="rounded-full bg-red-600 px-4 py-2 text-sm">End</button>
        </div>
        <p id="transfer" class="px-3 pb-3 text-sm text-slate-400"></p>
    </div>

    <aside class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Ticket #{{ $ticket->id }}</p>
        <p class="mt-1 font-medium">{{ $ticket->subject }}</p>
        <p class="mt-3 text-sm">Calling <b>{{ $ticket->customer->name }}</b></p>
    </aside>
</div>
@endsection

@push('scripts')
<script>
    (async () => {
        const $ = (name) => document.querySelector(`[data-beam="${name}"]`);
        const clock = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

        const call = await Beam.join(@js($token));
        call.attach({ local: '#beam-local', remote: '#beam-remote' });

        call.on('state', (s) => { $('state').textContent = s; $('banner').hidden = s !== 'reconnecting'; });
        call.on('tick', ({ seconds }) => { $('duration').textContent = clock(seconds); });
        call.on('muted', (m) => { $('mute').textContent = m ? 'Unmute' : 'Mute'; });
        call.on('camera', (on) => { $('camera').textContent = on ? 'Camera off' : 'Camera on'; });
        call.on('sharing', (on) => { $('share').textContent = on ? 'Stop sharing' : 'Share screen'; });
        call.on('ended', ({ reason, duration }) => {
            $('state').textContent = `ended (${reason}, ${clock(duration)})`;
            setTimeout(() => { window.location = @js(route('tickets.show', $ticket)); }, 2500);
        });

        $('mute').onclick = () => call.toggleMute();
        $('camera').onclick = () => (call.isCameraOn ? call.cameraOff() : call.cameraOn());
        $('share').onclick = () => (call.isSharing ? call.stopSharing() : call.shareScreen()).catch((e) => { $('state').textContent = e.message; });
        $('hangup').onclick = () => call.hangUp();

        // A file inside the call: the call's own connection carries it.
        document.getElementById('file').onchange = async (e) => {
            const transfer = Beam.send(e.target.files[0]).to(@js($ticket->customer->id));
            transfer.on('progress', ({ percent }) => { document.getElementById('transfer').textContent = `${e.target.files[0].name} · ${percent}%`; });
            try { await transfer.start(); document.getElementById('transfer').textContent = 'Delivered '; }
            catch (err) { document.getElementById('transfer').textContent = err.reason === 'declined' ? 'Declined' : err.message; }
        };
    })();
</script>
@endpush
```

## Step 4: The Customer Is Rung Anywhere

Every page the customer keeps open includes the incoming-call modal and the file-offer box. Put them in the layout once; `beam:install --views` copied `beam.incoming` for you.

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Support</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])   {{-- window.Echo --}}
    @beamAssets                                                {{-- window.Beam --}}
</head>
<body>
    @yield('content')

    @auth
        @include('beam.incoming')
        @include('partials.file-offers')
    @endauth
    @stack('scripts')
</body>
</html>
```

```blade
{{-- resources/views/partials/file-offers.blade.php --}}
<div id="file-offer" hidden class="fixed bottom-6 right-6 w-80 rounded-xl border bg-white p-4 shadow-lg">
    <p class="text-sm"><b data-file="from"></b> is sending <b data-file="name"></b></p>
    <div class="mt-3 h-2 rounded bg-slate-100"><div data-file="bar" class="h-full w-0 bg-emerald-500"></div></div>
    <button data-file="accept" class="mt-3 rounded-full bg-emerald-600 px-4 py-1.5 text-sm text-white">Accept</button>
    <button data-file="decline" class="mt-3 rounded-full bg-slate-200 px-4 py-1.5 text-sm">Decline</button>
</div>
<script>
    Beam.onFileOffered((offer) => {
        const box = document.getElementById('file-offer');
        const $ = (n) => box.querySelector(`[data-file="${n}"]`);
        $('from').textContent = offer.from.name;
        $('name').textContent = offer.name;
        box.hidden = false;
        $('decline').onclick = () => { box.hidden = true; offer.decline(); };
        $('accept').onclick = async () => {
            offer.on('progress', ({ percent }) => { $('bar').style.width = percent + '%'; });
            const blob = await offer.accept();
            Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: offer.name }).click();
            box.hidden = true;
        };
    });
</script>
```

## Step 5: Keep the History

Beam stores nothing. Three listeners write what a support desk wants to keep:

```php
<?php

namespace App\Listeners;

use App\Models\Ticket;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Events\FileTransferred;

class RecordCallOnTicket
{
    public function ended(CallEnded $e): void
    {
        Ticket::fromCallAbout($e->call->about)?->events()->create([
            'actor_id' => $e->call->caller->id,
            'type' => 'call',
            'outcome' => $e->reason,            // hangup | connection-lost
            'seconds' => $e->duration,          // measured on the server
        ]);
    }

    public function declined(CallDeclined $e): void
    {
        Ticket::fromCallAbout($e->call->about)?->events()->create([
            'actor_id' => $e->call->caller->id,
            'type' => 'call',
            'outcome' => $e->reason,            // declined | timeout
        ]);
    }

    public function transferred(FileTransferred $e): void
    {
        Ticket::open()->where('customer_id', $e->to->id)->latest()->first()?->events()->create([
            'actor_id' => $e->from->id,
            'type' => 'file',
            'payload' => ['name' => $e->file->name, 'size' => $e->file->size],
        ]);
    }
}
```

```php
// AppServiceProvider::boot()
Event::listen(CallEnded::class, [RecordCallOnTicket::class, 'ended']);
Event::listen(CallDeclined::class, [RecordCallOnTicket::class, 'declined']);
Event::listen(FileTransferred::class, [RecordCallOnTicket::class, 'transferred']);
```

`Ticket::fromCallAbout()` reads the ticket id back out of the `about` line — `preg_match('/^Ticket #(\d+):/', $about, $m)` — which is why Step 2 formats it that way.

## Step 6: Routes

```php
use App\Http\Controllers\CallController;

Route::post('/tickets/{ticket}/call', [CallController::class, 'ring'])->name('tickets.call')->middleware('auth');
Route::get('/join/{call}', fn () => view('calls.join'))->name('beam.join')->middleware('auth');   // where a push notification's Answer lands
```

## How It Works

1. The agent presses **Call customer**. The gate says yes; `ring()` mints two tokens and broadcasts `IncomingCall` on the customer's private channel.
2. If the customer has a tab open, the modal shows the ticket line and they answer; if not, `IncomingCallNotification` goes out as a Web Push and **Answer** on it opens `/join/{call}`, where `Beam.answerFromNotification()` joins.
3. The two browsers exchange offer, answer and candidates through your server and connect — directly, or through the [relay](/guide/turn) when a direct path does not exist.
4. Mute, camera and screen share are methods on the call; each fires an event, so the buttons follow the call, not the click.
5. A file dropped on the call travels over the same connection and never touches the server; `FileTransferred` fires when the receiver has verified it.
6. Either side hangs up; `CallEnded` fires with the duration the server measured, and the listener writes it into the ticket. If nobody answers, `CallDeclined` fires with reason `timeout` instead.

That's it! You now have a support desk that calls its customers, and remembers.
