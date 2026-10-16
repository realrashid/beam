{{-- Copied by `beam:install --views`. Yours now — Beam never renders this. --}}
{{-- Include on any page a logged-in user keeps open (a dashboard, a layout). Needs @beamAssets in <head>. --}}
{{-- On answer it dispatches `beam:call` on document with the Call in event.detail, and attaches to --}}
{{-- #beam-local / #beam-remote when the page has them; otherwise render your own screen from the event. --}}
<style>
    #beam-incoming { position: fixed; inset: 0; display: grid; place-items: center; background: rgba(0, 0, 0, .6); z-index: 1000; font: 15px/1.4 system-ui, sans-serif; }
    #beam-incoming[hidden] { display: none; }
</style>
<div id="beam-incoming" role="dialog" aria-modal="true" aria-labelledby="beam-incoming-title" hidden>
    <div style="min-width:280px;padding:24px;border-radius:12px;background:#fff;color:#111;text-align:center">
        <img data-beam="avatar" alt="" hidden style="width:64px;height:64px;border-radius:50%;object-fit:cover">
        <h2 id="beam-incoming-title" style="margin:8px 0 4px;font-size:18px"><span data-beam="from">Someone</span> is calling</h2>
        <p data-beam="about" style="margin:0 0 16px;color:#555"></p>
        <button type="button" data-beam="answer" style="padding:10px 18px;border:0;border-radius:999px;background:#1f9d55;color:#fff;cursor:pointer">Answer</button>
        <button type="button" data-beam="decline" style="padding:10px 18px;border:0;border-radius:999px;background:#d64545;color:#fff;cursor:pointer">Decline</button>
    </div>
</div>

<script>
    Beam.onIncoming((incoming) => {
        const modal = document.getElementById('beam-incoming');
        const $ = (name) => modal.querySelector(`[data-beam="${name}"]`);

        $('from').textContent = incoming.from.name;
        $('about').textContent = incoming.about ?? '';
        $('avatar').hidden = !incoming.from.avatar;
        if (incoming.from.avatar) $('avatar').src = incoming.from.avatar;
        modal.hidden = false;

        const close = () => { modal.hidden = true; $('answer').onclick = $('decline').onclick = null; };

        $('answer').onclick = async () => {
            close();
            let call;
            try {
                call = await incoming.answer({ video: incoming.media === 'video' });
            } catch (e) {
                // permission-denied, no-device, media-failed … — say so where the caller's name was.
                $('from').textContent = e.message || 'Could not answer';
                $('about').textContent = '';
                modal.hidden = false;
                setTimeout(close, 4000);
                return;
            }
            if (document.querySelector('#beam-remote')) call.attach({ local: '#beam-local', remote: '#beam-remote' });
            document.dispatchEvent(new CustomEvent('beam:call', { detail: call }));
        };
        $('decline').onclick = () => { close(); incoming.decline(); };

        // The caller gives up after incoming.timeout seconds; take the modal down with them.
        setTimeout(() => { if (!modal.hidden) close(); }, incoming.timeout * 1000);
    });
</script>
