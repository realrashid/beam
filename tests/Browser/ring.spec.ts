import { expect, test, waitForRemoteVideo } from './fixtures';

/**
 * The sketch's whole feature: Beam::call()->ring() in PHP, Beam.onIncoming()
 * fires in the callee's browser, answer() connects, hangUp() ends it, and the
 * server saw CallRinging, CallAnswered and CallEnded with a duration.
 */
test.beforeEach(async ({ request }) => {
    await request.post('/test/events/clear');
});

test('ring → incoming → answer → connected → hang up, with the server-side events', async ({ peers, request }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');

    // The callee is on their dashboard, listening.
    await b.goto('/dashboard');
    await b.waitForFunction(() => Boolean((window as any).Echo?.connector?.pusher?.connection?.state === 'connected'));

    // The caller rings from PHP and opens the call page with the token.
    const rung = await (await a.request.post('/call/2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })).json();
    expect(rung.callId).toMatch(/^call_/);
    await a.goto(`/join?token=${encodeURIComponent(rung.token)}`);

    // The callee's dashboard rings within 3 s, showing who and what about.
    await expect(b.locator('#incoming')).toBeVisible({ timeout: 3_000 });
    await expect(b.locator('#from')).toHaveText('User 1');
    await expect(b.locator('#about')).toHaveText('Ticket #42');
    expect(await b.evaluate(() => (window as any).__incoming.callId)).toBe(rung.callId);

    await b.click('#answer');

    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');
    expect(await a.evaluate(() => window.__beamState)).toBe('connected');
    expect(await b.evaluate(() => window.__beamState)).toBe('connected');

    // Long enough for the server to measure a duration.
    await a.waitForTimeout(2_000);
    await a.click('#hangup');

    await expect.poll(() => b.evaluate(() => window.__beamEnded?.reason ?? null)).toBe('hangup');
    await expect.poll(() => a.evaluate(() => window.__beamState)).toBe('ended');

    const events = (await (await request.get('/test/events')).json()) as { event: string; call: string; reason: string | null; duration: number | null; by: number | null }[];
    const names = events.filter((e) => e.call === rung.callId).map((e) => e.event);
    expect(names).toEqual(['CallRinging', 'CallAnswered', 'CallEnded']);
    const ended = events.find((e) => e.event === 'CallEnded')!;
    expect(ended.reason).toBe('hangup');
    expect(ended.by).toBe(1);
    expect(ended.duration).toBeGreaterThanOrEqual(1);
});

test('decline from the dashboard: the caller is told, and CallDeclined carries who and why', async ({ peers, request }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    await b.goto('/dashboard');
    await b.waitForFunction(() => (window as any).Echo?.connector?.pusher?.connection?.state === 'connected');

    const rung = await (await a.request.post('/call/2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })).json();
    await a.goto(`/join?token=${encodeURIComponent(rung.token)}`);
    await a.waitForFunction(() => Boolean((window as any).__call));

    await expect(b.locator('#incoming')).toBeVisible({ timeout: 3_000 });
    await b.click('#decline');

    await expect.poll(() => a.evaluate(() => window.__beamEnded?.reason ?? null)).toBe('declined');
    await expect.poll(() => b.evaluate(() => window.__declined ?? false)).toBe(true);

    const events = (await (await request.get('/test/events')).json()) as { event: string; call: string; reason: string | null; by: number | null }[];
    const declined = events.find((e) => e.event === 'CallDeclined' && e.call === rung.callId)!;
    expect(declined.reason).toBe('busy');
    expect(declined.by).toBe(2);
});

test('nobody answers: the browser timeout driver declines with reason timeout', async ({ peers, request }) => {
    const { a } = peers;
    await a.goto('/login-as/1');

    const rung = await (await a.request.post('/call/2?timeout=2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })).json();
    await a.goto(`/join?token=${encodeURIComponent(rung.token)}`);

    await expect.poll(() => a.evaluate(() => window.__beamEnded?.reason ?? null), { timeout: 8_000 }).toBe('timeout');

    const events = (await (await request.get('/test/events')).json()) as { event: string; call: string; reason: string | null; by: number | null }[];
    const declined = events.find((e) => e.event === 'CallDeclined' && e.call === rung.callId)!;
    expect(declined.reason).toBe('timeout');
    expect(declined.by).toBeNull();
});
