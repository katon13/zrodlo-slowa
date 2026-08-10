# GŁÓWNY RAPORT KOREKT I ODBIORU — Źródło Słowa Mobile

**Data tego etapu:** 4 sierpnia 2026
**Zakres tego etapu:** wyłącznie sekcja 4 dyspozycji ("Korekty kodu Android"), na wyraźne polecenie: testy z kontami użytkowników (sekcja 6) zostały pominięte w tym etapie i pozostają do kolejnej sesji.

Ten plik będzie uzupełniany w kolejnych etapach o pozostałe punkty (sekcja 3 — korekty wizualne, sekcja 6 — pełne testy E2E z kontami, sekcja 7 — komplet dowodów).

---

## 1. Status punktów z sekcji 4 dyspozycji

| Punkt | Opis | Status |
|---|---|---|
| 4.1 | WebView nie może wracać do adresu początkowego (rekompozycja Compose nie resetuje historii/loginu) | **POTWIERDZONE KODEM** — już wcześniej zaimplementowane: `SecureWebView` używa `key(currentHost)` (nowa instancja WebView tylko przy zmianie hosta) oraz `update = { if (webView.url != url) ... }` (przeładowanie tylko przy realnej zmianie parametru wejściowego), a nie sztywnego porównania do `initialUrl`. Bez zmian w tym etapie. |
| 4.2 | FLAG_SECURE ma wynikać z potwierdzonego przez serwer stanu sesji (nie z cookie ani z heurystyki URL) | **POTWIERDZONE KODEM (doprecyzowanie tego etapu)** — usunięto wcześniejszą heurystykę słów kluczowych w URL (`WebViewSecurityState.isSensitiveUrl`/`currentUrl`). Nowy `SessionSecurityState` ma dwa jawne pola: `authFlowActive` (ustawiane przez `LoginScreen` na czas logowania/2FA/resetu hasła) i `sessionConfirmed` (ustawiane przez `AuthGate` wyłącznie na podstawie wyniku `WebSessionManager.verifySession` — realne żądanie HTTP do chronionej trasy, nigdy sama obecność cookie). `ZrodloSlowaNavHost` ustawia `FLAG_SECURE` na `SessionSecurityState.isProtectionActive = authFlowActive || sessionConfirmed`. Podczas ponownej weryfikacji wcześniej aktywnej sesji (ON_RESUME, `logoutEpoch`) ochrona NIE jest zdejmowana w trakcie oczekiwania na odpowiedź serwera — `sessionConfirmed` zmienia się dopiero po jawnym wyniku `verified`. Brak jakiegokolwiek callbacku JavaScript ze strony WWW. Pinning certyfikatu 3DORS Author (pkt 4.3) pozostaje bez zmian. **NIEPOTWIERDZONE NA EMULATORZE** — brak testu z rzeczywistym logowaniem/2FA i realną sesją (wymaga kont testowych — pominięte w tym etapie na polecenie). |
| 4.3 | 3DORS Author: pakiet, SHA-256 certyfikatu, osobny fingerprint debug/release, komunikat przy braku aplikacji, odświeżenie po powrocie; release nie buduje się bez fingerprintu | **POTWIERDZONE KODEM (tego etapu)** — dodano `Dors3AuthorLauncher.isAuthorAppSignatureTrusted()` (weryfikacja SHA-256 certyfikatu podpisującego zainstalowanej aplikacji `pl.zrodloslowa.dors3.author`, osobne wartości `DORS3_AUTHOR_CERT_SHA256_DEBUG`/`_RELEASE` w `BuildConfig`), wywoływana w `SecureWebView.openAuthorApp()` przed uruchomieniem — niezgodny podpis jest traktowany jak brak aplikacji (ten sam komunikat). `app/build.gradle.kts` blokuje `assembleRelease`/`bundleRelease` itd., dopóki `ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_RELEASE` nie zostanie jawnie ustawiony (zweryfikowano: `assembleRelease` kończy się błędem konfiguracji bez tej zmiennej, `assembleDebug` działa). Blokada 3DORS Admin i odświeżenie po powrocie (`Dors3ResultHandler`) — bez zmian, już wcześniej zaimplementowane. **NIEPOTWIERDZONE NA EMULATORZE w tym etapie** (wcześniejsza sesja potwierdziła empirycznie sam przepływ uruchomienia/powrotu na poziomie OS, patrz `RAPORT_ODBIORU_NA_EMULATORZE.md`; nowa logika podpisu nie została jeszcze przetestowana na żywym urządzeniu z prawdziwym certyfikatem release). |
| 4.4 | Aplikacja czytnicza nie pobiera/nie wysyła plików — usunięcie DownloadManager/FileProvider/aparatu/galerii, komunikat "dostępne w pełnym serwisie WWW" | **POTWIERDZONE KODEM I NA EMULATORZE** — usunięto z `SecureWebView.kt`: `DownloadManager`, `setDownloadListener`/`startDownload`, `onShowFileChooser` z aparatem/galerią/`FileProvider`/`camera_uploads`. Usunięto provider `FileProvider` z `AndroidManifest.xml` i plik `res/xml/file_paths.xml`. Próba uploadu w treści WWW pokazuje teraz komunikat `file_operation_web_only` ("Ta operacja jest dostępna w pełnym serwisie WWW") — dodany do wszystkich 6 wersji językowych (pl/en/de/es/fr/it). Zweryfikowano: kompilacja, wszystkie testy jednostkowe (`testDebugUnitTest`) przechodzą, APK debug zainstalowany i uruchomiony na emulatorze Pixel_9 bez crasha (brak wpisów FATAL/AndroidRuntime w logcat). |
| 4.5 | Powiadomienia: stan kluczowany domeną/użytkownikiem/generacją sesji, jeden fetch naraz, timeout, limit callbacków, polling tylko przy STARTED, bezpieczne WebSettings, jawny stan błędu | **NIEZWERYFIKOWANE w tym etapie** — nie przeanalizowano jeszcze `NotificationsApiBridge.kt`/`NotificationsScreen.kt` pod kątem tych szczegółowych wymagań. Do zrobienia w kolejnym etapie. |

