# AegisCRM — CRM biztosítási ügynököknek és pénzügyi tanácsadóknak

Slim 4 + Twig + Tailwind alapú, többügynökös (multi-tenant) CRM. A teljes
terv: [`terv.md`](terv.md). A dizájn-referenciák: [`design-reference/`](design-reference/).

## Követelmények

- PHP 8.2+ (a `pdo_sqlite` vagy `pdo_mysql` bővítménnyel)
- Composer
- Éles: MySQL 8 / MariaDB. Helyi fejlesztés: SQLite (alapértelmezett, szerver nélkül fut).

## Telepítés

```bash
composer install
cp .env.example .env          # majd töltsd ki (helyi fejlesztéshez SQLite készen áll)
# Titkosítási kulcs generálása az .env APP_KEY mezőjébe:
php -r "require 'vendor/autoload.php'; echo \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();"
php vendor/bin/phinx migrate -c phinx.php
php vendor/bin/phinx seed:run -c phinx.php -s DemoSeeder
```

## Futtatás

```bash
composer serve     # http://localhost:8080  (php -S localhost:8080 -t public)
```

## Demó belépések (seed)

Jelszó mindenkinél: **`Titok1234!`**

| Szerep | E-mail | Belépés |
|--------|--------|---------|
| Szuperadmin | `superadmin@aegis.test` | `/belepes/ugynok` → `/superadmin` |
| Ügynök (iroda) | `ugynok@aegis.test` | `/belepes/ugynok` → `/admin` |
| Ügyfél | `ugyfel@aegis.test` | `/belepes/ugyfel` → `/portal` |

## Tesztek és statikus elemzés

```bash
composer test      # PHPUnit
composer stan      # PHPStan
```

## Mappaszerkezet

```
public/            # webgyökér (front controller: index.php)
config/            # settings, container (PHP-DI), routes, middleware
src/               # alkalmazás (App\ névtér, PSR-4)
  Kernel/          # AppFactory (bootstrap)
  Http/            # kontrollerek, middleware
  Auth/ Tenant/    # hitelesítés, bérlő-kontextus
  Database/        # PDO-kapcsolat, tenant-tudatos repository
  Clients/         # partner modul
  Support/         # titkosítás, audit napló
templates/         # Twig sablonok (layouts, public, auth, admin, portal, superadmin)
database/          # Phinx migrációk és seedek
storage/           # logs, cache, uploads, sqlite (webgyökéren kívül, gitignore)
tests/             # PHPUnit
```

## CLI és cron

```bash
php bin/console list              # parancsok listája
php bin/console app:generate-key  # új titkosítási kulcs
php bin/console schedule:run      # emlékeztetők + esedékes e-mail folyamatok (cronból)
php bin/console queue:work        # háttér-worker (Supervisor alatt)
php bin/console imap:sync         # beérkező levelek szinkronizálása minden irodánál
```

Éles cron (példa):
```
* * * * *  php /path/bin/console schedule:run >> /path/storage/logs/schedule.log 2>&1
*/10 * * * *  php /path/bin/console imap:sync >> /path/storage/logs/imap.log 2>&1
```
A `queue:work` Supervisor alatt fusson folyamatosan.

## Modulok (elkészült, működő — minden oldal renderel, CRUD tesztelve)

**Admin (iroda):** vezérlőpult · partnerek (CRUD, keresés/szűrés) · szerződések
(CRUD, ügyfél-kötés, 4 terméktípus) · dokumentumok (feltöltés a webgyökéren kívülre,
láthatóság, biztonságos letöltés) · **AI-adatkinyerés** (Claude: dokumentum →
kinyert mezők → kézi jóváhagyás → ügyfél/szerződés) · **sablon-kitöltés** (PDF
overlay FPDI-vel, DOCX PhpWord-del) · **postaláda** (IMAP-szinkron) · feladatok ·
leadek (pipeline + ügyféllé alakítás) · **e-mail folyamatok** (sablonok, folyamatok,
napló, „futtatás most") · **biztosítói küldés** (a biztosító címlistájára, csatolt
dokumentumokkal) · biztosítók + címlisták · riportok · jutalékok · tanácsadói
anyagok · **Excel import** (`Munkafüzet1.xlsx` → ügyfelek + szerződések) ·
Beállítások (SMTP, IMAP, Claude API-kulcs, Google, branding — titkosítva).

**Ügyfélportál:** kezdő (valós évforduló/lejárat) · szerződéseim · dokumentumaim
(megosztott, letöltés) · adataim (önkitöltő wizard → jóváhagyásra) · üzenetek
(ügynöknek, feladatként) · tanácsadás.

**Szuperadmin:** vezérlőpult · irodák (CRUD) · dolgozók (CRUD, szerepkör,
aktiválás).

**Háttér:** lejárat-/évforduló-emlékeztetők, ütemezett e-mail folyamatok,
IMAP-szinkron, egyszerű job-worker.

## Még finomítandó (élő hitelesítő adatokkal tesztelendő)

- Az SMTP/IMAP/Claude/Google integrációk kódja kész és bekötött, de éles
  kulcsokkal/fiókokkal kell végigtesztelni (a Beállításoknál megadva).
- Google Naptár OAuth-folyamat (összekötő gomb) és AcroForm-mezős PDF-kitöltés
  (jelenleg lapos PDF overlay + DOCX) későbbi bővítés.
- Kétlépcsős hitelesítés (TOTP) UI.
- A PHPStan (level 5) néhány apró figyelmeztetést ad (kihasználatlan injektált
  property-k) — ártalmatlanok, később takaríthatók.
