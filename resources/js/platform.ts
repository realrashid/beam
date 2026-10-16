/**
 * Every browser quirk Beam works around, in one place, each with the browser
 * it exists for. Nothing else in the client reads a user agent: the rest of
 * the code asks `platform` a question and applies the answer.
 *
 * `platform` is detected once from the page's own navigator. `detect()` takes
 * a user agent and the capabilities that matter, so a test can pin a browser.
 */
export interface Capabilities {
    /** navigator.maxTouchPoints — iPadOS 13+ presents a macOS user agent and is told apart by touch. */
    touchPoints?: number;
    /** navigator.mediaDevices.getDisplayMedia exists. */
    displayMedia?: boolean;
    /** window.PushManager exists and the page can register a service worker. */
    pushManager?: boolean;
    /** HTMLMediaElement.prototype.setSinkId exists. */
    setSinkId?: boolean;
    /** The page runs as an installed web app: navigator.standalone on iOS, display-mode: standalone elsewhere. */
    standalone?: boolean;
}

export interface Platform {
    /** WebKit-engined: Safari on macOS, and every browser on iOS — Apple allows no other engine there, so Chrome and Firefox for iOS share Safari's quirks. */
    isSafari: boolean;
    isIOS: boolean;
    isAndroid: boolean;
    /** Gecko-engined Firefox. Firefox for iOS (FxiOS) is WebKit and reads as Safari. */
    isFirefox: boolean;
    supportsDisplayMedia(): boolean;
    supportsPush(): boolean;
    supportsSetSinkId(): boolean;
    preferredVideoCodec(): 'H264' | 'VP8' | null;
    needsUserGestureForPlay(): boolean;
}

export function detect(ua: string, caps: Capabilities = {}): Platform {
    // iPadOS 13+ sends a Macintosh user agent; a Mac with more than one touch point is an iPad.
    const isIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && (caps.touchPoints ?? 0) > 1);
    const isAndroid = /Android/.test(ua);
    // Only Gecko carries "Firefox/"; FxiOS carries "FxiOS/" and is WebKit.
    const isFirefox = /Firefox\//.test(ua);
    // Every WebKit browser says "Safari/"; Chromium ones add their own token. iOS is WebKit whatever the token.
    const isSafari = isIOS || (/Safari\//.test(ua) && !/Chrome|Chromium|CriOS|Edg|OPR|Android|Firefox/.test(ua));

    return {
        isSafari,
        isIOS,
        isAndroid,
        isFirefox,
        // iOS has no screen picker in any browser; Android browsers either omit getDisplayMedia or reject it at call time (Chrome for Android has never shown one). Elsewhere the API's presence is the answer.
        supportsDisplayMedia: () => (caps.displayMedia ?? false) && !isAndroid && !isIOS,
        // iOS 16.4+ delivers Web Push only to a web app on the Home Screen; macOS Safari 16+, Chromium and Gecko take a plain page.
        supportsPush: () => (caps.pushManager ?? false) && (!isIOS || (caps.standalone ?? false)),
        // Chromium and Firefox 116+ implement setSinkId; Safari does not (through 18), so the remote audio follows the system output there.
        supportsSetSinkId: () => caps.setSinkId ?? false,
        // Safari encodes VP8 in software; H.264 has a hardware path on every Apple device, so it goes first there. Elsewhere the browser's own order stands.
        preferredVideoCodec: () => (isSafari ? 'H264' : null),
        // Safari and iOS refuse play() with sound until the page has had a tap; Chromium allows it once the origin has had any interaction.
        needsUserGestureForPlay: () => isSafari,
    };
}

function fromBrowser(): Platform {
    if (typeof navigator === 'undefined') return detect('');

    const nav = navigator as Navigator & { standalone?: boolean };
    const mediaProto = typeof HTMLMediaElement !== 'undefined' ? HTMLMediaElement.prototype : {};

    return detect(nav.userAgent ?? '', {
        touchPoints: nav.maxTouchPoints,
        displayMedia: typeof nav.mediaDevices?.getDisplayMedia === 'function',
        pushManager: typeof window !== 'undefined' && 'PushManager' in window && 'serviceWorker' in nav,
        setSinkId: 'setSinkId' in mediaProto,
        standalone: nav.standalone === true || (typeof matchMedia === 'function' && matchMedia('(display-mode: standalone)').matches),
    });
}

/** The browser this page runs in. */
export const platform: Platform = fromBrowser();
