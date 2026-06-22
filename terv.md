# CRM rendszer biztosítási ügynököknek és pénzügyi tanácsadóknak

## Áttekintés

A megrendelő egy CRM rendszert szeretne biztosítási ügynököknek és pénzügyi
tanácsadóknak, két felülettel: egy adminisztrációs felülettel, amelyen az iroda
dolgozói kezelik a partnereket, a dokumentumokat, az adataikat és az
elérhetőségeiket, valamint egy ügyfélportállal, amelyen a végfelhasználó belép, és
megtekinti a saját dokumentumait, a szerződései lejáratát, az évfordulóit, a hozzá
rendelt tanácsadói anyagokat, illetve felkeresheti a saját ügynökét. Az admin
felületen AI-funkciók is kellenek: egy feltöltött dokumentumból a rendszer kinyeri
az ügyfélre vonatkozó adatokat, majd ezekből az adatokból más dokumentumokat
automatikusan kitölt. Emellett e-mail folyamatokat is kezelni kell: beállítható,
hogy egy folyamat kinek menjen ki, és a rendszer a megadott címekre később
automatikusan elküldi az üzeneteket, illetve egyedi e-mailek is küldhetők az
ügyfeleknek.

A projekt zöldmezős. A rendszer egy többügynökös (multi-tenant) SaaS, ahol a
legfelső bérlői egység az **iroda**.

## Vezérelv: könnyűsúlyú, micro-keretes PHP-alkalmazás

A rendszer egyetlen, szerver-oldalon renderelt PHP-alkalmazás MySQL adatbázissal,
egy könnyűsúlyú micro-keretre (**Slim 4**) építve. Nincs nagy keretrendszer
(Laravel), nincs külön egyoldalas frontend (SPA), nincs külön API-réteg, nincs
Docker, nincs PostgreSQL és nincs Redis. A cél a könnyen érthető, könnyen
karbantartható és bárhol (egyszerű VPS-en vagy PHP-tárhelyen) futtatható
felépítés. A Slim adja a routingot és a middleware-réteget; minden mást
bevizsgált, jól karbantartott, keret-független könyvtárakkal oldunk meg, hogy az
érzékeny adatok kezelése biztonságos maradjon. A dinamikus felületeket a **Twig**
sablonok adják (automatikus kimenet-escapeléssel), a kisebb interaktivitást a
könnyűsúlyú **Alpine.js**, a megjelenést a **Tailwind CSS**.

> Megjegyzés a biztonságról: micro-keretnél a védelmi rétegeket nem egy nagy
> keretrendszer adja készen, hanem mi állítjuk össze bevizsgált könyvtárakból
> (CSRF, hitelesítés, adatbázis-réteg, jogosultság, titkosítás). Ezért minden
> ilyen pont a Biztonsági szakaszban kötelező, kötött könyvtárral és mintával
> rögzítve van, hogy a kézi megvalósítás ne jelentsen kockázatot.

## Tech stack

- **Keretrendszer:** **Slim 4** (PHP 8.3+), PSR-7/PSR-15 alapokon, **PHP-DI**
  konténerrel. Egyetlen monolit alkalmazás, szerver-oldali rendereléssel, három
  útvonalcsoporttal: admin (iroda dolgozói), ügyfélportál és szuperadmin —
  middleware-rel és jogosultsággal elkülönítve.
- **Adatbázis:** MySQL 8 (vagy MariaDB). A JSON-mezőket natívan kezeli, így az
  AI által kinyert strukturált adatok is jól tárolhatók. Elérés **PDO-val,
  kizárólag előkészített (prepared) lekérdezésekkel**, egy vékony, központi
  adat-réteg (repository) mögött. Migrációk: **Phinx** (`robmorgan/phinx`).
- **Sablonok / felület:** **Twig** (`slim/twig-view`) — automatikus
  kimenet-escapeléssel (XSS-védelem) — + **Tailwind CSS** + **Alpine.js**. Az
  asseteket egy egyszerű Vite vagy a Tailwind CLI fordítja (Laravel nélkül).
