# Frontend és dashboard átdolgozás — UI/UX terv

*Készült a `/ui-ux-pro-max` skill alapján. AegisCRM — biztosítási és pénzügyi CRM (PHP + Twig + Tailwind v4 böngésző-CDN + Alpine.js + Lucide).*

## 1. Kiindulási állapot

A rendszer három felületből áll:

- **Frontend (publikus):** landoló oldal (`public/landing.twig`) és a belépési oldalak (`auth/login.twig`).
- **Ügyfélportál:** `layouts/portal.twig` és a `portal/*` sablonok.
- **Dashboard (admin):** `layouts/admin.twig`, `admin/dashboard.twig`, `admin/reports/index.twig` és a többi admin sablon.

A jelenlegi vizuális alap **már erős**: egységes „ink" (sötétkék #0F2A4A) + „teal" (zöld #0E9F6E) + „gold" (#F4B740) paletta, Inter betűtípus, lekerekített kártyák, konzekvens Lucide ikonok, mobil alsó navigáció és drawer. Nem újratervezés kell, hanem **célzott finomítás és a hiányzó minőségi rétegek pótlása**.

## 2. A skill által megerősített irány

- **Paletta:** a meglévő navy + zöld páros pontosan a pénzügyi „bizalom + profit" színlogika. **Megtartjuk**, nem cseréljük indigó/violára.
- **Tipográfia:** a skill a pénzügyi szektorra az **IBM Plex Sans**-t ajánlja (banki, komoly, megbízható). Az Inter tiszta és jó, de az IBM Plex Sans erősebb szakmai karaktert ad. → Döntési kérdés lentebb.
- **Stílus:** admin oldalon a **Data-Dense Dashboard** és a **Swiss/Minimalism** elvek (12 oszlopos rács, matematikus térközök, egy kiemelő szín, alacsony kontrasztú rácsvonalak a diagramokon).

## 3. Konkrét hiányosságok és javítások

### 3.1 Akadálymentesség (KRITIKUS — 1. prioritás)
- Az ikon-gombokon **nincs `aria-label`** (fejléc harang, menü, keresés, értesítés, avatar). → Minden ikon-only gombra `aria-label`.
- A dashboard **kereső mezőnek nincs `<label>`-je** (csak placeholder). → Rejtett, de kiolvasható label.
- Fókuszgyűrűk: az input mezőkön jók, de az **ikon-gombokon nincs látható fókuszállapot**. → `focus-visible` gyűrű egységesen.
- Fejléc-hierarchia és `aria-current="page"` az aktív navigációs elemre.

### 3.2 Mozgás és `prefers-reduced-motion` (KRITIKUS)
- A `hover:-translate-y-1`, a `scroll-behavior: smooth` és a drawer-animációk **nem tisztelik a `prefers-reduced-motion`** beállítást. → Globális `@media (prefers-reduced-motion: reduce)` blokk a `base.twig`-ben, amely kikapcsolja/csökkenti az animációkat.

### 3.3 Emoji ikonként
- `portal/home.twig`: a „Üdv, {name}! 👋" és a `dashboard`-köszöntés emojit használ. A skill szabálya szerint az emoji nem strukturális elem. → Dekoratív helyen maradhat, de következetesség kedvéért Lucide `hand`/`sparkles` ikonra cseréljük, vagy elhagyjuk.

### 3.4 Dashboard tartalmi felértékelése
- A vezérlőpult két nagy szekciója (**Teendők**, **Legutóbbi tevékenység**) jelenleg üres placeholder. → Ha van adat a controllerben, valós tartalommal töltjük; ha nincs, a skill `empty-states` mintája szerint **cselekvésre hívó** üres állapot (nem csak „Nincs adat", hanem gomb: „Feladat létrehozása").
- **Számformázás:** a KPI-értékekhez `number-tabular` (tabuláris számjegyek) az elcsúszás ellen, és egységes ezres tagolás.
- A riport-oldali „sávdiagramok" jók és könnyűek; megtartjuk őket, de hozzáadjuk a `legend`/érték-címkéket és az `aria-label` szöveges összefoglalót (screen reader).

### 3.5 Táblázatok
- A `data-table` szabály szerint: rendezhető oszlopfejlécek `aria-sort` állapottal (legalább a fő listákon: partnerek, szerződések), `number-tabular` a szám- és összegoszlopokon.

### 3.6 Landoló oldal finomítás
- Hero: a két CTA jó; a felső `trust` sávot és a `funkciók`/`hogyan működik` szekciókat megtartjuk, de egységesítjük a szekció-térközöket a Swiss 8px-rácshoz.
- A hero jobb oldali „mockup" statikus számokkal — élőbb, de továbbra is könnyű vizuál.
- `line-length-control`: a bekezdések 60–75 karakterre korlátozva (jelenleg `max-w-lg` jó, ellenőrizzük).

### 3.7 Teljesítmény (opcionális, nagyobb döntés)
- Jelenleg a **Tailwind böngésző-CDN futásidőben fordít** (`@tailwindcss/browser@4`) — ez villódzást (FOUC) és nagyobb JS-terhet okoz. A skill perf-szabályai (critical-css, font-loading) miatt hosszabb távon **build lépéssel legenerált statikus CSS** ajánlott. → Külön, opcionális fázisként kezeljük, mert nagyobb beavatkozás.

### 3.8 Sötét mód (opcionális)
- A választott stílusok mind támogatják a sötét módot, de jelenleg nincs. Ha kell, a `@theme` token-készletet duplázzuk `prefers-color-scheme`/kapcsoló alapon. → Döntési kérdés.

## 4. Javasolt megvalósítási sorrend (fázisok)

1. **Fázis A — Alapréteg (`base.twig`):** `prefers-reduced-motion` blokk, `focus-visible` gyűrűk, tabuláris számok segédosztály, (opció) betűtípus-váltás.
2. **Fázis B — Akadálymentesség:** `aria-label`-ek, rejtett label-ek, `aria-current`, ikon-gomb fókusz — layoutok (`admin`, `portal`) és landing.
3. **Fázis C — Dashboard tartalom:** vezérlőpult üres állapotok cselekvésre hívással, KPI-formázás, riport-szekció szöveges összefoglalók.
4. **Fázis D — Táblázatok:** rendezhető fejlécek `aria-sort`, tabuláris összegek a fő listákon.
5. **Fázis E — Landing/portal finomítás:** emoji-csere, szekció-térközök, hero.
6. **Fázis F (opcionális):** Tailwind build lépés + sötét mód.

## 5. Rögzített döntések

1. **Terjedelem:** erősebb vizuális ráncfelvarrás (finomítás + látványosabb hero, kártyák, térközök, mikro-animációk).
2. **Betűtípus:** váltás **IBM Plex Sans**-ra (pénzügyi, banki karakter). Fejlécekhez 600–700, törzsszöveghez 400, címkékhez 500 súly.
3. **Sötét mód:** **kell most.** Megvalósítás Tailwind v4 `@theme` token-készlet duplázásával, `prefers-color-scheme` + kézi kapcsoló (fejléc `theme-toggle`, `localStorage` mentés). Minden felület (landing, portál, admin) külön tesztelve.
4. **Tailwind:** **marad a böngésző-CDN** (`@tailwindcss/browser@4`). Build lépés most nem.

## 6. A döntések hatása a fázisokra

- **Dark mode (új, átfogó réteg):** a `base.twig` `@theme` blokkját sötét változattal bővítjük (desaturált, világosabb tónusok — nem egyszerű invertálás), a `body`/felületek `dark:` variánsokkal. Kézi kapcsoló Alpine-nal, `localStorage`-ban tárolt preferenciával, villanásmentes inline init-scripttel a `<head>`-ben.
- **IBM Plex Sans:** a Google Fonts `<link>` és a `--font-sans` token cseréje a `base.twig`-ben; a `body`/`html` fallback stack frissítése.
- **Erősebb ráncfelvarrás:** hero-mélység (finomabb gradiens, réteges kártya), kártya-hover és `focus-visible` mikro-interakciók (150–300ms, `prefers-reduced-motion`-nal védve), egységes 8px térköz-ritmus, KPI-kártyák hangsúlyosabb tipográfiával és tabuláris számokkal.

---

## 7. Megvalósítva (2026-07-01)

Mind az öt fázis elkészült.

- **A — `base.twig`:** IBM Plex Sans (400–700), osztály-alapú sötét mód (`@custom-variant dark`), villanásmentes `<head>` init, globális téma-kapcsoló (`[data-theme-toggle]`, `localStorage`), `prefers-reduced-motion` blokk, egységes `focus-visible` gyűrű, `tabular-nums` és `num` segédosztályok.
- **B — Akadálymentesség:** `aria-label` az összes ikon-only gombra (menü, harang, téma-kapcsoló, fiók), rejtett `<label>` a keresőn, `aria-current="page"` az aktív navigációra mindhárom layoutban.
- **C — Dashboard:** cselekvésre hívó üres állapotok (Teendők → „Új feladat", Tevékenység → „Partnerek megnyitása"), tabuláris KPI-k, gazdagabb landing-hero.
- **D — Táblázatok:** 16 ikon-akcióra `aria-label` 11 sablonban, `scope="col"` és rejtett „Műveletek" fejléc, tabuláris összegoszlop a jutalékoknál.
- **E — Landing/portál/login:** téma-kapcsoló a nav-ban és a mobil menüben, hero gradiens-szövegkiemelés + második fény-folt + élő „mockup" mini-diagram, gradiens feature-ikonok, emoji (👋) → Lucide `hand` ikon, `font-extrabold` → `font-bold` (IBM Plex Sans max. 700) 17 fájlban.

**Ellenőrzés:** mind az 56 Twig-sablon hibátlanul fordul; az app bootol, a landing/login HTTP 200, az admin auth-redirect 302.

---

*Állapot: KÉSZ.*
