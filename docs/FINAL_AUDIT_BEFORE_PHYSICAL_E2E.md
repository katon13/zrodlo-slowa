# Finalny audyt przed fizycznym E2E

Data: 2026-08-08  
Repozytorium: `X:\zrodlo-slowa`  
Gałąź: `main`  
Punkt bazowy Git: `5018644` + bieżący, niezatwierdzony snapshot roboczy

## Werdykt

```text
READY_FOR_PHYSICAL_E2E
```

Ten werdykt oznacza gotowość kodu i środowiska developerskiego do rozpoczęcia testu z fizycznymi telefonami i kluczami. Nie oznacza zaliczenia samego fizycznego E2E ani gotowości produkcyjnej.

```text
NOT PRODUCTION READY
```

## Co sprawdzono

- backend, PostgreSQL, Valkey, sesje współdzielone, migracje i health check;
- kontrakt `GET /api/mobile/session`, jego brak efektów ubocznych oraz parsowanie po stronie Androida;
- role, uprawnienia i rozdzielenie zwykłego czytelnika, autora oraz administratora;
- routing operacji: artykuł/publikacja do 3DORS Author, wypłata/role/bezpieczeństwo do 3DORS Admin;
- enrollment, challenge, podpis, fingerprint operacji, credentiale, tokeny, replay, wygaśnięcie i unieważnienia;
- ograniczone recovery WWW oraz pełne recovery administratora przez lokalne CLI;
- anulowanie pending requests/enrollmentów, unieważnianie urządzeń, credentiali, tokenów i sesji;
- WebView, App Links/deep links, powiadomienia, lifecycle, polling, błędy sieciowe i wygaśnięcie sesji;
- bezpieczeństwo Androida, Keystore, biometria/PIN, kamera i skaner QR;
- release guards oparte o rzeczywisty graf zadań wariantu;
- PL/EN, branding, dark UI, insets, mały i duży ekran oraz powiększoną czcionkę;
- zgodność kontraktów backend ↔ Źródło Słowa Mobile ↔ 3DORS.
- osobny panel 3DORS Wartownik, alerty, lifecycle, maskowanie, SQL, powiadomienia i jawny heartbeat obu serwerów aplikacyjnych.

## Potwierdzone ustalenia architektoniczne

- Czytelnik korzysta ze zwykłego logowania hasłem i nie kwalifikuje się do 3DORS.
- 3DORS Author dotyczy autora/dziennikarza. `payout_enabled` nie jest warunkiem pracy autora.
- Telefon nie wybiera typu operacji. Backend ustala `application_variant`, rolę, operację i uprawnienia; klient wyświetla otrzymane dane oraz podpisuje świadomą decyzję.
- Nazwy maszynowe, np. `article.submit` i `payout.approve`, pozostają niezmienne. Widoczne etykiety pochodzą ze scentralizowanych zasobów PL/EN.
- Źródło Słowa Mobile nie liczy lokalnie uprawnień finansowych: używa `wallet_enabled`, `payout_enabled`, `can_write` i czasu wygaśnięcia przekazanych przez backend.
- Recovery 3DORS Admin i recovery 3DORS Author pozostają rozdzielone. Recovery WWW nie daje zwykłego dostępu administracyjnego.
- Financial Checkpoint nie należy do tej bramki E2E. Safety Fund został następnie włączony do bramki jako wewnętrzny, wersjonowany podział wpływu 40/40/20 w istniejącej księdze.

## Co znaleziono i naprawiono w tym audycie

### 1. BŁĄD / BLOKER E2E — możliwość fail-open przez niespójną konfigurację `required`

Kontrolery mogły wrócić do ścieżki bez telefonu, gdy `DORS3_MOBILE_MODE=required`, ale globalna lub operacyjna flaga 3DORS była wyłączona. Dodano wspólną bramkę `MobileApprovalConfiguration`, walidację startową środowiska i walidację ustawień. Tryb `required` aktywnego wariantu kończy się teraz błędem konfiguracji zamiast cichym fallbackiem.

Obejmuje to logowanie, wypłaty, krytyczne operacje administracyjne, wysłanie artykułu i publikację. Tryb `test` nadal pozwala świadomie testować pojedyncze flagi.

### 2. BŁĄD — wygasła sesja mogła pozostać widoczna na natywnym ekranie

