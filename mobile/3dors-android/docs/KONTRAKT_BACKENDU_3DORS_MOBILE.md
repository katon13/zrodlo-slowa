# Kontrakt backendu 3DORS Mobile

## Status dokumentu

Dokument opisuje uzgodniony kontrakt klienta Android i zaimplementowanego
backendu PHP. Obie strony używają protokołu v1; zmiana pól kanonicznych wymaga
równoczesnej zmiany i testów po obu stronach.

Wszystkie identyfikatory są traktowane przez telefon jako **nieprzezroczyste
napisy** (opaque string) — backend decyduje o ich formacie (UUID, ULID itp.).

## Zasady ogólne

- Cała komunikacja WYŁĄCZNIE przez HTTPS w wariancie produkcyjnym (wariant
  debug dopuszcza HTTP tylko do adresów deweloperskich/LAN — patrz
  `network_security_config.xml`).
- Telefon nigdy nie ufa danym z kodu QR / deep linku poza samym identyfikatorem
  żądania — pełne, wiążące dane pobiera zawsze z backendu przez HTTPS.
- Każda odpowiedź błędu powinna mieć postać:
  ```json
  { "error": "<kod_błędu>", "message": "<opis czytelny dla logów>" }
  ```
  Telefon rozpoznaje następujące kody `error` i pokazuje dedykowany ekran:
  `device_suspended`, `device_revoked`, `device_lost`, `request_expired`,
  `request_already_processed`. Inne kody są traktowane jako błąd ogólny.
- Trzy identyfikatory są ZAWSZE rozdzielone i nie mogą być używane zamiennie:
  - `enrollment_request_id` — identyfikator zgłoszenia rejestracyjnego (ważny
    tylko do czasu ukończenia rejestracji, jednorazowy),
  - `device_public_id` — identyfikator fizycznego urządzenia (telefonu) po
    zarejestrowaniu,
  - `credential_public_id` — identyfikator konkretnego klucza kryptograficznego
    (credentialu) wygenerowanego w Android Keystore telefonu. Rotacja klucza
    (np. po reinstalacji aplikacji) tworzy NOWY `credential_public_id` przy tym
    samym `device_public_id`.
- Każdy payload żądania (logowanie/operacja) i QR rejestracji zawiera pole
  `protocol_version` (liczba całkowita, aktualnie `1`). Telefon odrzuca
  żądania z nieobsługiwaną wersją protokołu bez próby ich przetworzenia.
- Każdy enrollment i approval zawiera `application_variant=admin|author`.
  Telefon i backend odrzucają niezgodny wariant przed podpisaniem/wykonaniem.

## 1. Rejestracja urządzenia

### 1.1 Kod QR / App Link rejestracji

Backend generuje kod QR (i/lub App Link) zawierający wyłącznie JSON opisany
niżej — wyświetlany np. w panelu administratora, skanowany przez telefon.

```json
{
  "token": "<jednorazowy token wymiany, ważny krótko>",
  "enrollment_request_id": "<identyfikator zgłoszenia rejestracyjnego>",
  "service": "Źródło Słowa",
  "environment": "LOKALNE|TESTOWE|PRODUKCJA",
  "organization": "<nazwa organizacji>",
  "user_display_name": "<imię i nazwisko>",
  "account": "<login/e-mail konta>",
  "role": "<rola w systemie>",
  "purpose": "enrollment",
  "application_variant": "admin|author",
  "protocol_version": 1,
  "expires_at": 1999999999
}
```

- `environment` MUSI dokładnie odpowiadać buildowi aplikacji (`DORS3_ENVIRONMENT`).
  Telefon odrzuca rejestrację, jeśli się różni (ochrona przed pomyłkowym
  zarejestrowaniem urządzenia produkcyjnego w środowisku testowym i odwrotnie).
- `expires_at` to unix epoch (sekundy). Telefon odrzuca rejestrację, jeśli
  `now >= expires_at`, PRZED utworzeniem klucza w Keystore.

### 1.2 `POST /api/3dors/mobile/enrollment/complete`

Wysyłane po utworzeniu pary kluczy w Android Keystore (klucz prywatny nigdy nie
opuszcza telefonu).

**Request:**
```json
{
  "token": "<token z QR>",
  "enrollment_request_id": "<jak w QR>",
  "public_key": "<Base64(X.509 SubjectPublicKeyInfo)>",
  "algorithm": "SHA256withECDSA",
  "security_level": "STRONGBOX|TEE|SOFTWARE",
  "device_model": "<producent model>",
  "os_version": "Android <wersja>",
  "app_version": "<versionName>",
  "application_variant": "admin|author"
}
```

**Response 200:**
```json
{
  "device_public_id": "<nowy identyfikator urządzenia>",
  "credential_public_id": "<identyfikator wygenerowanego klucza>",
  "comparison_code": "<krótki kod, np. 6 cyfr>"
}
```

