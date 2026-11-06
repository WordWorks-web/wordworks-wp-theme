# Word Works — block theme

Page-by-page rebuild of <https://wordworks.es> as a WordPress block theme, built from the
design canvas "Word Works — wordworks.es clone" (`design/`, 15 artboards).

```
theme/wordworks/        the theme (upload this folder)
  theme.json            colours, Montserrat (self-hosted), button element, custom template
  templates/            page, page-solid-header, home (blog), index, single, 404
  parts/                header, footer (each loads a pattern)
  patterns/             page-<slug>.php for every page, header, footer, blog-intro  (generated)
  assets/css/site.css   generated from design CSS + assets/css/src/*.css
  assets/js/motion.js   scroll reveals (IntersectionObserver) + current menu item
  inc/                  link/image resolution, [wordworks_form] contact forms
tools/                  converter, CSS build, validators, screenshot comparison
deploy/                 deploy.sh (ssh ww-hostinger), setup.php (wp eval-file), manifest.json
design/                 the design source the theme is generated from
```

## How it maps to WordPress

- Every page is real core blocks (Group, Heading, Paragraph, List, Buttons, Cover, Image,
  Custom HTML for the bubble SVGs, Shortcode for the forms), editable in the block editor.
  Patterns are generated with Gutenberg's own `serialize()` (packages pinned to WordPress 7.0)
  and validated against WordPress 6.9 and 7.0: no "unexpected content" warnings.
- Header = logo + core Navigation block (hover/click dropdowns, full-screen mobile menu
  below 1024px); footer = groups/lists. Both are template parts, editable in the Site Editor.
- Pages with the solid black header use the template **"Página con cabecera sólida"**.
- Blog = posts page with a Query Loop (2-column cards, numbered pagination).
- Forms: `[wordworks_form type="contacto"]` and `[wordworks_form type="trabaja"]` e-mail
  `info@wordworks.es` (option `wordworks_form_to`); honeypot + timing spam check.
- Images: covers and image blocks are matched to Media Library files by the file names in
  the design (e.g. `quienes-somos-2.png`). Missing ones are downloaded from the matching
  wordworks.es page by `deploy.sh` and imported with `wp media import`.
- Motion (from the design notes): hero text rises on load; sections, cards and lists rise as
  they scroll in; buttons/cards lift on hover; dropdowns fade. All off with
  `prefers-reduced-motion`; content stays visible without JS and in the editor.

## Rebuild after changing the design

```bash
cd tools && npm ci && node convert.mjs && cd .. && python3 tools/build_css.py
node tools/validate.mjs theme/wordworks/templates/*.html
```

## Deploy to staging

Needs `ssh ww-hostinger` (WP-CLI on the server) and WordPress 6.9+.

```bash
deploy/deploy.sh
```

It refuses to run unless the server's `siteurl` is the staging URL, dumps the database and
the previous theme to `~/wordworks-deploy/backups`, uploads + activates the theme, imports
missing images, writes every page from its pattern (old content stays as a revision), sets
front page / posts page, moves Site Editor customisations of this theme to the trash (JSON
backup in `wp-content/`), and purges caches. `deploy/deploy.sh --theme-only` uploads just the theme.

## Compare with the live site

```bash
node tools/compare.mjs --left https://wordworks.es --right https://lightblue-fox-316339.hostingersite.com --out shots
node tools/compare.mjs --left https://wordworks.es --right https://lightblue-fox-316339.hostingersite.com --out shots --width 390
node tools/compare.mjs --left https://wordworks.es --right https://lightblue-fox-316339.hostingersite.com --out shots --motion --pages home
```

Each run writes `shots/<page>-<width>.png` (live | staging) and prints a height and
pixel-difference score per page; `--motion` scrolls slowly with animations on and saves
a filmstrip.
