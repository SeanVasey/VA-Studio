# VASEY.AUDIO official vector marks

Owner-supplied marks for VASEY.AUDIO (Sean Vasey Productions). Sean Vasey handed the Vasey Brand Vector Kit (built 2026-11-10, status "draft for approval") to this repository on 2026-11-14; that is recorded as his approval for these VASEY.AUDIO files (see `docs/brand/README.md`). Only the kit's `vasey-audio/` folder is here. Marks of the other Vasey entities are deliberately not in this repository.

**Never edit these files.** They are copied byte for byte and `scripts/ci/test-brand-icons.py` checks them against `SHA256SUMS`. Replacement means a new kit and a new recorded approval, not a hand edit. Do not recreate or redraw the logo.

## Variants

File names are `vasey-audio-<asset>-<black|white>[-on-white|-on-black].svg`. With no ground suffix the background is transparent; `black` is for light grounds and `white` for dark grounds.

| Asset | viewBox | Rule |
| --- | --- | --- |
| `icon` | `0 0 1024 1024` | Live area 768: the mark's longest side is exactly 768, bbox-centred on (512, 512); clear space 128 |
| `lockup-horizontal` | `0 0 W 1024` | Same mark scale as the icon; wordmark cap height 320, 160 right of the mark |
| `lockup-stacked` | `0 0 W H` | Same mark scale; wordmark at uniform scale 0.45 (cap height 144) below the mark |
| `wordmark` | `0 0 W 576` | Cap height 320, clear space 128 |

A variant changes only the fill (`#000000` or `#FFFFFF`), an optional full-bleed background rect and a `translate`/uniform-`scale` wrapper, so every file carries the identical mark path. The VASEY.AUDIO glyph is 768 x 657.561 units, 16 vertices and two separate shapes that never touch. The wordmark is outlined Saira Extra Condensed ExtraBold (SIL OFL 1.1), so no font is needed to render it.

## Use in this repository

* README: `vasey-audio-icon-white.svg` on the dark canvas via `<picture>` with the `black` icon for light themes.
* App icons, favicon and manifest: `scripts/brand/build-app-icons.mjs` reads the path from `vasey-audio-icon-white.svg` and rasterizes it unmodified onto the `#052E3A` tile.
* The in-app navbar still uses the raster `public/brand/vasey-audio-logo.png`. An SVG lockup could replace it after a visual review.

## SHA-256

The same digests are in `SHA256SUMS` (check with `sha256sum -c SHA256SUMS`) and in `docs/brand/README.md`.

| File | SHA-256 |
| --- | --- |
| `vasey-audio-icon-black-on-white.svg` | `a0511f4fa3be50aa7d6cebb550feb13a57c9709006318008b28d18d38cf98cb6` |
| `vasey-audio-icon-black.svg` | `d94b603f92a74f57e605873ff8661967e7cb27c08777963b110d398c52b7b9e1` |
| `vasey-audio-icon-white-on-black.svg` | `49a346bbc20340dcb7a6aa22faab5938ddfced6c46c047f6605e5a16d3ad327d` |
| `vasey-audio-icon-white.svg` | `d5ef1ebd6e3e9b8576301b2103cf8ad4232a60bf8851119e50d465b7fff69636` |
| `vasey-audio-lockup-horizontal-black-on-white.svg` | `474e20196e6441c2d30979cb20e712d0fddb0165d612987d1e12d12bf1fde0b7` |
| `vasey-audio-lockup-horizontal-black.svg` | `0b232f484f81c618382c90fbe2617eb1471cee1fc67b1ee0e2afc3dc27e38698` |
| `vasey-audio-lockup-horizontal-white-on-black.svg` | `1ba2b33fea080bdeba5b4bf89761db5db3f8a61c91a654c03c5ea674432a21ee` |
| `vasey-audio-lockup-horizontal-white.svg` | `370da74da22b0073a850061c5647d493585a3f06aaf4e4434bea1131d36af1e7` |
| `vasey-audio-lockup-stacked-black-on-white.svg` | `f64c7b8fc7b98562f19768b66f28fdd3b2ddab80d8c618086e5a79467be97c07` |
| `vasey-audio-lockup-stacked-black.svg` | `4346ab506dcfa32348169ac2fbf47aeaa76b934b40ec3ec3755ad7fe89e6f92d` |
| `vasey-audio-lockup-stacked-white-on-black.svg` | `84657d4340220f504f1bf82fb2f78e9a32c3e60b63b8d27adeeb5aa8e7c70e5b` |
| `vasey-audio-lockup-stacked-white.svg` | `f5533085b55168ef1f51bea70e20f0b27ce03decea806cd33301edc9cb1128f3` |
| `vasey-audio-wordmark-black-on-white.svg` | `b35e45f2e04b653d2e13312ad220d909a5549b6065262c2b5f67840c6b77c76b` |
| `vasey-audio-wordmark-black.svg` | `c009525751bd722bdaa2f2bc7ccd5557a5871d6212f08c1f606e2b0e3ae61c6e` |
| `vasey-audio-wordmark-white-on-black.svg` | `5fb412705eb3324b03eeb37933d19b0b86b3e1d34502c3afba46c9f9b4f77a57` |
| `vasey-audio-wordmark-white.svg` | `13c909d22afc91fd210ac5a2d6dbe43b624b9455fc8bf80157e47625fbf9cd04` |
