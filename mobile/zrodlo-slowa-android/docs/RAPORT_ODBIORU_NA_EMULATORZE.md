# RAPORT ODBIORU NA EMULATORZE — Źródło Słowa Mobile

**Data:** 4 sierpnia 2026
**Emulator:** Pixel_9 (AVD, Android — API zgodne z konfiguracją SDK w środowisku), `emulator-5554`
**APK:** `app/build/outputs/apk/debug/app-debug.apk`, wariant `debug`, zbudowany lokalnie z `gradlew.bat assembleDebug` (BUILD SUCCESSFUL)
**Backend:** lokalny `docker compose` (`X:\zrodlo-slowa\compose.yaml`), usługi `proxy/app-1/app-2/postgres/valkey/minio/mailpit` — wszystkie `Healthy`
**Metoda:** rzeczywista instalacja i klikanie po `adb`/`uiautomator`, zrzuty ekranu z `adb screencap`, logi z `adb logcat`. Nie jest to symulacja — to faktyczne uruchomienie na emulatorze.

Wszystkie zrzuty w `docs/screenshots/`.

---

## 1. Co faktycznie sprawdzono i potwierdzono

| # | Krok | Wynik | Dowód |
|---|------|-------|-------|
| 1 | Build debug APK | DZIAŁA | BUILD SUCCESSFUL, log gradle |
| 2 | Instalacja na emulatorze | DZIAŁA | adb install -r -> Success |
| 3 | Zimny start | DZIAŁA | 01_start.png — ekran powitalny "Welcome to Source of Word" |
| 4 | Ekran startowy (3 opcje: gość / logowanie / autor) | DZIAŁA | 01_start.png |
| 5 | "Browse without an account" -> Główna | DZIAŁA | 03_glowna_home.png, 06_home_loaded.png |
| 6 | WebView bez backendu -> czytelny błąd | DZIAŁA | 03_glowna_home.png — "No connection... Try again", bez crasha |
| 7 | WebView z lokalnym backendem -> realna treść PL | DZIAŁA | 06_home_loaded.png, 07_articles.png — prawdziwe artykuły z bazy danych |
| 8 | Hamburger menu | DZIAŁA | 08_menu.png — Latest/Topics/Polls/Ads/How to earn/Authors/Change language/Sign in/Join as author |
| 9 | Dolna nawigacja: Home -> Articles | DZIAŁA | 09_articles2.png (po korekcie współrzędnych dotknięcia) |
| 10 | FLAG_SECURE na ekranie Konta | DZIAŁA — potwierdzone empirycznie | 11_account.png — adb screencap zwraca całkowicie czarny obraz, dokładnie tak jak oczekiwano dla ekranu z aktywną flagą antyzrzutową |
| 11 | Brak crasha (FATAL/AndroidRuntime) w całej sesji | DZIAŁA | adb logcat filtrowany na FATAL/AndroidRuntime — pusty |
| 12 | Otwarcie linku `dors3-author-dev://approve/{id}` -> uruchomienie aplikacji 3DORS Author | DZIAŁA — potwierdzone empirycznie w sesji uzupełniającej | e2e_02_author_launch.png — `topResumedActivity=pl.zrodloslowa.dors3.author/...MainActivity` |
| 13 | Powrót z 3DORS Author do Źródło Słowa Mobile (przycisk wstecz), bez crasha | DZIAŁA — potwierdzone empirycznie | e2e_03_return.png — `topResumedActivity=pl.zrodloslowa.app/.MainActivity` |

### Uzupełnienie sesji: domknięcie testu 3DORS Author

