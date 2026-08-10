# Raport odbioru 3DORS V3 — 2026-08-03

## Decyzja odbiorowa

**GO dla wersji developerskiej, zewnętrznej konsultacji kodu i dalszej integracji. NO-GO dla wdrożenia produkcyjnego.**

Integracja odpowiada aktualnemu modelowi ról i działa w bieżącym repozytorium na gałęzi `main`. Dane istniejącej bazy zostały zachowane, migracje są zastosowane, a usługi aplikacyjne po przebudowaniu przechodzą healthcheck. Integracja pozostaje domyślnie wyłączona:

- `DORS3_MOBILE_ENABLED=false`;
- `DORS3_MOBILE_MODE=disabled`;
- `DORS3_ADMIN_APP_ENABLED=false`;
- `DORS3_AUTHOR_APP_ENABLED=false`.

## Model ról przyjęty jako obowiązujący

- Zwykły czytelnik loguje się standardowym hasłem i **nie korzysta z 3DORS**.
- 3DORS Author służy dziennikarzowi/autorowi z aktywną rolą, prawem do pracy redakcyjnej i aktywnym uprawnieniem autora.
- Prawo do pracy redakcyjnej jest niezależne od wypłat. Operacje wypłat są kierowane do 3DORS Admin; `wallet_enabled` ani `payout_enabled` nie warunkują pracy w 3DORS Author.
- 3DORS Admin służy administratorowi do operacji administracyjnych oraz do obsługi panelu bezpieczeństwa.
- Uprawnienia są sprawdzane ponownie bezpośrednio przed wykonaniem podpisanej publikacji, zmiany roli albo operacji finansowej.

## Automatyczne kierowanie operacji do aplikacji

Backend jest jedynym źródłem decyzji o typie operacji, wariancie aplikacji, roli i uprawnieniach. Kontroler rozpoczynający operację nie przekazuje wariantu telefonu: wywołuje `createOperationApprovalRequest()`, a `MobileOperationPolicy::requiredVariant()` wyznacza go z niezmiennego identyfikatora maszynowego.

- `article.*`, publikacja i praca dziennikarska trafiają do **3DORS Author**;
- `payout.approve`, `payout.reject`, zmiany administracyjne wypłat, role i bezpieczeństwo trafiają do **3DORS Admin**;
- logowanie zachowuje wariant wynikający z roli konta;
- warianty debug mają osobne URI: `dors3-admin-dev://approve/...` i `dors3-author-dev://approve/...`;
- telefon nie pokazuje wyboru rodzaju operacji. Odbiera żądanie przypisane przez backend, prezentuje dane operacji i udostępnia decyzje **ZATWIERDŹ** oraz **ODRZUĆ**.

Wariant aplikacji jest ponownie kontrolowany po stronie Androida. Żądanie skierowane do niewłaściwego pakietu zostaje odrzucone, a wartości `action_type`, fingerprint i kanoniczny payload podpisu nie zostały zmienione.

## Centralizacja tekstów widocznych dla użytkownika

Centralizacja obejmuje wyłącznie warstwę UI 3DORS. Identyfikatory maszynowe, kody błędów, nazwy pól protokołu, typy operacji oraz kontrakt podpisu pozostają bez zmian.

- Android korzysta z `res/values/strings.xml` jako polskiej bazy i kompletnego `res/values-en/strings.xml` dla struktury angielskiej; osobne nazwy 3DORS Admin/Author mają zasoby obu języków.
- Ekrany startowe, rejestracja, skaner QR, operacja, biometria, wynik, wygaśnięcie i galeria debug nie mają widocznych tekstów wpisanych bezpośrednio w Compose/Kotlinie.
- Nazwy maszynowe, np. `article.submit` i `payout.approve`, są mapowane na tekst użytkownika; nieznany typ pokazuje neutralne „Operacja 3DORS”, nigdy surowy identyfikator.
- Backend i panel korzystają ze wspólnego katalogu `resources/lang/dors3.json` przez `Dors3UiText` i `dors3_t()`.
- Katalog PL i EN ma identyczną strukturę: **499 kluczy w każdym języku**.
- Telefon pokazuje wszystkie pola przygotowane przez backend, a nie tylko pierwszy element. Dane błędów technicznych nie są bezpośrednio ujawniane użytkownikowi.

