import { describe, expect, it } from 'vitest';
import { detect, platform } from '../../resources/js/platform';

/** One user agent per browser Beam names a quirk for; each property asserted. */
const UA = {
    safari17: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    ios17: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    ipadOS: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    iosChrome: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.37 Mobile/15E148 Safari/604.1',
    iosFirefox: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/130.0 Mobile/15E148 Safari/605.1.15',
    chrome130: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    edge130: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
    firefox130: 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
    androidChrome: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
};
const desktopCaps = { displayMedia: true, pushManager: true, setSinkId: true };

describe('platform.detect', () => {
    it('Safari 17 on macOS: WebKit quirks, H.264 first, push on a plain page, no setSinkId', () => {
        const p = detect(UA.safari17, { displayMedia: true, pushManager: true, setSinkId: false });
        expect([p.isSafari, p.isIOS, p.isAndroid, p.isFirefox]).toEqual([true, false, false, false]);
        expect(p.supportsDisplayMedia()).toBe(true);
        expect(p.supportsPush()).toBe(true);
        expect(p.supportsSetSinkId()).toBe(false);
        expect(p.preferredVideoCodec()).toBe('H264');
        expect(p.needsUserGestureForPlay()).toBe(true);
    });

    it('iOS 17 Safari: no screen share, push only when installed, H.264, gesture for play', () => {
        const tab = detect(UA.ios17, { displayMedia: false, pushManager: true, standalone: false });
        expect([tab.isSafari, tab.isIOS, tab.isAndroid, tab.isFirefox]).toEqual([true, true, false, false]);
        expect(tab.supportsDisplayMedia()).toBe(false);
        expect(tab.supportsPush()).toBe(false);
        expect(tab.preferredVideoCodec()).toBe('H264');
        expect(tab.needsUserGestureForPlay()).toBe(true);

        const installed = detect(UA.ios17, { displayMedia: false, pushManager: true, standalone: true });
        expect(installed.supportsPush()).toBe(true);
    });

    it('iPadOS pretends to be a Mac and is told apart by touch points', () => {
        expect(detect(UA.ipadOS, { touchPoints: 5 }).isIOS).toBe(true);
        expect(detect(UA.ipadOS, { touchPoints: 0 }).isIOS).toBe(false);
    });

    it('Chrome and Firefox for iOS are WebKit and carry every Safari quirk', () => {
        for (const ua of [UA.iosChrome, UA.iosFirefox]) {
            const p = detect(ua, { displayMedia: true, pushManager: true });
            expect(p.isIOS).toBe(true);
            expect(p.isSafari).toBe(true);
            expect(p.isFirefox).toBe(false);
            expect(p.supportsDisplayMedia()).toBe(false);
            expect(p.preferredVideoCodec()).toBe('H264');
        }
    });

    it('Chrome 130 and Edge 130: nothing to work around', () => {
        for (const ua of [UA.chrome130, UA.edge130]) {
            const p = detect(ua, desktopCaps);
            expect([p.isSafari, p.isIOS, p.isAndroid, p.isFirefox]).toEqual([false, false, false, false]);
            expect(p.supportsDisplayMedia()).toBe(true);
            expect(p.supportsPush()).toBe(true);
            expect(p.supportsSetSinkId()).toBe(true);
            expect(p.preferredVideoCodec()).toBeNull();
            expect(p.needsUserGestureForPlay()).toBe(false);
        }
    });

    it('Firefox 130: Gecko, screen share and push, codec order left alone', () => {
        const p = detect(UA.firefox130, desktopCaps);
        expect([p.isSafari, p.isIOS, p.isAndroid, p.isFirefox]).toEqual([false, false, false, true]);
        expect(p.supportsDisplayMedia()).toBe(true);
        expect(p.supportsSetSinkId()).toBe(true);
        expect(p.preferredVideoCodec()).toBeNull();
    });

    it('Android Chrome: no screen share even when getDisplayMedia is present; push on a plain page', () => {
        const p = detect(UA.androidChrome, { displayMedia: true, pushManager: true, setSinkId: true });
        expect([p.isSafari, p.isIOS, p.isAndroid, p.isFirefox]).toEqual([false, false, true, false]);
        expect(p.supportsDisplayMedia()).toBe(false);
        expect(p.supportsPush()).toBe(true);
        expect(p.needsUserGestureForPlay()).toBe(false);
    });

    it('capabilities default to unsupported when unknown', () => {
        const p = detect(UA.chrome130);
        expect(p.supportsDisplayMedia()).toBe(false);
        expect(p.supportsPush()).toBe(false);
        expect(p.supportsSetSinkId()).toBe(false);
    });

    it('the page-level detection runs in jsdom without throwing and reads as nothing special', () => {
        expect(platform.isSafari || platform.isIOS || platform.isAndroid || platform.isFirefox).toBe(false);
        expect(platform.preferredVideoCodec()).toBeNull();
    });
});