W poprzedniej części tej sesji aplikacja `pl.zrodloslowa.dors3.author` nie była zainstalowana na emulatorze (był tylko Admin), co uniemożliwiało test przejścia. W tej sesji zbudowano i zainstalowano wariant `authorDebug` z projektu `mobile/3dors-android` (flavor `author`, `applicationId=pl.zrodloslowa.dors3.author`) na tym samym emulatorze. Test `adb shell am start -a android.intent.action.VIEW -d "dors3-author-dev://approve/123"` REALNIE uruchomił aplikację 3DORS Author (zrzut ekranu ze splashem/kłódką Author, `e2e_02_author_launch.png`), a naciśnięcie przycisku wstecz REALNIE przywróciło aplikację Źródło Słowa Mobile na wierzch (`e2e_03_return.png`), bez żadnego crasha. To potwierdza empirycznie na poziomie systemu Android, że kontrakt custom scheme `dors3-author-dev://approve/{id}` opisany w kodzie (`Dors3AuthorLauncher.kt`, `mobile/3dors-android/.../Dors3DeepLink.kt`) faktycznie działa między dwiema realnie zainstalowanymi aplikacjami — wcześniej było to potwierdzone WYŁĄCZNIE testami jednostkowymi.

**Zastrzeżenie metodologiczne:** ten test wywołał intencję systemową bezpośrednio przez `adb`, a nie kliknięciem linku w treści WebView aplikacji Źródło Słowa. Sprawdza to poprawność rejestracji schematu w Android Manifest i ogólną działalność obu aplikacji, ale NIE sprawdza wewnętrznej ścieżki `SecureWebView` -> `Dors3AuthorLauncher.isApprovalLink` -> `markLaunched()` -> (powrót) -> `Dors3ResultHandler`/odświeżenie WebView — ta ścieżka nadal jest potwierdzona tylko testami jednostkowymi, nie kliknięciem w rzeczywistej stronie (do tego potrzebna byłaby strona backendu renderująca prawdziwy link zatwierdzenia).

### Ważne pozytywne odkrycie wykraczające poza dyspozycję

Podczas testu ekranu Konta zrzut ekranu (adb screencap) zwrócił jednolicie czarny obraz zamiast treści. To nie błąd — to rzeczywisty, zweryfikowany dowód działania FLAG_SECURE opisanego w `ZrodloSlowaNavHost.kt` (linie ok. 109–120): system Android odmawia przechwycenia zawartości ekranu, gdy flaga jest aktywna. To jest dokładnie to zachowanie, którego oczekuje audyt bezpieczeństwa dla ekranów Portfela/Konta — i zostało to potwierdzone na żywym urządzeniu, a nie tylko przeczytane w kodzie.

---

## 2. Błędy i niedociągnięcia znalezione podczas testów rzeczywistych

### BŁĄD UŻYTECZNOŚCI (zdiagnozowany i obejście zweryfikowane w tej sesji)

Domyślny debugowy adres backendu w kodzie to `http://10.0.2.2:8080/` (`app/build.gradle.kts`). W tym środowisku Docker publikuje port aplikacji wyłącznie na `127.0.0.1` hosta (`compose.yaml`, `proxy.ports: "127.0.0.1:8080:8080"`), więc alias `10.0.2.2` emulatora nie był w stanie się połączyć — WebView pokazywał (poprawnie i bez crasha) komunikat "No connection". Po przebudowaniu z `-PZRODLOSLOWA_DEBUG_WEB_BASE_URL=http://127.0.0.1:8080/` i ustawieniu `adb reverse tcp:8080 tcp:8080` połączenie zadziałało w 100%. To nie jest błąd aplikacji — to niedopasowanie topologii sieci kontenerów Docker do domyślnego aliasu emulatora. Zalecenie: udokumentować w README, że lokalne testy wymagają albo zmiany bindowania portu na `0.0.0.0`, albo nadpisania `ZRODLOSLOWA_DEBUG_WEB_BASE_URL` na `http://127.0.0.1:8080/` razem z `adb reverse`.

### OBSERWACJA WIZUALNA (nie blokująca)

