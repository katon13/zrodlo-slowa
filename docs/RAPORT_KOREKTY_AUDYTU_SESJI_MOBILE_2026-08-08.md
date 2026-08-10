# Korekta po audycie sesji Mobile — 2026-08-08

## Decyzja

Trzy uwagi audytu były słuszne. Dwa błędy endpointu sesji naprawiono przed
fizycznym pilotem. Guard release, mimo że nie blokował debugowego E2E, również
został poprawiony bez zmian w pozostałej logice aplikacji.

Status pozostaje:

```text
READY_FOR_E2E
NOT PRODUCTION READY
```

## 1. Rzeczywiście read-only `/api/mobile/session`

Dla dokładnie `GET /api/mobile/session` warstwa aplikacji otwiera PHP session z
opcją `read_and_close`. `SharedSessionHandler` otrzymuje jednocześnie tryb
`readOnly`, w którym:

- `write()` niczego nie zapisuje;
- `updateTimestamp()` nie prowadzi do `write()`;
- `destroy()` i losowe `gc()` nie mutują magazynu podczas probe;
- fallback PostgreSQL nie odbudowuje wpisu Valkey i nie nadaje mu nowego TTL.

Endpoint nie zapisuje już `_mobile_session_generation`. Stabilny identyfikator
generacji jest wyliczany jako skrócony HMAC identyfikatora sesji i
`session_version`, więc nie wymaga zmiany payloadu. Odpowiedź usuwa ewentualny
`Set-Cookie` i nadal ma `Cache-Control: no-store`.

Nieprawidłowa, wygasła lub unieważniona sesja jest dla probe raportowana jako
anonimowa bez kasowania, regenerowania ani przedłużania sesji. Zwykłe żądania
aplikacji zachowują dotychczasowy aktywny lifecycle sesji.

`session_expires_at` wynika wyłącznie z istniejącego `last_activity` i
skonfigurowanego `SESSION_TTL_SECONDS`. Dla współdzielonych sesji źródłem jest
rekord PostgreSQL, a dla lokalnego drivera plikowego — niezmieniony czas modyfikacji
pliku sesji.

## 2. Pełny kontrakt backend–Android

Uwierzytelniona odpowiedź ma teraz kontrakt:

```json
{
  "ok": true,
  "authenticated": true,
  "session": {
    "generation": "32 znaki hex",
    "version": 4,
    "session_expires_at": 1800000000
  },
  "user": {
    "id": 123,
    "primary_role": "author",
    "roles": ["author"],
    "can_write": true,
    "wallet_enabled": true,
    "payout_enabled": false
  }
}
```

`session_expires_at` jest epoch time w sekundach. Android parsuje wszystkie trzy
nowe pola. Brak któregokolwiek wymaganego pola w odpowiedzi uwierzytelnionej
powoduje stan `UNAVAILABLE`, zamiast dopowiadania uprawnień po stronie telefonu.

## 3. Guard release Źródło Słowa Mobile

Usunięto zależność od `gradle.startParameter.taskNames`. Android Components wybiera
rzeczywisty wariant `release`, a jego lifecycle `preReleaseBuild` zależy od
typowanego zadania `validateReleaseDors3Configuration` z `buildSrc`.

Próba pośredniego zadania:

```text
compileReleaseKotlin
```

uruchamia walidator i kończy się oczekiwanym fail-closed przy placeholderach
hostów/fingerprintu. Zadanie jest zgodne z Gradle Configuration Cache.

## Testy

| Obszar | Wynik |
| --- | --- |
| Test endpointu: trzy osobne `/api/mobile/session` | **OK** — payload, `last_activity` i expiry bez zmian |
| Test read-only handlera PostgreSQL/Valkey | **OK** — brak write, timestamp update i cache warm |
| Testy celowane PHP | **4 testy, 61 asercji — OK** |
| Pełny PHPUnit | **164 testy, 1127 asercji, 9 pominiętych — OK** |
| PHPStan zmienionego zakresu | **0 błędów** |
| Źródło Słowa Mobile JVM | **65/65 — OK** |
| Android lint debug | **OK** |
| Android assemble debug | **OK** |
| `compileReleaseKotlin` bez danych produkcyjnych | **oczekiwane FAIL-CLOSED przez guard wariantu** |
| Configuration Cache | **wpis zapisany bez problemów** |
| Docker `/health/ready` | **HTTP 200** |
| Anonimowy `/api/mobile/session` | **HTTP 200, brak `Set-Cookie`, `no-store`** |

## Zmienione pliki

- `app/Core/App.php`;
- `app/Core/Router.php`;
- `app/Infrastructure/Session/SharedSessionHandler.php`;
- `app/Controllers/MobileSessionController.php`;
- `tests/Integration/MobileSessionEndpointIntegrationTest.php`;
- `tests/Integration/SharedSessionFallbackTest.php`;
- `mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/session/WebSessionManager.kt`;
- `mobile/zrodlo-slowa-android/app/src/test/java/pl/zrodloslowa/app/session/WebSessionManagerTest.kt`;
- `mobile/zrodlo-slowa-android/app/build.gradle.kts`;
- `mobile/zrodlo-slowa-android/buildSrc/build.gradle.kts`;
- `mobile/zrodlo-slowa-android/buildSrc/src/main/java/pl/zrodloslowa/build/ValidateReleaseConfigurationTask.java`.

## Paczka audytowa

Aktualna paczka źródeł:

```text
exports/zrodlo-slowa-konsultacja-ready-for-e2e-2026-08-08.zip
```

Suma SHA-256 znajduje się w pliku o tej samej nazwie z rozszerzeniem
`.sha256.txt`. Paczka nie zawiera sekretów, `.git`, backupów, zależności,
cache, buildów, APK/AAB, keystore'ów ani lokalnych danych wykonawczych.
