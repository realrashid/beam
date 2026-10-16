/**
 * A root-free TURN relay for local relay-only runs that verifies coturn's
 * `use-auth-secret` scheme exactly: for a username "expiry:user" the password
 * is base64(HMAC-SHA1(secret, username)), and an expired username is refused.
 * node-turn's long-term mechanism does the 401 / nonce / MESSAGE-INTEGRITY
 * dance libwebrtc expects; a Proxy supplies the password per username.
 * CI uses real coturn; this proves the same thing on a developer machine.
 *
 *   TURN_SECRET=rig-secret node tests/Browser/turn-server.mjs [port]
 */
import { createHmac } from 'node:crypto';
import { networkInterfaces } from 'node:os';
import Turn from 'node-turn';

const secret = process.env.TURN_SECRET ?? 'rig-secret';
const isPrivate = (a) => /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(a);
const candidates = Object.values(networkInterfaces()).flat().filter((i) => i && i.family === 'IPv4' && !i.internal && !i.address.startsWith('192.0.2.')).map((i) => i.address);
const lan = process.env.TURN_RELAY_IP ?? candidates.find(isPrivate) ?? candidates[0] ?? '127.0.0.1';
const port = Number(process.argv[2] ?? 3478);

const credentials = new Proxy({}, {
    get(_, username) {
        if (typeof username !== 'string') return undefined;
        const [expiry] = username.split(':');
        if (!/^\d+$/.test(expiry) || Number(expiry) <= Math.floor(Date.now() / 1000)) return undefined;   // expired or malformed → 401
        return createHmac('sha1', secret).update(username).digest('base64');
    },
});

const server = new Turn({
    listeningPort: port,
    listeningIps: ['127.0.0.1', lan],
    relayIps: [lan],
    minPort: 49152,
    maxPort: 49200,
    authMech: 'long-term',
    realm: 'beam.test',
    credentials,
    debugLevel: process.env.TURN_DEBUG ? 'DEBUG' : 'ERROR',
});
server.start();
console.log(`turn: listening on 127.0.0.1:${port} and ${lan}:${port} (udp), relaying on ${lan}, coturn-style auth`);
process.on('SIGTERM', () => { server.stop(); process.exit(0); });
process.on('SIGINT', () => { server.stop(); process.exit(0); });
