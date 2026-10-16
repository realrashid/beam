import { expect, test, waitForRemoteVideo } from './fixtures';

/**
 * Phase 1's bet: two browsers connect through Laravel Broadcasting and each
 * sees the other's (fake) camera. Runs on both transports.
 */
for (const transport of ['controller', 'whisper'] as const) {
    test(`two peers connect and exchange video over the ${transport} transport`, async ({ peers }) => {
        const { a, b } = peers;

        await a.goto('/login-as/1');
        await b.goto('/login-as/2');

        const room = `phase1-${transport}-${Date.now()}`;
        await a.goto(`/room/${room}?transport=${transport}`);
        await b.goto(`/room/${room}?transport=${transport}`);

        await waitForRemoteVideo(a, '#them');
        await waitForRemoteVideo(b, '#them');

        for (const page of [a, b]) {
            expect(await page.evaluate(() => window.__beamError ?? null)).toBeNull();
            expect(await page.evaluate(() => window.__beamState)).toBe('connected');
            expect(await page.evaluate(() => document.querySelector<HTMLVideoElement>('#them')!.videoWidth)).toBeGreaterThan(0);
        }

        // Phase 2 exit: the page carries no configuration of its own — only a token.
        const html = await a.content();
        expect(html).not.toMatch(/stun:|turn:|beam\.room\.|iceServers/);

        // For 1.8: how long from join() to connected, per transport.
        const [ta, tb] = await Promise.all([a, b].map((p) => p.evaluate(() => window.__beamConnectedAt as number)));
        console.log(`[${transport}] connected in ${Math.round(ta)} ms / ${Math.round(tb)} ms`);
    });
}

test('the relay refuses a request whose token it did not issue, visibly', async ({ peers }) => {
    const { a } = peers;
    await a.goto('/login-as/1');
    await a.goto('/room/phase1-refuse?transport=controller');
    await a.waitForFunction(() => Boolean((window as any).__call));

    const status = await a.evaluate(async () => {
        const res = await fetch('/beam/signal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content, 'X-Beam-Token': 'not-a-token' },
            body: JSON.stringify({ channel: 'beam.room.phase1-refuse', message: { type: 'offer', sdp: { type: 'offer', sdp: 'v=0' } } }),
        });
        return [res.status, (await res.json()).reason];
    });

    expect(status).toEqual([401, 'TAMPERED']);
});

declare global {
    interface Window { __beamState: string; __beamError?: string; __beamConnectedAt?: number; __call: unknown; __incoming: unknown; __beamEnded?: { reason: string; duration: number }; __declined?: boolean }
}
