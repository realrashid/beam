import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { expect, test, waitForRemoteVideo } from './fixtures';
import { rigDir } from './rig';

/**
 * The starter screens `beam:install --views` copies into an app: the rig app
 * runs the command for real, then a plain-Beam peer joins the same room and
 * the copied Blade screen must show frames, count seconds, mute and hang up.
 */
test.beforeAll(() => {
    execFileSync('php', ['artisan', 'beam:install', '--views', '--force'], { cwd: rigDir(), stdio: 'ignore' });
    expect(existsSync(`${rigDir()}/resources/views/beam/call.blade.php`)).toBe(true);
    expect(existsSync(`${rigDir()}/resources/views/beam/incoming.blade.php`)).toBe(true);

    // The stub takes Echo from the app's Vite bundle; the rig has no Vite build and loads Echo from script tags instead.
    const copied = `${rigDir()}/resources/views/beam/call.blade.php`;
    const screen = readFileSync(copied, 'utf8');
    expect(screen).toContain("@vite(['resources/js/app.js'])");
    writeFileSync(copied, screen.replace("@vite(['resources/js/app.js'])", "@include('rig-echo')"));
});

test('the copied call screen connects, shows state and duration, mutes and hangs up', async ({ peers }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `stub-${Date.now()}`;
    await a.goto(`/stub/call/${room}`);
    await b.goto(`/room/${room}`);

    await waitForRemoteVideo(a, '#beam-remote', 20_000);
    await waitForRemoteVideo(b, '#them', 20_000);
    await expect(a.locator('[data-beam="state"]')).toHaveText('connected');
    await expect.poll(() => a.locator('[data-beam="duration"]').textContent(), { timeout: 5_000 }).not.toBe('0:00');

    await a.click('[data-beam="mute"]');
    await expect(a.locator('[data-beam="mute"]')).toHaveText('Unmute');
    await expect.poll(() => b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream).getAudioTracks()[0]?.readyState)).toBe('live');

    await a.click('[data-beam="hangup"]');
    await expect.poll(() => b.evaluate(() => window.__beamEnded?.reason ?? null), { timeout: 10_000 }).toBe('hangup');
    await expect(a.locator('[data-beam="state"]')).toContainText('ended');
});

test('the incoming modal rings, and Decline tells the caller', async ({ peers, request }) => {
    const { a, b } = peers;
    await b.goto('/login-as/2');
    await b.goto('/stub/dashboard');
    await b.waitForFunction(() => (window as any).Echo?.connector?.pusher?.connection?.state === 'connected');

    await a.goto('/login-as/1');
    const rung = await (await a.request.post('/call/2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })).json();

    const modal = b.locator('#beam-incoming');
    await expect(modal).toBeVisible({ timeout: 10_000 });
    await expect(modal.locator('[data-beam="from"]')).toHaveText('User 1');
    await b.click('[data-beam="decline"]');
    await expect(modal).toBeHidden();

    await expect.poll(async () => (await (await request.get('/test/events')).json()).map((e: { event: string }) => e.event), { timeout: 10_000 }).toContain('CallDeclined');
    expect(rung.callId).toMatch(/^call_/);
});
