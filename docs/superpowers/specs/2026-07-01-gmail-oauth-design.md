# Gmail OAuth-integráció — tervdokumentum (spec)

*Dátum: 2026-07-01 · Projekt: AegisCRM (Slim 4 + Twig + Guzzle, PHP 8.3 tárhely)*

## 1. Probléma és cél

A megosztott tárhely **tiltja a kimenő 993-as portot**, ezért a közvetlen Gmail-IMAP-szinkron „connection failed" hibával elbukik. Cél: **OAuth-alapú Gmail-integráció mint lehetőség** — ha egy iroda Gmail-címet akar bekötni a postaládához, a levélbehúzás a **Gmail API-n (HTTPS/443)** menjen, ami megkerüli a porttiltást. A kézi IMAP-út megmarad a nem-Gmail fiókokhoz.

## 2. Terjedelem

**Benne van:**
- Per-iroda Gmail-fiók bekötése OAuth2 authorization-code folyammal.
- Beérkező levelek (INBOX) behúzása a Gmail API-val a meglévő `incoming_emails` táblába — feladó, tárgy, dátum **és törzs**.
- A meglévő IMAP-szinkron megtartása; futásidőben diszpécser dönt IMAP vs Gmail API között.
- Beállítások UI: csatlakoztatás / leválasztás, kapcsolat állapota.

**Nincs benne (YAGNI):**
- Kimenő levélküldés a Gmailen át (marad az SMTP).
- Több Gmail-fiók egy irodához (egyelőre egy).
- Címkék/mappák a postaládán túl; csak INBOX.
- Push-értesítés (Gmail watch/Pub-Sub); a húzás továbbra is a cron/„Szinkronizálás" gomb.

## 3. Éles működési mód és a scope valósága

