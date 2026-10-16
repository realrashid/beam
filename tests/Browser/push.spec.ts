import { expect, test } from './fixtures';

/**
 * A closed tab rings. Headless browsers have no push service, so the rig
 * records sends instead of delivering them, and the worker is proven to
 * register at scope '/' from {prefix}/sw.js. What is asserted end to end:
 * the worker registers; a subscription is stored through Beam's route; a
 * ring to a user with no tab listening sends a push carrying the call id;
 * a ring to a user whose dashboard is open sends none.
 */
test.beforeEach(async ({ request }) => { await request.post('/test/pushes/clear'); await request.post('/test/subscriptions/clear'); });

test('the worker registers from /beam/sw.js at scope /, with the header that allows it', async ({ peers, request }) => {
    const { b } = peers;
    await b.goto('/login-as/2');
    await b.goto('/dashboard');

    const headers = (await request.get('/beam/sw.js')).headers();
    expect(headers['service-worker-allowed']).toBe('/');
    expect(headers['cache-control']).toContain('no-store');

    const scope = await b.evaluate(async () => {
        const reg = await navigator.serviceWorker.register('/beam/sw.js', { scope: '/' });
        await navigator.serviceWorker.ready;
        return reg.scope;
    });
    expect(scope).toBe('http://localhost:8090/');
});

test('a subscription is stored through the route, and a ring to a closed tab sends a push with the call id', async ({ peers, request }) => {
    const { a, b } = peers;
    await b.goto('/login-as/2');
    await b.goto('/dashboard');

    // No push service in a headless browser: subscribe the server directly, as Beam.push.subscribe() would.
    const status = await b.evaluate(async () => (await fetch('/beam/push/subscribe', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content },
        body: JSON.stringify({ endpoint: 'https://push.test/rig-device', keys: { p256dh: 'P', auth: 'A' } }),
    })).status);
    expect(status).toBe(204);

    // The tab goes away, and its presence lapses.
    await b.close();
    await request.post('/test/pushes/clear');   // also clears the presence flag the dashboard set

    await a.goto('/login-as/1');
    const rung = await (await a.request.post('/call/2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })).json();

    const pushes = (await (await request.get('/test/pushes')).json()) as { endpoint: string; callId: string; title: string }[];
    expect(pushes).toEqual([{ endpoint: 'https://push.test/rig-device', callId: rung.callId, title: 'User 1 is calling' }]);
});

test('a ring to a user whose dashboard is open sends no push — the tab is listening', async ({ peers, request }) => {
    const { a, b } = peers;
    await b.goto('/login-as/2');
    await b.goto('/dashboard');
    await b.evaluate(async () => (await fetch('/beam/push/subscribe', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content },
        body: JSON.stringify({ endpoint: 'https://push.test/open-tab', keys: { p256dh: 'P', auth: 'A' } }),
    })).status);
    await b.waitForFunction(() => (window as any).Echo?.connector?.pusher?.connection?.state === 'connected');
    await b.waitForTimeout(500);   // the presence heartbeat has been sent

    await a.goto('/login-as/1');
    await a.request.post('/call/2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

    await expect(b.locator('#incoming')).toBeVisible({ timeout: 3_000 });   // Broadcasting reached the open tab
    expect(await (await request.get('/test/pushes')).json()).toEqual([]);      // and no push went out
});
