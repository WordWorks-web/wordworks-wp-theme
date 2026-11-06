#!/usr/bin/env python3
"""Cut a tall side-by-side image into viewable slices: slice.py in.png out_prefix [slice_h] [scale]"""
import sys
from PIL import Image
im = Image.open(sys.argv[1]); p = sys.argv[2]
sh = int(sys.argv[3]) if len(sys.argv) > 3 else 1400
sc = float(sys.argv[4]) if len(sys.argv) > 4 else 0.5
n = 0
for y in range(0, im.height, sh):
    c = im.crop((0, y, im.width, min(im.height, y + sh)))
    c = c.resize((int(c.width * sc), int(c.height * sc)))
    c.save(f'{p}-{n}.png'); n += 1
print(n)