- A törzs olvasásához a **`https://www.googleapis.com/auth/gmail.readonly`** kell — ez a Google **restricted** kategóriája.
- **Sima @gmail.com, éles:** teljes verifikáció (márka + OAuth + **CASA biztonsági audit**) kellene a korlátlan használathoz. Amíg ez nincs meg:
  - Az OAuth consent screen **„In production"** státuszban, **nem verifikált** appként is működik: a felhasználó lát egy „a Google nem ellenőrizte ezt az appot" figyelmeztetést, és a **Speciális → Tovább az apphoz** linkkel átléphet. A refresh-token ilyenkor **nem jár le** (szemben a „Testing" státusszal, ahol 7 nap után igen). Korlát: **max 100 felhasználó**, amíg nincs verifikáció.
  - Kevés belső irodára ez elég. A CASA-audit csak a skálázáshoz és a figyelmeztetés eltüntetéséhez kell — ez a specen kívüli, üzleti/operatív döntés.
- **Google Workspace (saját domén) fiókoknál** a Workspace-admin belső appként engedélyezheti a scope-ot figyelmeztetés és felhasználói limit nélkül — de ez ugyanezt a kódot használja, csak más consent-beállítással.

Ezt a korlátot a beállítások UI-ban rövid súgószöveg is jelzi.

## 4. Architektúra

```
[Beállítások UI] --Csatlakoztatás--> GET /admin/beallitasok/gmail/connect
      --302 Google consent--> [Google] --code--> GET .../gmail/callback
      --> GmailOAuthService.handleCallback(): code→token, refresh_token mentése (titkosítva)

[cron imap:sync  /  Postaláda „Szinkronizálás"]
      --> MailboxSyncDispatcher.sync(officeId)
            ├─ van Gmail refresh_token? → GmailApiSyncService  (HTTPS 443)
            ├─ van imap_host?          → ImapSyncService       (meglévő, 993)
            └─ egyik sem               → „nincs beállítva"
      --> incoming_emails tábla
```

### 4.1 Komponensek (határok, felelősség)

**`GmailOAuthService`** (új) — *mit csinál:* OAuth2 authorization-code folyam. *Interfész:*
- `authUrl(int $officeId): string` — Google consent URL. Paraméterek: `scope=gmail.readonly`, `access_type=offline`, `prompt=consent` (hogy mindig kapjunk refresh_tokent), `include_granted_scopes=true`, `state=<aláírt>`.
- `handleCallback(string $code, string $state): array{email:string}` — `state` ellenőrzés; code→token csere a `https://oauth2.googleapis.com/token` végponton; a **refresh_token** és a fiók e-mail (a `gmail.users.getProfile` `emailAddress` mezőjéből) mentése az `office_settings`-be.
- `accessToken(int $officeId): string` — a tárolt refresh_tokenből friss access token (kérésen belül memóriában cache-elve). Refresh-bukásnál `GmailAuthException`.
- `disconnect(int $officeId): void` — token/e-mail törlése; best-effort token-visszavonás a Google `revoke` végponton.
- *Függ:* Guzzle `Client`, `SettingsService`, `Encryption`, app-config (client id/secret/redirect).

**`GmailApiSyncService`** (új) — *mit csinál:* INBOX-behúzás a Gmail API-val. *Interfész:* `syncOffice(int $officeId, int $limit = 25): array{count:int, error:?string}` (azonos alak, mint az IMAP-é). *Belső lépések:*
- `GET users/me/messages?labelIds=INBOX&q=newer_than:30d&maxResults=$limit` → id-lista.
- Minden id-re `GET users/me/messages/{id}?format=full` → fejlécek + payload.
- Törzs kinyerés: a `payload` parts-fa bejárása, elsőbbség `text/plain`-nek, fallback `text/html`-ből sima szöveggé; a `body.data` **base64url**-dekódolása.
- Dedup: az `RFC822 Message-Id` fejléc a `message_uid` (stabil IMAP↔API között); ha hiányzik, a Gmail üzenet-id.
- Tárolás a meglévő `store()` logikával (`incoming_emails`), `mb_substr(body, 0, 5000)`.
- *Függ:* Guzzle `Client`, `GmailOAuthService`, `PDO`.

**`MailboxSyncDispatcher`** (új, vékony) — *mit csinál:* eldönti és meghívja a helyes szinkront. *Interfész:* `sync(int $officeId, int $limit = 25): array{count:int, error:?string, via:string}`. *Logika:* Gmail-token → `GmailApiSyncService`; különben `imap_host` → `ImapSyncService`; különben `{0, 'Nincs postafiók beállítva', 'none'}`.

**Meglévők érintése:**
- `InboxController::sync` → a `MailboxSyncDispatcher`-t hívja az `ImapSyncService` helyett.
- `ImapSyncCommand` (`imap:sync`) → ugyanígy, minden irodára a diszpécseren át.
- `SettingsService::$secretKeys` → bővül `gmail_refresh_token`-nel (titkosított tárolás).
- `SettingsController` → új action-ök: `gmailConnect`, `gmailCallback`, `gmailDisconnect`.
- Beállítások Twig → „Gmail (OAuth)" szekció.

### 4.2 Adatmodell

Nincs séma-migráció. A kódbázis **már fenntartotta** a per-iroda Google-kulcsokat az `office_settings`-ben (`SettingsService::SECRET_KEYS` = `google_access_token`, `google_refresh_token`; `SettingsController` PLAIN = `google_client_id`, SECRET = `google_client_secret`), de implementáció eddig nem épült rájuk. Ezeket **újrahasznosítjuk**:
- `google_client_id`, `google_client_secret` — **per-iroda** OAuth-kliens (a client_secret titkosítva). Minden iroda a saját Google Cloud projektjét használja → külön 100-user-limit, nincs megosztott plafon.
- `google_refresh_token` — **titkosítva** (már a `SECRET_KEYS`-en).
- `gmail_email` — a bekötött fiók címe (megjelenítéshez; új plain kulcs).
- `gmail_connected_at` — időbélyeg (állapothoz; új plain kulcs).

A behúzott levelek a meglévő `incoming_emails` táblába kerülnek, változatlan sémával.

### 4.3 Konfiguráció (per-iroda, Beállítások UI)

A client id/secret **nem** `.env`-ben, hanem irodánként a Beállításokban (a meglévő `google_client_id`/`google_client_secret` mezőkön), mert a kódbázis már így tervezte, és így minden iroda saját OAuth-appja külön user-limittel bír.

Egyetlen app-szintű származtatott érték a **redirect URI**: a `config/settings.php` `app.url`-jából + a fix `/admin/beallitasok/gmail/callback` útból áll össze (pl. `https://visualbyadam.hu/zsolti_crm/admin/beallitasok/gmail/callback`). Ha nincs `app.url`, a request host/base-path adja. Ezt az irodának be kell írnia a saját OAuth-kliense „Authorized redirect URIs" mezőjébe.

Ha az irodának nincs `google_client_id`/`secret` beállítva → a „Csatlakoztatás" gomb letiltva + súgó („előbb add meg a Google client ID-t és secretet").

