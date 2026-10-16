import Beam, { type Incoming, type JoinOptions } from './beam';
import type { Call } from './call';
import type { FileOffer, Transfer } from './transfer';

type JQueryLike = ((...args: unknown[]) => { on: (...a: unknown[]) => unknown; trigger: (name: string, args?: unknown[]) => unknown }) & { beam?: BeamJQuery };

export interface BeamJQuery {
    /** Join; the resolved call also triggers its events as jQuery events: $(call).on('ended', (e, { reason }) => …). */
    join(token: string, options?: JoinOptions): Promise<Call>;
    onIncoming(handler: (incoming: Incoming) => void): () => void;
    onFileOffered(handler: (offer: FileOffer) => void): () => void;
    send(file: File | Blob, name?: string): { to(userId: string | number): Transfer };
    push: typeof Beam.push;
    configure: typeof Beam.configure;
}

const EVENTS = ['state', 'connected', 'reconnecting', 'ended', 'error', 'quality', 'tick', 'muted', 'camera', 'sharing', 'remotetrack'] as const;

/**
 * $.beam — the class behind a namespace jQuery code expects. attach() already
 * accepts jQuery objects; this bridges call events onto $(call).on(). About
 * thirty lines, and nothing about it reads as foreign.
 */
export function install($: JQueryLike): BeamJQuery {
    const beam: BeamJQuery = {
        async join(token, options) {
            const call = await Beam.join(token, options);
            for (const name of EVENTS) {
                call.on(name, (...args: unknown[]) => { $(call).trigger(name, args); });
            }
            return call;
        },
        onIncoming: (handler) => Beam.onIncoming(handler),
        onFileOffered: (handler) => Beam.onFileOffered(handler),
        send: (file, name) => Beam.send(file, name),
        get push() { return Beam.push; },
        configure: (config) => Beam.configure(config),
    };
    $.beam = beam;
    return beam;
}

// Importing '@realrashid/beam/jquery' installs $.beam on the jQuery already on the page.
// Under @beamAssets (the IIFE build) the entry does this itself, and a page without jQuery
// is the normal case rather than a mistake — Vite resolves the mode at build time.
if (import.meta.env.MODE !== 'iife') {
    const jq = (globalThis as { jQuery?: JQueryLike }).jQuery;
    if (jq) {
        install(jq);
    } else if (typeof console !== 'undefined') {
        console.warn('@realrashid/beam/jquery: jQuery is not on window, so $.beam was not installed. Load jQuery first, or call install($) yourself.');
    }
}

export { Beam };
