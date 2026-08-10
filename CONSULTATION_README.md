# ŹRÓDŁO SŁOWA — paczka konsultacyjna

Paczka zawiera kod backendu i panelu oraz trzy aplikacje mobilne:

- `mobile/zrodlo-slowa-android` — Źródło Słowa Mobile;
- `mobile/3dors-android`, wariant `admin` — 3DORS Admin;
- `mobile/3dors-android`, wariant `author` — 3DORS Author.

Aktualny werdykt, lista napraw i wyniki testów znajdują się w `docs/FINAL_AUDIT_BEFORE_PHYSICAL_E2E.md`. Wdrożenie „Odpowiedzi publikacją” i pełną inwentaryzację Programu Talent opisuje `docs/RAPORT_WDROZENIA_ODPOWIEDZI_PUBLIKACJA_I_TALENT_2026-08-10.md`.

Archiwum nie zawiera `.git`, sekretów `.env`, `vendor`, cache, logów, sesji, baz danych ani ich kopii, buildów Gradle, `local.properties`, keystore, APK ani wcześniejszych archiwów. Zależności i buildy należy odtworzyć ze źródeł.

## Backend

Wymagania: Docker Desktop z Compose v2 i wolny port `8080`.

1. Skopiuj `.env.example` do `.env`.
2. Ustaw własne długie wartości co najmniej dla `APP_KEY`, `PASSWORD_PEPPER` i `FINANCE_HMAC_KEY`.
3. Uruchom:

   ```powershell
   docker compose up -d --build
   ```

4. Dla pustej bazy wykonaj instalację:

   ```powershell
   docker compose exec -T app-1 php scripts/install_fresh.php --confirm --admin-name="Administrator" --admin-email="admin@example.test" --admin-password="ZmienToHaslo-123456!"
   ```

5. Sprawdź `http://localhost:8080/health/ready`.
6. Uruchom testy:

   ```powershell
   docker compose exec -T app-1 ./vendor/bin/phpunit --colors=never
   docker compose exec -T app-1 ./vendor/bin/phpstan analyse --no-progress --memory-limit=480M
   ```

Pełny PHPStan jawnie raportuje odziedziczony, niepodłączony fragment `Book100` i dwa skrypty-fixture. Szczegóły oraz czysty wynik zmienionego zakresu są w raporcie końcowym. Nie dodano baseline ukrywającego te problemy.

## Android

Wymagania: Android Studio/SDK z API 37 i JDK dostarczone z Android Studio albo zgodne JDK.

3DORS Admin i Author:

```powershell
cd mobile\3dors-android
.\gradlew.bat testAdminDebugUnitTest testAuthorDebugUnitTest
.\gradlew.bat lintAdminDebug lintAuthorDebug
.\gradlew.bat assembleAdminDebug assembleAuthorDebug
.\gradlew.bat connectedAdminDebugAndroidTest connectedAuthorDebugAndroidTest
```

Źródło Słowa Mobile:

```powershell
cd mobile\zrodlo-slowa-android
.\gradlew.bat testDebugUnitTest lintDebug assembleDebug
adb install -r ..\3dors-android\app\build\outputs\apk\author\debug\app-author-debug.apk
.\gradlew.bat connectedDebugAndroidTest
```

Testy `connected*` wymagają uruchomionego emulatora albo telefonu. Test package visibility Źródła Słowa wymaga zainstalowanego APK 3DORS Author; przy jego rzeczywistym braku zostaje jawnie pominięty zamiast udawać udany handoff. Na Linux/macOS należy użyć `./gradlew`.

## Zasady 3DORS

- zwykły czytelnik nie używa 3DORS i loguje się hasłem;
- dziennikarz/autor używa 3DORS Author; `payout_enabled` nie jest warunkiem pracy autora;
- administrator używa 3DORS Admin;
- backend wybiera wariant aplikacji i operację, a telefon tylko wyświetla dane i podpisuje decyzję;
- teksty mobilne są w Android string resources PL/EN, a nowe teksty backendu/panelu 3DORS we wspólnym `resources/lang/dors3.json`;
- domyślna konfiguracja integracji jest bezpiecznie wyłączona;
- build release pozostaje fail-closed do czasu ustawienia prawdziwych hostów, TLS/Digital Asset Links, fingerprintów i niezależnych keystore.

Recovery obejmuje jeden model 3DORS Admin, 10 kodów recovery, ograniczone recovery WWW i pełne lokalne recovery CLI. Nie ma klasy urządzenia MASTER/OPERATIONAL. Financial Checkpoint pozostaje późniejszym etapem.

## Safety Fund

Safety Fund jest wdrożonym trzecim udziałem w tej samej istniejącej księdze płatnych artykułów:

```text
40% Autor + 40% Serwis i redakcja + 20% Safety Fund = 100%
```

Reguła jest wersjonowana w basis points, a księgowanie pozostaje atomowe i idempotentne. Zmiana polityki oraz wydanie środków wymagają 3DORS Admin. Nie jest to osobny system płatniczy ani druga księga. Implementację, migrację, testy i publiczne tłumaczenia opisuje raport końcowy.

## Opinie zamiast komentarzy i Program Talent

Komentarze nie są częścią systemu. Komentator lub zatwierdzony autor może przygotować podpisaną opinię albo polemikę, wysłać ją do redakcji i otrzymać TT dopiero po pierwszej faktycznej publikacji. Kwalifikacja i liczba TT są snapshotowane do joba przy publikacji; rewizja nie daje kolejnej nagrody. Reguła korzysta z istniejącego `TalentService`, kolejki i ledgera, bez PLN oraz bez nowego portfela.

Panel administratora pokazuje gotowość i prawdziwy wyzwalacz każdej reguły Talent. Reguły bez wiarygodnego dowodu mają zablokowaną aktywację. Szczegóły i dowody testowe znajdują się w raporcie wdrożeniowym.

## 3DORS Wartownik

Osobny panel `/admin/security/sentinel` agreguje istniejące źródła ochrony. Pokazuje `app-1` i `app-2`, gotowość wykonawców, próby logowania, sesje, alerty `high` / `critical` i filtrowaną historię. Alerty mają osobny cykl obsługi i nie zmieniają źródłowych `security_events`. Wartownik nie podpisuje, nie blokuje i nie zastępuje polityk backendu ani 3DORS.

Dokument architektoniczny: `docs/3DORS_WARTOWNIK.md`. Bezpieczne screeny PL/EN znajdują się w `audit-evidence/visuals/`.

## Bezpieczeństwo paczki

Nie dołączaj do konsultacji surowych logów ani eksportów danych. Mogą zawierać identyfikatory, tokeny i dane finansowe. Narzędzie do sanityzacji osobnego katalogu dowodowego:

```powershell
php scripts/sanitize_audit_artifacts.php storage\audit-input storage\audit-safe
```

Narzędzie zapisuje wynik do nowego katalogu i nie nadpisuje wejścia.
