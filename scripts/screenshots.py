#!/usr/bin/env python3
"""Capture README screenshots of the demo sites with headless Chrome/Edge.

Usage:
    python scripts/screenshots.py [base_url] [--browser PATH]

Default base_url is http://localhost/omeka-s. Images are written to
docs/screenshots/. The browser must reach the site directly (the Bot
Challenge module exempts 127.0.0.1 in the dev setup).
"""
import argparse
import shutil
import subprocess
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "docs" / "screenshots"
PAGES = [
    ("01-etusivu-fi.png", "/s/fi", (1440, 1300)),
    ("02-home-en.png", "/s/en", (1440, 1300)),
    ("03-aineistot-fi.png", "/s/fi/item", (1440, 1400)),
    ("04-aineisto-fi.png", "/s/fi/item/{first_item}", (1440, 1700)),
    ("05-kartta-fi.png", "/s/fi/page/kartta", (1440, 1100)),
    ("06-haku-fi.png", "/s/fi/page/haku", (1440, 1400)),
    ("07-kokoelmat-fi.png", "/s/fi/item-set", (1440, 1200)),
    # Headless Chrome cannot render narrower than ~500 px, which is still
    # below Foundation's small-screen breakpoint (640 px).
    ("08-mobile-fi.png", "/s/fi", (500, 1400)),
]
CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "google-chrome", "chromium", "chromium-browser", "microsoft-edge",
]


def find_browser(explicit):
    for cand in ([explicit] if explicit else []) + CANDIDATES:
        path = shutil.which(cand) or (cand if Path(cand).exists() else None)
        if path:
            return path
    sys.exit("No Chrome/Edge found; pass --browser PATH")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("base_url", nargs="?", default="http://localhost/omeka-s")
    parser.add_argument("--browser")
    parser.add_argument("--first-item", default="")
    args = parser.parse_args()
    browser = find_browser(args.browser)
    OUT.mkdir(parents=True, exist_ok=True)
    for name, path, (w, h) in PAGES:
        if "{first_item}" in path:
            if not args.first_item:
                continue
            path = path.format(first_item=args.first_item)
        target = OUT / name
        cmd = [browser, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--no-first-run",
               f"--window-size={w},{h}", "--virtual-time-budget=6000",
               f"--screenshot={target}", args.base_url.rstrip("/") + path]
        subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
        print(f"ok {name}")


if __name__ == "__main__":
    main()
