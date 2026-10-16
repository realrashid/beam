# <img src="/icons/file.svg" class="h1-icon" alt=""> File Handoff

In this example, we'll build a file drop between two colleagues — no call, no upload, no build step. A file chosen in one browser lands in the other, and the server only ever learns its name. jQuery and `@beamAssets`, the way most Laravel applications in the wild are written.

## Step 1: Who May Send to Whom

```php
// AppServiceProvider::boot()
Gate::define('beam.call', fn (User $sender, User $recipient) =>
    $sender->team_id === $recipient->team_id);   // anyone on the team, to anyone on the team
```

The gate that decides calls decides files: a transfer outside a call asks your server for a data-only connection, and this is the question it asks.

## Step 2: The Page

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DropController extends Controller
{
    public function index(Request $request): View
    {
        return view('drop', [
            'colleagues' => User::where('team_id', $request->user()->team_id)->whereKeyNot($request->user()->id)->orderBy('name')->get(),
        ]);
    }
}
```

```blade
{{-- resources/views/drop.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Drop</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    @vite(['resources/js/app.js'])   {{-- window.Echo --}}
    @beamAssets                      {{-- window.Beam, and $.beam because jQuery is already here --}}
</head>
<body>
    <h1>Send a file</h1>

    <form id="drop">
        <select id="to">
            @foreach ($colleagues as $colleague)
                <option value="{{ $colleague->id }}">{{ $colleague->name }}</option>
            @endforeach
        </select>
        <input id="file" type="file">
        <button>Send</button>
    </form>
    <p id="status"></p>

    <div id="offer" style="display:none">
        <p><b id="offer-from"></b> is sending <b id="offer-name"></b> (<span id="offer-size"></span>)</p>
        <progress id="offer-bar" max="100" value="0"></progress>
        <button id="accept">Accept</button>
        <button id="decline">Decline</button>
    </div>

    <script>
        $(function () {
            // Sending: no call needed. Beam opens a data-only connection to the colleague's open tab.
            $('#drop').on('submit', function (e) {
                e.preventDefault();
                var file = $('#file')[0].files[0];
                if (!file) return;

                var transfer = $.beam.send(file).to($('#to').val());
                transfer.on('progress', function (p) { $('#status').text(p.percent + '% · ' + Math.round(p.bytesPerSecond / 1024) + ' KB/s'); });
                transfer.start()
                    .then(function () { $('#status').text('Delivered — both sides verified the SHA-256'); })
                    .catch(function (err) { $('#status').text(err.reason === 'declined' ? 'They declined' : err.message); });
            });

            // Receiving: nothing arrives until Accept.
            $.beam.onFileOffered(function (offer) {
                $('#offer-from').text(offer.from.name);
                $('#offer-name').text(offer.name);
                $('#offer-size').text((offer.size / 1048576).toFixed(1) + ' MB');
                $('#offer').show();

                $('#decline').one('click', function () { $('#offer').hide(); offer.decline(); });
                $('#accept').one('click', function () {
                    offer.on('progress', function (p) { $('#offer-bar').val(p.percent); });
                    offer.accept().then(function (blob) {
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = offer.name;
                        a.click();
                        $('#offer').hide();
                    });
                });
            });

            // A page that can receive files also has to be able to be reached: this is what lets the
            // data-only connection in. Render nothing for it — a transfer is an offer, never a ring.
            $.beam.onIncoming(function () {});
        });
    </script>
</body>
</html>
```

## Step 3: Route

```php
use App\Http\Controllers\DropController;

Route::get('/drop', [DropController::class, 'index'])->middleware('auth');
```

## Step 4: A Line in the Log

```php
use RealRashid\Beam\Events\FileTransferred;

Event::listen(FileTransferred::class, fn (FileTransferred $e) => Handoff::create([
    'from_id' => $e->from->id,
    'to_id'   => $e->to->id,
    'name'    => $e->file->name,
    'bytes'   => $e->file->size,
]));
```

## How It Works

1. **Send** calls `$.beam.send(file).to(id)`. There is no call with that colleague, so Beam posts to `/beam/transfers`; the gate says yes and the server answers with a token for a data-only connection.
2. The colleague's page — which registered `onIncoming` and `onFileOffered` — connects quietly (no camera, no microphone, no modal) and shows the offer.
3. **Accept** opens a data channel; the file crosses in 16 KiB chunks with back-pressure, straight from browser to browser.
4. The receiver's page assembles the file, hashes it, and both sides agree on the SHA-256 before `accept()` resolves. The browser saves it.
5. `FileTransferred` fires on your server with the name and size. The bytes never came near it.

That's it! Two colleagues, a file, and a server that never held it.
