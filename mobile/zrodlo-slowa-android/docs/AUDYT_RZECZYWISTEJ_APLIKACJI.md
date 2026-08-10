# AUDYT RZECZYWISTEJ APLIKACJI — ŹRÓDŁO SŁOWA MOBILE

Data: 2026-08-04
Zakres audytu: `mobile/zrodlo-slowa-android` (pakiet `pl.zrodloslowa.app`).
Metoda: **wyłącznie działający kod** — build `assembleDebug`, instalacja i uruchomienie na prawdziwym emulatorze Android (API z `targetSdk = 37`), realne zrzuty ekranów (`adb screencap`), lektura kodu źródłowego w miejscach, których nie dało się dotrzeć zrzutem (brak konta testowego z hasłem).

Backend, serwis WWW i 3DORS **nie zostały zmienione** — audyt jest czysto obserwacyjny. Jedyna ingerencja wykonana wyłącznie na potrzeby audytu: `adb shell am compat disable RESTRICT_LOCAL_NETWORK` (nowe ograniczenie systemowe Androida blokujące `10.0.2.2`) oraz przekierowanie deweloperskie `adb reverse tcp:8080 tcp:8080`, aby aplikacja mogła w ogóle połączyć się z lokalnym serwerem WWW na potrzeby testu — to ustawienie środowiska testowego, nie zmiana aplikacji ani backendu.

---

## 1. METODOLOGIA

1. Zbudowano `app-debug.apk` (`gradlew assembleDebug`) i zainstalowano na emulatorze.
2. Uruchomiono aplikację, wykonano nawigację po wszystkich 5 zakładkach stałego dolnego menu.
3. Wykonano rzeczywiste zrzuty ekranu (`adb exec-out screencap`) — załączone w `docs/screenshots/`.
4. Zbadano kod źródłowy ekranów niedostępnych bez konta testowego (Portfel po zalogowaniu, Panel autora, wywołanie 3DORS Author) — oceniono na podstawie działającej logiki, nie makiet.
5. Sprawdzono zgodność wizualną z listą wymagań z dyspozycji.

**Ograniczenie audytu**: w środowisku testowym nie było dostępnego konta z ustawionym hasłem (backend uwierzytelnia po stronie serwera), więc **stan Portfela/Konta/3DORS Author po zalogowaniu** nie mógł zostać zweryfikowany zrzutem ekranu — oceniono na podstawie kodu (patrz pkt 4 i 6).

---

## 2. ZRZUTY EKRANÓW

| # | Plik | Ekran |
|---|---|---|
| 1 | `docs/screenshots/01_glowna_audyt.png` | Główna (WebView, treść realna) |
| 2 | `docs/screenshots/02_artykuly.png` | Artykuły (WebView, treść realna) |
| 3 | `docs/screenshots/03_logowanie.png` | Logowanie (WebView `/login`, otwarte z Portfela) |
| 4 | `docs/screenshots/04_powiadomienia.png` | Powiadomienia → `AuthGate` → Logowanie (niezalogowany) |
| 5 | `docs/screenshots/05_konto.png` | Konto → `AuthGate` → Logowanie (niezalogowany) |
| 6 | `docs/screenshots/06_home_top.png` | Główna — weryfikacja górnego paska/przełącznika języka |
| 7 | `docs/screenshots/07_offline_blad.png` | Symulacja błędu sieci (`ERR_CONNECTION_REFUSED`) |
| 8 | `docs/screenshots/08_launcher_icon.png` | Ikona aplikacji na pulpicie systemowym |

---

## 3. DZIAŁA