- **Hitelesítés:** munkamenet- (session-) alapú belépés a PHP beépített
  `password_hash`/`password_verify` (argon2id) függvényeivel; biztonságos
  süti-beállítások; regisztráció, jelszó-visszaállítás kézzel, de bevizsgált
  elemekre építve. CSRF: **Slim CSRF Guard** (`slim/csrf`). Kétlépcsős
  hitelesítés (TOTP): **`robthree/twofactorauth`**. Belépési sebességkorlátozás
  middleware-rel.
- **Jogosultságok és szerepkörök:** saját, egyszerű RBAC (szerepek és
  jogosultságok táblákban) + egy központi authorizációs middleware/szolgáltatás
  (pl. `owner`, `assistant`, `client`, `super_admin`).
- **Validálás:** **`respect/validation`** minden bemenetre, az action/handler
  rétegben.
- **Háttérfolyamatok:** adatbázis-alapú `jobs` tábla és egy CLI-worker
  (`bin/worker.php`), amelyet Supervisor (vagy cron-felügyelet) futtat a VPS-en.
  Az ütemezést cron hívja percenként (`bin/schedule.php`). A CLI-parancsokat a
  **`symfony/console`** adja. Ez hajtja az e-mail-küldést, az ütemezett
  folyamatokat és az AI-feldolgozást. Redis/Horizon nincs.
- **AI:** Anthropic Claude API a hivatalos PHP SDK-val (`anthropic-ai/sdk`),
  `claude-opus-4-8` modell. Adatkinyerés PDF/kép bemenetből (base64 `document`/
  `image` content block) strukturált, JSON-séma szerinti kimenettel, hogy az
  eredmény mezőkre bontva, feldolgozhatóan érkezzen.
- **Dokumentumkitöltés:** PDF-űrlapok kitöltése a `setasign/fpdi` (+ `setasign/
  fpdf` vagy `tecnickcom/tcpdf`) csomaggal; DOCX-sablonok a `phpoffice/phpword`
  `TemplateProcessor` osztályával.
- **E-mail:** irodánként titkosítva tárolt SMTP-beállítások; futásidőben
  dinamikusan beállított küldő (**`symfony/mailer`** vagy PHPMailer), így minden
  iroda a saját postafiókjából küld. Beérkező levelek olvasása IMAP-on
  (**`webklex/php-imap`** — a keret-független változat).
- **Titkosítás (nyugalmi állapotban):** az érzékeny mezők és minden titok (SMTP-
  jelszó, Claude API kulcs, OAuth-tokenek) titkosítva tárolva
  **`defuse/php-encryption`** (vagy libsodium `sodium_crypto_secretbox`)
  segítségével; a kulcs környezeti változóban.
- **Import/export:** **`phpoffice/phpspreadsheet`** (CSV/Excel).
- **Naplózás:** **`monolog/monolog`**.
- **Konfiguráció:** **`vlucas/phpdotenv`** (`.env`).
- **Naptár:** Google Naptár integráció a hivatalos `google/apiclient` csomaggal
  (OAuth, a tokenek titkosítva tárolva).

## Adatmodell (fő entitások)

A multi-tenant elkülönítés alapja: minden bérlőhöz tartozó rekordon `office_id`,
amelyet a központi adat-réteg (tenant-tudatos repository) **minden** lekérdezésnél
kötelezően hozzátesz, és az authorizációs réteg is ellenőriz.

- `offices` — iroda (a legfelső bérlő): név, adatok, branding, beállítások.
- `office_settings` — irodánkénti titkosított beállítások (SMTP, Claude API
  kulcs, IMAP-fiók, Google OAuth-tokenek, egyéb integrációk).
- `users` — felhasználók `office_id`-vel; az iroda dolgozói (szerepkörrel és
  egyedi jogosultságokkal), illetve az ügyfelek belépési fiókjai. A szerepköröket
  saját RBAC kezeli (`roles`, `permissions`, `role_user` táblák; pl. `owner`,
  `assistant`, `client`, `super_admin`).
- `clients` (partnerek) — `office_id`-hoz kötve, felelős ügynökhöz rendelve;
  személyes és kapcsolati adatok.
- `contracts` (szerződések/kötvények) — terméktípus, biztosító, kötvényszám,
  kezdet, lejárat, évforduló dátuma, díj, státusz; `client_id`-hoz kötve.
- `documents` — fájlok ügyfélhez vagy szerződéshez; tároló, típus, feltöltő,
  láthatóság (`agent_only` | `shared`).