`AuthGate` Źródła Słowa planuje teraz ponowny, read-only probe dokładnie po `session_expires_at` z backendu. Nadal sprawdza sesję przy `ON_RESUME`. Klient nie przedłuża TTL i nie wylicza własnego czasu sesji.

### 3. BŁĄD — uwierzytelnienie podpisu na Androidzie 10

Na API 29 nie można łączyć `CryptoObject` z device credential w sposób używany na API 30+. Rozdzielono ścieżki:

- API 30+: `BIOMETRIC_STRONG | DEVICE_CREDENTIAL` z `CryptoObject`;
- API 29: systemowe potwierdzenie biometrią/PIN-em, a następnie inicjalizacja podpisu w krótkim oknie autoryzacji Keystore.

Podpis nadal zawsze powstaje kluczem prywatnym Android Keystore po lokalnym uwierzytelnieniu. Podstawa: dokumentacja AndroidX Biometric i Android Keystore: <https://developer.android.com/reference/androidx/biometric/BiometricPrompt>, <https://developer.android.com/reference/android/security/keystore/KeyGenParameterSpec.Builder.html>.

### 4. BŁĄD — deep link i polling w działającej aplikacji

- `singleTask` obsługuje teraz nowe żądanie w `onNewIntent` i reaktywnie otwiera właściwy request bez restartu aplikacji;
- identyfikator requestu jest kodowany w trasie nawigacji;
- polling działa z narastającym backoffem przez cały czas, gdy ekran jest aktywny, zamiast kończyć się po 60 sekundach;
- polling jest anulowany po opuszczeniu ekranu i natychmiast po znalezieniu requestu.

Test runtime potwierdził dostarczenie deep linku do już działającej, najwyższej instancji aktywności, bez uruchomienia drugiej aktywności.

### 5. BŁĄD — uprawnienie aparatu i zasoby skanera

Skaner QR żąda teraz runtime permission `CAMERA`, pokazuje stan odmowy w PL/EN oraz zwalnia CameraX, ML Kit i executor po opuszczeniu ekranu. Wynik skanu jest jednokrotny.

### 6. NIESPÓJNOŚĆ UX — insets, przewijanie i systemowy splash

- główny kontener 3DORS respektuje safe drawing insets;
- ekrany startowe, enrollment i onboarding przewijają się na małym ekranie/powiększonej czcionce;
- splash Źródła Słowa ma spójne kremowe tło i prawidłowy kontrast ikon systemowych;
- atrybut jasnego paska nawigacji przeniesiono do `values-v27`, zachowując zgodność z minimalnym API 26.

### 7. Test regresyjny nasłuchu

Dodano test potwierdzający, że polling pozostaje aktywny po 65 sekundach, o ile ekran nadal jest na pierwszym planie.

### 8. BŁĄD — package visibility 3DORS Author na Androidzie 11+

Źródło Słowa Mobile używało `resolveActivity()` oraz `getPackageInfo()` dla `pl.zrodloslowa.dors3.author`, ale manifest nie deklarował widoczności tego pakietu. Dodano minimalne:

```xml
<queries>
    <package android:name="pl.zrodloslowa.dors3.author" />
</queries>
```

Nie dodano widoczności 3DORS Admin ani szerokich zapytań intentowych. Test na Pixel 9 API 37 potwierdził cały przepływ: pakiet Author jest widoczny przez `getPackageInfo()`, jawny intent rozwiązuje się do właściwego pakietu, produkcyjna funkcja przekazuje dokładne URI, 3DORS Author odbiera właściwy request ID, a przycisk Back przywraca Źródło Słowa w stanie `RESUMED` z oczekującym jednokrotnym odświeżeniem WebView.

### 9. DOMKNIĘCIE MODELU FINANSOWANIA — Safety Fund 40/40/20

Istniejący punkt księgowania płatnego dostępu do artykułu został rozszerzony z dwóch na trzy udziały, bez tworzenia nowej księgi lub systemu płatniczego:

```text
40% Autor + 40% Serwis i redakcja + 20% Safety Fund = 100%
```

