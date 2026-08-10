# Raport odbioru 3DORS V2 — 2026-08-03

## Decyzja odbiorowa

**GO dla wersji developerskiej, konsultacji kodu i dalszej integracji. NO-GO dla publikacji produkcyjnej**, dopóki nie zostaną dostarczone prawdziwe domeny/API, zewnętrzny keystore i hasła podpisu, infrastruktura TLS oraz decyzja o świadomym włączeniu flag 3DORS.

Kod backendu, panelu i obu wariantów Androida jest spójny, działa na bieżącej gałęzi `main`, a dane istniejącej bazy nie zostały usunięte. Domyślny stan systemu pozostał bezpieczny:

- `DORS3_MOBILE_ENABLED=false`;
- `DORS3_MOBILE_MODE=disabled`;
- `DORS3_ADMIN_APP_ENABLED=false`;
- `DORS3_AUTHOR_APP_ENABLED=false`.

## Zrealizowane etapy

1. **Backend i baza** — rzeczywiste umowy Author, powiązanie enrollmentu z umową, jednorazowy 256-bitowy token urządzenia przechowywany wyłącznie jako SHA-256, TTL, uwierzytelnianie chronionych endpointów, pełny lifecycle urządzenia i idempotencja żądań finansowych.
2. **Bezpieczeństwo operacji** — podpisana tożsamość aktora przekazywana do Maker–Checker bez zależności od sesji przeglądarkowej; jawna macierz operacji GOTOWA/NIEGOTOWA; operacje niegotowe nie mogą zostać wymuszone przez mobile.
3. **Panel 3DORS** — wyszukiwanie użytkownika po nazwie/loginie/e-mailu zamiast ręcznego ID, osobne sekcje Admin/Author, rejestracje oczekujące, anulowanie, lifecycle urządzeń, kolejka decyzji, audyt mobilny i tabela gotowości.
4. **Android** — uwierzytelniony kontrakt API, szyfrowane przechowywanie credential/token/TTL, kod porównawczy przy rejestracji, fizyczne warianty Admin/Author, blokada błędnego release i finalne logo.
5. **Odbiór** — pełne testy backendu, analiza statyczna zmienionych modułów, build/lint/testy JVM obu wariantów, testy instrumentacyjne na emulatorze API 37, kontrola pakietów APK, separacji kodu i healthcheck środowiska Docker.

## Finalne logo i miejsca wdrożenia

Finalny znak to symetryczna czerwona tarcza, biała kłódka na osi pionowej i trzy symetryczne czerwone poziomy, bez dodatkowego znaku i bez tekstu w ikonie. Czarne tło oraz margines bezpieczeństwa zachowano dla masek kwadratowych, zaokrąglonych i okrągłych.

Logo wdrożono w:

- legacy launcherach `mdpi`, `hdpi`, `xhdpi`, `xxhdpi`, `xxxhdpi`;
- adaptive icon i wariancie okrągłym (`mipmap-anydpi-v26`);
- adaptive icon z monochrome (`mipmap-anydpi-v33`);
- splash screenie jasnym i ciemnym (API 31+);
- ekranie startowym obu aplikacji Admin/Author.

Źródłem współdzielonym jest `app/src/main/res/drawable/ic_dors3_foreground_vector.xml`; proporcje nie są skalowane niezależnie w osiach.

## Wyniki walidacji

| Obszar | Wynik |
| --- | --- |
| Backend PHPUnit | **OK — 139 testów, 894 asercje** |
| PHPStan, wszystkie zmienione moduły 3DORS | **OK — 0 błędów** |
| Docker readiness | **HTTP 200**, PostgreSQL, Valkey, vendor i object storage gotowe |
| Android Admin debug | **BUILD SUCCESSFUL** |
| Android Author debug | **BUILD SUCCESSFUL** |
| Testy JVM Android | **106 testów, 0 błędów** |
| Android Lint Admin/Author | **SUCCESS, 0 błędów**; po 49 ostrzeżeń informacyjnych |
| Testy instrumentacyjne | **6/6**, po 3 dla Admin i Author na Pixel_9/API 37 |
| Pakiety APK | `pl.zrodloslowa.dors3.admin` i `pl.zrodloslowa.dors3.author` |
| Separacja kodu w DEX | Admin: markery admin obecne, author nieobecne; Author: odwrotnie |
| Guard release | **poprawnie blokuje** placeholdery domen/API oraz brak podpisu; configuration cache zachowany |

Ostrzeżenia lint nie są błędami wykonania. Dotyczą głównie dostępności nowszych wersji zależności, odziedziczonych nieużywanych zasobów i sugestii KTX. Nie wykonywano szerokiej, ryzykownej aktualizacji bibliotek produkcyjnych. Zaktualizowano wyłącznie zależności testowe AndroidX Test do stabilnych wersji wymaganych przez API 37 (`ext.junit 1.3.0`, `espresso-core 3.7.0`).

Pełne, repozytoryjne uruchomienie PHPStan nadal raportuje 35 wcześniejszych problemów spoza wdrożenia 3DORS, głównie w `app/Services/Payments/Przelewy24Gateway.php` oraz skryptach stage8/stage9. Zmienione moduły 3DORS są czyste. To jawny dług techniczny do osobnego zakresu, a nie błąd tej integracji.

## Artefakty odbiorowe