Backend powinien zapisać `security_level` w metadanych urządzenia (audyt —
"informacja o poziomie ochrony zapisana w metadanych urządzenia").

Błędy: `token` nieznany/wygasły/zużyty → HTTP 400/404 z `error` odpowiednio
opisującym przyczynę.

### 1.3 `POST /api/3dors/mobile/enrollment/confirm`

Telefon pokazuje `comparison_code` użytkownikowi i prosi o porównanie z kodem
widocznym w przeglądarce/panelu — to jest ostatnia bariera przed aktywacją.

**Request:**
```json
{ "device_public_id": "<z kroku 1.2>", "confirmed": true }
```

**Response 204:** puste body. Backend zmienia status urządzenia na `active`,
jeśli `confirmed=true`; jeśli `confirmed=false`, urządzenie i klucz powinny
zostać odrzucone/unieważnione po stronie backendu.

## 2. Wykrywanie oczekującego żądania (ekran startowy)

### 2.1 `GET /api/3dors/mobile/devices/{device_public_id}/pending-request`

Odpytywane cyklicznie przez ekran startowy (polling z backoffem przez maksymalnie
60 sekund), aby telefon
mógł działać jako prosta aplikacja uwierzytelniająca bez konieczności
skanowania QR przy każdej operacji.

- **200** + body jak `ApprovalRequestDetails` (patrz sekcja 3.1) — istnieje
  aktywne żądanie.
- **204 lub 404** — brak aktywnego żądania (stan normalny, nie błąd).
- **403** z `error=device_suspended|device_revoked|device_lost` — telefon
  natychmiast pokazuje odpowiedni ekran blokady.

## 3. Logowanie i operacje (approve/reject)

### 3.1 `GET /api/3dors/mobile/requests/{public_id}`

**Response 200:**
```json
{
  "request_id": "<wewnętrzny identyfikator żądania (do podpisu)>",
  "public_id": "<identyfikator publiczny, ten z QR/deep linku>",
  "purpose": "login|operation",
  "service": "Źródło Słowa",
  "environment": "LOKALNE|TESTOWE|PRODUKCJA",
  "account": "<login osoby zatwierdzającej — użyty jako user_id w podpisie>",
  "person": "<imię i nazwisko>",
  "role": "<rola>",
  "organization": "<nazwa organizacji — użyta jako organization_id w podpisie>",
  "initiating_device": "<opis przeglądarki/komputera, opcjonalnie>",
  "action_type": "<np. \"Wypłata środków\", \"Publikacja materiału\" — tylko dla purpose=operation>",
  "display_fields": { "<etykieta>": "<wartość do pokazania 1:1 na ekranie>" },
  "challenge": "<losowy jednorazowy ciąg do podpisu>",
  "action_fingerprint": "<hash/odcisk dokładnej treści operacji, wymagany dla purpose=operation>",
  "browser_session_hash": "<opcjonalnie, wiąże decyzję z konkretną sesją przeglądarki>",
  "issued_at": 1700000000,
  "expires_at": 1700000060,
  "nonce": "<jednorazowa wartość, chroni przed replay>",
  "server_origin": "https://zrodlo-slowa.przyklad.pl",
  "protocol_version": 1,
  "application_variant": "admin|author"
}
```

**Wymagania krytyczne:**
- `expires_at - issued_at` jest konfigurowalne w zakresie 30–90 s i domyślnie wynosi 60 s;
  klient odrzuca żądanie po `expires_at`.
- `account` i `organization` MUSZĄ być danymi zweryfikowanego, zalogowanego
  użytkownika inicjującego operację po stronie przeglądarki/panelu — telefon
  używa ich WPROST jako `user_id`/`organization_id` w kanonicznym payloadzie
  podpisu (patrz sekcja 3.3). Podstawienie błędnych/pustych wartości unieważni
  użyteczność podpisu jako dowodu.
- Dla `purpose=operation` pole `action_fingerprint` jest OBOWIĄZKOWE — zmiana
  jakiegokolwiek parametru operacji (kwota, odbiorca, uprawnienia) MUSI
  wygenerować nowy `action_fingerprint` (a najlepiej też nowe `public_id`).
- `display_fields` są pokazywane użytkownikowi 1:1, bez modyfikacji — backend
  odpowiada za to, żeby dokładnie odzwierciedlały to, co zostanie wykonane po
  zatwierdzeniu.

Błędy: `public_id` nieznany/wygasły/zużyty → HTTP 404/410 z odpowiednim `error`
(`request_expired`, `request_already_processed`).

### 3.2 `POST /api/3dors/mobile/requests/{public_id}/approve`

### 3.3 `POST /api/3dors/mobile/requests/{public_id}/reject`

