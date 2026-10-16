# <img src="/icons/users.svg" class="h1-icon" alt=""> Marketplace

In this example, we'll add "Call the seller" to a classifieds site built with Inertia and React. Neither side shares a phone number, a seller is reachable only while the listing is live, the seller's phone rings even with the app closed, and a missed call becomes a message in the conversation.

## Step 1: The Gate Is the Product

```php
<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('beam.call', fn (User $caller, User $callee) =>
            Listing::live()->where('seller_id', $callee->id)->exists()      // a buyer may call a seller with a live listing…
            || Conversation::between($caller, $callee)->exists());          // …and either side may call back within a conversation
    }
}
```

A delisted seller is unreachable a second later. A blocked buyer is refused at `ring()`, before anything is broadcast.

## Step 2: Ring From the Listing

```php
<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RealRashid\Beam\Facades\Beam;

class ListingCallController extends Controller
{
    public function store(Request $request, Listing $listing): Response
    {
        $call = Beam::call($listing->seller)
            ->audio()                                   // a marketplace call is a phone call
            ->about("About: {$listing->title}")         // what the seller sees before answering — the listing, not a stranger
            ->timeout(30)
            ->ring();

        return Inertia::render('Listings/Call', [
            'listing' => $listing->only('id', 'title', 'price'),
            'token' => $call->token,
        ]);
    }
}
```

## Step 3: The React Page

```jsx
// resources/js/Pages/Listings/Call.jsx
import { useBeam } from '@realrashid/beam/react';
import { router } from '@inertiajs/react';

export default function Call({ listing, token }) {
    const { local, remote, state, duration, muted, toggleMute, hangUp } = useBeam(token, { media: { audio: true, video: false } });

    const label = { ringing: 'Ringing…', connecting: 'Ringing the seller…', connected: 'On the call', reconnecting: 'Reconnecting…', ended: 'Call ended' }[state];

    return (
        <div className="mx-auto max-w-md p-6 text-center">
            <p className="text-sm text-slate-500">About</p>
            <h1 className="text-xl font-semibold">{listing.title}</h1>

            {/* Audio only: the elements carry the streams, and stay hidden. */}
            <video ref={local} autoPlay muted playsInline hidden />
            <video ref={remote} autoPlay playsInline hidden />

            <p className="mt-8 text-2xl">{label}</p>
            <p className="text-slate-500">{Math.floor(duration / 60)}:{String(duration % 60).padStart(2, '0')}</p>

            <div className="mt-8 flex justify-center gap-3">
                <button onClick={toggleMute} className="rounded-full bg-slate-200 px-5 py-2">{muted ? 'Unmute' : 'Mute'}</button>
                <button onClick={() => hangUp().then(() => router.visit(`/listings/${listing.id}`))} className="rounded-full bg-red-600 px-5 py-2 text-white">End</button>
            </div>
        </div>
    );
}
```

`useBeam(token, { media: { audio: true, video: false } })` opens the microphone only; the `<video>` elements still carry the audio and stay hidden.

## Step 4: The Seller Is on Their Phone

Sellers are rarely on the page, so the seller's dashboard offers push once:

```jsx
// resources/js/Pages/Dashboard.jsx (seller)
import { useEffect, useState } from 'react';
import { useIncoming } from '@realrashid/beam/react';

export default function Dashboard() {
    const [pushStatus, setPushStatus] = useState('');
    const [incoming, setIncoming] = useState(null);

    useEffect(() => { Beam.push.status().then(setPushStatus); }, []);
    useIncoming(setIncoming);   // rings here while the tab is open

    return (
        <>
            {pushStatus !== 'granted' && (
                <button onClick={() => Beam.push.subscribe().then(() => setPushStatus('granted'))}>
                     Ring my phone about my listings
                </button>
            )}
            {Beam.platform.isIOS && !Beam.platform.supportsPush() && <p>On iPhone, add this app to your Home Screen first.</p>}

            {incoming && (
                <div role="dialog">
                    <p><b>{incoming.from.name}</b> is calling — {incoming.about}</p>
                    <button onClick={() => incoming.answer({ media: { audio: true, video: false } }).then((call) => { /* attach or navigate */ setIncoming(null); })}>Answer</button>
                    <button onClick={() => incoming.decline().then(() => setIncoming(null))}>Decline</button>
                </div>
            )}
        </>
    );
}
```

With the app closed, `IncomingCallNotification` goes out as a Web Push — or to a native app through `routeNotificationForBeam()`:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForBeam(): array
    {
        return ['webpush', 'fcm'];   // the web app, and the native app if they have it
    }
}
```

**Answer** on the notification opens the route named `beam.join`, where `Beam.answerFromNotification()` joins:

```jsx
// resources/js/Pages/Listings/Join.jsx
import { useEffect, useState } from 'react';

export default function Join() {
    const [call, setCall] = useState(null);
    useEffect(() => { Beam.answerFromNotification({ media: { audio: true, video: false } }).then(setCall); }, []);
    return <p>{call ? 'On the call' : 'Joining…'}</p>;
}
```

## Step 5: The Conversation Remembers

```php
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $e) {
    Conversation::firstOrCreate(['a' => $e->call->caller->id, 'b' => $e->call->callee->id])
        ->messages()->create(['type' => 'call', 'seconds' => $e->duration, 'outcome' => $e->reason]);
});

Event::listen(CallDeclined::class, function (CallDeclined $e) {
    Conversation::firstOrCreate(['a' => $e->call->caller->id, 'b' => $e->call->callee->id])
        ->messages()->create(['type' => 'missed', 'outcome' => $e->reason]);   // "Ayesha tried to call about the bicycle"
});
```

## Step 6: Routes

```php
Route::post('/listings/{listing}/call', [ListingCallController::class, 'store'])->middleware('auth');
Route::get('/join/{call}', fn () => Inertia::render('Listings/Join'))->middleware('auth')->name('beam.join');
```

## How It Works

1. The buyer presses **Call the seller**. The gate allows it because the listing is live; `ring()` broadcasts to the seller's open tabs and pushes to their phone.
2. The seller sees the listing title, not a number, and answers from the dashboard or the notification.
3. Audio only: `useBeam()` opens the microphone alone on both sides; the call connects directly or through the relay.
4. Either side ends; `CallEnded` writes a message into their conversation with the duration. If the seller does not pick up in 30 s, `CallDeclined` writes a missed call instead — and the conversation now exists, so the seller may call back.
5. Neither side ever learns the other's phone number, and a listing that comes down takes the ability to call with it.

That's it! Calls between strangers, on your terms, with nothing to leak.
