/*
 * Registered before the tests through `node --import`: routes the gateway's widget package to its
 * stand-in, because the real one needs a browser to be imported at all.
 */
import { register } from 'node:module';

register('./resolve-stubs.mjs', import.meta.url);