Oba endpointy mają identyczny kontrakt request/response — różni je wyłącznie
`decision` zaszyta w podpisanym payloadzie (patrz niżej). Odrzucenie jest
świadomą, podpisaną decyzją — NIGDY nie jest wysyłane bez podpisu.

**Request:**
```json
{
  "device_public_id": "<identyfikator urządzenia>",
  "credential_public_id": "<identyfikator aktywnego klucza>",
  "signature": "<Base64, ECDSA-SHA256 nad signed_payload>",
  "signed_payload": "<dokładny ciąg podpisany, patrz niżej>",
  "algorithm": "SHA256withECDSA"
}
```

**Kanoniczny format `signed_payload`** (kolejność pól MUSI być zachowana,
separator `\n`, bez normalizacji/przycinania wartości):

```
payload_version=1
decision=approve|reject
purpose=login|operation
{request_id}
{challenge}
{user_id}            <- account z pkt 3.1
{organization_id}    <- organization z pkt 3.1
{role}
{server_origin}
{environment}
{browser_session_hash lub pusty string}
{action_fingerprint lub pusty string}
{issued_at}
{expires_at}
{nonce}
{credential_public_id}
```

Backend odtwarza dokładnie ten sam ciąg z własnych danych żądania i decyzji, a
następnie weryfikuje podpis kluczem publicznym zapisanym przy
`credential_public_id` (np. `openssl_verify` z `OPENSSL_ALGO_SHA256` i kluczem
EC secp256r1). Zmiana KTÓREGOKOLWIEK pola po stronie serwera unieważnia
podpis — to jest podstawowa ochrona integralności.

**Response 200:**
```json
{ "status": "approved|rejected", "consumed_at": 1700000005 }
```

**Zużycie żądania MUSI być atomowe** — backend odrzuca (409,
`error=request_already_processed`) każdą kolejną próbę decyzji dla tego
samego `public_id`, niezależnie od telefonu, żeby ta sama decyzja nie mogła
zostać wysłana dwukrotnie (replay) ani zaakceptowana przez dwa równoległe
żądania.

Inne błędy zwracane tymi endpointami:
- `403 device_suspended` / `403 device_revoked` / `403 device_lost` — telefon
  natychmiast blokuje możliwość podpisywania i informuje użytkownika.
- `410 request_expired` — TTL minęło między pobraniem a wysłaniem decyzji.
- `409 request_already_processed` — replay/wyścig.

## 4. Status i heartbeat urządzenia

### 4.1 `GET /api/3dors/mobile/devices/{device_public_id}/status`

Zwraca `device_public_id`, `application_variant`, `status` oraz opcjonalne
`last_used_at`. Status backendu jest nadrzędny wobec lokalnego cache klienta.

### 4.2 `POST /api/3dors/mobile/devices/{device_public_id}/heartbeat`

```json
{
  "credential_public_id": "<aktywny credential>",
  "application_variant": "admin|author"
}
```

Backend aktualizuje `last_used_at` wyłącznie, gdy device, credential, wariant i
status są zgodne. Odpowiedź 200: `{ "status": "ok" }`.

## 5. Statusy urządzenia (`security_mobile_devices`)

Telefon rozpoznaje następujące statusy (pole `deviceStatus` po stronie
klienta, ustawiane po odpowiedziach backendu): `pending`, `active`,
`suspended`, `lost`, `revoked`, `expired`. Backend jest jedynym źródłem
prawdy — telefon nigdy sam nie zmienia statusu poza `pending`→`active` po
etapie 1.3.

## 6. Bezpieczeństwo — wymagania wobec backendu

- Klucz publiczny zarejestrowany dla `credential_public_id` musi być
  weryfikowany wyłącznie serwerowo (podpis ECDSA-SHA256, krzywa secp256r1).
- Backend NIE POWINIEN nigdy przyjmować decyzji bez poprawnego podpisu —
  nawet dla `reject`.
- `nonce` i `challenge` muszą być kryptograficznie losowe i jednorazowe.
- Zalecane: rejestrowanie `security_level` (STRONGBOX/TEE/SOFTWARE) w audycie,
  żeby administrator mógł ocenić poziom zaufania do urządzenia.

## 7. Ustalenia operacyjne przed produkcją

1. Endpoint pending zwraca jedno najstarsze aktywne żądanie; limit równoległych
   requestów i deduplikacja są egzekwowane serwerowo.
2. Reinstalacja oznacza nowy enrollment i credential; stare urządzenie należy
   unieważnić w panelu.
3. `action_type` jest nazwą maszynową z allowlisty właściwego wariantu, np.
   `payout.approve`, `payout.reject`, `article.submit`, `article.publish`.
4. Przed produkcją trzeba ustawić prawdziwy origin/host, dwa klucze podpisu APK,
   Digital Asset Links oraz wykonać fizyczne E2E StrongBox/FIDO2.
