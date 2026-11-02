#!/usr/bin/env python3
"""Derive Dev2Done assets from the user's SVG without changing its paths/gradients.

Generation uses the developer's Playwright Chromium via PLAYWRIGHT_MODULE (no new
package dependency). --check needs only Python and verifies source/vector/splash
geometry plus committed PNG/ICO hashes; production builds never need a browser.
"""
import base64
import hashlib
import json
from pathlib import Path
import re
import struct
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
MASTER = (ROOT / 'src/assets/brand-mark.svg').read_text().strip()
INNER = MASTER[MASTER.index('>') + 1:MASTER.rindex('</svg>')]
CHECK = '--check' in sys.argv
OUTPUTS = {}

def app_icon(maskable=False):
    scale = 1.55 if maskable else 2
    x, y = (512 - 220 * scale) / 2, (512 - 128 * scale) / 2
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="Dev2Done app icon">\n'
            f'<rect width="512" height="512" rx="{0 if maskable else 112}" fill="#18152e"/>\n'
            f'<g transform="translate({x:g} {y:g}) scale({scale:g})">{INNER}</g>\n</svg>\n')

OUTPUTS['public/app-icon.svg'] = app_icon()
OUTPUTS['public/favicon.svg'] = app_icon()
for name, color in [('light', '#1f2937'), ('dark', '#e4e9f0')]:
    OUTPUTS[f'public/brand-mark-{name}.svg'] = MASTER + '\n'
    OUTPUTS[f'public/brand-logo-{name}.svg'] = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 64" role="img" aria-label="Dev2Done">\n'
        f'<g transform="translate(0 4) scale(.44)">{INNER}</g>\n'
        f'<text x="108" y="44" fill="{color}" font-family="system-ui, sans-serif" font-size="34" font-weight="650" letter-spacing="-.8">Dev2Done</text>\n</svg>\n')
OUTPUTS['api/resources/dev2done-mark.svg'] = MASTER.replace('<svg ', '<svg width="220" height="128" ', 1) + '\n'
index = ROOT / 'index.html'
html = index.read_text()
updated = re.sub(r'(<!-- brand-mark:start -->)\s*.*?\s*(<!-- brand-mark:end -->)',
                 lambda m: m[1] + '\n        ' + MASTER + '\n        ' + m[2], html, flags=re.S)
manifest_path = ROOT / 'deployment/brand-assets.json'
specifications = [
    {'name': 'public/' + name, 'svg': app_icon(maskable), 'width': size, 'height': size}
    for name, size, maskable in [('icon-192.png', 192, False), ('icon-512.png', 512, False),
                                ('icon-maskable-192.png', 192, True), ('icon-maskable-512.png', 512, True),
                                ('apple-touch-icon.png', 180, False)]]
specifications += [
    {'name': 'api/resources/dev2done-mark.png', 'svg': OUTPUTS['api/resources/dev2done-mark.svg'], 'width': 440, 'height': 256},
    {'name': 'favicon-png', 'svg': app_icon(), 'width': 32, 'height': 32}]
if CHECK:
    normalize = lambda text: re.sub(r'\s+', '', text).replace('/>', '>')
    inline = re.search(r'<!-- brand-mark:start -->\s*(.*?)\s*<!-- brand-mark:end -->', html, re.S)
    if not inline or normalize(inline[1]) != normalize(MASTER):
        raise SystemExit('Inline splash mark is stale')
    for name, content in OUTPUTS.items():
        if not (ROOT / name).exists() or (ROOT / name).read_text() != content:
            raise SystemExit('Brand SVG is stale: ' + name)
    manifest = json.loads(manifest_path.read_text())
    if manifest['masterSha256'] != hashlib.sha256(MASTER.encode()).hexdigest():
        raise SystemExit('PNG derivatives are stale for the current master')
    for name, checksum in manifest['files'].items():
        if hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != checksum:
            raise SystemExit('Brand derivative checksum mismatch: ' + name)
    print('Dev2Done SVGs, splash, PNGs and ICO verified')
else:
    for name, content in OUTPUTS.items():
        (ROOT / name).write_text(content)
    result = subprocess.run(['node', str(ROOT / 'bin/rasterize-brand.mjs')], input=json.dumps(specifications), capture_output=True, text=True)
    if result.returncode:
        raise SystemExit(result.stderr)
    rendered = {name: base64.b64decode(data) for name, data in json.loads(result.stdout).items()}
    png = rendered.pop('favicon-png')
    ico = struct.pack('<HHH', 0, 1, 1) + struct.pack('<BBBBHHII', 32, 32, 0, 0, 1, 32, len(png), 22) + png
    rendered['public/favicon.ico'] = ico
    rendered['api/public/favicon.ico'] = ico
    for name, data in rendered.items():
        (ROOT / name).write_bytes(data)
    manifest = {'brand': 'Dev2Done', 'masterSha256': hashlib.sha256(MASTER.encode()).hexdigest(),
                'renderer': 'Chromium SVG canvas; source geometry/gradients preserved',
                'files': {name: hashlib.sha256(data).hexdigest() for name, data in sorted(rendered.items())}}
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n')
    index.write_text(updated)
    print('Dev2Done SVGs, splash, PNGs and ICO generated')
