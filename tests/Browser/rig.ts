import { resolve } from 'node:path';

/** Where the rig's Laravel app lives: outside the package, built by tests/Browser/rig.sh. */
export const rigDir = (): string => resolve(process.env.BEAM_RIG_DIR ?? resolve(__dirname, '..', '..', '..', 'beam-rig'));
