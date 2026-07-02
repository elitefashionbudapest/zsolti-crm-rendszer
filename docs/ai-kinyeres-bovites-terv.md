# AI-kinyerés bővítése — maximális adat több PDF-típusból

## Cél

Bármelyik biztosítási PDF-típusból (élet, gépjármű, lakás stb.) a lehető legtöbb
kitöltött adatot nyerjük ki, és minden adatot **külön-külön, névvel címezhető
mezőként** tároljunk — ne egy tömbben/blobban —, hogy a meglévő
`TemplateFiller` bármelyik mezőt közvetlenül fel tudja használni egy másik
dokumentum (DOCX vagy PDF-overlay) automatikus kitöltéséhez.

## Kiindulás (jelenlegi állapot)

- `AiExtractionController::upload` → fájl mentése → `jobs` sorba `ai_extract`.
- `WorkerCommand::handleExtract` → PDF első 10 oldala (`PdfTrimmer`) → `ClaudeClient::extract`
  fix **16 mezős** sémával → JSON `fields` mentése (`pending`).
- `review.twig` → 16 fix input.
- `AiExtractionController::apply` → partner (8 mező) + szerződés (8 mező).

### A rés

A valós PDF (pl. `Pannonia_ajanlat...`) ~30+ hasznos kitöltött mezőt tartalmaz,
amiből a séma csak 16-ot kér. Elveszik pl.: devizanem, telefon 2, IBAN, SWIFT,
bankszámlaszám, foglalkozás, munkahely, okmány típusa+száma, lakcímkártya-szám,
okmány érvényessége, állampolgárság, sporttevékenység, eltartottak száma,
közvetítői kód, 2. biztosított teljes adatai, kedvezményezettek.

Ráadásul a DB-ben **már van üres oszlop**, amit a kinyerés nem tölt:
`clients.mobile`, `clients.notes`; `contracts.category, module_code, anniversary,
agent_code, agent_name, payment_frequency, payment_method, risk_location`.

## Döntések (egyeztetve)

1. **Tárolás:** meglévő oszlopok + **címezhető kulcs-érték attribútum-tábla**
   (nem blob), hogy másik doksi kitölthető legyen belőle.
2. **Oldalak:** marad az első 10 oldal (költség-fék).
3. **Séma:** egységes séma + rugalmas gyűjtő (`additional_fields`) — egy AI-hívás,
   minden PDF-típus lefedve.

## Terv

### 1. Új tábla: `client_attributes` (Phinx-migráció)

Címezhető kulcs-érték tár, tenant-tudatosan. Egy kinyert extra mező = egy sor.

| oszlop | típus | megjegyzés |
|--------|-------|------------|
| id | pk | |
| office_id | int | tenant |
| client_id | int | FK partner |
| contract_id | int, null | FK szerződés (ha releváns) |
| extraction_id | int, null | honnan jött |
| group | string(50) | `szerzodo` / `biztositott_1` / `biztositott_2` / `kedvezmenyezett` / `bank` / `szerzodes` |
| attr_key | string(80) | stabil snake_case kulcs (`iban`, `foglalkozas`, `okmany_szam`) |
| label | string(191) | magyar felirat („IBAN", „Foglalkozás") |
| value | text | érték |
| created_at / updated_at | datetime | |

Index: `(office_id, client_id)`, valamint egyediség `(client_id, contract_id, group, attr_key)`
az upsert-hez (ismételt kinyerés ne duplikáljon).

### 2. Bővített egységes séma — `ClaudeClient::clientContractSchema()`

- **Core mezők maradnak** (16 db, a meglévő oszlopokra képezve).
- **Új core mezők**, amiknek már van DB-oszlopuk:
  `client_mobile`, `currency` (→ szerződés-jegyzet vagy attribútum),
  `category`, `module_code`, `payment_frequency`, `payment_method`,
  `agent_code`, `agent_name`, `risk_location`, `anniversary`.
- **Rugalmas gyűjtő** — minden más kitöltött adat ide kerül:

```php
'additional_fields' => [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'group'  => ['type' => 'string'],
            'attr_key' => ['type' => 'string'],
            'label'  => ['type' => 'string'],
            'value'  => ['type' => 'string'],
        ],
        'required' => ['group', 'attr_key', 'label', 'value'],
    ],
],
```

`required` a felső szinten továbbra is minden kulcs (a meglévő kód így állítja be).

