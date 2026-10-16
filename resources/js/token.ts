import { BeamError } from './errors';

/** Must equal Token::VERSION in PHP; tests/js/version.test.ts keeps them together. */
export const PROTOCOL_VERSION = 1;

export type Role = 'caller' | 'callee' | 'participant';
export type Transport = 'controller' | 'whisper';

export interface Claims {
    v: number;
    callId: string;
    channel: string;
    role: Role;
    /** The user this token was issued to — as PHP sent it, so it may be a number. */
    sub: string | number;
    ice: RTCIceServer[];
    exp: number;
    meta: {
        about?: string;
        from?: { id: string | number; name: string; avatar?: string | null };
        media?: 'audio' | 'video' | 'none';
        transport?: Transport;
        heartbeat?: number;
        reconnectTimeout?: number;
        transferMax?: number;
        timeout?: number;
        [key: string]: unknown;
    };
}

export interface DecodedToken {
    /** The opaque, server-trusted token: presented back on every relay request. */
    token: string;
    /** What the browser is allowed to read. */
    claims: Claims;
}

function base64UrlDecode(input: string): string {
    const padded = input.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (input.length % 4)) % 4);
    const bytes = Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    return new TextDecoder().decode(bytes);
}

/**
 * Unpack what PHP's Token::pack() produced. Checks, in order: shape → protocol
 * version → expiry. A token from a newer server fails with a message that says
 * what to update, not a silent hang later.
 */
export function decodeToken(packed: string, now: number = Date.now()): DecodedToken {
    let parsed: unknown;
    try {
        parsed = JSON.parse(base64UrlDecode(packed.trim()));
    } catch {
        throw new BeamError('malformed', 'This is not a Beam token.');
    }

    const obj = parsed as Partial<DecodedToken>;
    const claims = obj?.claims as Partial<Claims> | undefined;

    if (typeof obj?.token !== 'string' || !claims || typeof claims !== 'object') {
        throw new BeamError('malformed', 'This is not a Beam token.');
    }

    if (claims.v !== PROTOCOL_VERSION) {
        throw new BeamError(
            'protocol-mismatch',
            `This token speaks protocol v${String(claims.v)}; this client speaks v${PROTOCOL_VERSION}. Run npm update @realrashid/beam and composer update realrashid/beam together.`,
        );
    }

    if (typeof claims.exp !== 'number' || claims.exp * 1000 <= now) {
        throw new BeamError('expired', 'This Beam token has expired.');
    }

    if (typeof claims.channel !== 'string' || typeof claims.callId !== 'string' || !Array.isArray(claims.ice)
        || !['caller', 'callee', 'participant'].includes(claims.role as string)) {
        throw new BeamError('malformed', 'This Beam token is missing claims it must carry.');
    }

    return { token: obj.token, claims: { ...(claims as Claims), meta: claims.meta ?? {} } };
}
