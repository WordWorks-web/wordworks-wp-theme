#!/usr/bin/env python3
"""Compose two full-page screenshots side by side and print a difference score.
Usage: python3 tools/sidebyside.py left.png right.png out.png"""
import sys
from PIL import Image, ImageChops, ImageStat

a, b = Image.open(sys.argv[1]).convert('RGB'), Image.open(sys.argv[2]).convert('RGB')
h = max(a.height, b.height)
gap = 16
out = Image.new('RGB', (a.width + b.width + gap, h), (255, 0, 255))
out.paste(a, (0, 0))
out.paste(b, (a.width + gap, 0))
out.save(sys.argv[3])

w, hh = min(a.width, b.width), min(a.height, b.height)
diff = ImageChops.difference(a.crop((0, 0, w, hh)), b.crop((0, 0, w, hh)))
mean = sum(ImageStat.Stat(diff).mean) / 3
print(f'height {a.height} vs {b.height} ({b.height - a.height:+d}px), mean pixel diff {mean:.1f}/255')
