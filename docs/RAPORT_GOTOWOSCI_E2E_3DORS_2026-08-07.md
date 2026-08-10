# Raport gotowości E2E 3DORS i Źródło Słowa Mobile — 2026-08-07

## Decyzja

Stan bieżącej integracji developerskiej zmieniono z:

```text
BLOCKED_BEFORE_E2E
```

na:

```text
READY_FOR_E2E
```

Decyzja dotyczy lokalnego E2E aplikacji Źródło Słowa Mobile oraz fizycznego pilota
telefonów 3DORS Admin/Author z użyciem buildów debug i lokalnego backendu. Nie jest
to decyzja `PRODUCTION READY`.

Tryb produkcyjny pozostaje świadomie zablokowany do czasu dostarczenia prawdziwych
hostów HTTPS/App Links, dwóch niezależnych produkcyjnych keystore'ów, odbioru na
fizycznych telefonach oraz osobnego domknięcia pełnej ceremonii WebAuthn/FIDO2.

## Przyjęty model bezpieczeństwa

- istnieje jeden wariant administracyjny: `3DORS Admin`;
- nie istnieje `device_purpose`, MASTER, OPERATIONAL, allowlista MASTER ani osobny
  enrollment, rotacja lub operacje MASTER;
- czytelnik loguje się zwykłym hasłem i nie korzysta z 3DORS;
- artykuły i publikacje są kierowane przez backend do `3DORS Author`;
- wypłaty, role i bezpieczeństwo są kierowane przez backend do `3DORS Admin`;
- telefon nie pyta o typ operacji, tylko pokazuje kanoniczne dane oraz decyzje
  `ZATWIERDŹ` i `ODRZUĆ`;
- praca autora wymaga jego roli/uprawnienia redakcyjnego, ale nie `payout_enabled`;
- Financial Checkpoint pozostaje późniejszym modułem. Safety Fund został po tym
  raporcie wdrożony jako wersjonowany podział 40/40/20 w istniejącej księdze.

## Zakres wdrożenia domykającego

### Recovery administratora

Dodano ograniczony flow `/security/recovery`, który wymaga hasła i potwierdzonego,
jednorazowego kodu recovery. Flow otrzymuje jedynie piętnastominutową capability
`security_replacement_only`, powiązaną z bieżącą sesją anonimową. Nie tworzy sesji
administratora i Router nie pozwala jej opuścić tras recovery.

Recovery WWW:

- unieważnia credentiale i challenge WebAuthn administratora;
- kończy jego sesje i zwiększa `session_version`;
- unieważnia urządzenia, credentiale i tokeny 3DORS Mobile Admin;
- anuluje pending requests, niewykonane deferred operations i enrollmenty Admin;
- pozwala zarejestrować nowe urządzenie Admin oraz przygotować dokładnie 10 nowych
  kodów recovery;
- wymaga potwierdzonych 10 kodów oraz aktywnego urządzenia Admin przed zakończeniem;
- kończy capability bez nadania zwykłego dostępu administracyjnego;
- zapisuje audyt i kolejkuje powiadomienia bez ujawniania sekretów.

Pełne recovery przez lokalne CLI rozszerzono o cały 3DORS Mobile Admin. Zachowuje
rekordy historyczne, podpisy, decyzje i audyt, a unieważnia aktualne urządzenia,
credentiale, tokeny, pending, deferred operations, enrollmenty, WebAuthn, challenge,
step-upy i sesje. Nie unieważnia automatycznie wariantu Author tej samej osoby.

### Źródło Słowa Mobile

- dodano kanoniczny `GET /api/mobile/session` z odpowiedzią JSON i `no-store`;
- probe sesji jest read-only: nie zmienia payloadu, `last_activity`, TTL ani
  `session_expires_at`;
- kontrakt przekazuje z backendu także `wallet_enabled`, `payout_enabled` i
  `session_expires_at`;
- Android korzysta z jednego procesowego stanu sesji i pojedynczego równoległego
  odświeżenia dla danego originu;
- stan logowania nie jest już wnioskowany z treści stron HTML;
- WebView nie wraca do początkowego URL przy zwykłej rekompozycji;
- polling powiadomień respektuje lifecycle, ma timeouty i ochronę przed nakładaniem
  wywołań;
- zwykłe logowanie hasłem czytelnika pozostało bez zmian.

### Routing i teksty UI

Backend pozostaje jedynym źródłem wariantu operacji. `payout_details.change` oraz
`wallet.own_operation` przeniesiono do Admin zarówno w backendzie, bazie, jak i
skompilowanej polityce Androida. Operacje redakcyjne pozostały w Author.

Nowe teksty recovery korzystają ze wspólnego katalogu `resources/lang/dors3.json`.
Katalog ma identyczne drzewa PL/EN: 554/554 klucze. Zasoby Androida są kompletne:
3DORS 92/92 oraz Źródło Słowa Mobile 36/36 kluczy PL/EN. Nazwy maszynowe i kontrakt
podpisu nie zostały przetłumaczone ani zmienione.

## Weryfikacja