- polityka jest wersjonowana i zapisywana jako liczby całkowite `4000 + 4000 + 2000 = 10000`;
- zakup, trzy udziały i snapshot polityki są księgowane w jednej transakcji z zachowaniem idempotencji;
- wcześniejsze rozliczenia zachowują historyczny snapshot, a zmiana polityki dotyczy wyłącznie nowych wpływów;
- aktywacja nowej polityki oraz wydanie środków wymagają właściwej operacji `3DORS Admin`, ponownego sprawdzenia roli i zgodnego fingerprintu;
- wydatek kontroluje dostępne saldo Safety Fund, zapisuje kategorię, uzasadnienie, referencję i pełny audyt;
- `ArticleEconomyService` nie przyjmuje już indywidualnego procentu autora z formularza — źródłem reguły jest aktywna polityka backendu;
- publiczna zasada i plansza zostały wdrożone na `/pl/jak-zarabiac` oraz w EN, DE, FR, IT i ES;
- panel administracyjny pokazuje saldo, historię alokacji, wydatki i wersje polityki.

Migracja `20260808_005_safety_fund` jest zastosowana. Aktywna polityka w PostgreSQL została odczytana jako `4000/4000/2000`.

### 10. 3DORS WARTOWNIK — docelowa obserwacja i alarmowanie

Wdrożono osobny panel `/admin/security/sentinel`, bez tworzenia drugiego silnika bezpieczeństwa. Dotychczasowy panel `BEZPIECZEŃSTWO I 3DORS` pozostał miejscem konfiguracji urządzeń, enrollmentu, recovery, FIDO2 i mechanizmów potwierdzania. Wartownik przejął wyłącznie obserwację:

- stan ochrony i obu instancji `app-1` / `app-2`;
- gotowość 3DORS Admin, 3DORS Author, Mobile i fundamentu FIDO2;
- ostatnie próby logowania i aktywne sesje;
- osobne alerty `high` / `critical` ze stanami `open → acknowledged → resolved`;
- historię na pełnej szerokości z filtrami SQL, wyszukiwaniem, datami i paginacją;
- e-maile `high` / `critical` przekazywane idempotentnie do istniejącej kolejki pocztowej;
- PL/EN, zwarty responsywny układ oraz maskowanie e-maili, IP, sesji i dowolnych opisów/payloadów.

Źródłowe `security_events` nie są zmieniane podczas obsługi alertu. Migracja zapisuje watermark aktywacji, dlatego scheduler nie tworzy alertów ani wiadomości ze starych zdarzeń. Awaria projekcji Wartownika jest izolowana i nie może zmienić wyniku chronionej operacji. Szczegółowy model: `docs/3DORS_WARTOWNIK.md`.

Obie instancje zapisują niezależny heartbeat z `/health/ready`. Bezpośredni odczyt potwierdził `app-1=true` i `app-2=true`; 12 połączeń przez proxy rozłożyło się `6/6`. Oba kontenery pracują na identycznym obrazie `f1c1913b7def…` i mają stan `healthy`.

## Sesja mobilna

`GET /api/mobile/session` został ponownie zweryfikowany jako probe bez efektów ubocznych:

- nie zmienia payloadu sesji;
- nie zapisuje dodatkowych pól w sesji;
- nie aktualizuje `last_activity`;
- nie przedłuża TTL;
- nie przesuwa `session_expires_at`;
- zwraca `can_write`, `wallet_enabled`, `payout_enabled` i `session_expires_at` dla sesji zalogowanej;
- zwraca `Cache-Control: no-store, max-age=0`.

Test integracyjny wykonuje wielokrotne probe i porównuje payload oraz `last_activity` przed i po odczytach.

## Wyniki testów

