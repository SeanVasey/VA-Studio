#!/usr/bin/env python3
"""Verify the VASEY.AUDIO official brand vectors and the app icon suite built from them.

Standard library only, so it runs in the cheap preflight job. It re-checks, from the committed bytes:

* the 16 owner-supplied SVGs still match assets/brand/vasey-audio/SHA256SUMS (and docs/brand/README.md records
  the same hashes) and contain only inert, pure black or white geometry;
* every app icon PNG (manifest `any` and `maskable`, apple-touch) has no alpha channel or only fully opaque alpha,
  no tRNS transparency chunk, and a border of exactly the tile colour, so iOS 18+ has nothing to composite;
* maskable icons keep every non-background pixel inside the central circle of radius 40% of the tile;
* favicon.ico and favicon.svg carry the exact rendered/official geometry, and manifest and head references resolve.

Rebuild the suite with: node scripts/brand/build-app-icons.mjs
"""
import hashlib
import json
import re
import struct
import unittest
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
KIT = ROOT / "assets/brand/vasey-audio"
BRAND = ROOT / "public/brand"
TILE = (5, 46, 58)  # #052E3A
SIZES = (1024, 512, 384, 192, 144, 96)
APPLE = {"apple-touch-icon.png": 180, "apple-touch-icon-167.png": 167, "apple-touch-icon-152.png": 152, "apple-touch-icon-120.png": 120}
FAVICONS = {"favicon-32.png": 32, "favicon-16.png": 16}
ANY = {f"app-icon-{size}.png": size for size in SIZES}
MASKABLE = {f"app-icon-maskable-{size}.png": size for size in SIZES}
ASSETS = ("icon", "lockup-horizontal", "lockup-stacked", "wordmark")
KIT_FILES = sorted(f"vasey-audio-{asset}-{tone}{ground}.svg" for asset in ASSETS for tone in ("black", "white")
                   for ground in ("", "-on-white" if tone == "black" else "-on-black"))


def decode_png(path):
    """Return (width, height, color_type, chunk_types, rows) for an 8-bit, non-interlaced RGB or RGBA PNG."""
    data = path.read_bytes()
    assert data[:8] == b"\x89PNG\r\n\x1a\n", f"{path.name}: not a PNG"
    pos, chunks, idat, header = 8, [], b"", None
    while pos < len(data):
        length, kind = struct.unpack(">I4s", data[pos:pos + 8])
        body = data[pos + 8:pos + 8 + length]
        chunks.append(kind.decode("ascii"))
        if kind == b"IHDR":
            header = struct.unpack(">IIBBBBB", body)
        elif kind == b"IDAT":
            idat += body
        pos += 12 + length
    width, height, depth, color, _, _, interlace = header
    assert depth == 8 and interlace == 0 and color in (2, 6), f"{path.name}: unsupported PNG layout {header}"
    bpp = 3 if color == 2 else 4
    stride, raw = width * bpp, zlib.decompress(idat)
    rows, prev = [], bytearray(stride)
    for y in range(height):
        kind, line = raw[y * (stride + 1)], bytearray(raw[y * (stride + 1) + 1:(y + 1) * (stride + 1)])
        if kind == 1:
            for i in range(bpp, stride):
                line[i] = (line[i] + line[i - bpp]) & 255
        elif kind == 2:
            for i in range(stride):
                line[i] = (line[i] + prev[i]) & 255
        elif kind == 3:
            for i in range(stride):
                line[i] = (line[i] + (((line[i - bpp] if i >= bpp else 0) + prev[i]) >> 1)) & 255
        elif kind == 4:
            for i in range(stride):
                a, b, c = (line[i - bpp] if i >= bpp else 0), prev[i], (prev[i - bpp] if i >= bpp else 0)
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                line[i] = (line[i] + (a if pa <= pb and pa <= pc else b if pb <= pc else c)) & 255
        elif kind != 0:
            raise AssertionError(f"{path.name}: bad filter {kind}")
        rows.append(line)
        prev = line
    return width, height, color, chunks, rows


