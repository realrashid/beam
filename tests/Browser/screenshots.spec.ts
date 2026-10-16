import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { expect, test, waitForRemoteVideo } from './fixtures';
import { rigDir } from './rig';

/**
 * The pictures in docs/ come from the rig, not from a design tool: the copied
 * call screen connected, the incoming modal ringing, a transfer mid-way, the
 * reconnecting banner, and beam:doctor's output. SCREENSHOTS=1 turns it on.
 */
const OUT = 'docs/public/screenshots';
test.skip(!process.env.SCREENSHOTS, 'Set SCREENSHOTS=1 to refresh docs/public/screenshots');
test.use({ viewport: { width: 1200, height: 760 } });

test.beforeAll(() => {
    mkdirSync(OUT, { recursive: true });
    execFileSync('php', ['artisan', 'beam:install', '--views', '--force'], { cwd: rigDir(), stdio: 'ignore' });
    const copied = `${rigDir()}/resources/views/beam/call.blade.php`;
    writeFileSync(copied, readFileSync(copied, 'utf8').replace("@vite(['resources/js/app.js'])", "@include('rig-echo')"));
});

test('a connected call on the copied screen, and the reconnecting banner', async ({ peers }) => {
    const { a, b } = peers;
    await a.setViewportSize({ width: 1200, height: 760 });
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `shot-${Date.now()}`;
    await a.goto(`/stub/call/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#beam-remote', 20_000);
    await expect(a.locator('[data-beam="state"]')).toHaveText('connected');
    await a.waitForTimeout(2500);   // a few seconds on the clock
    await a.screenshot({ path: `${OUT}/call-connected.png` });

    // The banner, without waiting for a real drop: the state the screen shows when the peer reports reconnecting.
    await a.evaluate(() => { const banner = document.querySelector<HTMLElement>('[data-beam="banner"]')!; banner.hidden = false; document.querySelector('[data-beam="state"]')!.textContent = 'reconnecting'; });
    await a.screenshot({ path: `${OUT}/call-reconnecting.png` });
});

test('the incoming-call modal', async ({ peers }) => {
    const { a, b } = peers;
    await b.setViewportSize({ width: 900, height: 600 });
    await b.goto('/login-as/2');
    await b.goto('/stub/dashboard');
    await b.waitForFunction(() => (window as any).Echo?.connector?.pusher?.connection?.state === 'connected');
    await a.goto('/login-as/1');
    await a.request.post('/call/2', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    await expect(b.locator('#beam-incoming')).toBeVisible({ timeout: 10_000 });
    await b.screenshot({ path: `${OUT}/incoming-modal.png` });
    await b.click('[data-beam="decline"]');
});

test('a file transfer mid-way', async ({ peers }) => {
    test.setTimeout(180_000);
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `shot-xfer-${Date.now()}`;
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them', 20_000);
    await a.evaluate(() => { (window as any).__otherId = '2'; });
    await b.evaluate(() => { (window as any).__otherId = '1'; });
    await a.evaluate((n) => void (window as any).__send(n), 12 * 1024 * 1024);
    await expect.poll(() => a.evaluate(() => (window as any).__transferProgress.at(-1) ?? 0), { timeout: 90_000 }).toBeGreaterThan(30);
    await a.evaluate(() => {
        const p = (window as any).__transferProgress.at(-1);
        const bar = document.createElement('div');
        bar.style.cssText = 'position:fixed;left:24px;right:24px;bottom:24px;height:14px;background:#222;border-radius:7px;overflow:hidden';
        bar.innerHTML = `<div style="width:${p}%;height:100%;background:#5eead4"></div><span style="position:absolute;right:8px;top:-22px;font:13px system-ui;color:#ddd">rig.bin · ${p}%</span>`;
        document.body.appendChild(bar);
    });
    await a.screenshot({ path: `${OUT}/transfer-progress.png` });
    await a.evaluate(() => (window as any).__transfer?.cancel());
});

test('beam:doctor output', async ({ page }) => {
    // Exit code 1 is expected on the rig (no gate in production is not the rig's concern, but a yellow TURN line is); the text is what matters.
    const out = spawnSync('php', ['artisan', 'beam:doctor'], { cwd: rigDir(), encoding: 'utf8' }).stdout.replace(/\x1b\[[0-9;]*m/g, '');
    await page.setViewportSize({ width: 900, height: 520 });
    await page.setContent(`<body style="margin:0;background:#0f1115"><pre style="margin:0;padding:24px;font:14px/1.6 ui-monospace,monospace;color:#e6e8ec;white-space:pre-wrap">$ php artisan beam:doctor\n${out.replace(/</g, '&lt;')}</pre></body>`);
    await page.screenshot({ path: `${OUT}/doctor.png`, fullPage: true });
});
