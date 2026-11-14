# Independent Tax HTTP source correction and carry

DECISION: APPROVE SOURCE AND NARROW CARRY WITH CONDITIONS

Reviewed exact `bb3d29983d6d225cfb62d9607beaa54874f3aa03`; application source is byte-identical to approved `e3f06b084538dac11665ff9be77c74228bdab64d`. Only the real producer CSRF regression was added. No open P0/P1 or required source repair found in this bounded gate. Final sealed evidence/publication approval remains outstanding.

The earlier parent/reviewer assumption about producer CSRF mapping was incorrect. Laravel's routing pipeline catches the actual TokenMismatch inside the route slice and sends it through the configured exception handler, which maps it to419 and invokes the root private response handling. The delegated producer privacy receives a Response, so its generic exception catch does not convert this supported CSRF path to503. **No mapping patch is required.** Prior immutable review records remain unchanged; this original explicitly corrects that assumption.

The new real CSRF control disables the unit-test bypass, checks valid token→guest403, invalid token→419, old token after rotation→419 and fresh token→guest403, with private headers/no redirect/private marker/flash. Independently reconciled actual result: **1 test / 17 assertions**, zero failures/errors/skips, PHP/recorder/launch-bound observed OS all0. The anticipated-red run name is preserved; its outcome is a pass, not a defect oracle.

Also reconciled the e3 focused continuation: **2 tests / 40 assertions**, zero failures/errors/skips, all three exits0, exact launch identities and stable2423 inputs/runner/autoload/source. This directly covers corrected two-line PDF/master delivery and genuine elapsed original timing. Narrow source/continuation carry to bb is justified by the empty application/runtime diff. Earlier producer/V1 component carries stay prior-source evidence and the original ae1 whole48/970/E1 remains failed; no whole-current journey or native claim is made.

The current whole-root result reports45/597, PHP/recorder0; its OS witness is not evaluated in this note. Complete final receipt/static/source/loader/component reconciliation and the independent sealed-packet decision on the actual head, then cheap exact-head preflight and expected-SHA merge. Default-off/unbound/private synthetic development scope and all live/provider/host/full-suite/native/frozen-source limits remain. No reviewer test, DB, network/hosted action or tracked edit occurred.