- **Nawigacja i stałe dolne menu** — 5 zakładek (Główna, Artykuły, Portfel, Powiadomienia, Konto), przełączanie działa płynnie, podświetlenie aktywnej zakładki poprawne, stan zachowywany przy powrocie (`saveState`/`restoreState`).
- **Ekran Główna i Artykuły** — realnie ładują treść z serwisu WWW (WebView), widoczne prawdziwe artykuły, nagłówki, kurs TT, przyciski `PISZ`/`PUBLIKUJ`/`ZARABIAJ`.
- **Bramka logowania (`AuthGate`)** — Portfel, Powiadomienia i Konto poprawnie wymagają sesji i pokazują prawdziwy ekran `/login` serwisu, gdy użytkownik nie jest zalogowany; wykrywanie zalogowania oparte wyłącznie na opuszczeniu ścieżki `/login` (brak prób odczytu/łamania cookie po stronie natywnej) — zgodne z zasadą „serwer decyduje o autoryzacji”.
- **Rola czytelnik/autor** — w kodzie (`AccountScreen.kt`) potwierdzono zero natywnej logiki uprawnień: link do Panelu autora renderuje wyłącznie backend dla kont z `can_write=1`. Zgodne z wymogiem „role zgodnie z backendem”.
- **3DORS Author — wyłącznie otwieranie, bez podpisywania** — `Dors3AuthorLauncher.kt` potwierdza, że moduł jedynie rozpoznaje kształt linku `/3dors/approve/{id}` wygenerowanego przez backend i go otwiera (App Link do zainstalowanej aplikacji 3DORS Author lub przeglądarka); brak w module jakiegokolwiek kodu podpisu, klucza czy fingerprintu 3DORS.
- **Brak tarczy 3DORS w treści aplikacji** i **brak panelu administratora** — nie znaleziono żadnego ekranu/odnośnika do panelu admina ani logo/tarczy 3DORS w kodzie modułu `zrodlo-slowa-android`.
- **Dark mode** — aplikacja działa w trybie ciemnym „na sztywno” (paleta `ZrodloSlowaTheme.kt`), tło `#0B0B0B`, zgodnie z wymogiem.
- **Firmowa czerwień** — kolor `#B90012` konsekwentnie użyty jako `primary` w całej natywnej powłoce (dolne menu, przycisk Portfela, akcenty) — zgodny z barwą widoczną też w treści WWW.
- **Prostokątne dolne menu + uniesiona czerwona linia przy Portfelu** — potwierdzone wizualnie na wszystkich zrzutach: czarny prostokątny pasek, okrągły czerwony przycisk Portfela na środku z uniesioną czerwoną linią.
- **Portfel jako osobny ekran** — potwierdzone w `ZrodloSlowaNavHost.kt`: własna trasa `AppDestination.WALLET`, oddzielna od Konta.
- **Kolorowe zdjęcia** — częściowo: zdjęcie w sekcji promo na Głównej wyświetla się poprawnie (kolorowe tło zdjęciowe za tekstem), **ale patrz pkt 5** (miniatura artykułu = placeholder).

---

## 4. NIE DZIAŁA

- **Natywny przełącznik języka (`LanguageSwitcherBar`)** — w praktyce **niewidoczny i niedostępny** na uruchomionej aplikacji (zweryfikowano zrzutem i wycinkiem pikseli — patrz `06_home_top.png`/`crop_top.png`). Przyczyna zidentyfikowana w kodzie: `targetSdk = 37` wymusza tryb edge-to-edge, a pasek (`Row` w `LanguageSwitcherBar.kt`) nie stosuje żadnego marginesu na pasek statusu (`WindowInsets`), więc jest rysowany pod paskiem statusu systemu i pozostaje niewidoczny/nieklikalny. Efekt: użytkownik może zmieniać język **wyłącznie** przez wewnętrzny przełącznik strony WWW (który działa), natywne etykiety menu i tytuł ekranu logowania pozostają nie zsynchronizowane (patrz pkt 5).
- **Dedykowany ekran Offline/Błąd** — nie istnieje w bieżącym kodzie (`app/src/main/java` nie zawiera żadnego pakietu/pliku `offline`). Dokumentacja projektu (`docs/BEZPIECZENSTWO_WEBVIEW.md`) wspomina o „ekranie offline — placeholderze z ETAPU 1”, którego **nie znaleziono w rzeczywistym kodzie**. W praktyce błąd sieci pokazuje surowy, niezbrandowany komunikat Chromium/WebView („Webpage not available”, domyślna ikona zepsutego Androida) — patrz `07_offline_blad.png`.

---

## 5. ATRAPA / PLACEHOLDER

- **Miniatury zdjęć artykułów** — na ekranie Artykuły miniatura obok tekstu to szary prostokąt z napisem „Raport / miniatura” zamiast prawdziwego kolorowego zdjęcia (patrz `02_artykuly.png`). Może to wynikać z braku danych na lokalnym serwerze testowym, ale sam komponent renderujący miniaturę **nie ma logiki ładowania obrazu** — wymaga sprawdzenia na środowisku z pełnymi danymi produkcyjnymi, aby wykluczyć, że to stały placeholder w kodzie aplikacji, a nie tylko brak danych testowych.
- **Powiadomienia natywne** — kod (`NotificationsScreen.kt`) jest w pełni zaimplementowaną, działającą listą opartą o `GET /api/earnings/notifications`, ale **nie mogła zostać zweryfikowana z realnymi danymi** (wymaga zalogowanego konta) — brak dowodu z prawdziwego zrzutu z listą powiadomień; oceniono wyłącznie na podstawie kodu.

---

## 6. NIESPÓJNOŚĆ WIZUALNA