## Odpowiedź na niezależny audyt

### Zrealizowane poprawki krytyczne i wysokie

1. Telefon nie może już sam aktywować urządzenia. Po ukończeniu enrollmentu czeka na zatwierdzenie w tej samej sesji panelu administratora, z prawidłowym sześciocyfrowym kodem porównawczym i ponownym podaniem hasła administratora.
2. Negatywna decyzja telefonu wyłącznie odrzuca enrollment i usuwa lokalny credential/klucz; nie istnieje ścieżka pozytywnej samoaktywacji.
3. Rate limiting decyzji mobilnej stosuje jednocześnie ograniczenie IP, identyfikatora żądania i deklarowanego urządzenia. Losowanie identyfikatora urządzenia nie omija limitu IP.
4. Enrollment Author wymaga roli autora/dziennikarza, prawa do pisania i aktywnego uprawnienia autora. Czytelnik nie może zapisać urządzenia 3DORS.
5. Podpisana publikacja ponownie weryfikuje autora oraz wydawcę/redaktora naczelnego. Podpisana wypłata ponownie weryfikuje administratora i osobne flagi wypłat odbiorcy.
6. Fingerprint operacji bez materiałów źródłowych ma jawny schemat `none-v1` i jednoznaczny canonical hash zamiast nieopisanego skrótu pustej tablicy.
7. W bazie dodano datę ukończenia urządzenia, administratora zatwierdzającego, stan weryfikacji attestation oraz automatyczne wygaszanie wcześniejszych uprawnień autora.
8. Warianty Android Admin i Author wymagają niezależnych konfiguracji podpisu release. Guard blokuje placeholdery domen/API, brak kluczy oraz tę samą parę keystore/alias.
9. Release obu wariantów przechodzi R8. APK kontrolne zostały podpisane dwiema niezależnymi, jednorazowymi parami testowymi i zweryfikowane przez `apksigner` w schemacie v2. Klucze testowe zostały trwale usunięte po kompilacji.

### Jawnie niewdrażane pozory bezpieczeństwa

- Android Key Attestation nie jest uznawane za zweryfikowane. Panel pokazuje poziom deklarowany i osobne pole `attestation_verified`; bez serwerowej walidacji łańcucha pozostaje ono fałszywe.
- Biblioteka WebAuthn stanowi fundament techniczny, ale pełna ceremonia FIDO2 oraz autoryzacja operacji FIDO2 są oznaczone jako niedostępne. System nie deklaruje gotowości, której nie potrafi potwierdzić.
- Przejściowe uprawnienie `legacy-v1` jest opisane jako systemowe uprawnienie migracyjne, a nie dowód zawarcia umowy prawnej.

## Scalony panel „BEZPIECZEŃSTWO I 3DORS”

Administrator korzysta z jednego panelu `/admin/security/3dors`. Starsza ścieżka administratora `/account/security` przekierowuje do panelu wspólnego. Osobisty ekran bezpieczeństwa pozostaje dostępny dla nieadministratorów, ponieważ czytelnicy i autorzy nadal potrzebują własnej obsługi e-maila i 2FA.

Panel łączy:

- bezpieczeństwo konta administratora, e-mail i 2FA;
- role i statusy użytkowników;
- enrollmenty, urządzenia i sesje mobilne;
- 3DORS Admin i 3DORS Author;
- gotowość operacji, FIDO2 i attestation;
- wspólną, filtrowaną historię zdarzeń bezpieczeństwa bez podwójnego odczytu tej samej historii.

## Finalna identyfikacja aplikacji mobilnej

Finalny znak to symetryczna czerwona tarcza, biała kłódka ustawiona na osi pionowej oraz trzy czerwone poziomy na czarnym tle. Ikona nie zawiera tekstu ani dodatkowego znaku nad kłódką.

Logo wdrożono w:

