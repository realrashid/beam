import { defineConfig, devices } from '@playwright/test';

/**
 * The two-browser rig. Every spec opens two browser contexts and asserts they
 * connect to each other through a real Reverb server, with fake cameras.
 *
 * Chromium: fake media comes from launch flags.
 * WebKit:   there are no flags. Granting camera and microphone is what turns
 *           on Playwright's deterministic mock capture streams (WebKit r2332,
 *           rolled in July 2026), which is why this file pins a Playwright
 *           from after that release.
 * Firefox:  fake media and no prompt by preference; no permission grants exist.
 */
export default defineConfig({
    testDir: 'tests/Browser',
    timeout: 30_000,
    fullyParallel: false,
    // Real WebRTC between real browsers on one machine: a second worker's load shows up as timeouts.
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: 'http://localhost:8090',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: {
                    args: [
                        '--use-fake-device-for-media-stream=device-count=2',
                        '--use-fake-ui-for-media-stream',
                        // getDisplayMedia would otherwise show a picker nobody can click.
                        '--auto-select-desktop-capture-source=Entire screen',
                        // A relay on this machine hands out local addresses; Chromium filters those from ICE unless told not to.
                        '--allow-loopback-in-peer-connection',
                    ],
                },
                permissions: ['camera', 'microphone'],
            },
        },
        {
            name: 'webkit',
            use: {
                ...devices['Desktop Safari'],
                permissions: ['camera', 'microphone'],
            },
        },
        {
            name: 'firefox',
            // Gecko under the runner is several times slower than Chromium at opening pages; the specs' own waits are unchanged.
            timeout: 120_000,
            use: {
                ...devices['Desktop Firefox'],
                launchOptions: {
                    firefoxUserPrefs: {
                        // Gecko's fake camera and microphone, and no permission prompt for them.
                        'media.navigator.streams.fake': true,
                        'media.navigator.permission.disabled': true,
                        // A relay on this machine hands out local addresses.
                        'media.peerconnection.ice.loopback': true,
                        // Gecko blocks unmuted autoplay until a click; the specs assert the remote video is playing.
                        'media.autoplay.default': 0,
                        'media.autoplay.blocking_policy': 0,
                    },
                },
            },
        },
    ],
    webServer: {
        command: 'php tests/Browser/server.php',
        port: 8090,
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
