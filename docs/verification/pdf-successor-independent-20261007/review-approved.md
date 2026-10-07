# Corrected PDF successor seam — independent approval

Approved **source-only** executable `7501ef9f2f1245493a9ca5a584da8be849dd3d12`,
tree `781a07940729df3b0f361f7cca452f978c55f5a8`. All 19 executable/asset paths
match that source and experimental `a8f57946bf1c6599f19e3c52d71a6564f45fc768`
byte-for-byte. The latter's updated dependency lock is experimental only and is
excluded from publication approval.

Both reproduced direct-renderer version mismatches are corrected. The new V2
renderer requires its own version before any runtime/rendering. The legacy
runtime entry requires V1, protecting the unchanged pinned original renderer.
The explicit version runtime API retains canonical trusted metadata, complete
package pins, implementation hashes and exact asset byte checks. The operational
child still validates the full profile and matches only fixed trusted versions
to two fixed implementations; its bounded loader and process restrictions remain.

Separate independent own-autoload worktrees verified all 158 installed package
versions/references against each real lock. Exactly PDF/font identities differ.
The corrected original graph passed **21 cases / 43 assertions** (16 successor
cases / 21 assertions plus 5 reviewer canaries / 22). The corrected successor
graph passed **21 / 64** (16 / 29 plus 5 / 35). No errors, failures or skips.
Both actual isolated child paths preserve complete independently extracted
normalized text from every frozen section, 150 repeated paragraphs and literal
markup-looking input. Multipage bytes and page timestamps are deterministic;
no PDF URI, JavaScript or embedded-file entries appear. A complete copied V2
runtime first validates; modifying its generated font then refuses on actual
successor packages. V2 configuration cannot activate current issuance.

The actual newer importer reran two font conversions with zero errors and no
asset/manifest diff. All nine font/source/license byte sequences stay identical
to V1. Corrected V2 manifest digest is
`29e0b1b33abc78ab48c6669579dbb3296eb9e282f2a16b0fb337db8417b68dac`;
actual profile hash `c7bf3a77a1f35391a53f23364f0db2c6b30d2ff8fe1d383c894963d9f74ad692`;
actual deterministic PDF digest
`790fd79695a52b8578305c0cbd2c44c9e776c723200db59536f6394653ed1daf`,
751,516 bytes. Original V1 remains `37c92cb5...` with exact retained metadata,
manifest, text, renderer, fixture and candidate lock unchanged. The profile
validator adds the explicit version fence without changing V1 rendered bytes.

I read back the author's completed 140-case / 774-assertion retained six-file
selection and verified all 20 correction artifact digests at metadata
`e7c5a389ebf655fb0d62d1ed3b4e3e750b46c177`. It has zero failures/errors/skips.
That longer selection is author evidence, not an additional reviewer execution.
Both original red probes and the failing predecessor canary remain source-bound
historical evidence and are not relabeled as passing.

There is no remaining sensitive blocker for this source-only preparation seam.
Current profile and policy remain V1. PR #3's actual global dependency adoption
still needs reviewed activation and real pending-V1/original continuity; it
cannot merge with current-V1/runtime mismatch. No full CI/native/browser/provider,
production issuance, archival conformance or launch acceptance is claimed.
