import { expect, test, waitForRemoteVideo } from './fixtures';
import { relayKind, startRelay, stopRelay } from './relay';

/**
 * A file goes browser to browser over a data channel: never through the server.
 * The relay-only case kills the relay mid-transfer — a real network drop — and
 * expects the transfer to finish once it is back.
 */
async function pair(a: import('@playwright/test').Page, b: import('@playwright/test').Page, room: string, query = '') {
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    await a.goto(`/room/${room}${query}`);
    await b.goto(`/room/${room}${query}`);
    await waitForRemoteVideo(a, '#them', 20_000);
    await waitForRemoteVideo(b, '#them', 20_000);
    await a.evaluate(() => { (window as any).__otherId = '2'; });
    await b.evaluate(() => { (window as any).__otherId = '1'; });
}

test('a 20 MB file crosses inside a call: accepted, progress monotonic to 100, SHA-256 identical, events on the server', async ({ peers, request }) => {
    test.setTimeout(420_000);
    const { a, b } = peers;
    await request.post('/test/events/clear');
    await pair(a, b, `xfer-${Date.now()}`);

    const bytes = 20 * 1024 * 1024;
    await a.evaluate((n) => void (window as any).__send(n), bytes);

    await expect.poll(() => b.evaluate(() => (window as any).__offer ?? null), { timeout: 10_000 }).toEqual(expect.objectContaining({ name: 'rig.bin', size: bytes }));
    // 20 MB at the rate Chromium's estimator allows a data channel while video is
    // on — 50 to 300 KB/s on this rig — is 70 to 140 seconds, so the budget is
    // patient on purpose. A CI runner does it in a fraction of that.
    await expect.poll(() => a.evaluate(() => (window as any).__transferState ?? null), { timeout: 300_000 }).toBe('done');
    await expect.poll(() => b.evaluate(() => (window as any).__received ?? null), { timeout: 30_000 }).not.toBeNull();

    const sent = await a.evaluate(() => (window as any).__sentSha);
    // file-done reaches the sender before the receiver's page has hashed the blob for the rig; wait for that.
        await expect.poll(() => b.evaluate(() => (window as any).__received ?? null), { timeout: 15_000 }).not.toBeNull();
        const received = await b.evaluate(() => (window as any).__received);
    expect(received.size).toBe(bytes);
    expect(received.sha256).toBe(sent);

    const progress = (await a.evaluate(() => (window as any).__transferProgress)) as number[];
    expect(progress.at(-1)).toBe(100);
    expect(progress).toEqual([...progress].sort((x, y) => x - y));
    expect(await a.evaluate(() => (window as any).__transferError ?? null)).toBeNull();

    // The server heard about it — and never saw a byte of it.
    const events = (await (await request.get('/test/events')).json()) as { event: string; transferId?: string; file?: { name: string; size: number } }[];
    const names = events.filter((e) => e.transferId).map((e) => e.event);
    expect(names).toEqual(['FileOffered', 'FileAccepted', 'FileTransferred']);
    expect(events.find((e) => e.event === 'FileTransferred')!.file).toEqual({ name: 'rig.bin', size: bytes });
});

test('a declined offer rejects the sender with reason declined and sends nothing', async ({ peers }) => {
    const { a, b } = peers;
    await pair(a, b, `xfer-decline-${Date.now()}`);
    await b.evaluate(() => { (window as any).__autoAccept = false; });

    await a.evaluate(() => void (window as any).__send(1024 * 1024));

    await expect.poll(() => b.evaluate(() => (window as any).__declined ?? false), { timeout: 10_000 }).toBe(true);
    await expect.poll(() => a.evaluate(() => (window as any).__transferError ?? null), { timeout: 10_000 }).toMatch(/^declined/);
    expect(await a.evaluate(() => (window as any).__transferProgress)).toEqual([]);
});

test('a transfer survives the relay dying mid-way on a relay-only connection', async ({ peers }) => {
    test.skip(relayKind() !== 'coturn', 'node-turn wedges under data-channel load (measured 20 Sep 2026); this needs real coturn — Docker or Podman');
    // While video is on, Chromium paces data-channel traffic with its bandwidth estimator: on this
    // rig a transfer ramps from ~50 to ~300 KB/s over a minute, relayed or not (measured 20 Sep 2026).
    // 2 MB is enough to be mid-way when the relay dies and to finish inside the window once it is back.
    test.setTimeout(360_000);
    await startRelay();
    const { a, b } = peers;
    try {
        await pair(a, b, `xfer-drop-${Date.now()}`, '?relay=1');

        const bytes = 2 * 1024 * 1024;
        await a.evaluate((n) => void (window as any).__send(n), bytes);
        await expect.poll(() => a.evaluate(() => (window as any).__transferProgress.at(-1) ?? 0), { timeout: 60_000 }).toBeGreaterThan(10);

        await stopRelay();
        await expect.poll(() => a.evaluate(() => window.__beamState), { timeout: 60_000 }).toBe('reconnecting');
        await startRelay();
        await expect.poll(() => a.evaluate(() => window.__beamState), { timeout: 60_000 }).toBe('connected');

        await expect.poll(() => a.evaluate(() => (window as any).__transferState ?? null), { timeout: 180_000 }).toBe('done').catch(async (e) => {
            // What each side holds when the transfer did not finish — the failure is otherwise a bare state name.
            const channels = (p: import('@playwright/test').Page) => p.evaluate(async () => {
                const out: string[] = [];
                (await (window as any).__call.peer.connection.getStats()).forEach((r: any) => { if (r.type === 'data-channel') out.push(`${r.label}:${r.state}:tx${r.bytesSent}/rx${r.bytesReceived}`); });
                return out.join(' ');
            });
            const sender = await a.evaluate(() => { const t = (window as any).__transfer; return { state: t.state, sent: t.sent, sending: t.sending, channel: t.channel?.readyState, error: (window as any).__transferError ?? null }; });
            const receiver = await b.evaluate(() => { const o = (window as any).__offerObj; const keys = o ? [...o.parts.keys()].sort((x: number, y: number) => x - y) : []; let pos = 0, gaps = 0; for (const k of keys) { if (k !== pos) gaps++; pos = k + o.parts.get(k).byteLength; } return { received: o?.received, parts: keys.length, gaps, end: pos, size: o?.size, error: (window as any).__receiveError ?? null }; });
            console.log('sender', JSON.stringify(sender), await channels(a), '\nreceiver', JSON.stringify(receiver), await channels(b));
            throw e;
        });
        // file-done reaches the sender before the receiver's page has hashed the blob for the rig; wait for that.
        await expect.poll(() => b.evaluate(() => (window as any).__received ?? null), { timeout: 15_000 }).not.toBeNull();
        const received = await b.evaluate(() => (window as any).__received);
        expect(received.size).toBe(bytes);
        expect(received.sha256).toBe(await a.evaluate(() => (window as any).__sentSha));
    } finally {
        await stopRelay();
    }
});
