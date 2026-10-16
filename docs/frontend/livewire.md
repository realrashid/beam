# <img src="/icons/layers.svg" class="h1-icon" alt=""> Livewire

No adapter is needed: a Livewire component rings on the server and dispatches the token to the browser, where the class joins.

```php
use Livewire\Component;
use RealRashid\Beam\Facades\Beam;

class TicketCall extends Component
{
    public Ticket $ticket;

    public function call(): void
    {
        $call = Beam::call($this->ticket->customer)->video()->about("Ticket #{$this->ticket->id}")->ring();

        $this->dispatch('beam:join', token: $call->token);
    }

    public function render()
    {
        return view('livewire.ticket-call');
    }
}
```

```blade
<div>
    <button wire:click="call">Call customer</button>

    <video id="me" autoplay muted playsinline></video>
    <video id="them" autoplay playsinline></video>
    <button id="hangup">End</button>

    @script
    <script>
        let call;

        $wire.on('beam:join', async ({ token }) => {
            call = await Beam.join(token);
            call.attach({ local: '#me', remote: '#them' });
            call.on('ended', ({ reason, duration }) => $wire.dispatch('beam:ended', { reason, duration }));
        });

        document.getElementById('hangup').onclick = () => call?.hangUp();
    </script>
    @endscript
</div>
```

Two things worth knowing:

- **The click is the gesture.** `wire:click` → server → `dispatch` → `$wire.on` → `Beam.join()` still counts as user-initiated in every browser tested, because the round trip is short. If your handler does slow work before ringing, ring first.
- **Keep the call in JavaScript.** A Livewire re-render replaces markup but not the `call` object held in the `@script` block; re-attach after a render that touches the `<video>` elements (`wire:ignore` on them avoids the question).

The incoming side is the same `Beam.onIncoming()` as everywhere else, in a layout's `@script` block or a plain `<script>`.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