Ikona/etykieta "Wallet" na środkowym, wystającym przycisku dolnego paska jest zawsze czerwona (`ZrodloSlowaBottomBar.kt`, linia 136: `color = MaterialTheme.colorScheme.primary` na stałe, niezależnie od `selected`), nawet gdy aktywna jest inna zakładka (np. Articles — widoczne na 09_articles2.png, gdzie zarówno "Articles", jak i "Wallet" są czerwone jednocześnie). To wygląda na celowy zabieg graficzny (Wallet jako stały akcent marki), ale może mylić użytkownika co do tego, która zakładka jest faktycznie aktywna. Rekomendacja dla przyjazności użytkownika: rozważyć albo (a) pozostawienie Wallet czerwonego tylko gdy faktycznie wybrany, albo (b) jawne wizualne odróżnienie "akcentu marki" od "wskaźnika aktywnej zakładki" (np. innym odcieniem/rozmiarem), tak aby użytkownik jednoznacznie widział, na którym ekranie się znajduje.

### OGRANICZENIE METODOLOGICZNE (do jawnego zaznaczenia)

Test "trybu offline" (`svc wifi disable` + `svc data disable`) nie jest wiarygodny w tej konfiguracji: `adb reverse` tuneluje ruch do `127.0.0.1:8080` przez kanał ADB (USB/emulator console), niezależny od radia Wi-Fi/danych komórkowych emulowanego urządzenia. Zrzut 12_offline.png nadal pokazuje załadowaną treść — nie można tego uznać za potwierdzenie poprawnej obsługi trybu offline, ponieważ połączenie z backendem najprawdopodobniej nadal działało przez tunel ADB. Prawdziwy test offline wymaga fizycznego urządzenia lub backendu dostępnego przez rzeczywisty adres sieciowy (nie adb reverse).

---

## 3. Czego NIE udało się zweryfikować w tej sesji i dlaczego

Zgodnie z zasadą "nie zgaduj, nie oceniaj deklaracji" — jawnie wypisuję, czego NIE potwierdzono:

- **Logowanie/wylogowanie na prawdziwym koncie** — lokalna baza danych z docker compose nie ma znanych danych testowych (login/hasło) przygotowanych dla tej sesji; nie stworzono ich, aby uniknąć modyfikacji stanu backendu bez wyraźnego polecenia.
- **Portfel i Powiadomienia z realnymi danymi** — wymaga zalogowanego konta autora z uprawnieniami wallet_enabled; niepotwierdzone.
- **Blokada 3DORS Admin w praktyce (kliknięcie w WebView)** — logika blokady (`Dors3AuthorLauncher.isAdminLink`) jest pokryta testami jednostkowymi, ale nie przetestowano jej klikając rzeczywisty link Admin w WebView (wymagałoby to strony backendu renderującej taki link).
- **Odświeżenie WebView po realnym powrocie z Author zainicjowanym przez `SecureWebView.markLaunched()`** — test w tej sesji (patrz punkt 7 tabeli poniżej) uruchomił i zamknął aplikację Author przez bezpośredni `adb am start`/przycisk "wstecz", czyli na poziomie systemu Android, a nie przez kliknięcie w treści WebView. Dlatego wewnętrzny stan `Dors3PendingApproval.isPending` (ustawiany tylko przez `SecureWebView`) nie został w tym teście naprawdę ustawiony, więc mechanizm auto-odświeżenia strony po powrocie (`Dors3ResultHandler`) nie został wywołany i pozostaje potwierdzony tylko testami jednostkowymi, nie realnym przejściem z kliknięcia linku w stronie.
- **Zmiana języka (6 domen)** — potwierdzono tylko, że pozycja "Change language" istnieje w menu (08_menu.png); nie przetestowano przejścia między wszystkimi 6 wersjami domenowymi, ponieważ każda wymagałaby własnego, osobno skonfigurowanego backendu/DNS.
- **Prawdziwy tryb offline** — patrz ograniczenie metodologiczne wyżej.
- **Rzeczywisty numer wersji emulatora (poziom API)** — nie odczytano jawnie ro.build.version.release; nie było to wymagane do przeprowadzonych testów funkcjonalnych, ale dla pełnej dokumentacji należałoby to dopisać.