- **Mieszanie języków w interfejsie** — natywne etykiety dolnego menu (`Home`, `Articles`, `Wallet`, `Notification`, `Account`) oraz tytuł ekranu logowania („Sign in — SOURCE OF WORD”) wyświetlają się **po angielsku**, mimo że treść WebView poprawnie ładuje wersję **polską** (`PL`, „Zaloguj się”, „Najnowsze teksty” itd.). Przyczyna: natywne stringi Compose podążają za językiem **systemu** urządzenia, a nie za wyborem w `AppLanguageManager`/WebView — bezpośredni skutek niedziałającego przełącznika z pkt 4. Efekt końcowy: użytkownik widzi jednocześnie dwa różne języki na jednym ekranie.
- **Ikona aplikacji** — czerwony kształt zbliżony do tarczy/zakładki (`ic_launcher_foreground.xml`, `#B90012`, bez tekstu) zamiast pełnego, oficjalnego logo „Źródło Słowa” (z tekstem), jakie widać w nagłówku WebView. Do potwierdzenia z zespołem brandingowym, czy to zamierzony skrócony wariant, czy niedokończony etap.
- **Miniatura artykułu jako szary placeholder** zamiast kolorowego zdjęcia (patrz pkt 5) — bezpośrednio narusza wymóg „kolorowe zdjęcia”.

---

## 7. WYMAGA POPRAWY

1. Naprawić `LanguageSwitcherBar`, aby uwzględniał `WindowInsets.statusBars` (edge-to-edge, `targetSdk = 37`) — obecnie pasek jest niedostępny dla użytkownika.
2. Zsynchronizować język natywnych stringów Compose (dolne menu, tytuł logowania, powiadomienia) z wyborem języka aplikacji/WWW, zamiast polegać wyłącznie na locale systemowym.
3. Zaimplementować dedykowany, brandowany ekran Offline/Błąd (`SecureWebView.onReceivedError`) zamiast pozostawiać domyślny ekran Chromium.
4. Zweryfikować na środowisku z pełnymi danymi produkcyjnymi, czy miniatury artykułów faktycznie ładują zdjęcia (czy placeholder to tylko brak danych testowych, czy realny brak implementacji).
5. Potwierdzić z właścicielem marki zgodność ikony aplikacji z oficjalnym, pełnym logo „Źródło Słowa”.
6. Uzupełnić audyt o realny test z kontem testowym (Portfel po zalogowaniu, Panel autora, faktyczne wywołanie 3DORS Author i powrót) — obecnie oceniono wyłącznie na podstawie kodu.

---

## 8. GO / NO-GO

**NO-GO** (warunkowe) do czasu poprawy punktów z sekcji 7.

Uzasadnienie:
- Rdzeń architektury jest solidny i zgodny z dyspozycją: WebView jako źródło prawdy dla treści i uprawnień, natywna bramka sesji, natywne powiadomienia z prawdziwego API, integracja 3DORS Author ograniczona wyłącznie do otwierania linku — **żadnego makietowania kluczowej logiki**.
- Jednak wykryto **realny, reprodukowalny błąd funkcjonalny** (niedziałający natywny przełącznik języka na skutek zmiany domyślnego zachowania Androida `targetSdk 37`) oraz **wynikającą z niego niespójność wizualną** (mieszanie PL/EN na jednym ekranie) i **brak brandowanego ekranu błędu**, co bezpośrednio narusza punkty kontrolne z dyspozycji („PL / EN”, „Offline / Błąd”).
- Po usunięciu punktów z sekcji 7 (w szczególności pkt 1–3) aplikacja kwalifikuje się do ponownej, szybkiej weryfikacji i prawdopodobnego GO.

---

## 9. LISTA PROBLEMÓW (skrót)

| Priorytet | Problem | Plik |
|---|---|---|
| Wysoki | Niedziałający natywny przełącznik języka (ukryty pod paskiem statusu) | `ui/language/LanguageSwitcherBar.kt` |
| Wysoki | Mieszanie języków PL/EN w jednym widoku | `ui/language/LanguageSwitcherBar.kt`, `MainActivity.kt` |
| Średni | Brak brandowanego ekranu Offline/Błąd | `webview/SecureWebView.kt` (brak `onReceivedError`) |
| Średni | Miniatura artykułu jako placeholder | ekran Artykuły / dane testowe |
| Niski | Ikona aplikacji bez pełnego logo tekstowego | `res/drawable/ic_launcher_foreground.xml` |
| Informacyjny | Brak możliwości pełnej weryfikacji Portfela/Konta/3DORS po zalogowaniu (brak konta testowego) | n/d |

---

**Audyt zakończony. Zgodnie z dyspozycją — zatrzymuję się i czekam na akceptację przed dalszymi działaniami.**