| Obszar | Wynik |
| --- | --- |
| PHPUnit pełnego backendu | **OK — 164 testy, 1127 asercji, 9 pominiętych** |
| Testy recovery WWW/CLI | **OK** — obejmują revoke Admin, zachowanie Author, WebAuthn i brak sesji Admin |
| PHPStan zakresu zmienionego w tym etapie | **OK — 0 błędów** |
| PHPStan całego odziedziczonego repo | **35 błędów spoza zakresu** — `Book100` w `Przelewy24Gateway.php` oraz dwa skrypty stage8/stage9 |
| 3DORS Admin JVM | **OK — 56/56** |
| 3DORS Author JVM | **OK — 56/56** |
| Źródło Słowa Mobile JVM | **OK — 65/65** |
| Android lint debug | **OK** dla Admin, Author i Źródło Słowa Mobile |
| Android assemble debug | **OK** dla Admin, Author i Źródło Słowa Mobile |
| Build release 3DORS bez danych produkcyjnych | **oczekiwane FAIL-CLOSED** — brak realnych hostów i niezależnych keystore'ów |
| Migracja `20260807_004_3dors_recovery_ready` | **applied** |
| Docker | **OK** — dwie instancje aplikacji, proxy, PostgreSQL, Valkey, MinIO i workery działają |
| `/health/ready` | **HTTP 200** |
| `/api/mobile/session` anonimowo | **HTTP 200**, `authenticated=false`, `Cache-Control: no-store` |
| `/security/recovery` anonimowo | **HTTP 200**, bez sesji administratora |

Testy PHPUnit korzystały z izolowanego schematu PostgreSQL i testowego Valkey.
Nie wykonywano prawdziwej wypłaty, zmiany salda ani zapisu do produkcyjnej księgi.
Migrator developerski utworzył brakujące techniczne konto
`platform@zrodlo-slowa.local`; konto ma `payout_enabled=0`, a operacja nie zmieniała
sald ani nie wykonywała płatności.

## Pliki tego domknięcia

### Architektura i baza

- `docs/3DORS_MOBILE_MASTER_RECOVERY_PLAN_ARCHITEKTONICZNY.md`;
- `database/postgresql/migrations/20260807_004_3dors_recovery_ready.sql`;
- `app/Security/Dors3/MobileOperationPolicy.php`;
- `app/Services/Dors3MobileService.php`;
- polityki wariantów Admin/Author w `mobile/3dors-android/app/src/*/java/.../variant/VariantPolicy.kt`.

### Recovery

- `app/Services/AdminMobileSecurityResetService.php`;
- `app/Services/AdminRecoveryService.php`;
- `app/Services/AdminWebRecoveryService.php`;
- `app/Controllers/AdminWebRecoveryController.php`;
- `views/auth/admin_recovery.php`;
- `resources/lang/dors3.json`;
- `app/Core/Router.php` i `public/index.php`;
- `tests/Integration/AdminWebRecoveryIntegrationTest.php`;
- `tests/Unit/AdminRecoveryArchitectureTest.php`.

### Źródło Słowa Mobile

- `app/Controllers/MobileSessionController.php`;
- `mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/session/WebSessionManager.kt`;
- `.../ui/auth/AuthGate.kt`;
- `.../notifications/NotificationsApiBridge.kt`;
- `.../ui/notifications/NotificationsScreen.kt`;
- `.../webview/SecureWebView.kt`;
- testy `WebSessionManagerTest.kt` i `WebViewRequestedUrlTest.kt`;
- zależność testowa JSON w `app/build.gradle.kts` i `gradle/libs.versions.toml`.

## Granica statusu READY_FOR_E2E

Można rozpocząć developerski pilot telefonu według
`docs/3DORS_PILOT_FIZYCZNY_CHECKLIST.md`. Flagi 3DORS i polityki w głównej bazie
pozostają niewymuszane (`enforced=0`, tryb `prepare`), więc ich włączenie powinno
nastąpić świadomie wyłącznie w środowisku E2E.

Pełna ceremonia WebAuthn/FIDO2 nie jest obecnie zaimplementowana i nie wolno
przedstawiać pilota telefonu jako testu fizycznego klucza. Zachowano bibliotekę,
schemat, challenge, credentiale, polityki fail-closed i pełne unieważnianie w obu
recovery. Fizyczny FIDO2 pozostaje osobnym odbiorem po skonfigurowaniu RP/origin i
dostarczeniu klucza.

## Pozostałe bramki produkcyjne

1. Prawdziwy publiczny HTTPS API, hosty Admin/Author, App Links i `assetlinks.json`.
2. Dwa niezależne produkcyjne keystore'y poza repozytorium.
3. Fizyczny odbiór biometrii, Android Keystore/StrongBox i masek launchera.
4. Pełna ceremonia WebAuthn/FIDO2 oraz jej fizyczny test, jeśli ma autoryzować operacje.
5. Decyzja o serwerowej Android Key Attestation.
6. Rotacja tokenów urządzenia bez ponownego enrollmentu albo jawne utrzymanie obecnego modelu TTL.
7. Usunięcie 35 odziedziczonych błędów pełnego PHPStan w oddzielnym zakresie.
8. Końcowy przegląd zewnętrzny i podpisanie konfiguracji wdrożeniowej.

## Paczka do niezależnego audytu

Wygenerowano `exports/zrodlo-slowa-konsultacja-ready-for-e2e-2026-08-07.zip`.
Paczka zawiera kod backendu, panelu, migracje, testy, dokumentację, wrappery Gradle
oraz źródła trzech aplikacji Android. Do samodzielnego odtworzenia środowiska służy
`CONSULTATION_README.md`.

Wyłączono `.git`, rzeczywiste pliki `.env`, `vendor`, `node_modules`, backupy i dane
baz, cache, logi, sesje, buildy Gradle, APK/AAB, `local.properties`, keystore'y,
klucze prywatne, dane uploadów i wcześniejsze eksporty. Obok archiwum znajduje się
plik `.sha256.txt` umożliwiający sprawdzenie integralności po przesłaniu.

Wniosek końcowy: integracja jest **READY_FOR_E2E** w zakresie developerskiego pilota
telefonów i aplikacji mobilnej. Jest **NOT PRODUCTION READY**.
