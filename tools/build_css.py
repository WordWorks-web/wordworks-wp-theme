#!/usr/bin/env python3
"""Builds theme/wordworks/assets/css/site.css.

1. Merges the CSS of every design artboard (design/*.dc.html), dropping duplicates.
2. Adapts it to block markup (buttons, covers, navigation) and drops the
   design-only rules (prototype menu, placeholder labels, its own motion rules).
3. Appends assets/css/src/blocks.css, motion.css and utilities.css.

Usage: python3 tools/build_css.py
"""
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DESIGN = os.path.join(ROOT, 'design')
THEME = os.path.join(ROOT, 'theme', 'wordworks')
ORDER = ["Main", "NuestraHistoria", "Calidad", "Equipo", "GlobalReadiness", "AIContent", "TeamReadiness",
         "SolucionesLinguisticas", "Blog", "TrabajaConNosotras", "Contacto", "FAQ", "AvisoLegal", "Privacidad", "Cookies"]


def style_of(name):
    s = open(os.path.join(DESIGN, name + '.dc.html'), encoding='utf-8').read()
    return re.search(r'<style>(.*?)</style>', s, re.S).group(1)


def parse(s, ctx=(), out=None):
    out = [] if out is None else out
    s = re.sub(r'/\*.*?\*/', '', s, flags=re.S)
    i = 0
    while i < len(s):
        j = s.find('{', i)
        if j < 0:
            break
        sel = s[i:j].strip()
        if sel.startswith('@media') or sel.startswith('@supports') or sel.startswith('@keyframes'):
            d, k = 1, j + 1
            while d:
                d += {'{': 1, '}': -1}.get(s[k], 0)
                k += 1
            if sel.startswith('@keyframes'):
                out.append((ctx, sel, s[j + 1:k - 1].strip()))
            else:
                parse(s[j + 1:k - 1], ctx + (sel,), out)
            i = k
        else:
            k = s.find('}', j)
            out.append((ctx, sel, s[j + 1:k].strip()))
            i = k + 1
    return out


# Selectors owned by the block theme (navigation, header variants) or by motion.css.
DROP = re.compile(r'(^|[\s,>+~])\.(nav|nl|dd|caret|burger|mm|mm-[a-z]+|has-sub|ph-label|todo|todo-wrap|reveal|load|load-2|load-3|load-fade|below|post|pager)\b'
                  r'|\.hdr\.solid|^body$|^a$|^a:hover$')


def adapt_selector(sel):
    parts = []
    for p in [x.strip() for x in sel.split(',')]:
        if DROP.search(p):
            continue
        parts.append(p)
        if p == '.btn':
            parts.append('.wp-block-button__link')
        elif p == '.btn:hover':
            parts.append('.wp-block-button__link:hover')
        elif p == '.btn-sm':
            parts[-1] = '.btn.btn-sm'
            parts.append('.btn-sm>.wp-block-button__link')
        elif re.search(r'\s\.btn$', p):
            parts.append(p[:-4] + ' .wp-block-buttons')
        elif p == '.stat span':
            parts[-1] = '.stat .stat-l'
        elif p == '.photo>*':
            parts[-1] = '.photo>.wp-block-cover__inner-container>*'
    return ','.join(parts)


def adapt_body(sel, body):
    if sel == '.photo':
        body += ';min-height:0'
    if sel == '.photo::before':
        body += ';z-index:1'
    if sel == '.photo>.wp-block-cover__inner-container>*':
        body += ';z-index:2'
    if sel.startswith('.fit .btn'):
        # Size goes to the link, spacing to the wrapper (see blocks.css).
        return 'margin-top:72px'
    return body


def build():
    base = style_of('Main')
    common = base[:base.index('.home-hero{')]
    seen = set()
    lines = ['/* ==== Design CSS (merged from design/*.dc.html by tools/build_css.py; do not edit) ==== */']

    def emit(rules, label):
        lines.append(f'\n/* ---- {label} ---- */')
        cur = None
        for ctx, sel, body in rules:
            if (ctx, sel, body) in seen or sel.startswith('@keyframes') or any('animation-timeline' in c for c in ctx):
                continue
            seen.add((ctx, sel, body))
            if any('prefers-reduced-motion' in c for c in ctx):
                continue
            new_sel = adapt_selector(sel)
            if not new_sel:
                continue
            new_body = adapt_body(new_sel.split(',')[0], body)
            if ctx != cur:
                if cur:
                    lines.append('}' * len(cur))
                if ctx:
                    lines.append(''.join(c + '{' for c in ctx))
                cur = ctx
            lines.append(f'{new_sel}{{{new_body}}}')
        if cur:
            lines.append('}' * len(cur))

    # Each artboard's CSS is: [shared base][page rules A][shared responsive block][page rules B].
    # Keep that cascade order for every page: base, all A parts, shared block, all B parts.
    specs = {n: parse(style_of(n)[len(common):]) for n in ORDER}
    shared = set.intersection(*[set(v) for v in specs.values()])
    split = {}
    for n, rules in specs.items():
        idx = [i for i, r in enumerate(rules) if r in shared]
        split[n] = (rules[:idx[0]], rules[idx[0]:idx[-1] + 1], rules[idx[-1] + 1:])
    emit(parse(common), 'shared base')
    for n in ORDER:
        emit(split[n][0], n)
    emit(split['Main'][1], 'shared responsive')
    for n in ORDER:
        emit(split[n][2], n + ' (responsive)')

    css = '\n'.join(lines) + '\n'
    for part in ('blocks.css', 'motion.css', 'utilities.css'):
        with open(os.path.join(THEME, 'assets', 'css', 'src', part), encoding='utf-8') as f:
            css += f'\n/* ==== {part} ==== */\n' + f.read()
    with open(os.path.join(THEME, 'assets', 'css', 'site.css'), 'w', encoding='utf-8') as f:
        f.write(css)
    print(f'site.css: {len(css)} bytes')


if __name__ == '__main__':
    build()
