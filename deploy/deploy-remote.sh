#!/usr/bin/env bash
#
# A SZERVEREN futó deploy lépések (tárhely.eu / cPanel, Jailed SSH).
# A GitHub Action és a helyi deploy.sh is ezt hívja: git szinkron → composer
# → migrációk → könyvtárak/jogosultságok. Idempotens, többször futtatható.
#
set -euo pipefail

# A repo gyökerébe lépünk (a script a deploy/ alatt van).
cd "$(dirname "$0")/.."

# Felülírható környezeti változók (cPanelen gyakran kell a teljes elérési út):
#   PHP_BIN=/opt/cpanel/ea-php82/root/usr/bin/php
#   COMPOSER_BIN=$HOME/bin/composer
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-$HOME/bin/composer}"
export COMPOSER_MEMORY_LIMIT=-1

echo "==> [1/5] Git szinkronizálás az origin/main-re…"
git fetch --all --prune
git reset --hard origin/main

echo "==> [2/5] Composer (csak éles függőségek)…"
if [ -f "$COMPOSER_BIN" ]; then
  "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
else
  # Tartalék: ha a composer a PATH-on van
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "==> [3/5] Adatbázis-migrációk…"
"$PHP_BIN" vendor/bin/phinx migrate

echo "==> [4/5] Könyvtárak és jogosultságok…"
mkdir -p storage/logs storage/uploads storage/cache
chmod -R 775 storage 2>/dev/null || true

echo "==> [5/5] Twig gyorsítótár ürítése…"
rm -rf storage/cache/* 2>/dev/null || true

echo "==> Deploy kész: $(git rev-parse --short HEAD)"