| Zakres | Wynik |
|---|---:|
| PHPUnit — pełny zestaw | **186 testów, 2423 asercje, 0 błędów, 9 świadomych skipów środowiskowych** |
| Wartownik — testy kontraktowe/integracyjne | **6 testów, 47 asercji**: alerty, lifecycle, watermark, idempotencja, maskowanie, SQL i obie instancje |
| PHPStan — zmieniony backend/sesja/3DORS | **0 błędów** |
| PHPStan — całe odziedziczone repo | **35 istniejących problemów poza wykonywanym zakresem** |
| 3DORS Admin JVM | **57/57** |
| 3DORS Author JVM | **57/57** |
| Źródło Słowa Mobile JVM | **67/67** |
| 3DORS Admin instrumentation | **3/3** na Pixel 9 API 37 |
| 3DORS Author instrumentation | **4/4** na Pixel 9 API 37, w tym odbiór dokładnego URI |
| Źródło Słowa instrumentation | **1/1** na Pixel 9 API 37, pełny handoff Author i powrót |
| Android lint — 3DORS Admin/Author | **PASS** |
| Android lint — Źródło Słowa | **PASS** |
| Debug build — wszystkie 3 aplikacje | **PASS** |
| Migracje PostgreSQL | **16/16 zastosowanych**, w tym `20260808_005_safety_fund` i `20260808_006_3dors_sentinel` |
| Docker Compose | `app-1` i `app-2` na identycznym obrazie `f1c1913b7def…`, oba **healthy**; proxy i zależności zdrowe; workery/scheduler aktywne |
| Dwa serwery | bezpośrednie `health/ready`: **200/200**; heartbeat: **app-1/app-2 ready+expected**; proxy: **6/6** odpowiedzi |
| Scheduler Wartownika | ostatni przebieg **completed**, synchronizacja i kolejka powiadomień obecne w wyniku |
| Safety Fund — testy integracyjne | podział, rounding, idempotencja, wersjonowanie, podpisane approve/reject i wydatek **PASS** |
| `/jak-zarabiac` | PL/EN/DE/FR/IT/ES: HTTP 200, 40/40/20, plansza i brak brakujących kluczy **PASS** |
| `GET /health/ready` | **200**, wszystkie zależności `true` |
| anonimowe `GET /api/mobile/session` | **200**, sesja anonimowa, `no-store` |
| pośredni task release Admin | oczekiwane **FAIL-CLOSED** — brak prawdziwego API URL/hostów |
| pośredni task release Author | oczekiwane **FAIL-CLOSED** — brak prawdziwego API URL/hostów |
| pośredni task release Źródła Słowa | oczekiwane **FAIL-CLOSED** — developerskie hosty/fingerprinty |

### Jawny dług PHPStan

Pełny PHPStan kończy się kodem błędu z powodu odziedziczonego, niepodłączonego fragmentu obcego projektu `Book100` w `app/Services/Payments/Przelewy24Gateway.php` i dwóch skryptów-fixture stage8/stage9. Plik nie ma referencji w działającej aplikacji i nie uczestniczy w ścieżkach Mobile/3DORS. Nie usunięto go ani nie ukryto w baseline, ponieważ byłaby to poboczna, nieautoryzowana przebudowa. Jest to dług do osobnego uporządkowania, nie blocker fizycznego E2E.

## Testy negatywne bezpieczeństwa

Testy obejmują między innymi:

- odrzucenie czytelnika przy enrollment 3DORS Author;
- brak zależności autora od `payout_enabled`;
- rozdzielenie credentiali i requestów Admin/Author;
- błędny token, błędny podpis, zmieniony fingerprint/payload, replay i request po terminie;
- cofnięcie roli/uprawnienia przed wykonaniem już podpisanej operacji;
- anulowanie i unieważnienie pending requests, enrollmentów, tokenów, credentiali i urządzeń;
- brak fallbacku operacji chronionej przy niespójnym `required`;
- ograniczony zakres recovery WWW i pełny reset 3DORS Mobile Admin przez CLI;
- wygaśnięcie sesji i read-only probe;
- blokadę release przy konfiguracji developerskiej.

## Odbiór wyglądu

Uruchomiono Źródło Słowa Mobile, 3DORS Admin i 3DORS Author na emulatorze Pixel 9 API 37.

Sprawdzono:

- rozdzielczość 1080×2424;
- rozdzielczość 720×1280 przy `font_scale=1.3`;
- PL i EN;
- ciemny interfejs, kontrast systemowych pasków i safe insets;
- finalne logo 3DORS: symetryczna czerwona tarcza, centralna biała kłódka i trzy czerwone poziomy, bez dodatkowego znaku;
- home Admin/Author, skaner i odmowę aparatu, ekrany enrollment/approval w galerii debug, onboarding Źródła Słowa oraz stany błędów/puste;
- brak obcięć i nakładania tekstów w sprawdzonych wariantach.

`FLAG_SECURE` celowo blokuje screenshoty rzeczywistych ekranów zawierających dane operacji. Ich strukturę sprawdzono przez UIAutomator, a układ przez bezpieczną galerię debug.

Wybrane dowody lokalne:

