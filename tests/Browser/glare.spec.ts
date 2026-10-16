import { expect, test, waitForRemoteVideo } from './fixtures';

/**
 * Both sides offer at the same instant. Perfect negotiation: the polite side
 * rolls back and answers, the impolite side ignores the incoming offer, and
 * both end up stable with media still flowing.
 */
test('simultaneous offers resolve by the polite/impolite rule and media keeps flowing', async ({ peers }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `glare-${Date.now()}`;
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');

    const politeness = await Promise.all([a, b].map((p) => p.evaluate(() => (window as any).__call.peer.polite)));
    expect(politeness.filter(Boolean)).toHaveLength(1);   // exactly one polite side

    await Promise.all([a.evaluate(() => (window as any).__forceOffer()), b.evaluate(() => (window as any).__forceOffer())]);

    for (const p of [a, b]) {
        await expect.poll(() => p.evaluate(() => (window as any).__call.peer.connection.signalingState), { timeout: 10_000 }).toBe('stable');
        await expect.poll(() => p.evaluate(() => window.__beamState), { timeout: 10_000 }).toBe('connected');
        expect(await p.evaluate(() => (window as any).__beamError ?? null)).toBeNull();
    }
    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');
});
