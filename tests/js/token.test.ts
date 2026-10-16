import { describe, expect, it } from 'vitest';
import { BeamError } from '../../resources/js/errors';
import { decodeToken, PROTOCOL_VERSION } from '../../resources/js/token';

const pack = (obj: unknown) => Buffer.from(JSON.stringify(obj)).toString('base64url');
const claims = (over: Record<string, unknown> = {}) => ({
    v: 1, callId: 'call_1', channel: 'beam.call.call_1', role: 'callee', sub: 7,
    ice: [{ urls: ['stun:s'] }, { urls: ['turn:t'], username: 'u', credential: 'c' }],
    exp: Math.floor(Date.now() / 1000) + 60, meta: { transport: 'controller', media: 'video' }, ...over,
});

describe('decodeToken', () => {
    it('decodes a packed token into the opaque token and readable claims', () => {
        const { token, claims: c } = decodeToken(pack({ token: 'opaque', claims: claims() }));
        expect(token).toBe('opaque');
        expect(c.channel).toBe('beam.call.call_1');
        expect(c.ice[1]).toEqual({ urls: ['turn:t'], username: 'u', credential: 'c' });
        expect(c.meta.transport).toBe('controller');
        expect(c.sub).toBe(7);
    });

    it('accepts the base64url alphabet PHP produces (no padding, - and _)', () => {
        const packed = pack({ token: 'opaque', claims: claims({ about: '???~~~' }) });
        expect(packed).not.toMatch(/[+/=]/);
        expect(() => decodeToken(packed)).not.toThrow();
    });

    it('refuses garbage as malformed', () => {
        expect(() => decodeToken('not-a-token')).toThrow(BeamError);
        expect(() => decodeToken('not-a-token')).toThrow(/not a Beam token/);
        expect(() => decodeToken(pack({ claims: claims() }))).toThrow(expect.objectContaining({ reason: 'malformed' }));
    });

    it('refuses another protocol version with a message that says what to update', () => {
        expect(() => decodeToken(pack({ token: 'x', claims: claims({ v: PROTOCOL_VERSION + 1 }) })))
            .toThrow(expect.objectContaining({ reason: 'protocol-mismatch', message: expect.stringContaining('npm update @realrashid/beam') }));
    });

    it('refuses an expired token', () => {
        expect(() => decodeToken(pack({ token: 'x', claims: claims({ exp: Math.floor(Date.now() / 1000) - 1 }) })))
            .toThrow(expect.objectContaining({ reason: 'expired' }));
    });

    it('refuses claims missing what the client needs', () => {
        for (const bad of [{ channel: undefined }, { ice: 'nope' }, { role: 'root' }, { callId: 3 }]) {
            expect(() => decodeToken(pack({ token: 'x', claims: claims(bad) }))).toThrow(expect.objectContaining({ reason: 'malformed' }));
        }
    });

    it('defaults meta to an empty object', () => {
        expect(decodeToken(pack({ token: 'x', claims: claims({ meta: undefined }) })).claims.meta).toEqual({});
    });
});
