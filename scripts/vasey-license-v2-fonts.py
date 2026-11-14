"""Build the static TrueType faces of the vasey-license-pdf-v2 profile: each brand face with Latin Extended.

Run only by scripts/build-contract-profile-vasey-license-v2.php, which pins every input and output by SHA-256.
Usage: python3 scripts/vasey-license-v2-fonts.py <source-dir> <ttf-dir>

Fontsource ships each family as disjoint per-script subsets. Each face here is the pinned `latin` subset merged with
the `latin-ext` subset of the same package and version, so every glyph is the upstream outline that v1 already prints;
v1 (scripts/vasey-license-fonts.py) is unchanged. The variable faces are instanced at fixed weights before the merge.
The output depends only on the pinned woff2 inputs and the pinned fontTools and brotli versions: the merge would stamp
the build time into head.created and head.modified, so both are restored from the Latin source.
"""

import io
import sys
from pathlib import Path

import brotli
import fontTools
from fontTools.merge import Merger, Options
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

FONTTOOLS = "4.68.0"
BROTLI = "1.2.1"

# Output name -> (Latin source woff2, Latin Extended source woff2, weight to instance at, or None for a static face).
FACES = {
    "bebasneue.ttf": ("bebas-neue-latin-400-normal.woff2", "bebas-neue-latin-ext-400-normal.woff2", None),
    "redditsans.ttf": ("reddit-sans-latin-wght-normal.woff2", "reddit-sans-latin-ext-wght-normal.woff2", 400),
    "redditsansb.ttf": ("reddit-sans-latin-wght-normal.woff2", "reddit-sans-latin-ext-wght-normal.woff2", 700),
    "jetbrainsmono.ttf": ("jetbrains-mono-latin-wght-normal.woff2", "jetbrains-mono-latin-ext-wght-normal.woff2", 400),
}


def bold(font: TTFont) -> None:
    """Name the 700 instance as the Bold style, so the PDF never embeds two faces under one PostScript name."""
    family = font["name"].getDebugName(1)
    postscript = font["name"].getDebugName(6).split("-")[0]
    for record in list(font["name"].names):
        if record.nameID in (2, 3, 4, 6, 17):
            font["name"].removeNames(nameID=record.nameID)
    font["name"].setName("Bold", 2, 3, 1, 0x409)
    font["name"].setName(f"{postscript}-Bold", 3, 3, 1, 0x409)
    font["name"].setName(f"{family} Bold", 4, 3, 1, 0x409)
    font["name"].setName(f"{postscript}-Bold", 6, 3, 1, 0x409)
    font["OS/2"].fsSelection = (font["OS/2"].fsSelection & ~0x0040) | 0x0020
    font["head"].macStyle = font["head"].macStyle | 0x0001


def static(path: Path, weight: int | None) -> tuple[io.BytesIO, TTFont]:
    """One subset as static, unflavoured TrueType in memory, instanced at `weight` when it is variable."""
    font = TTFont(path, recalcTimestamp=False, recalcBBoxes=False)
    if font.flavor != "woff2":
        raise ValueError(f"{path.name} is not woff2")
    if weight is not None:
        if "fvar" not in font:
            raise ValueError(f"{path.name} has no variation axes")
        font = instancer.instantiateVariableFont(font, {"wght": weight}, inplace=False, updateFontNames=False)
        font["OS/2"].usWeightClass = weight
    elif "fvar" in font:
        raise ValueError(f"{path.name} is unexpectedly variable")
    font.flavor = None
    font.recalcTimestamp = False
    buffer = io.BytesIO()
    font.save(buffer, reorderTables=True)
    buffer.seek(0)

    return buffer, font


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: vasey-license-v2-fonts.py <source-dir> <ttf-dir>", file=sys.stderr)
        return 2
    if fontTools.version != FONTTOOLS or brotli.version != BROTLI:
        print(f"pinned fontTools {FONTTOOLS} / brotli {BROTLI} required, found {fontTools.version} / {brotli.version}", file=sys.stderr)
        return 2
    source, target = Path(sys.argv[1]), Path(sys.argv[2])
    target.mkdir(parents=True, exist_ok=True)
    for name, (latin, extended, weight) in FACES.items():
        try:
            latin_bytes, latin_font = static(source / latin, weight)
            extended_bytes, extended_font = static(source / extended, weight)
        except ValueError as error:
            print(str(error), file=sys.stderr)
            return 1
        if latin_font["head"].unitsPerEm != extended_font["head"].unitsPerEm:
            print(f"{latin} and {extended} differ in unitsPerEm", file=sys.stderr)
            return 1
        # The Latin subset comes first, so its names, metrics header and mappings win wherever the subsets overlap.
        font = Merger(options=Options(drop_tables=[])).merge([latin_bytes, extended_bytes])
        font["head"].created = latin_font["head"].created
        font["head"].modified = latin_font["head"].modified
        if weight == 700:
            bold(font)
        font.recalcTimestamp = False
        font.save(target / name, reorderTables=True)
        print(f"{name} <- {latin} + {extended}" + (f" @wght={weight}" if weight else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
