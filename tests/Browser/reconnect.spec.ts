import { expect, test, waitForRemoteVideo } from './fixtures';

/**
 * Chromium's offline emulation (CDP) cuts HTTP, not WebRTC's UDP, so a real
 * network drop cannot be simulated here; turn.spec.ts (Phase 6) kills the relay
 * on a relay-only call instead. This spec proves the recovery machinery: an ICE
 * restart offered by the impolite side, answered by the polite side, media
 * continuing — and the give-up path when recovery is impossible.
 */
test('an ICE restart is offered, answered, and media continues', async ({ peers }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `reconnect-${Date.now()}`;
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');

    const impolite = (await a.evaluate(() => (window as any).__call.peer.polite)) ? b : a;
    const other = impolite === a ? b : a;

    const ufragBefore = await impolite.evaluate(() => (window as any).__call.peer.connection.localDescription.sdp.match(/a=ice-ufrag:(\S+)/)[1]);
    await impolite.evaluate(() => (window as any).__forceRestart());

    // A restart produces a new offer with a new ufrag; the other side answers; both stay connected.
    await expect.poll(() => impolite.evaluate(() => (window as any).__call.peer.connection.localDescription.sdp.match(/a=ice-ufrag:(\S+)/)[1]), { timeout: 10_000 }).not.toBe(ufragBefore);
    await expect.poll(() => other.evaluate(() => (window as any).__call.peer.connection.signalingState), { timeout: 10_000 }).toBe('stable');
    await expect.poll(() => impolite.evaluate(() => (window as any).__call.peer.connection.iceConnectionState), { timeout: 15_000 }).toMatch(/connected|completed/);
    expect(await other.evaluate(() => window.__beamState)).toBe('connected');
    await waitForRemoteVideo(other, '#them');
});

test('quality events report round-trip time and packet loss while connected', async ({ peers }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `quality-${Date.now()}`;
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');

    await expect.poll(() => a.evaluate(() => (window as any).__beamQuality ?? null), { timeout: 10_000 }).toEqual(expect.objectContaining({ rtt: expect.any(Number), packetLoss: expect.any(Number) }));
    const q = await a.evaluate(() => (window as any).__beamQuality);
    expect(q.packetLoss).toBeGreaterThanOrEqual(0);
    expect(q.packetLoss).toBeLessThanOrEqual(1);
});

test('when recovery is impossible the call ends with connection-lost after reconnectTimeout, not before', async ({ peers }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `lost-${Date.now()}`;
    await a.goto(`/room/${room}?reconnect=3`);
    await b.goto(`/room/${room}?reconnect=3`);
    await waitForRemoteVideo(a, '#them');

    // Cut a's signaling so no restart offer can travel, then make its connection report a drop.
    await a.evaluate(async () => {
        const call = (window as any).__call;
        await call.signaling.leave('rig-cut');
        call.peer.connection.oniceconnectionstatechange = null;
        call.peer.emit('state', 'reconnecting');
    });

    await expect.poll(() => a.evaluate(() => window.__beamState)).toBe('reconnecting');
    await a.waitForTimeout(1500);
    expect(await a.evaluate(() => window.__beamState)).toBe('reconnecting');   // not given up yet
    await expect.poll(() => a.evaluate(() => window.__beamEnded?.reason ?? null), { timeout: 6_000 }).toBe('connection-lost');
});
