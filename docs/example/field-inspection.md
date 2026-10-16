# <img src="/icons/search.svg" class="h1-icon" alt=""> Field Inspection

In this example, we'll build remote inspections for a maintenance company with Livewire: an engineer at head office starts a video call to a technician on site from the job screen, the technician answers on a phone and flips to the rear camera, a photo of the serial plate is sent inside the call, and the job records the inspection.

## Step 1: The Job Screen, in Livewire

```php
<?php

namespace App\Livewire;

use App\Models\Job;
use Livewire\Component;
use RealRashid\Beam\Facades\Beam;

class InspectJob extends Component
{
    public Job $job;

    public function inspect(): void
    {
        $this->authorize('manage', $this->job);

        $call = Beam::call($this->job->technician)
            ->video()
            ->about("Job #{$this->job->number}: {$this->job->site}")
            ->timeout(60)                                        // a technician has to get the phone out of a pocket
            ->ring();

        $this->dispatch('beam:join', token: $call->token);       // the browser takes it from here
    }

    public function render()
    {
        return view('livewire.inspect-job');
    }
}
```

```blade
{{-- resources/views/livewire/inspect-job.blade.php --}}
<div>
    <h1>Job #{{ $job->number }} — {{ $job->site }}</h1>
    <p>Technician: {{ $job->technician->name }}</p>

    <button wire:click="inspect"> Inspect with {{ $job->technician->name }}</button>

    <div id="call" hidden wire:ignore>
        <video id="them" autoplay playsinline></video>
        <video id="me" autoplay muted playsinline></video>
        <p><span id="state">ringing</span> · <span id="clock">0:00</span></p>
        <button id="mute">Mute</button>
        <button id="hangup">End</button>
        <p id="photo"></p>
    </div>

    <h2>Inspections</h2>
    <ul>
        @foreach ($job->inspections as $inspection)
            <li>{{ $inspection->created_at->format('j M, H:i') }} — {{ $inspection->engineer->name }}, {{ gmdate('i:s', $inspection->seconds) }}
                @foreach ($inspection->attachments as $attachment) ·  {{ $attachment->name }} @endforeach
            </li>
        @endforeach
    </ul>

    @script
    <script>
        let call;

        $wire.on('beam:join', async ({ token }) => {
            document.getElementById('call').hidden = false;
            call = await Beam.join(token);
            call.attach({ local: '#me', remote: '#them' });
            call.on('state', (s) => { document.getElementById('state').textContent = s; });
            call.on('tick', ({ seconds }) => { document.getElementById('clock').textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`; });
            call.on('muted', (m) => { document.getElementById('mute').textContent = m ? 'Unmute' : 'Mute'; });
            call.on('ended', () => { $wire.$refresh(); document.getElementById('call').hidden = true; });   // the inspection is on the job by then
        });

        document.getElementById('mute').onclick = () => call?.toggleMute();
        document.getElementById('hangup').onclick = () => call?.hangUp();

        // The photo the technician sends arrives here, inside the call.
        Beam.onFileOffered(async (offer) => {
            document.getElementById('photo').textContent = `Receiving ${offer.name}…`;
            const blob = await offer.accept();
            const img = Object.assign(document.createElement('img'), { src: URL.createObjectURL(blob), width: 320 });
            document.getElementById('photo').replaceChildren(img);
        });
    </script>
    @endscript
</div>
```

`wire:ignore` keeps Livewire's re-renders away from the `<video>` elements; the `call` object lives in the `@script` block across them.

## Step 2: Who May Inspect Whom

```php
// AppServiceProvider::boot()
Gate::define('beam.call', fn (User $caller, User $callee) =>
    $caller->isEngineer() && Job::inProgress()->where('technician_id', $callee->id)->exists());