---

## 2. Weryfikacja techniczna wykonana w tym etapie

- `gradlew compileDebugKotlin` — BUILD SUCCESSFUL (bez błędów, jedno wcześniej istniejące ostrzeżenie o przestarzałym konstruktorze `Locale`, niezwiązane z tym etapem).
- `gradlew testDebugUnitTest` — BUILD SUCCESSFUL, wszystkie istniejące testy jednostkowe przechodzą bez zmian w ich treści.
- `gradlew assembleDebug` — BUILD SUCCESSFUL, APK zainstalowany na emulatorze `Pixel_9` (`adb install -r`), aplikacja uruchomiona (`adb shell am start`), `adb logcat` przefiltrowany na `FATAL|AndroidRuntime` — pusty (brak crasha).
- `gradlew assembleRelease` bez `ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_RELEASE` — kończy się błędem konfiguracji (zgodnie z zamierzeniem pkt 4.3).

## 3. Czego jawnie NIE zrobiono w tym etapie (pozostaje do kolejnej sesji)

- Sekcja 3 (korekty wizualne: język, logo, intro, dolne menu, hamburger) — nieprzeglądnięta w tym etapie.
- Punkt 4.5 (powiadomienia) — nieprzeglądnięty w tym etapie.
- Sekcja 5 (braki backendu dla Codex) — nieprzygotowana w tym etapie.
- Sekcja 6 (pełne testy E2E z kontami czytelnika/autora/administratora) — **pominięta na wyraźne polecenie użytkownika** w tym etapie; w bazie danych backendu istnieje obecnie tylko jedno konto (`admin@100pl.pl`), brak przygotowanych kont czytelnika/autora.
- Sekcja 7 (komplet zrzutów i filmów) — w tym etapie wykonano wyłącznie zrzuty pomocnicze do weryfikacji braku crasha (`docs/screenshots/junie_*`), nie pełny zestaw wymagany w dyspozycji.

## 4. GO / NO-GO (tylko zakres tego etapu)

```text
4.1 WebView nie resetuje stanu:                       GO (bez zmian, już wcześniej poprawne)
4.2 FLAG_SECURE na realnym URL:                        GO / NO-GO DO E2E (kod gotowy, brak testu na żywym linku logowania)
4.3 3DORS Author — pinning certyfikatu:                GO / NO-GO DO E2E (kod gotowy i zweryfikowany budową, brak testu z realnym certyfikatem release)
4.4 Usunięcie logiki plików:                           GO (potwierdzone kodem i na emulatorze)
4.5 Powiadomienia:                                     NIEPOTWIERDZONY (nieprzeanalizowany w tym etapie)
Sekcja 3 (wizualne):                                   NIEPOTWIERDZONY (nieprzeanalizowana w tym etapie)
Sekcja 6 (konta/E2E):                                  POMINIĘTE na polecenie użytkownika w tym etapie
PRODUKCJA:                                             NO-GO (release nadal wymaga uzupełnienia fingerprintu/hostów produkcyjnych 3DORS oraz pełnych testów E2E)
```
