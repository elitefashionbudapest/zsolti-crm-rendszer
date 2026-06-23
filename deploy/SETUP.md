# Telepítés és automatikus deploy — tárhely.eu (cPanel)

Git-alapú deploy. A kód a GitHubon van; a szerver onnan húzza le. **Nem kell
kézzel pusholni:** vagy a GitHub Action deployol automatikusan minden push után,
vagy a `./deploy.sh` egyetlen paranccsal commitol + pushol + szinkronizál.

A folyamat: **push a GitHubra → SSH a szerverre → `git pull` + `composer` +
migrációk**.

---

## 0) Áttekintés

```
[helyi gép]  --push-->  [GitHub repo]  --GitHub Action / deploy.sh (SSH)-->  [tárhely.eu]
                                                                              ~/zsolti-crm  (kód)
                                                                              docroot → ~/zsolti-crm/public
```

A két deploy-mód közül elég az egyik (de mindkettő mehet egyszerre):
- **A) GitHub Action** — automatikus, push után magától deployol.
- **B) `./deploy.sh`** — helyi egygombos parancs (commit + push + szerver-szinkron).

---

## 1) Egyszeri szerver-beállítás (tárhely.eu)

### 1.1 SSH bekapcsolása
cPanel → **Jailed SSH** → „jailed SSH bekapcsolása". Az oldalon megjelenik a
**szerver IP**, a **felhasználónév** és a **port (22)**. Jelszó = cPanel jelszó.

### 1.2 Composer telepítése a fiókba
SSH-n belépve:
```bash
cd ~
wget -O composer-setup.php https://getcomposer.org/installer
mkdir -p bin
php composer-setup.php --install-dir=bin --filename=composer
rm -f composer-setup.php
~/bin/composer --version
```

### 1.3 PHP verzió
cPanel → **MultiPHP Manager** → a domainhez PHP **8.2** (vagy újabb).
A CLI php verzióját ellenőrizd: `php -v`. Ha régi, a teljes út pl.:
`/opt/cpanel/ea-php82/root/usr/bin/php` — ezt a `deploy/deploy-remote.sh`
elején a `PHP_BIN` változóval lehet megadni (vagy a cron sorban a teljes úttal).

### 1.4 A repo letöltése (deploy kulccsal)
A privát GitHub repóhoz a szervernek olvasási jog kell. Készíts deploy kulcsot:
```bash
ssh-keygen -t ed25519 -C "tarhely-deploy" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```
A kiírt **publikus** kulcsot add hozzá a GitHubon:
**repo → Settings → Deploy keys → Add deploy key** (Read csak elég).
Majd klónozd:
```bash
git clone git@github.com:elitefashionbudapest/zsolti-crm-rendszer.git ~/zsolti-crm
cd ~/zsolti-crm
```

