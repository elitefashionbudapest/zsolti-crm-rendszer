#!/usr/bin/env bash
#
# cPanel Git "Deploy HEAD" futtatja (a .cpanel.yml-ből), mert nincs SSH/Terminal.
# Mindent elvégez a szerveren: PHP kiválasztása, Composer telepítése (ha kell),
# függőségek, adatbázis-migráció, első seed (belépő-felhasználók!), jogosultságok.
#
set -uo pipefail
# A repó gyökerét a script SAJÁT helyéből vezetjük le (deploy/ szülője), így nem
# függ a .cpanel.yml taszkok közti env-öröklődéstől (ami cPanelen nincs).
DEPLOYPATH="${DEPLOYPATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$DEPLOYPATH" || { echo "Nincs ilyen mappa: $DEPLOYPATH"; exit 1; }
echo "==> DEPLOYPATH: $DEPLOYPATH"

# A chmod/jogosultság-változások ne tegyek a git-fat "piszkossa" (a cPanel deploy
# tiszta fat var). A git ettol nem figyeli a fajl-mod valtozasokat.
git config core.fileMode false 2>/dev/null || true

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
mkdir -p storage/logs storage/uploads storage/cache storage/sessions
# Csak a konyvtarakat chmod-oljuk (a kovetett .gitkeep fajlokat ne).
find storage -type d -exec chmod 775 {} + 2>/dev/null || true

# --- Adatbazis: csak ha van .env (a repoban VAGY a szulo/nagyszulo mappaban) ---
if [ -f .env ] || [ -f ../.env ] || [ -f ../../.env ]; then
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

# --- Alkonyvtaras kiszolgalas: szimlink a domain dokumentumgyokerebe ---
# A /zsolti_crm a public mappara mutasson. A domain docroot-ja altalaban
# ~/public_html vagy ~/visualbyadam.hu — mindkettore probaljuk.
for d in "$HOME/public_html" "$HOME/visualbyadam.hu"; do
  [ -d "$d" ] || continue
  # Ha a cel maga a repo mappaja (a repo a docrooton belul van), NE clobbereld —
  # ott a repo gyokerebeli .htaccess iranyit a public/-ra.
  [ "$d/zsolti_crm" = "$DEPLOYPATH" ] && { echo "==> a repo a docrootban van, szimlink helyett a gyoker .htaccess szolgal ki"; continue; }
  ln -sfn "$DEPLOYPATH/public" "$d/zsolti_crm" && echo "==> szimlink: $d/zsolti_crm -> public"
done

# --- Twig cache uritese ---
rm -rf storage/cache/* 2>/dev/null || true
echo "==> Kesz: $(git rev-parse --short HEAD 2>/dev/null)"