- `exports/final_admin_pl_portrait.png`
- `exports/final_author_pl.png`
- `exports/final_admin_small_en.png`
- `exports/final_approval_small_pl.png`
- `exports/final_camera_prompt_pl.png`
- `exports/final_camera_denied_pl.png`
- `exports/final_source_small_pl.png`
- `exports/final_source_small_en.png`
- `exports/final_source_intro_contrast3.png`
- `exports/final_deeplink.xml`
- `docs/screenshots/safety-fund-pl-small.png`
- `docs/screenshots/safety-fund-en-normal.png`
- `docs/screenshots/3dors-wartownik-pl.png`
- `docs/screenshots/3dors-wartownik-en.png`

Wybrane kopie tych dowodów są również dostępne w paczce konsultacyjnej pod `audit-evidence/visuals/`.
Wyniki XML nowych testów package visibility/deep link znajdują się w `audit-evidence/test-results/`.

## Artefakty debug

| Aplikacja | SHA-256 APK |
|---|---|
| 3DORS Admin | `F0373C21EFD8B0B42A979AF292D689596EDD26D397567DCA34E571E726E0F3E5` |
| 3DORS Author | `27692F9E4B9AAC263C48568C545A4609EF6E0BCF434D943749B97AD14F7FA7FC` |
| Źródło Słowa Mobile | `5B5EC5D6459EA2F30BB95B1907DB867208D27B1C0A7A1311FFDD271A1B99244D` |

## Paczka do niezależnego audytu

Skrypt `scripts/package_audit_repo.ps1` tworzy odtwarzalny ZIP źródeł i dokumentacji. Wyklucza `.git`, dane IDE/systemu, wszystkie rzeczywiste `.env`, `local.properties`, `vendor`, `node_modules`, cache, runtime `storage`, kopie baz, buildy, APK/AAB, keystore/klucze, logi i wcześniejsze archiwa. Zachowuje pliki przykładowej konfiguracji, lockfiles, oba Gradle Wrappery, migracje, testy, raport oraz wybrane bezpieczne dowody wizualne.

Wygenerowany plik:

```text
exports/zrodlo-slowa_FINAL_BEFORE_PHYSICAL_E2E_2026-08-08.zip
```

Suma SHA-256 znajduje się w równoległym pliku `.zip.sha256`, dzięki czemu nie powstaje zależność cykliczna od sumy archiwum wpisanej do dokumentu wewnątrz tego samego archiwum.

## Pliki zmienione w finalnym domknięciu

3DORS Wartownik:

- `database/postgresql/migrations/20260808_006_3dors_sentinel.sql`
- `app/Services/Dors3SentinelAlertService.php`
- `app/Services/Dors3SentinelService.php`
- `app/Controllers/Dors3SentinelController.php`
- `app/Services/SecurityEventService.php`
- `app/Services/SchedulerService.php`
- `app/Services/AdminSecurityPanelService.php`
- `app/Services/Dors3OperatorPresenter.php`
- `app/Services/AuditArtifactSanitizer.php`
- `app/Controllers/Dors3AdminController.php`
- `app/Controllers/AdminController.php`
- `public/index.php`
- `compose.yaml`
- `.env.example`, `.env.local.example`, `.env.production.example`
- `views/admin/dors3_sentinel.php`
- `views/admin/dors3.php`
- `views/admin/dashboard.php`
- `resources/lang/dors3.json`
- `public/assets/css/app.css`
- `tests/Integration/Dors3SentinelIntegrationTest.php`
- `tests/Unit/Dors3SentinelContractTest.php`
- `docs/3DORS_WARTOWNIK.md`
- `docs/screenshots/3dors-wartownik-pl.png`
- `docs/screenshots/3dors-wartownik-en.png`
- `scripts/capture_sentinel_screenshot.cjs`

Safety Fund, księga i prezentacja zasady:

- `database/postgresql/migrations/20260808_005_safety_fund.sql`
- `app/Services/SafetyFundService.php`
- `app/Services/ArticleEconomyService.php`
- `app/Controllers/SafetyFundAdminController.php`
- `app/Controllers/ArticleController.php`
- `app/Controllers/HomeController.php`
- `app/Services/Dors3MobileOperationExecutor.php`
- `app/Services/Dors3OperationFingerprintService.php`
- `app/Services/MobileOperationReadiness.php`
- `app/Services/PublicTranslationService.php`
- `app/Services/EconomyMapService.php`
- `views/admin/safety_fund.php`
- `views/admin/dashboard.php`
- `views/admin/role_panel.php`
- `views/articles/show.php`
- `views/economy/show.php`
- `resources/lang/safety_fund.json`
- `resources/lang/public.json`
- `resources/lang/dors3.json`
- `public/assets/img/safety-fund/safety-fund-principle.png`
- `public/assets/css/app.css`
- `tests/Integration/SafetyFundIntegrationTest.php`
- `tests/Integration/Dors3MobileIntegrationTest.php`
- `tests/Unit/SafetyFundTranslationTest.php`

