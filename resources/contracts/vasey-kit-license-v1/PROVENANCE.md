# vasey-kit-license-pdf-v1 provenance

The sound-kit license profile (BL-66) prints in the same visual system as `vasey-license-pdf-v2`: the same four brand faces
(Bebas Neue, Reddit Sans regular and bold, JetBrains Mono, Latin and Latin Extended), the same unmodified VASEY.AUDIO lockup,
the same page layout, and Sean's signature from the same private path and SHA-256 pin
(`contracts.vasey_license_v1`). It therefore owns no font or image file. `profile-assets.json` pins each shared file in
`resources/contracts/vasey-license-v2/` by SHA-256 and size, the tc-lib package graph, and the implementation closure of the
kit renderer. Sources, licenses and conversion of the faces are documented in `resources/contracts/vasey-license-v2/PROVENANCE.md`.

Rebuild: `php scripts/build-contract-profile-vasey-kit-license-v1.php` (`--check` compares without writing). No signature, no
license wording and no customer or price data is in this directory.
