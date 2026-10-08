#!/usr/bin/env python3
"""Deterministic SVG rasterization for the local brand paths; Python stdlib only.

Run python3 bin/generate-pwa-icons.py. The master mark fits within the maskable
safe circle (radius 40%); ordinary/Apple icons enlarge it by 1.25. No fonts,
external services, package dependencies, or platform image tools are required.
"""
import math
from pathlib import Path
import re
import struct
import xml.etree.ElementTree as ET
import zlib

PUBLIC = Path(__file__).resolve().parent.parent / 'public'
SVG = ET.parse(PUBLIC / 'app-icon.svg').getroot()


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
    (PUBLIC / name).write_bytes(render(size, maskable))