```

## Step 3: The Technician's Phone

The technician is never on the page. Their phone rings through [push](/usage/push) — they pressed *Ring me for inspections* once, and on an iPhone added the app to the Home Screen — and **Answer** opens this page:

```php
Route::get('/inspect/{call}', fn () => view('inspect.phone'))->middleware('auth')->name('beam.join');
```

```blade
{{-- resources/views/inspect/phone.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="manifest" href="/manifest.json">
    @vite(['resources/js/app.js'])
    @beamAssets
</head>
<body style="margin:0;background:#000;color:#fff">
    <video id="them" autoplay playsinline style="position:fixed;right:12px;top:12px;width:120px"></video>
    <video id="me" autoplay muted playsinline style="width:100vw;height:100vh;object-fit:cover"></video>   {{-- the technician mostly sees their own camera: what they are pointing at --}}

    <div style="position:fixed;bottom:24px;left:0;right:0;display:flex;justify-content:center;gap:12px">
        <button id="flip"> Flip camera</button>
        <label> Send photo <input id="photo" type="file" accept="image/*" capture="environment" hidden></label>
        <button id="hangup">End</button>
    </div>
    <p id="state" style="position:fixed;top:12px;left:12px"></p>

    <script>
        (async () => {
            const call = await Beam.answerFromNotification({ media: { video: { facingMode: 'environment' }, audio: true } });   // start on the rear camera
            if (!call) { document.getElementById('state').textContent = 'Open this from the notification.'; return; }

            call.attach({ local: '#me', remote: '#them' });
            call.on('state', (s) => { document.getElementById('state').textContent = s; });
            call.on('error', (e) => { if (e.reason === 'autoplay-blocked') document.getElementById('state').textContent = 'Tap to hear'; });

            document.getElementById('flip').onclick = () => call.switchCamera();     // on a phone: the other facing
            document.getElementById('hangup').onclick = () => call.hangUp();

            document.getElementById('photo').onchange = (e) => {
                const transfer = Beam.send(e.target.files[0], `plate-${Date.now()}.jpg`).to(call.remoteId);
                transfer.on('done', () => { document.getElementById('state').textContent = 'Photo sent'; });
                transfer.start().catch(() => {});
            };
        })();
    </script>
</body>
</html>
```

Three phone edges are handled for you: `switchCamera()` flips `facingMode` rather than walking a list of three rear lenses; a tab that went to the background for the camera app restarts ICE the moment it is visible again; and on cellular, behind carrier-grade NAT, the call goes through the [relay](/guide/turn) you configured — this is the call STUN alone cannot make.

## Step 4: The Record

```php
<?php

namespace App\Listeners;

use App\Models\Job;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Events\FileTransferred;

class RecordInspection
{
    public function ended(CallEnded $e): void
    {
        if ($e->duration === null) return;   // never answered

        Job::fromCallAbout($e->call->about)?->inspections()->create([
            'engineer_id' => $e->call->caller->id,
            'seconds' => $e->duration,
        ]);
    }

    public function transferred(FileTransferred $e): void
    {
        Job::inProgress()->where('technician_id', $e->from->id)->first()
            ?->inspections()->latest()->first()
            ?->attachments()->create(['name' => $e->file->name, 'bytes' => $e->file->size, 'received_by' => $e->to->id]);
    }
}
```

```php
// AppServiceProvider::boot()
Event::listen(CallEnded::class, [RecordInspection::class, 'ended']);
Event::listen(FileTransferred::class, [RecordInspection::class, 'transferred']);
```

## How It Works

1. The engineer presses **Inspect**. Livewire rings on the server and dispatches the token; the browser joins in the `@script` block — the click is the gesture, so the camera opens.
2. The technician's phone rings through Web Push. **Answer** opens the phone page, which joins from the notification on the rear camera.
3. The plant room is on cellular: the browsers cannot reach each other directly, so both connect to the relay and the video flows through it.
4. The technician flips cameras to show a gauge, steps out to take a photo, comes back — the tab wakes and the call restarts ICE at once — and sends the photo inside the call; it arrives on the engineer's screen without touching the server.
5. Hang up; `CallEnded` records the inspection with the duration, and `FileTransferred` attaches the photo's name to it.

That's it! A site inspection through a phone, from a job screen, with the record where the job lives.