Backend i testy:

- `app/Security/Dors3/MobileApprovalConfiguration.php`
- `app/Controllers/AdminController.php`
- `app/Controllers/AuthorController.php`
- `app/Controllers/AuthController.php`
- `app/Services/Dors3SettingsService.php`
- `app/Services/EnvironmentValidator.php`
- `tests/Unit/Dors3MobileApprovalConfigurationTest.php`
- `tests/Unit/EnvironmentValidatorTest.php`

3DORS Android:

- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/MainActivity.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/biometric/BiometricAuthenticator.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/biometric/Dors3BiometricSigner.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/crypto/Dors3KeystoreManager.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/data/ApprovalRepository.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/ui/home/HomeScreen.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/ui/home/HomeViewModel.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/ui/approval/ApprovalScreen.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/ui/qr/QrScannerScreen.kt`
- `mobile/3dors-android/app/src/main/java/pl/zrodloslowa/mobile/ui/enrollment/EnrollmentScreen.kt`
- `mobile/3dors-android/app/src/main/res/values/strings.xml`
- `mobile/3dors-android/app/src/main/res/values-en/strings.xml`
- odpowiadające testy `HomeViewModelTest`, `ApprovalRepositoryTest` i test doubles.

Źródło Słowa Android:

- `mobile/zrodlo-slowa-android/app/src/main/AndroidManifest.xml`
- `mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/MainActivity.kt`
- `mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/ui/auth/AuthGate.kt`
- `mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/ui/onboarding/OnboardingScreen.kt`
- `mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/webview/SecureWebView.kt`
- `mobile/zrodlo-slowa-android/app/src/main/res/values/themes.xml`
- `mobile/zrodlo-slowa-android/app/src/main/res/values-v27/themes.xml`
- `mobile/zrodlo-slowa-android/app/src/androidTest/java/pl/zrodloslowa/app/dors3/Dors3PackageVisibilityE2eTest.kt`
- `mobile/zrodlo-slowa-android/app/src/test/java/pl/zrodloslowa/app/dors3/Dors3PendingApprovalTest.kt`
- `mobile/zrodlo-slowa-android/app/src/test/java/pl/zrodloslowa/app/session/WebSessionManagerTest.kt`

Test odbioru po stronie 3DORS Author:

- `mobile/3dors-android/app/src/androidTestAuthor/java/pl/zrodloslowa/mobile/AuthorDeepLinkReceiptTest.kt`

## Co pozostaje przed produkcją lub na później

Nie blokuje rozpoczęcia fizycznego E2E:

1. Wykonać rzeczywisty pilot na co najmniej jednym telefonie Android 10 i jednym Android 11+ z biometrią/PIN-em, aparatem, zmianą sieci i ubijaniem procesu.
2. Podłączyć prawdziwe hosty, TLS, Digital Asset Links, `assetlinks.json`, produkcyjne fingerprinty i niezależne klucze podpisujące APK.
3. Ustawić kontrolowaną konfigurację pilota 3DORS; bieżące domyślne flagi są bezpiecznie wyłączone/developerskie.
4. Pełny WebAuthn/FIDO2 pozostaje bramką produkcyjną. Obecny kod nie powinien być opisywany jako działające pełne FIDO2.
5. Uporządkować odziedziczony fragment `Book100` i dwa fixture PHPStan w osobnym zadaniu.
6. Pełna internacjonalizacja całego starszego panelu PHP/JavaScript jest osobnym zakresem; nowe teksty 3DORS/recovery i wszystkie teksty mobilne mają centralne katalogi.
7. Financial Checkpoint pozostaje świadomie późniejszym rozwojem. Safety Fund jest wdrożony przed fizycznym E2E i nie tworzy osobnej infrastruktury finansowej.

## Konkluzja

Nie znaleziono pozostającego błędu kodu, który uzasadniałby dalsze blokowanie fizycznego E2E. Potwierdzone blokery wykryte w audycie zostały naprawione i objęte testami. Świadomie odłożone bramki produkcyjne pozostają fail-closed i nie są przedstawiane jako gotowe.