- `extracted_data` — az AI által egy dokumentumból kinyert strukturált mezők
  (JSON), a forrásdokumentumhoz és ügyfélhez kötve; az ügynök jóváhagyja vagy
  szerkeszti, mielőtt a `clients`/`contracts` rekordba mentené.
- `document_templates` — kitöltési sablonok: AcroForm PDF (mezőnév → adat
  leképzés), lapos PDF (pozíció → adat: oldal/x/y/betűméret térkép) és DOCX
  (helyettesítő → adat). A sablon típusát és a leképzést is tárolja.
- `generated_documents` — a kitöltés eredményei.
- `email_templates` — e-mail sablonok (tárgy + törzs, változókkal).
- `email_workflows` — folyamat-definíció: címzettlista vagy szegmens, sablon,
  ütemezés (időzített / ismétlődő / eseményvezérelt, pl. „lejárat előtt X nap").
- `email_sends` — kiküldési napló (állapot, időbélyeg, hiba).
- `insurers` — biztosítók: név, alap e-mail címlista, állapot.
- `insurer_email_routes` — biztosítóhoz és opcionálisan terméktípushoz rendelt
  e-mail címlista (a biztosítók felé menő küldés címzettjei).
- `insurer_dispatches` — a generált dokumentumok kiküldése a kiválasztott
  biztosító címlistájára (queue-ban fut), állapot- és időbélyeg-naplóval.
- `client_intake_submissions` — az ügyfél által a portálon felvitt adatok,
  jóváhagyásra váró állapotban; az ügynök hagyja jóvá és emeli be a `clients`/
  `contracts` rekordba.
- `advisory_resources` — tanácsadói anyagok, ügyfélhez vagy szegmenshez rendelve,
  az ügyfélportálon megjelenítve.
- `mailboxes` és `incoming_emails` — bekötött IMAP-fiókok és a beérkező,
  AI-val feldolgozott levelek (kategória, ügyfél-hozzárendelés).
- `calendar_events` — Google Naptárral szinkronizált események.
- `tasks` — feladatok (cím, leírás, határidő, állapot, felelős, kapcsolódó ügyfél).
- `commissions` — jutalékok (szerződés, összeg, állapot, dátum, dolgozó).
- `leads` — érdeklődők (adatok, forrás, fázis, felelős); a leadből ügyfél képezhető.
- `jobs` — adatbázis-alapú háttérfeladatok sora (típus, payload, állapot,
  próbálkozások, futtatási idő).
- `reminders` / `notifications` — szerződéslejárat, évforduló stb. értesítések.
- `audit_logs` — GDPR-megfelelőséghez: ki, mikor, mit ért el vagy módosított.

## Terméktípusok

Mind a négy kategóriát kezelni kell, mindegyikhez saját, releváns mezőkészlettel:
élet- és egészségbiztosítás; vagyonbiztosítás; nyugdíj és megtakarítás; valamint
befektetés és pénzügyi tervezés.

## Valós adatforrások: meglévő Excel és PDF-sablonok

A megrendelő átadta a jelenlegi adatbázist (`Munkafüzet1.xlsx`) és a gyakran
használt PDF-dokumentumokat. Ezek határozzák meg a valós mezőkészletet és a
kitöltési feladatot.

### Meglévő Excel adatbázis (a `clients`/`contracts` mezők alapja)

Az Excel oszlopai (soronként egy szerződés, az ügyfél adataival):
Típus, Brokka#, Biztosító, Módozat (kód), Módozat neve, Ügyfél neve, Ügyfél
címe, Ügyfél telefon, Ügyfél mobil, Ügyfél email, Kötvényszám, Ajánlatszám,
Biztosítás kezdete (év/hó/nap), Biztosítás vége, Évforduló, Rendszám, Éves díj,
Státusz, Megszűnt, Megszűnés oka, Üzletkötő kód, Üzletkötő név, Rögzítés dátuma,
Jutalék rendezve, Díjfizetés gyakorisága, Díjfizetés módja, Jelleg kód, Jelleg
név, Kockázati hely, Díjrendezettség.

Ezek alapján a `contracts` és `clients` táblák a fenti mezőket tartalmazzák (az
ügyfél-mezők a `clients`-be, a szerződés-mezők a `contracts`-ba kerülnek, a
biztosító és a módozat hivatkozásként). Az induló adatmigrációt az import modul
végzi: a `Munkafüzet1.xlsx` betöltése `phpoffice/phpspreadsheet`-tel,
mezőleképzéssel és validálással, a tenant- (iroda-) hozzárendelés mellett.

### PDF-dokumentumok: sablonok és forrásdokumentumok

A PDF-ek szkennelése alapján két csoport:

- **Kitöltendő sablonok** (ezeket tölti automatikusan a rendszer a tárolt
  ügyfél-/szerződésadatokból):
  - `emet_..._interaktiv` — valódi AcroForm (kitölthető mezőkkel): közvetlen
    mező-kitöltés (FPDI / pdftk-szerű kitöltés a mezőnevek alapján).
  - `igenyfelmero_es_alkalmassagi`, `hozzajarlasi_nyilatkozat`,
    `penzmosasi_nyilatkozatok`, `1_szamu_alkuszi_megbizas`,
    `2_szamu_zaro_alkuszi_megbizas` — **lapos PDF-ek** (nincs űrlapmező): a
    kitöltés szöveg-ráhelyezéssel történik a `setasign/fpdi` segítségével, egy
    **sablononkénti mezőpozíció-térkép** (oldal, x, y, betűméret) alapján. Ezt a
    térképet sablononként egyszer beállítjuk, és a `document_templates`
    rekordban tároljuk.
- **Forrásdokumentumok (AI-bemenet, nem kitöltés):** `5316400_ajanlat`,
  `MetLife_ajanlat`, `Kockázati élet összehasonlító` — ezekből az AI nyeri ki az
  ügyfél- és szerződésadatokat (a meglévő AI-kinyerő folyamattal, kézi
  jóváhagyással).

A `document_templates` modell ezért kétféle sablont kezel: AcroForm
(mezőnév → adat) és lapos (pozíció → adat). A sablonfájlokat a megvalósításkor
egy `templates/` mappába (a webgyökéren kívül) helyezzük, kisbetűs néven.

## Megvalósítás (fázisok)

A projekt nagy, ezért fázisokra bontva épül, mindegyik önállóan tesztelhető és
demózható végeredménnyel. Egy fázis lezárul, mielőtt a következő indul.

1. **Alapváz:** Slim 4 projektváz (PHP-DI, PSR-7, hibakezelő middleware), MySQL +
   Phinx, Twig + Tailwind + Alpine, alaplayout (admin és portál Twig-elrendezés a
   design szerint), `.env` (phpdotenv), egészség-ellenőrző oldal, PHPUnit +
   PHPStan + PHP-CS-Fixer, CI alap.
2. **Multi-tenant + szerepkörök + belépés:** `offices`, `users` `office_id`-vel,
   saját RBAC, tenant-tudatos repository-réteg (kötelező `office_id`-szűrés) +
   authorizációs middleware. Három belépés (szuperadmin, iroda dolgozója, ügyfél)
   elkülönített útvonalcsoporttal és middleware-rel; CSRF Guard, biztonságos
   session, belépési rate limiting.
3. **Partnerkezelés + dokumentumok:** `clients` CRUD kereshető/szűrhető listával
   és adatlappal, dokumentumfeltöltés és -tárolás láthatósági szabályokkal.
4. **Szerződések + emlékeztetők + ügyfélnézet:** `contracts` a négy
   terméktípussal, lejárat- és évforduló-számítás, napi ütemezett parancs az
   emlékeztetőkhöz; az ügyfélportál megjeleníti a saját szerződéseket,
   lejáratokat és évfordulókat.
5. **AI adatkinyerés:** dokumentum feltöltése → Claude (PDF/kép base64 +
   JSON-séma) → `extracted_data`. A feldolgozás háttér-jobban fut. Az ügynök egy
   felületen ellenőrzi és kézzel jóváhagyja a kinyert mezőket, mielőtt a
   `clients`/`contracts` rekordba kerülnének.
6. **Sablonok + AI dokumentumkitöltés:** `document_templates` (PDF-űrlap mezők +
   DOCX helyettesítők), kitöltés a tárolt ügyféladatokból (FPDI a PDF-hez,
   PHPWord a DOCX-hez), `generated_documents` előállítása.
7. **E-mail sablonok + SMTP + folyamatok + ütemező:** irodánkénti titkosított
   SMTP, dinamikus küldő, `email_templates`, `email_workflows` (címzettek +
   ütemezés + eseménytrigger), a háttér-worker + ütemező hajtja a kiküldést,
   `email_sends` napló. Egyedi e-mail is küldhető egy ügyfélnek. Külön, az ügyfél
   elől rejtett, biztosítók felé menő küldés: a szerződés és a dokumentumok
   elkészülte után az ügynök kiválasztja a biztosítót, és a rendszer a
   `insurer_email_routes` szerinti címekre automatikusan kiküldi a dokumentumokat
   (`insurer_dispatches`).
8. **Ügyfélportál teljes képességei + tanácsadói anyagok + értesítések:** az
   ügyfél dokumentumot tölthet fel, adatmódosítást kérhet, üzenhet az ügynökének,
   és egy önkitöltő (wizard) űrlapon felviheti a saját adatait
   (`client_intake_submissions`, jóváhagyásra várva). `advisory_resources`
   megjelenítése, `notifications`.
9. **Extra modulok:** feladatok (`tasks`) a vezérlőpulton megjelenő teendőlistával;
   riportok és kimutatások (iroda- és dolgozó-szintű bontásban); jutalékkövetés
   (`commissions`); lead / értékesítési pipeline (`leads`); teljes körű
   CSV/Excel import-export (`phpoffice/phpspreadsheet`), a tenant- és jogosultsági
   szabályok betartásával.
10. **Beérkező e-mail (IMAP) + Google Naptár:** egyetlen e-mail-fiók bekötése
    (SMTP küldéshez és IMAP fogadáshoz), postaláda-nézet, az AI besorolja a
    beérkező leveleket, ügyfélhez köti, és a csatolmányokból adatot nyer ki (kézi
    jóváhagyással). Google Naptár OAuth-összekötés, események szinkronizálása.
11. **Szuperadmin + központi beállítások:** szuperadmin felület az irodák, a
    dolgozók és az előfizetések kezelésére, a jogosultságok finomhangolásával.
    Központi Beállítások felület: SMTP, Claude API kulcs, IMAP, Google Naptár,
    branding, biztosítók és címlistáik — minden titok titkosítva.
12. **Csiszolás + biztonság + telepítés:** mobilbarát finomítás, GDPR-keményítés
    (érzékeny mezők titkosítása nyugalmi állapotban, `audit_logs`, HTTPS,
    biztonsági mentés), a háttér-worker és a cron beállítása a VPS-en, telepítési
    folyamat.

## Felületek (a Claude Design mockupok alapján, Twig-ben megvalósítva)

A dizájnokat Claude Design készítette, és a claude.ai design projektből
importáltuk őket. A három felület konkrét, futtatható referencia-implementációja
a `design-reference/` mappában él, önálló HTML fájlként (Tailwind CSS v4 +
Alpine.js + Lucide ikonok), pixelhűen a mockupokhoz:

- `design-reference/landing.html` — bemutató / landing + kettős belépés.
- `design-reference/vezerlopult.html` — admin (iroda) vezérlőpult.
- `design-reference/ugyfelportal.html` — ügyfélportál (asztal + mobil).

Ezeket alakítjuk át a megvalósításkor Twig-sablonokká (a statikus demóadatokat
valós adatkötésre cserélve, a fejlécbe CSRF-tokennel, a titkos felületekre
`noindex` fejléccel). A designban a márkanév „AegisCRM" (a végleges név
egyeztetendő). A menüfeliratok magyarul. A reszponzivitás mindhárom felületen
adott: az ügyfélportál asztalon bal oldalsávval, mobilon alsó ikonsávval; az
admin összecsukható oldalsávval.

