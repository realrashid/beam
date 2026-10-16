# <img src="/icons/door.svg" class="h1-icon" alt=""> Telehealth

In this example, we'll build appointment calls for a clinic with Inertia and Vue: creating a room when an appointment is booked, emailing a link that only the doctor and the patient can use, joining with `useBeam()`, and closing the room when the appointment ends — with attendance recorded from events.

## Step 1: Email the Link When the Appointment Is Booked

The appointment is the record — it lives in your table. The Beam room is live state with a lifetime, so it is not created here: three weeks in a cache is three weeks of `cache:clear`, evictions and Redis restarts, any one of which would leave a link that 404s. The observer sends the link; the link's own page creates the room.

```php
<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Notifications\ConsultationBooked;

class AppointmentObserver
{
    public function created(Appointment $appointment): void
    {
        $url = route('consultations.show', $appointment);

        $appointment->patient->notify(new ConsultationBooked($appointment, $url));
        $appointment->doctor->notify(new ConsultationBooked($appointment, $url));
    }
}
```

```php
// AppServiceProvider::boot()
Appointment::observe(AppointmentObserver::class);
```

## Step 2: The Join Page Creates the Room

`create()` is idempotent: it updates the room's settings and keeps whoever is already inside, with their arrival times. So every visit can call it, and the room exists because someone is trying to enter it.

```php
<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RealRashid\Beam\Facades\Beam;

class ConsultationController extends Controller
{
    public function show(Request $request, Appointment $appointment): Response
    {
        abort_if($appointment->ends_at->isPast(), 410, 'That consultation is over.');

        $room = Beam::room("consult-{$appointment->id}")
            ->allow($appointment->doctor, $appointment->patient)   // nobody else can get a token
            ->video()
            ->screenShare()                                        // the doctor shows a scan
            ->expiresAt($appointment->ends_at)                     // the link dies with the appointment
            ->create();

        return Inertia::render('Consult/Room', [
            'appointment' => $appointment->only('id', 'starts_at', 'ends_at'),
            'with' => $request->user()->is($appointment->doctor) ? $appointment->patient->name : $appointment->doctor->name,
            'token' => $room->token($request->user()),                // FORBIDDEN for anyone the room does not allow → 403,
        ]);                                                          // ROOM_FULL if two are already in it → 409
    }
}
```

## Step 3: The Vue Page

```vue
<!-- resources/js/Pages/Consult/Room.vue -->
<script setup>
import { computed } from 'vue';
import { useBeam } from '@realrashid/beam/vue';

const props = defineProps({ appointment: Object, with: String, token: String });

const { local, remote, state, duration, muted, cameraOn, sharing, error,
        toggleMute, enableCamera, disableCamera, shareScreen, stopSharing, hangUp } = useBeam(props.token);

const clock = computed(() => `${Math.floor(duration.value / 60)}:${String(duration.value % 60).padStart(2, '0')}`);
</script>

<template>
    <div class="grid h-screen grid-rows-[1fr_auto] bg-slate-900 text-slate-100">
        <div class="relative">
            <video ref="remote" autoplay playsinline class="h-full w-full object-cover" />
            <video ref="local" autoplay muted playsinline class="absolute right-4 top-4 w-48 rounded-lg" />

            <p class="absolute left-4 top-4 rounded-full bg-black/60 px-3 py-1 text-sm">
                {{ state === 'connecting' ? `Waiting for ${with}…` : state }} · {{ clock }}
            </p>
            <p v-if="state === 'reconnecting'" class="absolute left-4 top-14 rounded bg-amber-500 px-3 py-1 text-sm text-black">Reconnecting…</p>
            <p v-if="error" class="absolute left-4 top-14 rounded bg-red-600 px-3 py-1 text-sm">{{ error.message }}</p>
        </div>

        <div class="flex justify-center gap-2 p-4">
            <button @click="toggleMute" class="rounded-full bg-slate-700 px-4 py-2">{{ muted ? 'Unmute' : 'Mute' }}</button>
            <button @click="cameraOn ? disableCamera() : enableCamera()" class="rounded-full bg-slate-700 px-4 py-2">{{ cameraOn ? 'Camera off' : 'Camera on' }}</button>
            <button @click="sharing ? stopSharing() : shareScreen()" class="rounded-full bg-slate-700 px-4 py-2">{{ sharing ? 'Stop sharing' : 'Share screen' }}</button>
            <button @click="hangUp()" class="rounded-full bg-red-600 px-4 py-2">Leave</button>
        </div>
    </div>
</template>
```

`useBeam()` joins when the component mounts, attaches once the two `<video>` refs exist, and hangs up when the component unmounts — navigating away is leaving.

## Step 4: The Link in the Email

The email goes out when the appointment is booked, before any room exists, so it points at your own route — the one that creates the room when it is opened:

```php
Route::get('/consult/{appointment}', [ConsultationController::class, 'show'])
    ->middleware('auth')
    ->name('consultations.show');
```

```php
class ConsultationBooked extends Mailable
{
    public function __construct(public Appointment $appointment, public string $url) {}

    public function build(): self
    {
        return $this->markdown('mail.consultation', ['link' => $this->url, 'appointment' => $this->appointment]);
    }
}
```

`$room->link()` is the other way round, for when a room does already exist: name a route `beam.room` taking `{room}`, and Beam will point at it — handy from a dashboard listing rooms in progress.

## Step 5: Attendance, From Events

```php
use RealRashid\Beam\Events\ParticipantJoined;
use RealRashid\Beam\Events\ParticipantLeft;

Event::listen(ParticipantJoined::class, function (ParticipantJoined $e) {
    Attendance::create(['room' => $e->room->name, 'user_id' => $e->participant->id, 'joined_at' => now()]);
});

Event::listen(ParticipantLeft::class, function (ParticipantLeft $e) {
    Attendance::where('room', $e->room->name)->where('user_id', $e->participant->id)->whereNull('seconds')->update(['seconds' => $e->duration]);
});
```

## Step 6: Closing Early

```php
class AppointmentController extends Controller
{
    public function cancel(Appointment $appointment): RedirectResponse
    {
        $appointment->update(['status' => 'cancelled']);
        Beam::room("consult-{$appointment->id}")->close('cancelled');   // anyone inside is told; the link is dead

        return back();
    }
}
```

## How It Works

1. Booking an appointment creates a room that only two people may enter, and that expires when the appointment ends. Nothing is broadcast: a room waits.
2. The email link opens the Inertia page; the controller finds the room and mints a token for whoever is signed in — or refuses them.
3. `useBeam()` joins. Whoever arrives second finds the first; who offers and who answers is settled on the first hello, so arriving at the same moment still connects.
4. `ParticipantJoined` fires when a participant's first heartbeat reaches the server; `ParticipantLeft` fires — with their duration — when they leave, hang up, or vanish.
5. On a phone, the patient joins in Safari as a normal page: camera, microphone, `playsinline`. Screen sharing is hidden there because `platform.supportsDisplayMedia()` is false — the doctor shares, the patient watches.

That's it! You now have appointment calls that live and die with the appointment.