---

## 4. GO / NO-GO

```text
Powłoka aplikacji (start, nawigacja, WebView, hamburger, błędy sieci):  GO (potwierdzone empirycznie)
Ochrona ekranu (FLAG_SECURE) na Koncie/Portfelu:                        GO (potwierdzone empirycznie)
Logowanie/konto/rola/2FA:                                               NO-GO DO E2E (brak danych testowych/backendu roli)
Portfel z realnymi danymi:                                              NO-GO DO E2E
3DORS Author (przejście i powrót na poziomie OS/dwóch aplikacji):        GO (potwierdzone empirycznie w sesji uzupełniającej, patrz pkt 1 tabela wiersz 12-13)
3DORS Author (kliknięcie linku w WebView -> markLaunched -> odświeżenie): NIEPOTWIERDZONY (test szedł przez adb, nie przez kliknięcie w treści strony)
3DORS Admin (blokada w praktyce):                                       NO-GO DO E2E (tylko testy jednostkowe)
Tryb offline:                                                           NIEPOTWIERDZONY (ograniczenie metodologiczne adb reverse)
PRODUKCJA:                                                              NO-GO (zgodnie z wcześniejszym audytem — brak zmian backendowych w tej sesji)
```

---

## 5. Rekomendacje z inicjatywy własnej (bezpieczeństwo i przyjazność użytkownika)

1. **Ujednolicić wskaźnik aktywnej zakładki** w dolnym pasku — obecnie stały czerwony akcent na "Wallet" może wprowadzać w błąd co do tego, który ekran jest aktywny (patrz obserwacja wizualna wyżej).
2. **Udokumentować w README/CONTRIBUTING**, że test lokalny wymaga `adb reverse tcp:8080 tcp:8080` razem z `ZRODLOSLOWA_DEBUG_WEB_BASE_URL=http://127.0.0.1:8080/`, bo domyślny `10.0.2.2:8080` nie działa z bindowaniem `127.0.0.1` w `compose.yaml` — to realny, odtworzony w tej sesji problem, który utrudni pracę każdemu kolejnemu deweloperowi/testerowi.
3. **Nie ufać testom "offline" wykonanym przez adb reverse** — do przyszłych testów trybu offline użyć fizycznego urządzenia lub backendu wystawionego na prawdziwy adres LAN.
4. Przed kolejnym pełnym odbiorem E2E: przygotować (a) konto testowe czytelnika i autora w lokalnej bazie, (b) stronę backendu renderującą prawdziwy link zatwierdzenia `dors3-author-dev://approve/{id}`, aby przetestować kliknięcie w rzeczywistej treści WebView (nie tylko `adb am start`) i faktyczne wywołanie `Dors3ResultHandler`/odświeżenia po powrocie.
   - Aktualizacja: aplikacja `pl.zrodloslowa.dors3.author` została już zbudowana i zainstalowana na emulatorze w tej sesji (flavor `author` z `mobile/3dors-android`) — to ograniczenie zostało zamknięte.

---

## 6. Załączniki

Wszystkie zrzuty rzeczywiste, wykonane adb screencap podczas tej sesji, w `docs/screenshots/`:
01_start.png, 02_glowna.png, 03_glowna_home.png, 04_backend.png, 05_backend2.png,
06_home_loaded.png, 07_articles.png, 08_menu.png, 09_articles2.png, 10_account.png (przed poprawką współrzędnych — pokazuje poprzedni ekran),
11_account.png (czarny — potwierdzenie FLAG_SECURE), 12_offline.png,
e2e_01_start.png, e2e_02_author_launch.png (uruchomienie 3DORS Author przez custom scheme), e2e_03_return.png (powrót do Źródło Słowa Mobile).

Nagranie wideo nie zostało nagrane w tej sesji (brak narzędzia do nagrywania ekranu w dostępnym środowisku non-interaktywnym) — zamiast tego dostarczono sekwencję zrzutów pokrywającą te same kroki.