- Panel: `exports/3dors-panel-odbior-2026-08-03.png`.
- Admin APK: `mobile/3dors-android/app/build/outputs/apk/admin/debug/app-admin-debug.apk`, SHA-256 `FBAE01FC01E2C15694775444EB26117238624C08989CE8F6112E51CDE1E4C001`.
- Author APK: `mobile/3dors-android/app/build/outputs/apk/author/debug/app-author-debug.apk`, SHA-256 `ECB129EE4A6821EC8CBF87198E35D7CED5057ACCE3594328A51FE4612AFCC0DC`.
- Paczka konsultacyjna: `exports/zrodlo-slowa-konsultacja-2026-08-03.zip`; suma kontrolna w pliku obok archiwum.

APK są lokalnymi artefaktami debug i celowo nie są dołączane do paczki źródłowej. Konsultant może odtworzyć je z Gradle.

Paczka została niezależnie rozpakowana do czystego katalogu kontrolnego: test CRC ZIP przeszedł, nie znaleziono żadnego zabronionego wpisu ani brakującego pliku wymaganego, `docker compose config --quiet` zakończył się poprawnie, a dołączony Gradle Wrapper wykonał zadanie `help` z wynikiem `BUILD SUCCESSFUL`. Katalog kontrolny został następnie usunięty.

## Manifest zmienionych plików

### Backend, bezpieczeństwo i baza

- `.dockerignore`, `.env.example`, `.env.local.example`, `.env.production.example`, `compose.yaml`, `config/dors3.php`;
- `database/postgresql/migrations/20260803_001_3dors_mobile.sql`;
- `database/postgresql/migrations/20260803_002_3dors_acceptance.sql`;
- `app/Controllers/Dors3AdminController.php`;
- `app/Controllers/Dors3MobileAdminController.php`;
- `app/Controllers/Dors3MobileApiController.php`;
- `app/Services/AuthorAgreementService.php`;
- `app/Services/Dors3MobileService.php`;
- `app/Services/Dors3MobileOperationExecutor.php`;
- `app/Services/Dors3OperationFingerprintService.php`;
- `app/Services/FinancialService.php`, `app/Services/InstallService.php`;
- `app/Security/Dors3/MobileEnrollmentQrCode.php`;
- `app/Security/Dors3/MobileOperationPolicy.php`;
- `app/Security/Dors3/MobileOperationReadiness.php`;
- `app/Security/Dors3/MobileProtocol.php`;
- `app/Security/Dors3/MobileSignatureVerifier.php`;
- `public/index.php`, `public/assets/css/slowo-system.css`, `views/admin/dors3.php`.

### Testy backendu

- `tests/Integration/Dors3MobileIntegrationTest.php`;
- `tests/Integration/Dors3MobileFinancialActorTest.php`;
- `tests/Integration/FreshInstallTest.php`;
- `tests/Unit/Dors3MobileMigrationTest.php`;
- `tests/Unit/Dors3MobileProtocolTest.php`.

### Android — kontrakt, warianty i testy

- `mobile/3dors-android/app/build.gradle.kts`;
- `mobile/3dors-android/gradle/libs.versions.toml`;
- `app/src/main/.../model/Dors3Models.kt`;
- `app/src/main/.../network/Dors3ApiService.kt`;
- `app/src/main/.../data/Dors3CredentialStore.kt`;
- `app/src/main/.../data/DeviceCredentialStore.kt`;
- `app/src/main/.../data/EnrollmentRepository.kt`;
- `app/src/main/.../data/ApprovalRepository.kt`;
- `app/src/main/.../ui/enrollment/EnrollmentViewModel.kt` i `EnrollmentScreen.kt`;
- `app/src/main/.../ui/home/HomeScreen.kt`;
- odpowiadające testy JVM w `app/src/test`;
- polityki i testy w `app/src/admin` oraz `app/src/author`;
- `app/src/androidTest/.../ExampleInstrumentedTest.kt` i `HomeScreenTest.kt`.

### Android — identyfikacja wizualna

- `app/src/main/res/drawable/ic_dors3_foreground_vector.xml`;
- `app/src/main/res/drawable/ic_dors3_logo_vector.xml`;
- `app/src/main/res/mipmap-anydpi-v26/ic_launcher*.xml`;
- `app/src/main/res/mipmap-anydpi-v33/ic_launcher*.xml`;
- `app/src/main/res/mipmap-{mdpi,hdpi,xhdpi,xxhdpi,xxxhdpi}/ic_launcher*.webp`;
- `app/src/main/res/values-v31/themes.xml`;
- `app/src/main/res/values-night-v31/themes.xml`.

### Dokumentacja odbiorowa

- `CONSULTATION_README.md`;
- `docs/RAPORT_ODBIORU_3DORS_V2_2026-08-03.md`.

## Pozostałe warunki przed produkcją

1. Prawdziwe domeny Admin/Author i publiczny HTTPS API.
2. Zewnętrzny keystore release, polityka jego przechowywania i procedura rotacji.
3. Testy na co najmniej jednym fizycznym urządzeniu oraz na docelowych wersjach Androida.
4. Decyzja biznesowa, które pozycje z macierzy NIEGOTOWA mają zostać zaimplementowane.
5. Świadome włączenie flag dopiero po odbiorze bezpieczeństwa i planie rollbacku.
