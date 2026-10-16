// The IIFE entry: only a default export, so the built bundle assigns the class
// itself to window.Beam. See vite.config.ts.
import Beam from './beam';
import { install } from './jquery';

// A page with jQuery loaded before @beamAssets gets $.beam as well as window.Beam.
const jq = (globalThis as { jQuery?: Parameters<typeof install>[0] }).jQuery;
if (jq) install(jq);

export default Beam;
