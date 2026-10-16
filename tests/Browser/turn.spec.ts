import { expect, test, waitForRemoteVideo } from './fixtures';
import { startRelay, stopRelay } from './relay';

/**
 * The relay is the fifth call. relay.ts runs real coturn in a container where
 * Docker or Podman exists (CI included), verifying the same use-auth-secret
 * scheme the driver mints for, and node-turn otherwise. Either way: with
 * iceTransportPolicy 'relay' the selected candidate pair is relay/relay, media
 * flows, and killing the relay is a real network drop the call recovers from
 * once the relay is back.
 */
test.beforeEach(async () => { await startRelay(); });
test.afterEach(async () => { await stopRelay(); });

async function relayPair(a: import('@playwright/test').Page, b: import('@playwright/test').Page, room: string) {
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    await a.goto(`/room/${room}?relay=1`);
    await b.goto(`/room/${room}?relay=1`);
    await waitForRemoteVideo(a, '#them', 20_000);
    await waitForRemoteVideo(b, '#them', 20_000);
}

const selectedPair = (p: import('@playwright/test').Page) => p.evaluate(async () => {
    const stats = await (window as any).__call.peer.connection.getStats();
    const byId: Record<string, any> = {};
    stats.forEach((r: any) => { byId[r.id] = r; });
    const pair = [...stats.values()].find((r: any) => r.type === 'candidate-pair' && (r.nominated || r.state === 'succeeded')) as any;
    return pair ? { local: byId[pair.localCandidateId]?.candidateType, remote: byId[pair.remoteCandidateId]?.candidateType } : null;
});

test('a relay-only call connects through TURN, with the credentials the coturn driver minted', async ({ peers }) => {
    const { a, b } = peers;
    await relayPair(a, b, `turn-${Date.now()}`);

    // The token carried minted TURN credentials, and the page never saw the secret.
    const ice = await a.evaluate(() => (window as any).__call.claims.ice);
    expect(ice.some((s: RTCIceServer) => String(s.urls).startsWith('turn:') && 'credential' in s)).toBe(true);
    expect(await a.content()).not.toContain('rig-secret');

    const pair = await selectedPair(a);
    expect(pair).toEqual({ local: 'relay', remote: 'relay' });
});

test('killing the relay is a real network drop: reconnecting, then connected again once it is back', async ({ peers }) => {
    test.setTimeout(150_000);
    const { a, b } = peers;
    await relayPair(a, b, `turn-drop-${Date.now()}`);

    await stopRelay();

    // Consent freshness fails once the relay stops answering; Chrome reports disconnected within ~30 s.
    await expect.poll(() => a.evaluate(() => window.__beamState), { timeout: 60_000 }).toBe('reconnecting');

    await startRelay();

    await expect.poll(() => a.evaluate(() => window.__beamState), { timeout: 60_000 }).toBe('connected');
    expect(await a.evaluate(() => window.__beamEnded ?? null)).toBeNull();
    await waitForRemoteVideo(b, '#them', 20_000);
});
