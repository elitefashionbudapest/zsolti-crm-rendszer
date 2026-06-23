#!/usr/bin/env bash
#
# cPanel Git "Deploy HEAD" futtatja (a .cpanel.yml-ből), mert nincs SSH/Terminal.
# Mindent elvégez a szerveren: PHP kiválasztása, Composer telepítése (ha kell),
# függőségek, adatbázis-migráció, első seed (belépő-felhasználók!), jogosultságok.
#
set -uo pipefail
DEPLOYPATH="${DEPLOYPATH:-$HOME/zsolti-crm}"
cd "$DEPLOYPATH" || { echo "Nincs ilyen mappa: $DEPLOYPATH"; exit 1; }

# --- PHP binaris kivalasztasa (8.3 vagy 8.2 elonyben) ---
PHP=""
for v in ea-php83 ea-php82 ea-php81; do
  p="/opt/cpanel/$v/root/usr/bin/php"
  [ -x "$p" ] && PHP="$p" && break
done
[ -z "$PHP" ] && PHP="$(command -v php || echo php)"
echo "==> PHP: $PHP ($($PHP -r 'echo PHP_VERSION;' 2>/dev/null))"

# --- Composer telepitese a fiokba, ha nincs ---
COMPOSER="$HOME/bin/composer"
if [ ! -f "$COMPOSER" ]; then
  echo "==> Composer telepitese…"
  mkdir -p "$HOME/bin"
  ( cd "$HOME" && { curl -fsSL https://getcomposer.org/installer -o composer-setup.php \
      || wget -qO composer-setup.php https://getcomposer.org/installer; } \
    && "$PHP" composer-setup.php --install-dir="$HOME/bin" --filename=composer \
    && rm -f composer-setup.php )
fi

# --- Fuggosegek (csak eles) ---
export COMPOSER_MEMORY_LIMIT=-1
echo "==> Composer install…"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

# --- Konyvtarak ---
mkdir -p storage/logs storage/uploads storage/cache
chmod -R 775 storage 2>/dev/null || true

# --- Adatbazis: csak ha mar van .env (DB beallitasok) ---
if [ -f .env ]; then
  echo "==> Migraciok…"
  "$PHP" vendor/bin/phinx migrate
  # Elso seed: letrehozza a demo irodat, a belepő-felhasznalokat es a demo adatot.
  if [ ! -f storage/.seeded ]; then
    echo "==> Elso seed (belepő-felhasznalok + demo adat)…"
    "$PHP" vendor/bin/phinx seed:run && touch storage/.seeded
  fi
else
  echo "!! Nincs .env — hozd letre (DB adatok + APP_KEY), majd Deploy ujra."
fi

# --- Twig cache uritese ---
rm -rf storage/cache/* 2>/dev/null || true
echo "==> Kesz: $(git rev-parse --short HEAD 2>/dev/null)"