## 5. Folyamatok

**Csatlakoztatás:** UI gomb → `/gmail/connect` → 302 a Google consent-re → felhasználó jóváhagy → Google visszairányít `/gmail/callback?code=…&state=…` → `handleCallback` menti a refresh_tokent + e-mailt → flash „Gmail csatlakoztatva: <email>" → vissza a beállításokhoz.

**Szinkron:** cron/gomb → diszpécser → `GmailApiSyncService.syncOffice` → access token frissítés → messages.list + get → parse → `incoming_emails` → `{count}` flash.

**Hibaágak:**
- `state` érvénytelen/lejárt → 400, „Érvénytelen vagy lejárt kérés, próbáld újra."
- code-csere bukik → flash a Google hibaszövegével.
- refresh `invalid_grant` (visszavont/lejárt token) → token törlése, `gmail_connected_at` nullázás, flash „A Gmail-kapcsolat lejárt, csatlakoztasd újra." A diszpécser `error`-t ad vissza, a cron logolja.
- Gmail API 429/5xx → a sync `error`-t ad vissza (nincs retry az első körben; YAGNI), a következő futás pótol.

## 6. Biztonság

- **`state`** = base64url(`officeId . '.' . nonce`) + HMAC-SHA256 az `APP_KEY`-jel; a callback ellenőrzi az aláírást és a lejáratot (pl. 10 perc). Ez köti az officeId-t és véd a CSRF ellen.
- **refresh_token** és **client_secret** titkosítva nyugalmi állapotban (meglévő `Encryption`, `SECRET_KEYS`); sosem a repóban, sosem a kliensen.
- A callback-útvonal az `AuthGuard` mögött (belépett admin), a `state` az officeId-hez köt.
- A tárolt access token nem perzisztál (mindig frissül); csak a refresh_token él.

## 7. Tesztelés

- **Törzs-parser unit-teszt** (`GmailApiSyncService` payload-bejárás): fixture JSON-ök — sima `text/plain`; `multipart/alternative` (plain+html); base64url-törzs ékezetes UTF-8-cal; hiányzó törzs. Ellenőrzés: helyes szöveg, dedup-kulcs a Message-Id-ből.
- **OAuth/HTTP mock**: Guzzle `MockHandler` — sikeres token-csere; `invalid_grant`; access-token frissítés. `GmailAuthException` a bukásnál.
- **Diszpécser**: Gmail-token jelenléte → Gmail ág; csak imap_host → IMAP ág; egyik sem → „nincs beállítva".
- A meglévő `TenantIsolationTest` mintájára: az egyik iroda tokene nem szivárog a másikhoz (per-office olvasás).

## 8. Előfeltételek (operatív, kódon kívül) — irodánként

1. Google Cloud projekt → **Gmail API** engedélyezése.
2. **OAuth consent screen**: user type External; scope `gmail.readonly`; app-név, támogatási e-mail, logó; státusz **In production** (vagy Testing + test users a fejlesztéshez).
3. **OAuth Client (Web application)**: „Authorized redirect URIs" = `https://visualbyadam.hu/zsolti_crm/admin/beallitasok/gmail/callback`.
4. A kapott **client ID + secret** beírása a CRM Beállítások → Gmail szekciójába (per iroda), nem `.env`-be.
5. (Opcionális, később) verifikáció/CASA a 100-user-limit és a figyelmeztetés eltüntetéséhez.

## 9. Megvalósítási sorrend (a plan majd finomítja)

1. Config + konténer-bekötés (`.env` kulcsok, `.env.example` frissítés).
2. `GmailOAuthService` + `state` aláírás + unit-tesztek (HTTP mock).
3. Útvonalak + `SettingsController` action-ök + Beállítások UI szekció.
4. `GmailApiSyncService` + törzs-parser + unit-tesztek.
5. `MailboxSyncDispatcher` + `InboxController`/`imap:sync` átkötés.
6. Kézi végpróba a szerveren (In production consent, valós fiók), majd cron.

---

*Állapot: SPEC, jóváhagyásra. A kód a jóváhagyott implementációs terv után készül.*
