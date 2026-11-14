"""Decode the brand woff2 faces into the static TrueType files the vasey-license-pdf-v1 profile converts.

Run only by scripts/build-contract-profile-vasey-license-v1.php, which pins every input and output by SHA-256.
Usage: python3 scripts/vasey-license-fonts.py <source-dir> <ttf-dir>

The output depends only on the pinned woff2 inputs and the pinned fontTools and brotli versions:
head.modified is never recalculated, and the two variable faces are instanced at fixed weights.
"""

import sys
from pathlib import Path

import brotli
import fontTools
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

FONTTOOLS = "4.68.0"
BROTLI = "1.2.1"

# Output name -> (source woff2, weight to instance at, or None for a static face).
FACES = {
    "bebasneue.ttf": ("bebas-neue-latin-400-normal.woff2", None),
    "redditsans.ttf": ("reddit-sans-latin-wght-normal.woff2", 400),
    "redditsansb.ttf": ("reddit-sans-latin-wght-normal.woff2", 700),
    "jetbrainsmono.ttf": ("jetbrains-mono-latin-wght-normal.woff2", 400),
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


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: vasey-license-fonts.py <source-dir> <ttf-dir>", file=sys.stderr)
        return 2
    if fontTools.version != FONTTOOLS or brotli.version != BROTLI:
        print(f"pinned fontTools {FONTTOOLS} / brotli {BROTLI} required, found {fontTools.version} / {brotli.version}", file=sys.stderr)
        return 2
    source, target = Path(sys.argv[1]), Path(sys.argv[2])
    target.mkdir(parents=True, exist_ok=True)
    for name, (woff2, weight) in FACES.items():
        font = TTFont(source / woff2, recalcTimestamp=False, recalcBBoxes=False)
        if font.flavor != "woff2":
            print(f"{woff2} is not woff2", file=sys.stderr)
            return 1
        if weight is not None:
            if "fvar" not in font:
                print(f"{woff2} has no variation axes", file=sys.stderr)
                return 1
            font = instancer.instantiateVariableFont(font, {"wght": weight}, inplace=False, updateFontNames=False)
            font["OS/2"].usWeightClass = weight
            if weight == 700:
                bold(font)
        elif "fvar" in font:
            print(f"{woff2} is unexpectedly variable", file=sys.stderr)
            return 1
        font.flavor = None
        font.recalcTimestamp = False
        font.save(target / name, reorderTables=True)
        print(f"{name} <- {woff2}" + (f" @wght={weight}" if weight else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
