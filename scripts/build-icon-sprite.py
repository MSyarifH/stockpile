#!/usr/bin/env python3
"""
Builds views/partial/icon-sprite.php from the official lucide-static package.

Why a generated subset instead of linking a CDN or committing the whole set:

  * A CDN breaks the brief's requirement that the app run from a clean folder
    via Docker (S5.1). An assessor without internet would see no icons at all.
  * lucide-static ships 1800+ icons (~5 MB). The app uses ~25. Shipping the
    rest is dead weight in the repository and in every page load.
  * Generating rather than hand-copying keeps the icons provably identical to
    upstream -- nothing here is redrawn, so the attribution in README.md is
    accurate (S6.1: sources that are not the participant's own work are cited).

Usage (the tarball is deliberately NOT committed):

    curl -sSLo /tmp/lucide.tgz \
      https://registry.npmjs.org/lucide-static/-/lucide-static-0.544.0.tgz
    tar xzf /tmp/lucide.tgz -C /tmp
    python3 scripts/build-icon-sprite.py /tmp/package
"""
import re
import sys
from pathlib import Path

VERSION = "0.544.0"

# Every icon the views actually reference. Keep this list in sync with the
# markup: an unused entry is dead weight, a missing one renders as a blank box.
ICONS = [
    # Navigation
    "layout-dashboard", "package", "truck", "shopping-cart", "file-text",
    "tags", "warehouse", "factory", "users", "user", "log-out",
    # Actions
    "plus", "trash-2", "pencil", "download", "filter",
    "check", "x", "chevron-left", "chevron-right", "chevron-down",
    # Status and feedback
    "circle-check", "triangle-alert", "info", "eye",
]


def symbol(source: Path, name: str) -> str:
    svg = source.read_text(encoding="utf-8")
    # Take only what is inside <svg ...> ... </svg>: the wrapper's width/height
    # belong to the consuming page, not to the symbol.
    body = re.search(r"<svg\b[^>]*>(.*)</svg>", svg, re.S)
    if body is None:
        raise SystemExit(f"unexpected svg structure in {source}")
    paths = re.sub(r"\n\s*", "\n    ", body.group(1).strip())
    # The stroke presentation lives on each symbol, not on the sprite root.
    # <use> clones the symbol into a shadow tree that inherits from wherever the
    # <use> element sits in the document -- NOT from the symbol's original
    # parent -- so attributes on the root would simply never reach the shapes,
    # and every icon would render as a black silhouette or nothing at all.
    return (
        f'  <symbol id="i-{name}" viewBox="0 0 24 24" fill="none" stroke="currentColor"\n'
        f'          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">\n'
        f"    {paths}\n  </symbol>"
    )


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: build-icon-sprite.py <path to extracted lucide-static>")

    icons_dir = Path(sys.argv[1]) / "icons"
    if not icons_dir.is_dir():
        raise SystemExit(f"no icons/ directory under {sys.argv[1]}")

    symbols = []
    for name in ICONS:
        source = icons_dir / f"{name}.svg"
        if not source.is_file():
            raise SystemExit(f"icon not found upstream: {name}")
        symbols.append(symbol(source, name))

    # Inlined into the page rather than served as /assets/icons.svg and
    # referenced with <use href="file.svg#id">. That external form is in the
    # spec but was measured not to render in Chrome here, showing empty boxes
    # where every icon should be; an inline sprite works in every browser and
    # costs one fewer request. The trade-off is ~8 KB repeated in each HTML
    # response instead of being cached once -- noted in docs/quality/tech-debt.md.
    out = Path("views/partial/icon-sprite.php")
    out.write_text(
        "<?php\n"
        "/**\n"
        " * GENERATED FILE -- do not edit by hand.\n"
        f" * Rebuild with: python3 scripts/build-icon-sprite.py <lucide-static>\n"
        " *\n"
        f" * Lucide v{VERSION}, ISC License, https://lucide.dev. Icons are used\n"
        " * unmodified; see the attribution section of README.md.\n"
        " */\n"
        "?>\n"
        '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0"\n'
        '     style="position:absolute" aria-hidden="true" focusable="false">\n'
        + "\n".join(symbols)
        + "\n</svg>\n",
        encoding="utf-8",
    )
    print(f"wrote {out} with {len(symbols)} icons")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
