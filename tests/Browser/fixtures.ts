import { test as base, type Browser, type BrowserContext, type Page } from '@playwright/test';

/**
 * Two isolated browser contexts — two people — in one test.
 * Contexts do not share cookies, so `a` and `b` can be logged in as different users.
 */
export type TwoPeers = { a: Page; b: Page; contexts: [BrowserContext, BrowserContext] };

export async function twoPeers(browser: Browser): Promise<TwoPeers> {
    const [ca, cb] = await Promise.all([browser.newContext(), browser.newContext()]);
    // Firefox has no permission grants in Playwright; its project turns the prompt off by preference instead.
    if (browser.browserType().name() !== 'firefox') {
        await Promise.all([
            ca.grantPermissions(['camera', 'microphone']),
            cb.grantPermissions(['camera', 'microphone']),
        ]);
    }
    const [a, b] = await Promise.all([ca.newPage(), cb.newPage()]);

    return { a, b, contexts: [ca, cb] };
}

/**
 * Resolves once a <video> is actually playing a stream with real frames —
 * readyState >= HAVE_CURRENT_DATA, not paused, and non-zero dimensions.
 * A popover can look right while pointing at nothing; a video element can
 * exist while showing nothing. This asserts the frames.
 */
// 20 s is a ceiling, not an expectation: two Chromiums on one machine connect in 3–5 s, but a slow laptop
// spends that long just opening the pages, and a timeout here would read as a WebRTC failure.
export async function waitForRemoteVideo(page: Page, selector: string, timeout = 20_000): Promise<void> {
    await page.waitForFunction(
        (sel) => {
            const v = document.querySelector<HTMLVideoElement>(sel);
            return !!v && v.readyState >= 2 && !v.paused && v.videoWidth > 0 && v.videoHeight > 0;
        },
        selector,
        { timeout },
    );
}

export const test = base.extend<{ peers: TwoPeers }>({
    peers: async ({ browser }, use) => {
        const peers = await twoPeers(browser);
        await use(peers);
        await Promise.all(peers.contexts.map((c) => c.close()));
    },
});

export { expect } from '@playwright/test';
