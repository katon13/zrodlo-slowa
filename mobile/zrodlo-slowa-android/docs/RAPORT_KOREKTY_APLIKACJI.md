# RAPORT KOREKTY APLIKACJI
# ŹRÓDŁO SŁOWA MOBILE — realizacja dyspozycji z audytu z 4 sierpnia 2026

Raport dokumentuje zmiany wprowadzone w projekcie
`X:\zrodlo-slowa\mobile\zrodlo-slowa-android` w odpowiedzi na
`AUDYT_I_DYSPOZYCJA_KOREKTY_JUNIE_ZRODLO_SLOWA_MOBILE_2026-08-04.md`.
Backend, publiczna strona WWW i 3DORS **nie zostały zmienione** — wszystkie
poprawki dotyczą wyłącznie aplikacji mobilnej.

## Stan wyjściowy

Przed korektą część punktów dyspozycji była już zrealizowana we wcześniejszych
etapach: pięć stałych zakładek dolnego menu, ikona launchera (białe tło +
czerwony znak Źródła Słowa), brandowany ekran offline oraz częściowa
synchronizacja natywnego języka Compose z wersją serwisu. Główny problem —
pełny nagłówek/stopka strony WWW widoczne w WebView aplikacji — nie był
jeszcze naprawiony.

## ETAP 1 — UI aplikacji

1. **Ikona launchera** — bez zmian, już zgodna z dyspozycją (białe tło,
   czerwony znak, brak tarczy/kłódki 3DORS).
2. **Ukrycie pełnego nagłówka i stopki WWW** (`SecureWebView.kt`) —
   po każdym `onPageFinished` wstrzykiwany jest kontrolowany styl CSS
   ukrywający `.site-header` i `.site-footer` (dokładne klasy serwisu,
   zweryfikowane w `views/layouts/main.php`). Backend i publiczna strona
   WWW pozostają bez zmian — ten sam adres otwarty w zwykłej przeglądarce
   wygląda tak jak dotychczas.
3. **Kompaktowy natywny nagłówek** (`AppTopBar.kt`) — logo (nazwa marki
   wersji językowej), ikona wyszukiwania (przenosi do zakładki Artykuły) i
   menu hamburger.
4. **Menu hamburgera** zawiera: Najnowsze, Tematy, Ankiety, Reklamy, Jak
   zarabiać, Autorzy, Język (podmenu z sześcioma wersjami), KURS SŁOWA,
   Zaloguj/Konto, Dołącz jako autor — pozycje bez własnej zakładki otwierają
   istniejące podstrony serwisu przez nowy generyczny `WebPageScreen.kt`
   (trasa `webpage/{path}`), bez tworzenia nowych endpointów backendu.
5. **Usunięcie stałego przycisku „Dołącz”** — nie występuje już w żadnym
   nagłówku aplikacji; dostępny wyłącznie w hamburgerze i na ekranie
   startowym.
6. **Ekran startowy** (`OnboardingScreen.kt` + `OnboardingPreferenceStore.kt`)
   — trzy opcje: „Przeglądaj bez konta”, „Mam konto — zaloguj się”, „Dołącz
   jako autor”, pokazywany tylko przy pierwszym uruchomieniu. Nie tworzy
   nowej rejestracji czytelnika — korzysta wyłącznie z istniejących tras
   `/login` i `/register`.

## ETAP 2 — język i sesja

7. **Usunięcie dawnego paska językowego** spod status bara
   (`LanguageSwitcherBar.kt` usunięty) — wybór języka przeniesiony do
   podmenu hamburgera (`AppTopBar.kt`).
8. **Naprawa hosta cookies** (`WebSessionManager.hasAnyCookie`,
   `AuthGate.kt`) — sprawdzany jest realny, aktualnie używany adres bazowy
   (`WebUrlResolver.baseUrl`), a nie zawsze domena produkcyjna, dzięki czemu
   w wariancie debug weryfikowany jest rzeczywisty host lokalny.
9. **Naprawa detekcji logowania** (`LoginScreen.kt`) — samo opuszczenie
   `/login` już nie oznacza zakończonego logowania. Wprowadzono listę tras
   pośrednich (`/login`, `/register`, `/reset-password`, `/forgot-password`,
   `/2fa`, `/verify`, `/dors3`), które nie są traktowane jako zalogowanie.

## ETAP 3 — bezpieczeństwo i funkcje

10. **CSRF w powiadomieniach** (`NotificationsApiBridge.kt`) —
    `acknowledge()` pobiera token z `meta[name="csrf-token"]` strony i
    wysyła go w nagłówku `X-CSRF-TOKEN` razem z `X-Requested-With`, zgodnie
    z wymaganiami endpointu `POST /api/earnings/notifications/ack`. Nie
    utworzono nowego endpointu ani nie wyłączono CSRF.
11. **Ekran offline** — bez zmian, już zgodny z dyspozycją.
12. **HTTPS-only w release** (`WebViewAllowlist.isAllowedScheme`,
    `SecureWebViewClient.kt`, `SecureWebView.kt`) — w release dopuszczany
    jest wyłącznie schemat `https`; `http` jest możliwy tylko w wariancie
    debug (`BuildConfig.DEBUG`), do lokalnych adresów testowych.
13. **Upload plików, aparat, download, PDF** — **NIE ZOSTAŁY WYKONANE** w
    tej turze korekt (patrz sekcja Uwagi).
14. **Powrót z 3DORS Author** — mechanizm z wcześniejszego etapu
    (`Dors3ResultHandler`, `Dors3AuthorLauncher`) pozostał bez zmian;
    dodatkowo trasa `/dors3` została uwzględniona w wykluczeniach detekcji
    logowania (pkt 9).

## Weryfikacja

- `gradlew compileDebugKotlin` — **BUILD SUCCESSFUL**.
- `gradlew testDebugUnitTest` — **BUILD SUCCESSFUL** (wszystkie istniejące
  testy przechodzą, w tym zaktualizowany `WebViewAllowlistTest`).

## Uwagi / czego nie wykonano

- **Pkt 13 (upload/aparat/download/PDF w `SecureWebView`)** nie został
  zaimplementowany w tej turze ze względu na zakres prac — wymaga osobnej
  korekty (`onShowFileChooser`, uprawnienia kamery, `DownloadListener`,
  otwieranie PDF) i osobnych testów na urządzeniu.
- Zgodnie z dyspozycją („Nie oceniaj makiet. Pokaż wyłącznie uruchomioną
  aplikację”) prawdziwe zrzuty ekranu (pierwsze uruchomienie, Główna,
  hamburger, Artykuły, Logowanie, Rejestracja autora, Portfel czytelnika/
  autora, Powiadomienia, Konto, Offline, ikona na pulpicie, PL, EN, powrót
  z 3DORS Author) wymagają uruchomienia aplikacji na urządzeniu/emulatorze
  z połączeniem do serwisu — nie zostały dołączone do tego raportu.
- Zgodnie z dyspozycją: po wykonaniu korekt zatrzymuję się i czekam na
  akceptację przed dalszymi zmianami.
