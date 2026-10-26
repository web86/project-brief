#!/usr/bin/env python3
"""Generate all standalone brand assets from src/assets/brand-mark.svg.

Run python3 bin/generate-pwa-icons.py [--check]. The master mark fits within the maskable
safe circle (radius 40%); ordinary/Apple icons enlarge it by 1.25. No fonts,
external services, package dependencies, or platform image tools are required.
"""
import math
from pathlib import Path
import re
import struct
import sys
import xml.etree.ElementTree as ET
import zlib

PUBLIC = Path(__file__).resolve().parent.parent / 'public'
ROOT = PUBLIC.parent
MASTER = (ROOT / 'src/assets/brand-mark.svg').read_text().strip()
CHECK = '--check' in sys.argv
OUTPUTS = {}


def standalone(foreground, detail, background=False):
    svg = MASTER.replace('currentColor', foreground)
    svg = svg.replace(f'var(--brand-detail, {foreground})', detail)
    svg = svg.replace(' aria-hidden="true"', '')
    if background:
        svg = svg.replace('viewBox="96 104 320 304"', 'viewBox="0 0 512 512"')
        svg = svg.replace('>\n', '>\n  <rect width="512" height="512" fill="#243b62"/>\n', 1)
    return svg + '\n'


OUTPUTS['app-icon.svg'] = standalone('#ffffff', '#c9d7ed', True)
for name, foreground, detail in [('light', '#243b62', '#5979a6'), ('dark', '#9dbff4', '#bad2fa')]:
    svg = standalone(foreground, detail)
    OUTPUTS[f'brand-mark-{name}.svg'] = svg
    inner = svg[svg.index('>')+1:svg.rindex('</svg>')]
    text = '#1f2937' if name == 'light' else '#e4e9f0'
    OUTPUTS[f'brand-logo-{name}.svg'] = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 270 64">\n'
        f'  <g transform="translate(-19.2 -20.8) scale(.2)">{inner}  </g>\n'
        f'  <text x="70" y="42" fill="{text}" font-family="system-ui, sans-serif" '
        'font-size="30" font-weight="650" letter-spacing="-.8">project'
        '<tspan font-weight="450">brief</tspan></text>\n</svg>\n'
    )
favicon = OUTPUTS['app-icon.svg'].replace('height="512" fill=', 'height="512" rx="112" fill=')
favicon = favicon.replace('  <path', '  <g transform="translate(-64 -64) scale(1.25)">\n  <path')
OUTPUTS['favicon.svg'] = favicon.replace('</svg>', '  </g>\n</svg>')
SVG = ET.fromstring(OUTPUTS['app-icon.svg'])


def polygons(data):
    tokens = iter(re.findall(r'[MLHVCZ]|-?\d+(?:\.\d+)?', data))
    paths, points, x, y = [], [], 0, 0
    for command in tokens:
        if command in ('M', 'L'):
            x, y = float(next(tokens)), float(next(tokens))
            points.append((x, y))
        elif command == 'H':
            x = float(next(tokens))
            points.append((x, y))
        elif command == 'V':
            y = float(next(tokens))
            points.append((x, y))
        elif command == 'C':
            a, b, c, d, e, f = [float(next(tokens)) for _ in range(6)]
            for step in range(1, 65):
                t = step / 64
                u = 1 - t
                points.append((u**3*x + 3*u*u*t*a + 3*u*t*t*c + t**3*e,
                               u**3*y + 3*u*u*t*b + 3*u*t*t*d + t**3*f))
            x, y = e, f
        elif command == 'Z':
            paths.append(points)
            points = []
        else:
            raise ValueError('Unsupported SVG command: ' + command)
    return paths


def chunk(name, data):
    return struct.pack('!I', len(data)) + name + data + struct.pack('!I', zlib.crc32(name + data))


def render(size, maskable):
    samples = 4
    width = size * samples
    scale = 1 if maskable else 1.25
    transform = lambda v: ((v - 256) * scale + 256) * width / 512
    background = bytes.fromhex(SVG[0].attrib['fill'][1:])
    pixels = bytearray(background * width * width)
    for node in SVG[1:]:
        color = bytes.fromhex(node.attrib['fill'][1:])
        if node.tag.endswith('path'):
            paths = polygons(node.attrib['d'])
        else:
            cx, cy, radius = [float(node.attrib[key]) for key in ('cx', 'cy', 'r')]
            paths = [[(cx + radius * math.cos(i * math.tau / 256),
                       cy + radius * math.sin(i * math.tau / 256)) for i in range(256)]]
        paths = [[(transform(x), transform(y)) for x, y in path] for path in paths]
        edges = [(a, b) for path in paths for a, b in zip(path, path[1:] + path[:1])]
        for row in range(width):
            y = row + .5
            crossings = sorted(a[0] + (y-a[1]) * (b[0]-a[0]) / (b[1]-a[1])
                               for a, b in edges if (a[1] > y) != (b[1] > y))
            for left, right in zip(crossings[::2], crossings[1::2]):
                start, end = max(0, math.ceil(left-.5)), min(width, math.ceil(right-.5))
                offset = (row * width + start) * 3
                pixels[offset:offset + (end-start)*3] = color * (end-start)
    output = bytearray()
    for y in range(size):
        output.append(0)
        for x in range(size):
            totals = [0, 0, 0]
            for dy in range(samples):
                offset = ((y * samples + dy) * width + x * samples) * 3
                for dx in range(samples):
                    for channel in range(3):
                        totals[channel] += pixels[offset + dx * 3 + channel]
            output.extend((total + 8) // 16 for total in totals)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', size, size, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(output, 9)) + chunk(b'IEND', b''))


for name, size, maskable in [('icon-192.png', 192, False), ('icon-512.png', 512, False),
                             ('icon-maskable-192.png', 192, True), ('icon-maskable-512.png', 512, True),
                             ('apple-touch-icon.png', 180, False)]:
    OUTPUTS[name] = render(size, maskable)

for name, content in OUTPUTS.items():
    data = content.encode() if isinstance(content, str) else content
    target = PUBLIC / name
    if CHECK:
        if not target.exists() or target.read_bytes() != data:
            raise SystemExit('Brand asset is stale: ' + name)
    else:
        target.write_bytes(data)

# The splash must work before JS or external CSS load. Keep its inline geometry
# generated from exactly the same source that BrandLogo.vue imports as raw SVG.
index = ROOT / 'index.html'
html = index.read_text()
updated = re.sub(r'(<!-- brand-mark:start -->)\s*.*?\s*(<!-- brand-mark:end -->)',
                 lambda m: m[1] + '\n        ' + MASTER + '\n        ' + m[2], html, flags=re.S)
if CHECK:
    inline = re.search(r'<!-- brand-mark:start -->\s*(.*?)\s*<!-- brand-mark:end -->', html, re.S)
    normalize = lambda text: re.sub(r'\s+', '', text).replace('/>', '>')
    if not inline or normalize(inline[1]) != normalize(MASTER):
        raise SystemExit('Inline splash mark is stale')
else:
    index.write_text(updated)
print('Brand SVGs, splash and 5 PNGs ' + ('verified' if CHECK else 'generated'))