- ekranie startowym 3DORS Admin i 3DORS Author;
- ikonach launchera `mdpi`, `hdpi`, `xhdpi`, `xxhdpi`, `xxxhdpi`;
- adaptive icon i wariancie okrągłym dla API 26+;
- foreground/background i zasobie monochrome dla API 33+;
- jasnym i ciemnym splash screenie API 31+.

Z kodu usunięto stare źródło `ic_dors3_foreground_vector.xml`. Manifest wskazuje `@mipmap/ic_launcher` i `@mipmap/ic_launcher_round`, które korzystają wyłącznie z finalnego foregroundu. Stary, zainstalowany wcześniej pakiet `pl.zrodloslowa.mobile` miał odrębną ikonę i po podziale jest pakietem przestarzałym; został usunięty z emulatora odbiorowego. Aktualne pakiety to:

- `pl.zrodloslowa.dors3.admin`;
- `pl.zrodloslowa.dors3.author`.

Zrzuty odbiorowe ekranu aplikacji i launchera są dołączone do osobnej paczki dowodowej.

## Wyniki walidacji końcowej

| Obszar | Wynik |
| --- | --- |
| Backend PHPUnit | **OK — 154 testy, 997 asercji, 9 pominiętych** |
| PHPStan, zakres wdrożenia 3DORS | **OK — 0 błędów** |
| PHPStan całego odziedziczonego repo | **35 wcześniejszych błędów poza zakresem 3DORS**; głównie niekompletna integracja `Book100` w `Przelewy24Gateway.php` oraz skrypty stage8/stage9 |
| Migracje PostgreSQL | **OK** — `20260803_001`, `_002`, `_003` mają stan `already_applied` |
| Docker liveness/readiness | **HTTP 200**; PostgreSQL, Valkey, vendor, schemat i object storage gotowe |
| Android build debug | **BUILD SUCCESSFUL** dla Admin i Author |
| Android testy JVM | **112/112** — po 56 dla Admin i Author |
| Android lint | **0 błędów**; po 50 ostrzeżeń informacyjnych |
| Android instrumentation | **6/6** — po 3 dla Admin i Author na emulatorze Pixel_9/API 37 |
| Routing aplikacji | **OK** — odrębne schematy Admin/Author obecne w scalonych manifestach |
| Katalog UI PL/EN | **OK** — 499/499 kluczy, identyczna struktura |
| Izolacja testów | **OK** — brak schematów `phpunit_%`; `public.users` pozostało bez zmian (1 rekord) |
| Android release + R8 | **BUILD SUCCESSFUL** dla obu wariantów; zadania `minify*ReleaseWithR8` wykonane |
| Podpisy kontrolnych APK release | **OK** — osobne certyfikaty RSA 3072, APK Signature Scheme v2 |
| Ikona wejścia | **Potwierdzona zrzutem działającego launchera** po czystej reinstalacji aktualnych pakietów |

Ostrzeżenia Android lint i Kotlin nie blokują kompilacji. Dotyczą głównie dostępności nowszych zależności, przyszłej zmiany domyślnego celu adnotacji, deprecacji API i sugestii porządkowych.

## Pliki zmienione w etapie audytowym

### Backend, bezpieczeństwo i panel

- `app/Controllers/AccountSecurityController.php`;
- `app/Controllers/Dors3AdminController.php`;
- `app/Controllers/Dors3MobileAdminController.php`;
- `app/Controllers/Dors3MobileApiController.php`;
- `app/Services/AdminSecurityPanelService.php`;
- `app/Services/AuthSecurityService.php`;
- `app/Services/AuthorAgreementService.php`;
- `app/Services/Dors3MobileService.php`;
- `app/Services/Dors3MobileOperationExecutor.php`;
- `app/Services/Dors3OperationFingerprintService.php`;
- `app/Services/Dors3OperatorPresenter.php`;
- `app/Services/Dors3UiText.php`, `resources/lang/dors3.json`, `app/Core/bootstrap.php`;
- `app/Services/WebAuthnFoundationService.php`;
- `app/Security/ArticleSubmissionPolicy.php` oraz klasy `app/Security/Dors3/Mobile*.php`;
- `views/admin/dashboard.php`, `views/admin/dors3.php`;
- `public/index.php`, `app/Core/Router.php`.

