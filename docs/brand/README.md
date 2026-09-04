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
| Short section heading | Bebas Neue 400 | Short uppercase headings |
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

The navbar raster contains the correct existing geometric VASEY.AUDIO icon and wordmark. It is not a vector master and should not be enlarged to cover a screen. Search identified `VASEY_AUDIO_BeatStars_Footer_Deep_Turquoise_v5.zip` and `VASEY_AUDIO_BeatStars_Edition_04_Install_Ready_v2.zip`, but source package retrieval returned HTTP 502. Official product SVG retrieval is therefore **unverified**. Do not trace the raster, typeset an imitation logo, or replace it with the VM master mark. Replacing the PNG with a verified official SVG is an asset follow-up, not a prerequisite for building the initial store.

The current published footer PNG was visually inspected and excluded: it contains a very small mark in a large transparent 420 × 100 canvas. Repeating that asset would preserve the previous footer sizing defect. Use the verified navbar lockup with its natural proportions until the approved footer/vector source is available.

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
