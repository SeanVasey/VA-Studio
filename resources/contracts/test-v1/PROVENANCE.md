# Offline test contract font profile

These unmodified DejaVu Sans source fonts come from `tecnickcom/tc-font-mirror`
release `2.4.0`, commit `3251310e5f8e92659ac3ef1133e591ed681ef95d`.
The accompanying original `fonts/source/LICENSE` applies to the fonts. This is
a bounded local/testing profile, with no production or legal approval implied.

| Source path in that commit | Git blob SHA-1 | SHA-256 |
| --- | --- | --- |
| `dejavu/ttf/DejaVuSans.ttf` | `e5f7eecce43be41ff0703ed99e1553029b849f14` | `7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954` |
| `dejavu/ttf/DejaVuSans-Bold.ttf` | `6d65fa7dc41ae8ffae77a4a843a73ba31ffd78c7` | `e6476c1b80502924294eed40894c5b18e06c181444ca953e5334262df9c27724` |
| `dejavu/LICENSE` | `df52c1709bea171104d41bf084313ea434858423` | `7a083b136e64d064794c3419751e5c7dd10d2f64c108fe5ba161eae5e5958a93` |

`scripts/build-contract-profile.php` verifies those source hashes before using
the real Composer-locked `tc-lib-pdf-font` 4.4.0 importer to produce offline
TrueTypeUnicode font definitions/programs. It records SHA-256 hashes for each
generated asset, exact installed Tecnick package versions/references, and the
versioned template/renderer source files. It
does not run the upstream nested Composer or bulk font download targets.

The build has no customer data. Rendering permits only the committed generated
font directory as an internal resource, with no markup file or remote resources.
Glyph and script coverage are checked before rendering; these fonts do not
establish universal Unicode or complex-script support.
