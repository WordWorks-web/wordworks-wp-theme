#!/usr/bin/env bash
# Deploy the Word Works block theme and its pages to the staging site over SSH.
#
#   deploy/deploy.sh               build, upload, import images, rebuild pages
#   deploy/deploy.sh --theme-only  only upload the theme (pages and media untouched)
#
# Environment overrides:
#   WW_SSH_HOST  (default ww-hostinger)      SSH host alias from ~/.ssh/config
#   WW_SITE_URL  (default https://lightblue-fox-316339.hostingersite.com)
#   WW_LIVE_URL  (default https://wordworks.es)  where missing images are downloaded from
#   WW_WP_PATH   WordPress root on the server (auto-detected under ~/domains/<host>/public_html)
#
# Safety: refuses to run unless the WordPress install at WW_WP_PATH reports WW_SITE_URL
# as its siteurl, and never targets the live domain. A database dump and a copy of the
# previous theme are kept in ~/wordworks-deploy/backups on the server.
set -euo pipefail

HOST="${WW_SSH_HOST:-ww-hostinger}"
SITE_URL="${WW_SITE_URL:-https://lightblue-fox-316339.hostingersite.com}"
LIVE_URL="${WW_LIVE_URL:-https://wordworks.es}"
THEME_ONLY=0
[[ "${1:-}" == "--theme-only" ]] && THEME_ONLY=1

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_HOST="${SITE_URL#https://}"; SITE_HOST="${SITE_HOST#http://}"; SITE_HOST="${SITE_HOST%%/*}"
REMOTE_DIR='~/wordworks-deploy'
STAMP="$(date +%Y%m%d-%H%M%S)"

say() { printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

case "$SITE_HOST" in
  wordworks.es|www.wordworks.es) die "WW_SITE_URL points at the live site; this script only deploys to staging." ;;
esac

say "Building patterns and CSS"
if [[ -d "$ROOT/tools/node_modules" ]] || (cd "$ROOT/tools" && npm ci --silent); then
  (cd "$ROOT/tools" && node convert.mjs)
fi
python3 "$ROOT/tools/build_css.py"

say "Locating WordPress on $HOST"
WP_PATH="${WW_WP_PATH:-}"
if [[ -z "$WP_PATH" ]]; then
  WP_PATH="$(ssh "$HOST" "for d in ~/domains/$SITE_HOST/public_html ~/public_html; do if [ -f \"\$d/wp-config.php\" ]; then cd \"\$d\" && pwd; break; fi; done")"
fi
[[ -n "$WP_PATH" ]] || die "WordPress not found; set WW_WP_PATH."
WP="wp --path=$WP_PATH"
echo "WordPress root: $WP_PATH"

REMOTE_SITEURL="$(ssh "$HOST" "$WP option get siteurl" | tr -d '\r')"
[[ "${REMOTE_SITEURL%/}" == "${SITE_URL%/}" ]] || die "siteurl at $WP_PATH is '$REMOTE_SITEURL', expected '$SITE_URL'. Not deploying."
WP_VERSION="$(ssh "$HOST" "$WP core version" | tr -d '\r')"
echo "WordPress $WP_VERSION at $REMOTE_SITEURL"
if [[ "$(printf '%s\n6.9\n' "$WP_VERSION" | sort -V | head -1)" != "6.9" ]]; then
  die "WordPress $WP_VERSION is older than 6.9; update core first (the block markup targets 6.9+)."
fi

say "Backing up database and current theme"
ssh "$HOST" "mkdir -p $REMOTE_DIR/backups && $WP db export $REMOTE_DIR/backups/db-$STAMP.sql --quiet && \
  if [ -d '$WP_PATH/wp-content/themes/wordworks' ]; then tar -C '$WP_PATH/wp-content/themes' -czf $REMOTE_DIR/backups/theme-wordworks-$STAMP.tgz wordworks; fi && \
  echo \"active theme before deploy: \$($WP option get stylesheet)\" | tee $REMOTE_DIR/backups/previous-theme-$STAMP.txt"

say "Uploading theme"
tar -C "$ROOT/theme" --exclude='.DS_Store' -czf - wordworks | ssh "$HOST" "set -e; cd '$WP_PATH/wp-content/themes'; \
  rm -rf .wordworks-new && mkdir .wordworks-new && tar -xzf - -C .wordworks-new; \
  rm -rf .wordworks-old; if [ -d wordworks ]; then mv wordworks .wordworks-old; fi; \
  mv .wordworks-new/wordworks wordworks && rm -rf .wordworks-new .wordworks-old"
ssh "$HOST" "$WP theme activate wordworks"

if [[ "$THEME_ONLY" == 0 ]]; then
  say "Uploading setup script and manifest"
  ssh "$HOST" "mkdir -p $REMOTE_DIR"
  tar -C "$ROOT/deploy" -czf - setup.php manifest.json | ssh "$HOST" "tar -xzf - -C $REMOTE_DIR"

  say "Images: Media Library first, then $LIVE_URL"
  ssh "$HOST" "set -e; cd $REMOTE_DIR; \
    $WP eval-file setup.php media-plan manifest.json '$LIVE_URL' > media-plan.tsv; \
    : > media-map.tsv; \
    while IFS=\$'\t' read -r file url; do \
      [ -n \"\$url\" ] || continue; \
      id=\$($WP media import \"\$url\" --porcelain 2>/dev/null || true); \
      if [ -n \"\$id\" ]; then printf '%s\t%s\n' \"\$file\" \"\$id\" >> media-map.tsv; echo \"imported \$file -> #\$id\"; \
      else echo \"FAILED to import \$file from \$url\"; fi; \
    done < media-plan.tsv; \
    $WP eval-file setup.php media-save media-map.tsv"

  say "Pages"
  ssh "$HOST" "cd $REMOTE_DIR && $WP eval-file setup.php pages manifest.json --reset-customizations"

  say "Image report"
  ssh "$HOST" "cd $REMOTE_DIR && $WP eval-file setup.php media-report manifest.json"
fi

say "Flushing caches"
ssh "$HOST" "$WP rewrite flush --quiet; $WP cache flush --quiet || true; wp --path=$WP_PATH litespeed-purge all 2>/dev/null || true"

say "Done: $SITE_URL"
echo "Compare with the live site:  node tools/compare.mjs --left $LIVE_URL --right $SITE_URL --out shots"