def pixels(rows, color):
    """Yield (x, y, (r, g, b), alpha) for every pixel; alpha is 255 for RGB images."""
    step = 3 if color == 2 else 4
    for y, row in enumerate(rows):
        for x in range(len(row) // step):
            yield x, y, tuple(row[x * step:x * step + 3]), (row[x * step + 3] if step == 4 else 255)


class BrandKitTest(unittest.TestCase):
    def test_official_svgs_match_recorded_hashes_and_are_inert(self):
        sums = {}
        for line in (KIT / "SHA256SUMS").read_text().splitlines():
            digest, name = line.split("  ", 1)
            sums[name] = digest
        self.assertEqual(KIT_FILES, sorted(sums))
        self.assertEqual(KIT_FILES, sorted(p.name for p in KIT.glob("*.svg")), "unexpected or missing kit file")
        readme = (ROOT / "docs/brand/README.md").read_text()
        for name in KIT_FILES:
            raw = (KIT / name).read_bytes()
            self.assertEqual(sums[name], hashlib.sha256(raw).hexdigest(), f"{name} was edited")
            self.assertIn(sums[name], readme, f"docs/brand/README.md does not record {name}")
            body = raw.decode("utf-8")
            self.assertTrue(set(re.findall(r'fill="([^"]+)"', body)) <= {"#000000", "#FFFFFF"}, name)
            for forbidden in ("<script", "href=", "<image", "<foreignObject", "<style"):
                self.assertNotIn(forbidden, body, f"{name} contains {forbidden}")

    def test_black_and_white_icons_share_one_geometry(self):
        paths = {re.search(r' d="([^"]+)"', (KIT / n).read_text()).group(1) for n in KIT_FILES if "-icon-" in n}
        self.assertEqual(1, len(paths))


class AppIconTest(unittest.TestCase):
    def check_opaque(self, name, size):
        width, height, color, chunks, rows = decode_png(BRAND / name)
        self.assertEqual((size, size), (width, height), name)
        self.assertNotIn("tRNS", chunks, f"{name} declares transparency")
        self.assertEqual([], [p for p in pixels(rows, color) if p[3] != 255][:1], f"{name} has transparent pixels")
        border = {rgb for x, y, rgb, _ in pixels(rows, color) if x in (0, width - 1) or y in (0, height - 1)}
        self.assertEqual({TILE}, border, f"{name} is not a full-bleed tile")
        return width, color, rows

    def test_every_app_icon_is_a_full_bleed_opaque_tile(self):
        for name, size in {**ANY, **MASKABLE, **APPLE, **FAVICONS}.items():
            with self.subTest(name):
                self.check_opaque(name, size)

    def test_icons_show_a_white_mark(self):
        for name, size in (("app-icon-512.png", 512), ("app-icon-maskable-512.png", 512), ("apple-touch-icon.png", 180)):
            with self.subTest(name):
                _, color, rows = self.check_opaque(name, size)
                white = sum(1 for _, _, rgb, _ in pixels(rows, color) if rgb == (255, 255, 255))
                self.assertGreater(white, size * size // 20, f"{name} has no white mark")

    def test_maskable_marks_stay_inside_the_safe_zone(self):
        for name, size in MASKABLE.items():
            with self.subTest(name):
                width, color, rows = self.check_opaque(name, size)
                centre, limit = width / 2, 0.4 * width + 1.5  # safe zone: circle of radius 40% of the tile
                worst = max(((x + 0.5 - centre) ** 2 + (y + 0.5 - centre) ** 2) ** 0.5
                            for x, y, rgb, _ in pixels(rows, color) if rgb != TILE)
                self.assertLessEqual(worst, limit, name)

    def test_favicon_ico_embeds_the_16_and_32_renders(self):
        data = (ROOT / "public/favicon.ico").read_bytes()
        self.assertEqual((0, 1, 2), struct.unpack("<HHH", data[:6]))
        found = {}
        for i in range(2):
            w, h, _, _, planes, bpp, length, offset = struct.unpack("<BBBBHHII", data[6 + 16 * i:22 + 16 * i])
            self.assertEqual((1, 32, w), (planes, bpp, h))
            found[w] = data[offset:offset + length]
        self.assertEqual({16, 32}, set(found))
        for size, png in found.items():
            self.assertEqual((BRAND / f"favicon-{size}.png").read_bytes(), png)

    def test_favicon_svg_uses_the_exact_official_path(self):
        official = re.search(r' d="([^"]+)"', (KIT / "vasey-audio-icon-white.svg").read_text()).group(1)
        favicon = (BRAND / "favicon.svg").read_text()
        self.assertEqual(official, re.search(r' d="([^"]+)"', favicon).group(1))
        self.assertEqual(1, favicon.count("<path"))
        self.assertNotIn("<script", favicon)

    def test_manifest_and_head_references_resolve(self):
        manifest = json.loads((ROOT / "public/manifest.webmanifest").read_text())
        self.assertEqual("#052e3a", manifest["background_color"])
        seen = {"any": set(), "maskable": set()}
        for icon in manifest["icons"]:
            declared = int(icon["sizes"].split("x")[0])
            prefix = "maskable-" if icon["purpose"] == "maskable" else ""
            self.assertEqual("image/png", icon["type"])
            self.assertEqual(f"/brand/app-icon-{prefix}{declared}.png", icon["src"])
            self.assertTrue((ROOT / "public" / icon["src"].lstrip("/")).is_file(), icon["src"])
            seen[icon["purpose"]].add(declared)
        self.assertEqual({192, 512}, {192, 512} & seen["any"])
        self.assertEqual({192, 512}, {192, 512} & seen["maskable"])
        head = (ROOT / "resources/views/app.blade.php").read_text()
        for href in re.findall(r'<link[^>]+rel="(?:icon|apple-touch-icon)"[^>]+href="([^"]+)"', head):
            self.assertTrue((ROOT / "public" / href.lstrip("/")).is_file(), href)
        for href in ("/favicon.ico", "/brand/favicon.svg", "/brand/favicon-32.png", "/brand/favicon-16.png"):
            self.assertIn(f'href="{href}"', head)


if __name__ == "__main__":
    unittest.main()
