# <img src="/icons/lock.svg" class="h1-icon" alt=""> Secure Documents

In this example, we'll build a document exchange for a firm that must never hold its clients' files: a lawyer sends a contract to a client, a client sends a scan back, and the server keeps an audit line for each — name, size, who, when — but never a byte of the document. Files only; no call anywhere in this example.

## Step 1: Who May Send to Whom

```php
<?php

namespace App\Providers;

use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A lawyer and a client may exchange files while they share an open matter. Nobody else, in either direction.
        Gate::define('beam.call', fn (User $sender, User $recipient) =>
            Matter::open()->between($sender, $recipient)->exists());
    }
}
```

```php
class Matter extends Model
{
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeBetween(Builder $query, User $a, User $b): Builder
    {
        return $query->where(fn ($q) => $q->where('lawyer_id', $a->id)->where('client_id', $b->id))
            ->orWhere(fn ($q) => $q->where('lawyer_id', $b->id)->where('client_id', $a->id));
    }

    public function exchanges(): HasMany
    {
        return $this->hasMany(Exchange::class)->latest();
    }
}
```

## Step 2: The Matter Page

```php
<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MatterController extends Controller
{
    public function show(Request $request, Matter $matter): View
    {
        $this->authorize('view', $matter);

        return view('matters.show', [
            'matter' => $matter->load('exchanges'),
            'other' => $request->user()->is($matter->lawyer) ? $matter->client : $matter->lawyer,
        ]);
    }
}
```

```blade
{{-- resources/views/matters/show.blade.php --}}
@extends('layouts.app')

@section('content')
<h1>{{ $matter->title }}</h1>

<section>
    <h2>Send {{ $other->name }} a document</h2>
    <p>It goes straight to their browser. We never hold a copy.</p>
    <input id="document" type="file" accept=".pdf,.docx,.png,.jpg">
    <p id="status"></p>
    <progress id="bar" max="100" value="0" hidden></progress>
</section>

<section>
    <h2>Exchange log</h2>
    <table>
        @foreach ($matter->exchanges as $exchange)
            <tr>
                <td>{{ $exchange->created_at->format('j M Y H:i') }}</td>
                <td>{{ $exchange->sender->name }} → {{ $exchange->recipient->name }}</td>
                <td>{{ $exchange->name }}</td>
                <td>{{ number_format($exchange->bytes / 1024) }} KB</td>
                <td>{{ $exchange->sha256 ? ' verified' : '' }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection

@push('scripts')
<script>
    document.getElementById('document').onchange = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const status = document.getElementById('status');
        const bar = document.getElementById('bar');
        bar.hidden = false;
        status.textContent = `Offering ${file.name} to {{ $other->name }}…`;

        const transfer = Beam.send(file).to(@js($other->id));
        transfer.on('accepted', () => { status.textContent = 'Accepted — sending'; });
        transfer.on('progress', ({ percent }) => { bar.value = percent; });

        try {
            await transfer.start();
            status.textContent = 'Delivered — both sides verified the SHA-256';
            setTimeout(() => location.reload(), 1500);     // the log has it by then
        } catch (err) {
            status.textContent = err.reason === 'declined' ? `${@js($other->name)} declined` : err.message;
        }
    };
</script>
@endpush
```

## Step 3: Receiving, in the Layout

Any page the other party keeps open can receive. The layout registers the two handlers once.

```blade
{{-- resources/views/layouts/app.blade.php, before </body> --}}
@auth
    <div id="offer" hidden class="fixed bottom-6 right-6 rounded-xl border bg-white p-4 shadow-lg">
        <p><b id="offer-from"></b> is sending <b id="offer-name"></b> (<span id="offer-size"></span>)</p>
        <progress id="offer-bar" max="100" value="0"></progress>
        <button id="offer-accept">Accept</button>
        <button id="offer-decline">Decline</button>
    </div>
    <script>
        Beam.onIncoming(() => {});   // lets the data-only connection in; a document is an offer, never a ring

        Beam.onFileOffered((offer) => {
            const box = document.getElementById('offer');
            document.getElementById('offer-from').textContent = offer.from.name;
            document.getElementById('offer-name').textContent = offer.name;
            document.getElementById('offer-size').textContent = `${(offer.size / 1048576).toFixed(1)} MB`;
            box.hidden = false;

            document.getElementById('offer-decline').onclick = () => { box.hidden = true; offer.decline(); };
            document.getElementById('offer-accept').onclick = async () => {
                offer.on('progress', ({ percent }) => { document.getElementById('offer-bar').value = percent; });
                const blob = await offer.accept();                // resolves only when the hashes agree
                Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: offer.name }).click();
                box.hidden = true;
            };
        });
    </script>
@endauth
```

## Step 4: The Audit Line

```php
<?php

namespace App\Listeners;

use App\Models\Exchange;
use App\Models\Matter;
use App\Models\User;
use RealRashid\Beam\Events\FileDeclined;
use RealRashid\Beam\Events\FileTransferred;

class RecordExchange
{
    public function transferred(FileTransferred $e): void
    {
        $matter = Matter::open()->between(User::find($e->from->id), User::find($e->to->id))->first();

        $matter?->exchanges()->create([
            'sender_id' => $e->from->id,
            'recipient_id' => $e->to->id,
            'name' => $e->file->name,
            'bytes' => $e->file->size,
            'mime' => $e->file->mime,
            'sha256' => true,    // FileTransferred means the receiver's hash matched the sender's
        ]);
    }

    public function declined(FileDeclined $e): void
    {
        // Worth knowing on a legal matter: an offer was made and refused.
        Matter::open()->between(User::find($e->from->id), User::find($e->to->id))->first()
            ?->exchanges()->create(['sender_id' => $e->from->id, 'recipient_id' => $e->to->id, 'name' => $e->file->name, 'bytes' => $e->file->size, 'declined' => true]);
    }
}
```

```php
// AppServiceProvider::boot()
Event::listen(FileTransferred::class, [RecordExchange::class, 'transferred']);
Event::listen(FileDeclined::class, [RecordExchange::class, 'declined']);
```

## Step 5: A Size Limit That Suits Documents

```php
// config/beam.php
'transfer' => ['max_bytes' => 200 * 1024 * 1024],   // 200 MB: scans and contracts, not video
```

The sender refuses to offer anything larger, the receiver refuses to accept it, and the server refuses the offer with `413 TOO_LARGE` — three places, one number.

## Step 6: Route

```php
Route::get('/matters/{matter}', [MatterController::class, 'show'])->middleware('auth')->name('matters.show');
```

## How It Works

1. The lawyer picks a file on the matter page. `Beam.send().to()` finds no call with the client, so it asks the server for a data-only connection; the gate confirms the two share an open matter.
2. The client's page — any page, thanks to the layout — connects without a camera or microphone and shows the offer with the name and size. Nothing has been transferred yet.
3. **Accept** opens a data channel. The document crosses browser to browser; a dropped connection pauses and resumes; the receiver assembles it and both sides compare SHA-256 before `accept()` resolves and the browser saves it.
4. `FileTransferred` writes the audit line: who, what, how big, verified. `FileDeclined` writes a refusal. No table anywhere holds the document.
5. Nothing to encrypt at rest, nothing to purge on a retention schedule, nothing to hand over on a subpoena — the firm never had it.

That's it! A document exchange with a complete audit trail and no documents on the server.