### 1.5 Webgyökér (document root) a `public/`-ra
cPanel → **Domains** (vagy „Aldomének") → a kívánt domain/aldomén
**dokumentumgyökerét** állítsd erre: `zsolti-crm/public`.
Így a `public/` a webgyökér, a kód (`src/`, `.env`, `storage/`) a webről nem
érhető el. (Ha nem tudod átállítani, jelezd — van `.htaccess`-es megoldás is.)

### 1.6 MySQL adatbázis
cPanel → **MySQL® Databases**: hozz létre adatbázist + felhasználót, és rendeld
hozzá (ALL PRIVILEGES). Jegyezd fel: **DB név, felhasználó, jelszó**. A host
tárhely.eu-n `localhost`.

### 1.7 `.env` a szerveren
```bash
cd ~/zsolti-crm
cp .env.example .env
nano .env
```
Töltsd ki:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://a-te-domained.hu
DB_DRIVER=mysql
DB_HOST=localhost
DB_NAME=cpanelfelh_aegis
DB_USER=cpanelfelh_aegis
DB_PASS=erős-jelszó
SESSION_SECURE=true
```
Titkosítási kulcs generálása (ezt a sort írd be az `APP_KEY=`-hez):
```bash
php bin/console app:generate-key
```

### 1.8 Első telepítés (függőségek + adatbázis)
```bash
~/bin/composer install --no-dev --optimize-autoloader
php vendor/bin/phinx migrate
mkdir -p storage/logs storage/uploads storage/cache && chmod -R 775 storage
```
Demó adat (csak ha bemutatóhoz kell, **éles ügyféladat mellé ne**):
```bash
php vendor/bin/phinx seed:run
```

### 1.9 Háttérfolyamatok — cron (cPanel → Cron Jobs)
Megosztott tárhelyen nincs folyamatosan futó worker, ezért cron hívja:
```
* * * * *      cd ~/zsolti-crm && php bin/console schedule:run   >> storage/logs/cron.log 2>&1
*/10 * * * *   cd ~/zsolti-crm && php bin/console imap:sync      >> storage/logs/imap.log 2>&1
* * * * *      cd ~/zsolti-crm && php bin/console queue:work --once >> storage/logs/worker.log 2>&1
```
(Ha a `php` CLI régi: cseréld a teljes útra, pl. `/opt/cpanel/ea-php82/root/usr/bin/php`.)

---

## 2) A) Automatikus deploy GitHub Actionnel (ajánlott)

A `.github/workflows/deploy.yml` minden `main`-re pusholt változás után
belép SSH-n és lefuttatja a `deploy/deploy-remote.sh`-t. Csak titkok kellenek.

### 2.1 SSH kulcs a deployhoz
Generálj egy dedikált kulcspárt (helyi gépen vagy a szerveren), és a **publikus**
felét tedd a szerver `~/.ssh/authorized_keys` fájljába:
```bash
ssh-keygen -t ed25519 -C "github-actions" -f gha_key -N ""
# a gha_key.pub tartalmát fűzd a szerver ~/.ssh/authorized_keys végére
```

### 2.2 GitHub repo titkok
**repo → Settings → Secrets and variables → Actions → New repository secret**:
| Név | Érték |
|-----|-------|
| `SSH_HOST` | a szerver IP-je |
| `SSH_USER` | cPanel felhasználónév |
| `SSH_PORT` | `22` |
| `SSH_KEY` | a **privát** `gha_key` teljes tartalma |
| `APP_DIR` | `~/zsolti-crm` |

Vagy parancssorból (gh CLI):
```bash
gh secret set SSH_HOST -b"123.45.67.89"
gh secret set SSH_USER -b"cpanelfelh"
gh secret set SSH_PORT -b"22"
gh secret set APP_DIR  -b"~/zsolti-crm"
gh secret set SSH_KEY  < gha_key
```
Ettől kezdve minden push automatikusan deployol. Kézi indítás:
**repo → Actions → „Deploy to tárhely.eu" → Run workflow**.

---

## 3) B) Egygombos deploy helyi gépről (`./deploy.sh`)

```bash
cp deploy/deploy.config.example deploy/deploy.config
# töltsd ki az SSH adatokat
./deploy.sh                       # commit + push + szerver-szinkron
./deploy.sh "üzenet a committhoz" # saját commit üzenettel
```

---

## 4) Frissítés a jövőben

- **Semmi kézi lépés:** elég pushra kerülnie a kódnak (a GitHub Action deployol),
  vagy futtatni a `./deploy.sh`-t. A szerver mindig az `origin/main`-re áll
  (`git reset --hard`), lefut a composer és a migráció.
- Új adatbázis-változás = új Phinx migráció a `database/migrations/`-ben; a deploy
  automatikusan lefuttatja.

## 5) Hibakeresés

- **Action hibázik a titkok előtt:** amíg a 2.2 titkok nincsenek beállítva, a
  futás elbukik — ez normális, állítsd be a titkokat.
- **`php` régi verzió:** add meg a teljes PHP-utat (`PHP_BIN`) a cron sorban és a
  `deploy/deploy-remote.sh`-ban.
- **403 / fehér oldal:** a domain dokumentumgyökere a `…/zsolti-crm/public` legyen
  (1.5), és a `storage/` írható (775).
- **DB hiba:** ellenőrizd a `.env` `DB_*` adatait és hogy a felhasználóhoz
  hozzá van-e rendelve az adatbázis.
