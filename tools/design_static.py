#!/usr/bin/env python3
"""Writes static HTML renders of the design artboards (for screenshot comparison).
Usage: python3 tools/design_static.py <out_dir>"""
import os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
out = sys.argv[1]; os.makedirs(out, exist_ok=True)
font = os.path.join(ROOT, 'theme/wordworks/assets/fonts')
face = ("@font-face{font-family:Montserrat;font-style:normal;font-weight:100 900;src:url('file://%s/montserrat-latin-wght-normal.woff2') format('woff2')}"
        "@font-face{font-family:Montserrat;font-style:italic;font-weight:100 900;src:url('file://%s/montserrat-latin-wght-italic.woff2') format('woff2')}") % (font, font)
for f in sorted(os.listdir(os.path.join(ROOT, 'design'))):
    if not f.endswith('.dc.html'): continue
    s = open(os.path.join(ROOT, 'design', f), encoding='utf-8').read()
    style = re.search(r'<style>(.*?)</style>', s, re.S).group(1)
    body = re.search(r'</helmet>(.*)</x-dc>', s, re.S).group(1)
    body = re.sub(r'<sc-if.*?</sc-if>', '', body, flags=re.S)
    body = re.sub(r'\s(onClick|hint-placeholder-val)="\{\{[^}]*\}\}"', '', body)
    html = f'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>{face}{style}</style></head><body>{body}</body></html>'
    open(os.path.join(out, f.replace('.dc.html', '.html')), 'w', encoding='utf-8').write(html)
print('ok')