### Baza i testy

- `database/postgresql/migrations/20260803_003_3dors_independent_audit.sql`;
- `tests/Integration/Dors3MobileIntegrationTest.php`;
- `tests/Unit/RouteSecurityConfigurationTest.php`;
- testy i migracje 3DORS dodane we wcześniejszym etapie V2 pozostają częścią rozwiązania.

### Android

- `mobile/3dors-android/app/build.gradle.kts`;
- `app/src/main/res/values/strings.xml`, `app/src/main/res/values-en/strings.xml` oraz zasoby nazw wariantów `admin`/`author`;
- ekrany `HomeScreen`, `QrScannerScreen`, `EnrollmentScreen`, `ApprovalScreen`, `ExpiredScreen`, `RejectedScreen` i repozytorium promptu biometrycznego;
- `HomeScreenTest.kt` — teksty testowe pobierane z aktywnego katalogu językowego;
- `EnrollmentRepository.kt`, `EnrollmentViewModel.kt`, `EnrollmentScreen.kt` i odpowiadające testy;
- `HomeScreen.kt`;
- `drawable-xxxhdpi/ic_dors3_logo.png`;
- `drawable-xxxhdpi/ic_launcher_foreground.png`;
- `drawable/ic_dors3_logo_vector.xml`;
- `mipmap-anydpi-v26/ic_launcher*.xml`;
- `mipmap-anydpi-v33/ic_launcher*.xml`;
- `mipmap-{mdpi,hdpi,xhdpi,xxhdpi,xxxhdpi}/ic_launcher*.webp`;
- `values-v31/themes.xml`, `values-night-v31/themes.xml`;
- usunięty `drawable/ic_dors3_foreground_vector.xml`.

## Pozostałe bramki przed produkcją

1. Prawdziwe domeny Admin/Author, publiczny HTTPS API, poprawne App Links i `assetlinks.json`.
2. Dwa produkcyjne klucze podpisu przechowywane poza repozytorium, procedura backupu, dostępu i rotacji.
3. Serwerowa weryfikacja Android Key Attestation albo świadoma rezygnacja z tej polityki; do tego czasu attestation pozostaje nieweryfikowane.
4. Pełna ceremonia FIDO2/WebAuthn, jeśli FIDO2 ma być używane do autoryzacji operacji.
5. Rotacja tokenu API urządzenia. Aktualnie token ma TTL i wymaga ponownego enrollmentu po wygaśnięciu, ale płynna rotacja nie jest wdrożona.
6. Prawne umowy autora zamiast przejściowego `legacy-v1`.
7. Strukturalny rejestr materiałów źródłowych dla operacji, które mają go wymagać; obecny `none-v1` jest jednoznaczny, lecz oznacza brak materiałów.
8. Testy na fizycznych urządzeniach oraz odbiór na docelowych wersjach Androida.
9. Usunięcie 35 odziedziczonych błędów pełnego PHPStan w osobnym zakresie.
10. Świadome włączenie flag 3DORS dopiero po spełnieniu powyższych bramek i zatwierdzeniu rollbacku.

## Paczka do zewnętrznego sprawdzenia

W katalogu `exports` znajduje się świeża paczka `zrodlo-slowa-konsultacja-prepilot-2026-08-03.zip`. Zawiera pełny kod źródłowy potrzebny do niezależnego uruchomienia i audytu, w tym wrapper Gradle, migracje, testy, dokumentację i finalne zasoby logo.

Wyłączono: `.git`, sekrety `.env`, `vendor`, `node_modules`, cache, logi, sesje, buildy Androida, APK/AAB, pliki IDE i systemowe oraz poprzednie eksporty. Instrukcja odtworzenia zależności znajduje się w `CONSULTATION_README.md`. Suma SHA-256 jest zapisana obok archiwum i podana w końcowym raporcie Codex.