### Admin (iroda) felület

Asztali nézetre optimalizált, de mobilon is használható, összecsukható oldalsávval.
Bal oldalon rögzített mélykék navigációs oldalsáv, fölül vékony fejléc keresővel,
értesítés-ikonnal és profilkapcsolóval, jobbra világosszürke háttéren fehér
kártyák. Fő képernyők: vezérlőpult (kártyás összesítők + teendők + legutóbbi
tevékenység), partnerlista (kereshető/szűrhető táblázat), partner adatlap (füles),
dokumentumok (drag-and-drop feltöltés), AI-kinyerés jóváhagyó nézet (kétoszlopos),
szerződés-szerkesztő, dokumentumkitöltés (sablonok), e-mail folyamatok, csapat,
beállítások. Minden lista kereshető, szűrhető, rendezhető, lapozható; a fejlécben
globális kereső.

### Ügyfélportál

Teljesen reszponzív: asztalon és mobilon egyaránt kiváló. Asztalon bal oldali
navigáció (vagy felső menüsor) és szélesebb, többoszlopos elrendezés; mobilon
alul rögzített ikonsáv és egyoszlopos nézet. Sok fehér tér, nagy, jól tapintható
elemek, megnyugtató hangulat. Fő képernyők: kezdőképernyő (kiemelt kártyák +
„Ügynököm" kártya), szerződéseim, dokumentumaim (letöltés + feltöltés), adataim
(önkitöltő wizard), hasznos információk, üzenetek, profil.

### Bemutató / landing + belépés

Nyilvános, görgethető bemutató oldal (fejléc két belépő gombbal, hero
mockuppal, bizalmi sáv, funkciókártyák, „hogyan működik" lépések, kettős belépés,
lábléc), amelyről külön az admin és külön az ügyfél felületre lehet belépni.

## Színrendszer

A pénzügyi/biztosítási szektorban a kék a legerősebb bizalomszín, a zöld a
növekedést és a stabilitást közvetíti, a mélyzöldeskék (teal) a kettőt egyesíti.
Az alábbi palettát használjuk mindenhol.

| Szerep | Szín | HEX |
|--------|------|-----|
| Elsődleges (bizalom) | Mélykék / navy | `#0F2A4A` |
| Másodlagos (akció) | Smaragd / teal | `#0E9F6E` |
| Akcentus (prémium) | Lágy arany / amber | `#F4B740` |
| Háttér | Tiszta fehér | `#FFFFFF` |
| Felület | Világosszürke | `#F5F7FA` |
| Szöveg | Sötét palaszürke | `#1E293B` |
| Halvány szöveg | Szürke | `#64748B` |
| Siker | Zöld | `#16A34A` |
| Figyelmeztetés | Amber | `#D97706` |
| Hiba | Piros | `#DC2626` |

Tipográfia: Inter (a Claude Design mockupok is ezt használják). Lekerekített
sarkok (8–12 px), lágy árnyékok, nagyvonalú térközök. Ikonkészlet: egységesen
Lucide (a mockupokkal összhangban). A mockupok Tailwind v4 `@theme` blokkban
rögzítik ezt a palettát (`--color-ink`, `--color-teal`, `--color-gold` stb.),
amelyet a megvalósításkor a Tailwind-konfigba emelünk át.

## Biztonsági / GDPR megfontolások

A rendszer különleges, érzékeny személyes és pénzügyi adatokat kezel, ezért a kód
minden rétege biztonságosra készül. Micro-keretnél ezek a védelmek nem
automatikusak, ezért kötött könyvtárakkal és mintákkal rögzítjük őket.

- **Hitelesítés:** munkamenet-alapú belépés a PHP `password_hash`/`password_verify`
  (argon2id) függvényeivel; erős jelszó-szabályzat és rate limiting a belépési
  végpontokon; opcionális kétlépcsős hitelesítés (TOTP, `robthree/twofactorauth`)
  az ügynököknek és kötelezően a szuperadminnak; a három belépés szigorúan
  elkülönített jogosultsággal.
- **CSRF:** minden állapotváltó űrlapnál Slim CSRF Guard (`slim/csrf`) token.
- **Admin elrejtése:** az admin és a szuperadmin felület csak hitelesítés után
  érhető el, nem indexelhető (`robots.txt` Disallow, `noindex` fejléc/meta); a
  szuperadminhoz opcionális IP-engedélyezőlista (middleware).
- **Tenant-izoláció:** minden irodához kötött adatot a központi, tenant-tudatos
  repository-réteg `office_id`-re szűr (kötelezően, minden lekérdezésnél); az
  authorizációs middleware IDOR ellen minden erőforrásnál ellenőrzi a
  tulajdonjogot; a legkisebb jogosultság elve.
- **Adatbázis:** kizárólag PDO előkészített (prepared) lekérdezés — soha nincs
  string-összefűzéses SQL —, így SQL-injection ellen védett.
- **Kimenet:** a Twig automatikusan escapel (XSS-védelem); a `|raw` használata
  tilos felhasználói adatra.
- **Adatvédelem:** kötelező HTTPS/TLS HSTS-szel; sütik `secure`, `httponly`,
  `samesite` beállítással; az érzékeny mezők és minden titok titkosítva tárolva
  (`defuse/php-encryption` vagy libsodium), a kulcs kizárólag környezeti
  változóban, soha a kódban vagy a gitben.
- **Bemenet/fájlok:** minden bemenet szerveroldali validálása
  (`respect/validation`); szigorú fájlfeltöltés-validálás (kiterjesztés,
  MIME-típus, méret); a feltöltött fájlok a webgyökéren kívül, csak hitelesített,
  jogosultság-ellenőrzött letöltéssel elérhetők.
- **HTTP-fejlécek:** biztonsági fejlécek middleware-ből (CSP, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`).
- **Naplózás és üzemeltetés:** `audit_logs` minden érzékeny műveletre (titkok
  nélkül, Monologgal); rendszeres `composer audit` / `npm audit`; titkosított
  biztonsági mentés. A Claude API 30 napos adatmegőrzést igényel — éles érzékeny
  adat feldolgozása előtt ellenőrizni az Anthropic-fiók beállítását, és
  minimalizálni a kiküldött személyes adatot.

## Kódolási konvenciók

- **Kisbetűs, kötőjeles fájlnevek** minden saját, nem a keretrendszer által
  megkövetelt fájlnál (Twig-sablonok, asset-ek, feltöltési könyvtárak, CLI-
  szkriptek), mert a szerver Linux és kis-nagybetű érzékeny. Kivétel: a PSR-4
  miatt a PHP-osztályok PascalCase nevet kapnak.
- Egységes kódstílus: PSR-12 (PHP-CS-Fixer) a PHP-hoz, Prettier a Twig/CSS/JS-hez.
- Moduláris, rétegezett kód: vékony Slim-action/handler osztályok, külön
  Service-réteg az üzleti logikának, tenant-tudatos Repository-réteg az
  adatbázishoz, és központi authorizációs réteg — hogy egy-egy rész könnyen,
  lokálisan javítható legyen.

## Ellenőrzés (end-to-end teszt)

- **Backend:** PHPUnit a kulcs-funkciókra (tenant-izoláció, auth, CRUD, AI-job,
  e-mail-folyamat ütemezés). Statikus elemzés: PHPStan.
- **Tenant-izoláció:** két iroda fiókjával ellenőrizni, hogy egyik sem látja a
  másik adatait (repository-szűrés + authorizáció teszt).
- **AI adatkinyerés:** valós minta-PDF feltöltése → a kinyert JSON a várt mezőket
  tartalmazza, és jóváhagyás után a rekordba kerül.
- **Dokumentumkitöltés:** egy PDF-űrlap és egy DOCX-sablon kitöltése a tárolt
  ügyféladatokból → a `generated_documents` a helyes értékeket tartalmazza.
- **E-mail folyamat:** folyamat beállítása és a háttér-worker + ütemező futtatása
  → a megfelelő SMTP-n kimennek az üzenetek, és az `email_sends` napló kitöltődik
  (teszt SMTP / Mailpit).
- **Ügyfélportál:** ügyfélként belépve csak a saját adatok láthatók.
- **Felület:** a Twig-oldalak mobil nézetben (reszponzív) is helyesen működnek.

## Telepítés (egyszerű)

Egyszerű VPS vagy PHP+MySQL tárhely: az alkalmazás `public/` könyvtára a
webgyökér, a `storage` és a feltöltések a gyökéren kívül; cron a percenkénti
ütemezéshez (`bin/schedule.php`); egy `bin/worker.php` háttér-worker (Supervisor
alatt vagy cron-felügyelettel) a háttérfeladatokhoz; HTTPS kötelezően. A
migrációkat a Phinx futtatja a telepítéskor.
