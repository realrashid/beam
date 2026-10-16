import { expect, test, waitForRemoteVideo } from './fixtures';

/**
 * Sketch §9: the same two lines in every frontend. Each adapter page joins a
 * room through its own idiom — $.beam with no build step, useBeam() in Vue,
 * useBeam() in React — against a plain page on the other side.
 */
for (const frontend of ['jquery', 'vue', 'react'] as const) {
    test(`${frontend}: joins through the adapter, shows state, mutes, hangs up`, async ({ peers }) => {
        const { a, b } = peers;
        await a.goto('/login-as/1');
        await b.goto('/login-as/2');
        const room = `adapter-${frontend}-${Date.now()}`;
        await a.goto(`/adapters/${frontend}/${room}`);
        await b.goto(`/room/${room}`);

        await waitForRemoteVideo(a, '#them', 20_000);
        await waitForRemoteVideo(b, '#them', 20_000);
        expect(await a.evaluate(() => (window as any).__beamError ?? null)).toBeNull();
        await expect(a.locator('#state')).toHaveText('connected');

        if (frontend !== 'jquery') {
            // Reactive state: the duration renders and advances.
            await expect.poll(() => a.locator('#duration').textContent(), { timeout: 5_000 }).not.toBe('0');
            await a.click('#mute');
            await expect(a.locator('#mute')).toHaveText('Unmute');
            expect(await a.evaluate(() => (window as any).__call.isMuted)).toBe(true);
        }

        await a.click('#hangup');
        await expect.poll(() => b.evaluate(() => window.__beamEnded?.reason ?? null), { timeout: 10_000 }).toBe('hangup');
        if (frontend === 'jquery') {
            await expect.poll(() => a.evaluate(() => (window as any).__beamEnded?.reason ?? null)).toBe('hangup');   // bridged through $(call).on('ended')
        }
    });
}