**Utasítás (bővített):** „Nyerd ki MINDEN kitöltött mezőt. Az ismert mezőket a
megadott helyükre; minden további kitöltött adatot az `additional_fields` listába,
stabil snake_case `attr_key`-vel, magyar `label`-lel és logikus `group`-pal
(szerződő / biztosított_1 / biztosított_2 / kedvezményezett / bank / szerződés).
Üres/kitöltetlen mezőt hagyj ki. Dátum ÉÉÉÉ-HH-NN."

### 3. Worker

Nincs érdemi változás — az új sémát adja át. Marad a 10 oldal.

### 4. `AiExtractionController::apply`

- Partner core oszlopok kitöltése (+ `mobile`).
- Szerződés core oszlopok kitöltése (+ `category, module_code, payment_frequency,
  payment_method, agent_code, agent_name, risk_location, anniversary`).
- Az `additional_fields` minden eleme → `client_attributes` sor, az új
  partnerhez (és ha van, a szerződéshez) kötve.
- Új `ClientAttributeRepository` (upsert `group`+`attr_key` alapján).

### 5. Review UI — `review.twig`

- A meglévő strukturált core szekciók maradnak, kiegészülve az új core mezőkkel.
- Új „További kinyert adatok" szekció: az `additional_fields` sorai
  csoportosítva, **szerkeszthető** felirat/érték párként (a felhasználó
  javíthat/törölhet jóváhagyás előtt).
- `AiExtractionController::extractFields` kezelje a dinamikus listát is
  (hidden indexelt inputok).

### 6. A haszon: sablon-kitöltés integráció

- Új segéd (`ClientDataMap`): egy partnerhez/szerződéshez lapos `[kulcs => érték]`
  térképet épít = core oszlopok (`client_*`, `contract_*`) + minden
  `client_attributes` `attr_key`. Ezt kapja a `TemplateFiller`.
- Így egy új sablon hivatkozhat `${iban}`, `${foglalkozas}`, `${biztositott_1_nev}`
  stb. jelölőkre, és a rendszer automatikusan kitölti a kinyert adatokból.
- (2. fázis, opcionális) sablon-szerkesztőben az elérhető kulcsok listázása.

## Fázisok / sorrend

1. Migráció: `client_attributes` tábla.
2. `ClientAttributeRepository`.
3. Séma + utasítás bővítése (`ClaudeClient`).
4. `apply` bővítése (core + attribútumok mentése).
5. `review.twig` + `extractFields` dinamikus mezők.
6. `ClientDataMap` + `TemplateFiller` bekötése (a tényleges újrahasznosítás).

## Eldöntött kérdések

- **Partner-adatlap:** igen — a `client_attributes` sorokat a partner
  adatlapján is megjelenítjük és szerkeszthetővé tesszük (7. lépés).
- **Devizanem (HUF/EUR):** attribútumként tároljuk (`bank`/`szerzodes` group),
  nem kap külön DB-oszlopot.
- **Ismételt kinyerés ugyanarra a partnerre:** felülírás, de **előbb rákérdezünk**
  („Felülírja a meglévő adatokat?"), és csak megerősítés után írjuk felül.

### 7. Partner-adatlap — attribútumok megjelenítése/szerkesztése

- A partner adatlapján (`ClientsController` + partner-`show` sablon) új szakasz:
  a `client_attributes` sorai csoportosítva, felirat/érték párként,
  **szerkeszthető** (mentés/törlés/új sor hozzáadása).
- Mentés végpont a `ClientAttributeRepository`-n keresztül, tenant-ellenőrzéssel,
  CSRF-fel.

### Ismételt kinyerés — felülírás megerősítéssel

- A jóváhagyáskor (`apply`), ha a kinyert adat egy **már létező** partnerhez
  kötődik (pl. adóazonosító/e-mail egyezés vagy kézi kiválasztás), és van
  ütköző mező/attribútum, a review-ban figyelmeztetés + „Felülírás" jelölő.
- Megerősítés nélkül a meglévő értékek maradnak; megerősítéssel a
  `client_attributes` upsert felülírja a `(group, attr_key)` sorokat, és a
  core oszlopok is frissülnek.

## Fázissorrend (frissítve)

1. Migráció: `client_attributes`.
2. `ClientAttributeRepository` (upsert + tenant).
3. Séma + utasítás bővítése (`ClaudeClient`).
4. `apply` bővítése (core + attribútumok, felülírás-megerősítéssel).
5. `review.twig` + `extractFields` dinamikus mezők + felülírás-jelölő.
6. `ClientDataMap` + `TemplateFiller` bekötése.
7. Partner-adatlap: attribútumok megjelenítése/szerkesztése.
