#!/usr/bin/env bash
#
# Auto-deploy: cronból percenként/5-percenként fut. Csak akkor csinál bármit,
# ha a GitHubon ÚJ commit van. Ekkor: tiszta reset + (lock-változásnál) composer
# + migráció + Twig-cache ürítés. Így a "deploy" teljesen automatikus, kattintás nélkül.
#
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DIR" || exit 0

GIT=/usr/local/cpanel/3rdparty/bin/git
[ -x "$GIT" ] || GIT=git

"$GIT" config core.fileMode false 2>/dev/null || true
"$GIT" fetch origin main -q 2>/dev/null || exit 0

# Nincs új commit -> nincs teendő (olcsó, gyakran futtatható).
[ "$("$GIT" rev-parse HEAD 2>/dev/null)" = "$("$GIT" rev-parse origin/main 2>/dev/null)" ] && exit 0

OLD_LOCK="$(md5sum composer.lock 2>/dev/null | cut -d' ' -f1)"
"$GIT" reset --hard origin/main -q

# PHP kiválasztása
PHP=""
for v in ea-php83 ea-php82 ea-php81; do
  p="/opt/cpanel/$v/root/usr/bin/php"
  [ -x "$p" ] && PHP="$p" && break
done
[ -z "$PHP" ] && PHP="$(command -v php || echo php)"

# Composer csak akkor, ha a lock változott.
NEW_LOCK="$(md5sum composer.lock 2>/dev/null | cut -d' ' -f1)"
if [ "$OLD_LOCK" != "$NEW_LOCK" ] && [ -f "$HOME/bin/composer" ]; then
  export COMPOSER_MEMORY_LIMIT=-1
  "$PHP" "$HOME/bin/composer" install --no-dev --optimize-autoloader --no-interaction
fi

# Migráció (idempotens: a már lefutottakat kihagyja), könyvtárak, cache-ürítés.
mkdir -p storage/logs storage/uploads storage/cache storage/sessions 2>/dev/null || true
find storage -type d -exec chmod 775 {} + 2>/dev/null || true
"$PHP" vendor/bin/phinx migrate 2>>storage/logs/auto-migrate.log || true
rm -rf storage/cache/* 2>/dev/null || true

echo "$(date '+%F %T') deployed $("$GIT" rev-parse --short HEAD)"
