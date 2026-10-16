import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// This file is ESM (package.json: "type": "module"), where __dirname does not
// exist — only visible once CI ran a spec that imports this without
// BEAM_RIG_DIR already set, since the fallback below is otherwise never
// evaluated.
const here = dirname(fileURLToPath(import.meta.url));

/** Where the rig's Laravel app lives: outside the package, built by tests/Browser/rig.sh. */
export const rigDir = (): string => resolve(process.env.BEAM_RIG_DIR ?? resolve(here, '..', '..', '..', 'beam-rig'));
