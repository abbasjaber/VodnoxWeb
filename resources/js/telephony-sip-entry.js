/*
 * VODNOX browser-telephony bundle entry.
 *
 * Only this file and the built output are shipped: the telephony pages load
 * `public/assets/vodnox/vendor/sip.min.js`, which exposes the global `SIP`
 * namespace used by `public/assets/vodnox/telephony.js`. Production hosting
 * never needs npm or a dev server.
 *
 * Build: npm run build:telephony
 */
import * as SIP from 'sip.js';

window.SIP = SIP;
