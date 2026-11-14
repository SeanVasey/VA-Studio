# VASEY.AUDIO active brand theme

Evidence date: 2026-09-04. Status: project implementation direction, with the exact published raster identity retained. This is a VASEY.AUDIO application of the current theme, not a replacement canonical Vasey Multimedia guide.

## Authority and conflict resolution

Sean explicitly requested the colors from the recent media work and the updated theme **currently used on BeatStars**, with newer Vasey Multimedia styling. The published [VASEY.AUDIO storefront](https://www.vasey.audio/) links the [active theme stylesheet](https://s3.amazonaws.com/beatstarsdata/proweb/2.0/user-styles/1096218.css?_2.3.7841788360217). Its declarations establish the five primary colors below. They take precedence for this project over generic Edition 04 default surface colors and the conflicting palette in the attached Claude research.

Edition 04 remains the source for type roles, spacing, editorial composition, accessibility, state communication and geometry preservation. The implementation must not claim strict Edition 04 palette conformance. No new global canonical palette is being declared.

| Role | Active value | Published evidence | Decision |
| --- | --- | --- | --- |
| Deep turquoise canvas | `#052E3A` | Page body, landing, contact and membership background | Retain |
| Charcoal surface | `#29363F` | Navigation, panels and license background | Retain |
| Silver primary text | `#C9D0D3` | Primary/menu text declarations | Retain |
| Turquoise active/action | `#00B8D9` | Primary highlight, current navigation and primary controls | Retain, use sparingly |
| Teal hover | `#397281` | Primary button hover | Retain with white text |
| Readable muted text | `#AAB4BD` | Edition 04 accessibility role | Supporting UI token, not a sampled brand primitive |
| Strong meaningful boundary | `#6A8E98` | Edition 04 accessibility role | Supporting UI token; verify adjacent surface |

The attached research's `#454CFC` signature accent is not present in the current published theme and is not adopted. Its palette commentary is migration research rather than authority over the current user instruction. Do not add purple, magenta, neon green, Project DIG|TAL yellow, legacy `#00FFFF`, or replace normal turquoise with brighter cyan across the interface.

Semantic CSS tokens are in `public/brand/theme.css`. Components should use semantic roles instead of duplicating hex values. Tokens do not supply font files; exact font licensing and loaded-font verification remain a separate implementation requirement.

## Typography and layout

| Role | Family | Treatment |
| --- | --- | --- |
| Impact display | Noto Sans Display ExtraCondensed 700/900 | Condensed font face or supported width axis, uppercase, no CSS scale compression |
| Short section heading and signage | Bebas Neue 400 | Short uppercase headings, header and footer links, cart label, license names; 20–26 px with 0.08em tracking for links (no bolder cut exists, so size and tracking carry weight). The hero headline alone stays on the impact face |
| Body and controls | Reddit Sans 400–700 | Readable live text |
| Music metadata | JetBrains Mono 400–700 | BPM, key, time, identifiers, tabular figures |

Use `font-synthesis: none`. Keep live headings, price, license terms and controls out of images. Use a 4 px spacing base; 20 px mobile margins; 4/8/12-column mobile/tablet/desktop grids; 1 px rules, 2 px editorial corners and 6 px control corners. Public interactive targets should reach 44 by 44 CSS px. Preserve a visible 3 px focus ring with offset. Reduce nonessential motion when requested.

## Asset provenance and use

All six committed image assets were copied without modifying their bytes from URLs referenced directly by the live published stylesheet. SHA-256, source URLs, dimensions and file sizes are recorded in `asset-manifest.json`. This is evidence of the user's existing published brand treatment; no third-party stock or newly generated logo is introduced.

| Repository path | Dimensions | Recommended use |
| --- | --- | --- |
| `public/brand/vasey-audio-logo.png` | 420 × 100 | Exact existing navbar lockup; preserve intrinsic ratio, use at or below native dimensions |
| `public/images/storefront-hero.jpg` | 2400 × 890 | Desktop hero; quiet space at left, equipment and signal artwork at right |
| `public/images/storefront-hero-mobile.jpg` | 960 × 890 | Mobile hero; centered quiet copy area above equipment |
| `public/images/section-banner.jpg` | 1800 × 200 | Short section strip, decorative use |
| `public/images/memberships.jpg` | 1440 × 630 | Reel-to-reel studio image with right-side quiet space |
| `public/images/video-studio.jpg` | 1440 × 630 | Speaker/studio image with left-side quiet space |

Use a responsive `<picture>` for the hero and reserve intrinsic layout dimensions to prevent shifts. Treat atmospheric images as decorative when adjacent live copy supplies the meaning. Use contextual alternate text when an image independently conveys information. Lazy-load images below the fold. These six originals total approximately 1.83 MiB; the desktop hero is 559,096 bytes and the mobile hero is 890,975 bytes. Mobile image compression is a measured launch optimization still to complete.

The navbar raster contains the correct existing geometric VASEY.AUDIO icon and wordmark. It is not a vector master and should not be enlarged to cover a screen. Search identified `VASEY_AUDIO_BeatStars_Footer_Deep_Turquoise_v5.zip` and `VASEY_AUDIO_BeatStars_Edition_04_Install_Ready_v2.zip`, but source package retrieval returned HTTP 502. Official product SVG retrieval from those packages is therefore **unverified**; the owner-supplied kit recorded in [Official vector marks](#official-vector-marks-owner-supplied-2026-11-14) is the vector source for the mark. Do not trace the raster, typeset an imitation logo, or replace it with the VM master mark. Replacing the PNG with a verified official SVG is an asset follow-up, not a prerequisite for building the initial store.

The current published footer PNG was visually inspected and excluded: it contains a very small mark in a large transparent 420 × 100 canvas. Repeating that asset would preserve the previous footer sizing defect. Use the verified navbar lockup with its natural proportions until the approved footer/vector source is available.

## Official vector marks (owner-supplied 2026-11-14)

**Approval.** Sean Vasey handed the Vasey Brand Vector Kit (built 2026-11-10, kit status "draft for approval") to this repository on 2026-11-14 for use here. That hand-over is recorded as his approval of the **VASEY.AUDIO** marks only. Only the kit's `vasey-audio/` folder is imported: no mark of Vasey Multimedia, Sean Vasey Productions, Vasey Music Group, VASEY/AI or VASEY/DEV is copied into this repository, and the audio and technology brands are never mixed. The kit's other open decisions (typeface, other entities' marks) do not apply to these files.

**Source and integrity.** The 16 SVGs are copied byte for byte and must never be edited; `scripts/ci/test-brand-icons.py` fails if any byte changes. Kit `_README.md` SHA-256: `612265cf85c8ece19ed7b1c7e63e3f503ef45b57d41745f61630be44efdaa5e5`. Layout rules, canvas policy and variant naming are in `assets/brand/vasey-audio/README.md`. The kit locks the glyph at 768 × 657.561 units, 16 vertices, 2 separate shapes, line-fit to the 2792 × 2664 alpha master (IoU 0.985).

| File | SHA-256 |
| --- | --- |
| `assets/brand/vasey-audio/vasey-audio-icon-black-on-white.svg` | `a0511f4fa3be50aa7d6cebb550feb13a57c9709006318008b28d18d38cf98cb6` |
| `assets/brand/vasey-audio/vasey-audio-icon-black.svg` | `d94b603f92a74f57e605873ff8661967e7cb27c08777963b110d398c52b7b9e1` |
| `assets/brand/vasey-audio/vasey-audio-icon-white-on-black.svg` | `49a346bbc20340dcb7a6aa22faab5938ddfced6c46c047f6605e5a16d3ad327d` |
| `assets/brand/vasey-audio/vasey-audio-icon-white.svg` | `d5ef1ebd6e3e9b8576301b2103cf8ad4232a60bf8851119e50d465b7fff69636` |
| `assets/brand/vasey-audio/vasey-audio-lockup-horizontal-black-on-white.svg` | `474e20196e6441c2d30979cb20e712d0fddb0165d612987d1e12d12bf1fde0b7` |
| `assets/brand/vasey-audio/vasey-audio-lockup-horizontal-black.svg` | `0b232f484f81c618382c90fbe2617eb1471cee1fc67b1ee0e2afc3dc27e38698` |
| `assets/brand/vasey-audio/vasey-audio-lockup-horizontal-white-on-black.svg` | `1ba2b33fea080bdeba5b4bf89761db5db3f8a61c91a654c03c5ea674432a21ee` |
| `assets/brand/vasey-audio/vasey-audio-lockup-horizontal-white.svg` | `370da74da22b0073a850061c5647d493585a3f06aaf4e4434bea1131d36af1e7` |
| `assets/brand/vasey-audio/vasey-audio-lockup-stacked-black-on-white.svg` | `f64c7b8fc7b98562f19768b66f28fdd3b2ddab80d8c618086e5a79467be97c07` |
| `assets/brand/vasey-audio/vasey-audio-lockup-stacked-black.svg` | `4346ab506dcfa32348169ac2fbf47aeaa76b934b40ec3ec3755ad7fe89e6f92d` |
| `assets/brand/vasey-audio/vasey-audio-lockup-stacked-white-on-black.svg` | `84657d4340220f504f1bf82fb2f78e9a32c3e60b63b8d27adeeb5aa8e7c70e5b` |
| `assets/brand/vasey-audio/vasey-audio-lockup-stacked-white.svg` | `f5533085b55168ef1f51bea70e20f0b27ce03decea806cd33301edc9cb1128f3` |
| `assets/brand/vasey-audio/vasey-audio-wordmark-black-on-white.svg` | `b35e45f2e04b653d2e13312ad220d909a5549b6065262c2b5f67840c6b77c76b` |
| `assets/brand/vasey-audio/vasey-audio-wordmark-black.svg` | `c009525751bd722bdaa2f2bc7ccd5557a5871d6212f08c1f606e2b0e3ae61c6e` |
| `assets/brand/vasey-audio/vasey-audio-wordmark-white-on-black.svg` | `5fb412705eb3324b03eeb37933d19b0b86b3e1d34502c3afba46c9f9b4f77a57` |
| `assets/brand/vasey-audio/vasey-audio-wordmark-white.svg` | `13c909d22afc91fd210ac5a2d6dbe43b624b9455fc8bf80157e47625fbf9cd04` |

**Raster comparison.** The official icon was rendered in Chromium and overlaid on `public/brand/vasey-audio-logo.png` (hash pinned in `asset-manifest.json`). The raster mark is the same two interlocked pieces drawn as a thin cyan outline rather than a solid fill: a best-fit scale and offset places the official polygon outlines within a mean of 0.74 and 0.95 raster pixels in the two directions (95th percentile 3 px, stroke width about 2 px) of the raster's strokes, with the same vertices, notches and proportions (82 × 71 px stroked mark, ratio 1.155 against 1.168 for the solid glyph). Geometry therefore matches; the treatment differs (outline versus solid).

**What uses the marks now.** The app icon suite (`public/brand/app-icon-*.png`, `app-icon-maskable-*.png`, `apple-touch-icon*.png`, `favicon*`, `public/favicon.ico`) is the official white icon on the `#052E3A` canvas, generated by `scripts/brand/build-app-icons.mjs`: the mark's path data is read from `vasey-audio-icon-white.svg` and rasterized unmodified; only the tile colour and a uniform scale wrapper vary. `any` and apple-touch tiles keep the kit's live area (mark 75% of the tile width, bbox-centred); `maskable` tiles scale the mark by 0.82 so its farthest vertex sits at 38.7% of the tile, inside the 40% safe-zone circle. Every tile is full-bleed and opaque, asserted by the check above. The previous icons placed the raster lockup on the same tile; the old derivative bytes and ratios are replaced and `asset-manifest.json` records the new ones. The navbar still uses the raster lockup `public/brand/vasey-audio-logo.png` and is unchanged; replacing it with an SVG lockup after a visual review is a follow-up.

## Motif ledger

| Motif | Source | Purpose | Accessible treatment | Restriction |
| --- | --- | --- | --- | --- |
| Ambient audio signal art | Existing published hero/section media | Continue Sean's approved studio/audio identity | Decorative image; live text remains independent | Not actual music data, meters, playback progress or a product waveform |
| Studio equipment | Existing published console/reel/speaker imagery | Communicate the production and sound-design practice | Decorative or concise contextual alt | Do not claim a pictured hardware model or studio ownership from the artwork |
| Functional waveform | Not included in these assets | Future preview seeking | Native labeled seek input remains available | Generate only from the public tagged preview's actual peaks; never simulate track data |

## Contrast evidence

Calculated from solid-color WCAG relative luminance. This is token evidence, not complete rendered accessibility certification.

| Foreground / background | Ratio | Use |
| --- | --- | --- |
| Silver / deep turquoise | 9.22:1 | Body copy |
| Silver / charcoal | 7.94:1 | Panel copy |
| Turquoise / deep turquoise | 6.07:1 | Link/current text, focus |
| Turquoise / charcoal | 5.23:1 | Link/current text, focus |
| Deep turquoise / turquoise | 6.07:1 | Filled primary button text |
| White / teal | 5.39:1 | Hover button text |
| White / turquoise | 2.37:1 | **Fails normal text; do not use** |
| Teal / deep turquoise | 2.67:1 | **Insufficient for a sole meaningful boundary or text** |

Check real text over imagery, keyboard focus, reduced motion, forced colors, player obstruction and responsive reflow in the rendered implementation. Full device, screen reader, font-file and performance measurements are not established by this evidence package.
