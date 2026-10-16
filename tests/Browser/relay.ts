import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createSocket } from 'node:dgram';
import { randomBytes } from 'node:crypto';
import { networkInterfaces } from 'node:os';

/**
 * The TURN relay the rig's relay-only specs run against, on this machine.
 *
 * Preferred: real coturn in a container (Docker on CI, Docker or Podman on a
 * developer machine), with the same --use-auth-secret scheme the CoturnDriver
 * mints for. Fallback: node-turn (tests/Browser/turn-server.mjs), root-free but
 * a JavaScript relay that wedges under data-channel load — enough to prove a
 * relay-only video call, not a file transfer; specs that push bytes through it
 * skip with a reason.
 *
 * Chromium filters relay candidates on 127.0.0.1, so the relay address is the
 * machine's own LAN address; --allow-loopback-in-peer-connection lets the
 * browsers reach it. Stopping the relay is a real network drop.
 */
export const RELAY_PORT = 3478;
export const RELAY_SECRET = 'rig-secret';
const CONTAINER = 'beam-rig-coturn';

const isPrivate = (a: string) => /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(a);
export const lanAddress = (): string => {
    const all = Object.values(networkInterfaces()).flat().filter((i): i is NonNullable<typeof i> => !!i && i.family === 'IPv4' && !i.internal && !i.address.startsWith('192.0.2.')).map((i) => i.address);
    return process.env.TURN_RELAY_IP ?? all.find(isPrivate) ?? all[0] ?? '127.0.0.1';
};

const has = (bin: string): boolean => { try { execFileSync(bin, ['--version'], { stdio: 'ignore' }); return true; } catch { return false; } };
const engine = (): string | null => (process.env.BEAM_RELAY === 'node' ? null : has('docker') ? 'docker' : has('podman') ? 'podman' : null);

export type RelayKind = 'coturn' | 'node-turn';
let running: { kind: RelayKind; child?: ChildProcess } | null = null;

/** Which relay startRelay() will run here — for `test.skip(relayKind() !== 'coturn', …)`. */
export const relayKind = (): RelayKind => (engine() ? 'coturn' : 'node-turn');

export async function startRelay(): Promise<RelayKind> {
    const bin = engine();
    const lan = lanAddress();

    if (bin) {
        try { execFileSync(bin, ['rm', '-f', CONTAINER], { stdio: 'ignore' }); } catch { /* not running */ }
        execFileSync(bin, [
            'run', '-d', '--name', CONTAINER, '--network', 'host', 'docker.io/coturn/coturn',
            '--use-auth-secret', `--static-auth-secret=${RELAY_SECRET}`, '--realm=beam.test',
            `--listening-port=${RELAY_PORT}`, '--listening-ip=127.0.0.1', `--listening-ip=${lan}`, `--relay-ip=${lan}`,
            '--min-port=49152', '--max-port=49200', '--no-tls', '--no-cli',
        ], { stdio: 'ignore' });
        running = { kind: 'coturn' };
    } else {
        const child = spawn('node', ['tests/Browser/turn-server.mjs', String(RELAY_PORT)], { stdio: ['ignore', 'ignore', 'inherit'], env: { ...process.env, TURN_SECRET: RELAY_SECRET, TURN_RELAY_IP: lan } });
        running = { kind: 'node-turn', child };
    }

    await answersStun(15_000);
    return running.kind;
}

export async function stopRelay(): Promise<void> {
    if (!running) return;
    const { kind, child } = running;
    running = null;

    if (kind === 'coturn') {
        execFileSync(engine()!, ['rm', '-f', CONTAINER], { stdio: 'ignore' });
        return;
    }
    await new Promise<void>((resolve) => { if (!child || child.exitCode !== null) return resolve(); child.once('exit', () => resolve()); child.kill('SIGTERM'); });
}

/** A STUN Binding request to the relay port; every TURN server answers it without credentials. */
function answersStun(timeout: number): Promise<void> {
    const deadline = Date.now() + timeout;
    const request = Buffer.concat([Buffer.from([0x00, 0x01, 0x00, 0x00, 0x21, 0x12, 0xa4, 0x42]), randomBytes(12)]);

    return new Promise((resolve, reject) => {
        const socket = createSocket('udp4');
        const timer = setInterval(() => {
            if (Date.now() > deadline) { clearInterval(timer); socket.close(); reject(new Error(`no relay answering on 127.0.0.1:${RELAY_PORT}`)); return; }
            socket.send(request, RELAY_PORT, '127.0.0.1');
        }, 250);
        socket.once('message', () => { clearInterval(timer); socket.close(); resolve(); });
        socket.on('error', () => { /* retried by the interval */ });
    });
}
