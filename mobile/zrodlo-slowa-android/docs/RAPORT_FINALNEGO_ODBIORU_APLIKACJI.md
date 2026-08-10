# RAPORT FINALNEGO ODBIORU APLIKACJI — Źródło Słowa Mobile

**Zakres:** ostatnia, zamknięta korekta Junie po `NIEZALEZNY_AUDYT_ZRODLO_SLOWA_MOBILE_2026-08-04_1515.md`.
**Projekt:** `mobile/zrodlo-slowa-android` (wyłącznie). Backend, serwis WWW i `mobile/3dors-android` — **bez zmian**.

## 1. Werdykt tej tury

```text
KOD: NAPRAWIONY WG ZAKRESU AUDYTU
BUILD: assembleDebug — BUILD SUCCESSFUL
TESTY JEDNOSTKOWE: ZIELONE
ODBIÓR WIZUALNY NA EMULATORZE/TELEFONIE: NIE WYKONANY W TEJ SESJI (patrz pkt 8 — Ograniczenia)
```

Ta tura obejmowała wyłącznie pracę na kodzie (Compose, WebView, Android lifecycle, konfiguracja
Gradle/manifestu) w środowisku bez dostępu do GUI/emulatora Android. Wszystkie poniższe punkty
zostały zweryfikowane przez kompilację (`compileDebugKotlin`, `assembleDebug`) i testy jednostkowe
JVM (`testDebugUnitTest`) — **nie przez rzeczywiste uruchomienie aplikacji**. Zgodnie z zasadami
rzetelności, punkt "Odbiór obowiązkowy" (nagrania z emulatora) NIE mógł zostać wykonany w tym
środowisku — patrz pkt 8.

## 2. Czołówka

| Naprawa | Opis |
| --- | --- |
| Wyłączone animacje pokazują puste tło | Naprawione. Przy wyłączonych animacjach systemowych progres nie jest już ustawiany na `1f` (co uruchamiało fazę `FADE_OUT` w pełni, rysując kryjące tło na całym ekranie), a na `IntroTiming.FADE_OUT.start` — logo, nazwa i motto są w pełni uformowane i widoczne statycznie, bez jakiegokolwiek zaciemnienia. Plik: `ui/intro/SourceIntroScreen.kt`. |
| Oficjalny znak i układ nazwy | Znak wektorowy (`buildSourceMarkPath`) pozostaje tą samą geometrią co `ic_launcher_foreground.xml`. Krój napisu zmieniony z `Typeface.DEFAULT` (zależny od producenta/skina) na `Typeface.SANS_SERIF BOLD` (stabilny na wszystkich urządzeniach) — udokumentowana decyzja tymczasowa do czasu dostarczenia oficjalnego pliku fontu przez dział graficzny. |
| Nazwa marki wg wersji językowej | **Decyzja:** czołówka NIE ma jednej stałej nazwy wpisanej na trwałe. Pokazuje `SiteConfig.siteForLanguage(...).brandName` dla efektywnie wybranego języka (`AppLanguageManager.resolveEffectiveLanguageCode`), tak samo jak `AppTopBar` i cała reszta powłoki. To pozostaje jedna marka koncepcyjnie ("Źródło Słowa"), z lokalnym wordmarkiem — konsekwentnie z tym, jak `SiteConfig` już traktuje markę w innych ekranach. Nowa funkcja `splitWordmark()` dzieli nazwę na maks. dwie linie bez zakładania konkretnego języka. |
| Systemowy splash bez czarnego mignięcia | Dodano `androidx.core:core-splashscreen`. Nowy motyw `Theme.ZrodloSlowa.Splash` (tło `@color/zs_intro_cream` — to samo co tło czołówki, zamiast czarnego `windowBackground`), `installSplashScreen()` wywoływany w `MainActivity.onCreate()` przed `super.onCreate()`. |
| Czołówka tylko przy zimnym starcie | Bez zmian funkcjonalnych — `IntroLaunchGate` ustawia flagę raz na cały proces; `MainActivity` nie jest tworzona na nowo przy powrocie z tła/3DORS Author. |
| Nagranie z emulatora | **Nie wykonane w tej sesji** — patrz pkt 8. |

## 3. Sesja i logowanie

| Naprawa | Opis |
| --- | --- |
| Dowolne cookie ≠ zalogowanie | `WebSessionManager.hasAnyCookie()` już nie jest źródłem decyzji w `AuthGate`. Nowa funkcja `WebSessionManager.verifySession(baseUrl, protectedPath = "account/settings", onResult)` wykonuje rzeczywiste żądanie HTTP (bez śledzenia przekierowań) do chronionej trasy z aktualnymi cookies i uznaje zalogowanie tylko, gdy serwer odpowiedział 2xx i NIE przekierował do `/login`. |
| `AuthGate` używa realnej trasy | Przebudowany: stan `loggedIn: Boolean?` (null = w trakcie weryfikacji, pokazuje `CircularProgressIndicator`), weryfikacja przez `verifySession` w `LaunchedEffect`. |
| Zbyt szerokie wykrywanie końca logowania | `LoginScreen` już nie uznaje każdej strony spoza tras logowania za sukces. Po opuszczeniu tras pośrednich (`/login`, `/register`, `/2fa`...) wykonywana jest dodatkowa weryfikacja `verifySession` — `onLoggedIn()` wywoływany tylko po jej potwierdzeniu. |
| Wylogowanie: cookies + WebStorage + cache + stan | **Domknięte w tej turze.** `WebSessionManager.clearAllWebViewState(webView, onCleared)` był zdefiniowany, ale nigdzie nie był wywoływany — realne kliknięcie „Wyloguj” na stronie WWW nie czyściło niczego i nie resetowało `AuthGate`. Naprawione: `SecureWebView` wykrywa nawigację do rzeczywistej trasy `POST /logout` (`isLogoutNavigationUrl`, rozpoznaje `/logout` niezależnie od domeny/query/fragmentu), po jej zakończeniu wywołuje `clearAllWebViewState` (cookies, `WebStorage`, cache, dane formularzy, historia) oraz nowy callback `onLogoutDetected`. `AuthGate` przyjmuje teraz `content: @Composable (onLogoutDetected: () -> Unit) -> Unit` i po tym sygnale natychmiast resetuje stan na niezalogowany (`loggedIn = false`), pokazując ponownie `LoginScreen` — naprawa punktu audytu "stan ekranów po wylogowaniu". Podłączone w `AccountScreen` i `WalletScreen` (jedyne ekrany z osadzonym WebView pod `AuthGate`). |
| Hasło / 2FA / reset / OAuth | **Nie przetestowane fizycznie w tej sesji** (brak dostępu do emulatora/telefonu i internetu w środowisku pracy). Logika po stronie aplikacji pozostaje niezmienna funkcjonalnie (100% delegowana do serwera przez WebView) — zgodnie z architekturą audytu. |
| OAuth w powłoce | **Oznaczone jako ograniczenie**, zgodnie z zaleceniem audytu: link OAuth jest otwierany w zewnętrznej przeglądarce (poza allowlistą WebView), a cookies przeglądarki nie są automatycznie współdzielone z `CookieManager` WebView. Nie deklarujemy OAuth jako "działającego" bez fizycznego testu. |

## 4. WebView i praca autora

| Naprawa | Opis |
| --- | --- |
| Przycisk Wstecz w historii WebView | `SecureWebView` rejestruje `BackHandler(enabled = canGoBackInWebView)`, wywołujący `webView.goBack()`. Stan `canGoBackInWebView` aktualizowany w `onPageStarted`/`onPageFinished`. |
| Wybór pliku / aparat / upload | `WebChromeClient.onShowFileChooser` — chooser łączący galerię (`ACTION_GET_CONTENT`) i aparat (`ACTION_IMAGE_CAPTURE` z wyjściem przez `FileProvider`, katalog cache `camera_uploads/`). Dodano `FileProvider` w `AndroidManifest.xml` + `res/xml/file_paths.xml`, uprawnienie `CAMERA` i `uses-feature` (opcjonalne). |
| Download / PDF | `WebView.setDownloadListener` → `DownloadManager` z cookies sesji i `User-Agent`, zapis do `Environment.DIRECTORY_DOWNLOADS`, otwarcie domyślną aplikacją systemową (w tym PDF-viewerem) po zakończeniu. |
| Ukryty nagłówek/stopka bez mignięcia | Gdy urządzenie wspiera `WebViewFeature.DOCUMENT_START_SCRIPT` (androidx.webkit), CSS ukrywający `.site-header`/`.site-footer` jest wstrzykiwany przez `WebViewCompat.addDocumentStartJavaScript` — PRZED jakimkolwiek skryptem strony, a nie tylko po `onPageFinished`. Dotychczasowe ukrywanie po `onPageFinished` pozostaje jako uzupełnienie dla starszych WebView. |
| TLS i HTTPS-only w release | Bez zmian — `SecureWebViewClient.onReceivedSslError` nigdy nie wywołuje `proceed()`; `WebViewAllowlist.isAllowedScheme` dopuszcza `http` wyłącznie w `BuildConfig.DEBUG`. |

## 5. 3DORS Author

| Naprawa | Opis |
| --- | --- |
| Allowlista hosta Author | `Dors3AuthorLauncher.isApprovalLink` wymaga teraz, aby host zaczynał się od `author-3dors.` (backend: `.env.example` → `DORS3_AUTHOR_APP_LINK_BASE_URL=https://author-3dors.*`), a nie każdego hosta HTTPS o kształcie ścieżki `/3dors/approve/{id}`. |
| Nigdy nie otwieraj Admin | Nowa funkcja `isAdminLink()` rozpoznaje host `admin-3dors.*` (backend: `DORS3_ADMIN_APP_LINK_BASE_URL`). `SecureWebView` jawnie blokuje taki link (Toast informacyjny), zamiast przekazywać go do `openExternally`. |
| Komunikat gdy Author niezainstalowany | `openExternally(..., onNotInstalled)` — przy `ActivityNotFoundException` wywołuje `onNotInstalled`, który w `SecureWebView` pokazuje `Toast` (`R.string.dors3_author_not_installed`) we wszystkich 6 językach. |
| Odświeżenie po powrocie | Bez zmian — `Dors3ResultHandler` + `webViewRef?.reload()` już to realizowały. |

## 6. Języki i UI

| Naprawa | Opis |
| --- | --- |
| Dolne menu bez przebudowy | Nie zmienione. |
| Czerwony znak w natywnym nagłówku | `AppTopBar` — dodano `Icon(painterResource(R.drawable.ic_source_mark_red))` przed nazwą marki (nowy zasób `ic_source_mark_red.xml`, ta sama geometria co `ic_launcher_foreground.xml`). |
| Nakładające się menu językowe | Naprawione: kliknięcie "Zmień język" teraz zamyka główne menu (`menuExpanded = false`) przed otwarciem podmenu — usunięto nakładanie dwóch `DropdownMenu`. |
| Opcja "Automatycznie" | Dodana pozycja na początku podmenu językowego → `LanguagePreferenceStore.clearManualLanguage()` + `AppLanguageManager.resolveLanguageCode()` (powrót do języka systemowego). Nowy string `action_language_automatic` w 6 językach. |
| Kompletne teksty DE/FR/IT/ES | Wszystkie 4 języki uzupełnione z 16 do pełnych 33 natywnych tekstów (menu, wyszukiwanie, onboarding, motto czołówki, nowe stringi 3DORS) — bez mieszania języków. |
| Karta Portfela na Głównej | `HomeScreen` renderuje wyłącznie `SecureWebView` wskazujący stronę główną serwisu — nie zawiera żadnej natywnej karty Portfela. (Zawartość samej strony WWW to backend/serwis — poza zakresem tej korekty). |

## 7. Powiadomienia

| Naprawa | Opis |
| --- | --- |
| Konflikt równoległych callbacków | `NotificationsApiBridge` — zamiast jednego `pendingCallback` nadpisywanego przez kolejne żądania, każde wywołanie (`fetchNotifications`, `acknowledge`) ma unikalny `callId` (`AtomicInteger`), a wyniki trafiają do właściwego callbacku przez mapę `pendingCallbacks`. JS przekazuje `callId` z powrotem do `AndroidBridge.onResult(callId, json)`. |
| Ta sama kontrola hostów/TLS co główny WebView | Ukryty `WebView` powiadomień używa teraz `SecureWebViewClient` (allowlista hostów + blokada błędów TLS) zamiast anonimowego `WebViewClient()`. |
| Test pobrania/ack na realnym koncie | **Nie wykonany w tej sesji** — wymaga rzeczywistego backendu i konta (poza zakresem środowiska pracy). |

## 8. Ograniczenia tej tury (WAŻNE)

Środowisko, w którym pracowała Junie w tej sesji, **nie miało dostępu do**:
- emulatora Android / fizycznego telefonu z wyświetlaczem,
- internetu / rzeczywistego backendu produkcyjnego,
- działającej aplikacji 3DORS Author do testu powrotu,
- konta użytkownika do testu logowania/2FA/OAuth na żywo.

W związku z tym **żaden z poniższych dowodów z listy "Odbiór obowiązkowy" nie został dostarczony
w tej sesji**: film czołówki, onboarding, Główna, hamburger, Artykuły, Portfel czytelnika/autora,
Powiadomienia, Konto, upload z aparatu, download/PDF, PL/EN + dodatkowy język, logowanie + 2FA,
powrót z 3DORS Author, offline, ikona na pulpicie.

Zweryfikowano wyłącznie na poziomie kodu i budowania:
- `gradlew compileDebugKotlin` — sukces,
- `gradlew testDebugUnitTest` — wszystkie testy zielone,
- `gradlew assembleDebug` — APK budowany bez błędów.

**Rekomendacja:** przed przekazaniem do Codex / pilota konieczny jest jeden krótki cykl
rzeczywistego uruchomienia (emulator lub telefon) z nagraniem wymienionych powyżej ekranów —
kod jest gotowy do tego kroku, ale sam kod nie zastępuje wizualnego i funkcjonalnego odbioru.

## 9. Nowe/zmienione testy jednostkowe

- `ui/intro/IntroTimingTest.kt` — regresja błędu "wyłączone animacje pokazują puste tło".
- `ui/intro/SourceIntroScreenWordmarkTest.kt` — podział nazwy marki na linie wordmarku (PL/EN/FR/DE).
- `dors3/Dors3AuthorLauncherTest.kt` — rozszerzony o allowlistę hosta Author i blokadę Admin.
- `webview/SecureWebViewLogoutTest.kt` — **nowy**, regresja błędu "wylogowanie nie czyści stanu
  WebView": rozpoznawanie rzeczywistej trasy `POST /logout` (wszystkie domeny, z/bez ukośnika,
  z query/fragmentem) i odrzucanie tras jedynie zawierających "logout" jako fragment nazwy.
- Istniejące testy (`WebViewAllowlistTest`, `AppLanguageManagerTest`, `SiteConfigTest`,
  `AppDestinationTest`, `WebUrlResolverTest`) — bez zmian, wciąż zielone.

**Nie dodano** (wymagają Robolectric/instrumentacji, niedostępnych w tym środowisku offline):
testów `AuthGate`/`LoginScreen` (zależność od `HttpURLConnection` i Compose runtime), testu
`SecureWebView` back-handlera na żywym WebView, testu `NotificationsApiBridge` na żywym WebView.
Logika czysta (host allowlist, timing, podział nazwy marki) jest przetestowana; integracja z
faktycznym silnikiem WebView wymaga uruchomienia na urządzeniu/emulatorze (patrz pkt 8).

## 10. Zmienione/nowe pliki (ta tura)

```text
app/src/main/java/pl/zrodloslowa/app/MainActivity.kt
app/src/main/java/pl/zrodloslowa/app/config/LanguagePreferenceStore.kt
app/src/main/java/pl/zrodloslowa/app/dors3/Dors3AuthorLauncher.kt
app/src/main/java/pl/zrodloslowa/app/notifications/NotificationsApiBridge.kt
app/src/main/java/pl/zrodloslowa/app/session/WebSessionManager.kt
app/src/main/java/pl/zrodloslowa/app/ui/account/AccountScreen.kt
app/src/main/java/pl/zrodloslowa/app/ui/auth/AuthGate.kt
app/src/main/java/pl/zrodloslowa/app/ui/auth/LoginScreen.kt
app/src/main/java/pl/zrodloslowa/app/ui/wallet/WalletScreen.kt
app/src/main/java/pl/zrodloslowa/app/ui/intro/SourceIntroScreen.kt
app/src/main/java/pl/zrodloslowa/app/ui/intro/SourceLogoAnimation.kt
app/src/main/java/pl/zrodloslowa/app/ui/navigation/AppTopBar.kt
app/src/main/java/pl/zrodloslowa/app/ui/navigation/ZrodloSlowaNavHost.kt
app/src/main/java/pl/zrodloslowa/app/webview/SecureWebView.kt
app/src/main/java/pl/zrodloslowa/app/webview/SecureWebViewClient.kt
app/src/main/res/drawable/ic_source_mark_red.xml
app/src/main/res/values*/strings.xml (6 wersji językowych)
app/src/main/res/values/colors.xml
app/src/main/res/values/themes.xml
app/src/main/res/xml/file_paths.xml
app/src/main/AndroidManifest.xml
gradle/libs.versions.toml
app/build.gradle.kts
app/src/test/java/pl/zrodloslowa/app/dors3/Dors3AuthorLauncherTest.kt
app/src/test/java/pl/zrodloslowa/app/ui/intro/IntroTimingTest.kt
app/src/test/java/pl/zrodloslowa/app/ui/intro/SourceIntroScreenWordmarkTest.kt
app/src/test/java/pl/zrodloslowa/app/webview/SecureWebViewLogoutTest.kt
```

## 11. Domknięcie kolejnej tury (ta sesja)

Po werdykcie z pkt 1 zweryfikowano ponownie kod pod kątem błędów implementacyjnych wskazanych w
audycie (nie tylko punktów już oznaczonych jako "gotowe"). Znaleziono i naprawiono realną lukę:

- **`clearAllWebViewState` był martwym kodem.** Funkcja czyszcząca cookies/WebStorage/cache przy
  wylogowaniu była w pełni zaimplementowana, ale nie była wywoływana z żadnego miejsca w
  aplikacji — kliknięcie "Wyloguj" na stronie WWW (formularz `POST /logout` z
  `views/layouts/main.php`) nie czyściło niczego, a `AuthGate` nadal pokazywał zalogowaną treść.
  Naprawione przez wykrywanie nawigacji do `/logout` w `SecureWebView` i podłączenie pełnego
  czyszczenia + reset stanu ekranu — patrz pkt 3 tabeli wyżej i nowy test
  `SecureWebViewLogoutTest`.

Po tej poprawce ponownie wykonano `compileDebugKotlin`, `testDebugUnitTest` i `assembleDebug` —
wszystkie zakończone sukcesem. Ograniczenia z pkt 8 (brak emulatora/telefonu/internetu w tej
sesji) pozostają aktualne — fizyczny test klikania "Wyloguj" na żywym koncie nadal wymaga
rzeczywistego uruchomienia aplikacji.

## 12. Niezależny audyt bezpieczeństwa + `DYSPOZYCJA_NAPRAWCZA_CODEX_ZRODLO_SLOWA_MOBILE_PRZED_PILOTEM.md` (ta sesja)

Na żądanie użytkownika wykonano **własny, wielowymiarowy audyt kodu** (architektura, jakość
implementacji, bezpieczeństwo, role/uprawnienia, obejścia zabezpieczeń, logika sesji, WebView,
przechowywanie danych, integracja 3DORS, bezpieczeństwo plików/urządzenia, rzeczywiste przepływy
użytkownika) — inspirowany dostarczoną `DYSPOZYCJA_NAPRAWCZA_CODEX_ZRODLO_SLOWA_MOBILE_PRZED_PILOTEM.md`
— analizując wyłącznie rzeczywisty kod, bez zakładania poprawności na podstawie wcześniejszych
raportów. Znaleziono i naprawiono następujące, potwierdzone w kodzie luki i błędy:

| # | Luka / błąd (potwierdzony w kodzie) | Naprawa |
| --- | --- | --- |
| 1 | **Obejście allowlisty hosta 3DORS Author/Admin przez prefiks.** `Dors3AuthorLauncher` sprawdzał `host.startsWith("author-3dors.")` — host phishingowy `author-3dors.attacker.example` był błędnie rozpoznawany jako prawdziwy link Author (analogicznie dla Admin). | Dokładne dopasowanie do zamkniętej listy hostów (`AUTHOR_HOSTS`/`ADMIN_HOSTS`, konfigurowalne przez `BuildConfig.DORS3_AUTHOR_HOSTS`/`DORS3_ADMIN_HOSTS`, patrz `app/build.gradle.kts`). **Uwaga:** rzeczywisty host produkcyjny jest ustawiany w `.env` backendu per środowisko (`DORS3_AUTHOR_APP_LINK_BASE_URL`; w `.env.production.example` widnieje jawne `CHANGE_ME`) — nie da się go potwierdzić z tego repozytorium; domyślne wartości pokrywają `dors3-author-dev`/`dors3-admin-dev` wskazane wprost w dyspozycji. Testy: `Dors3AuthorLauncherTest` (nowe przypadki phishingowe + dev host). |
| 2 | **Allowlista WebView dopuszczała dowolną subdomenę (`*.domena`).** `WebViewAllowlist.isAllowedHost` sprawdzał `normalized.endsWith(".$allowed")` — host w rodzaju `cokolwiek.zrodlo-slowa.pl` byłby błędnie uznany za zaufany. | Dokładne dopasowanie hosta; wariant `www.` każdej domeny dodany jawnie do `SiteConfig.allowlistHosts` (bo jest realnie potrzebny, a wildcard już nie jest dozwolony). Testy: `WebViewAllowlistTest` (regresja subdomen). |
| 3 | **Brak blokady tras administracyjnych (`/admin`) w publicznej aplikacji** — nawet w obrębie dozwolonego hosta, ścieżka `/admin` byłaby ładowana bez przeszkód. | `WebViewAllowlist.isBlockedAdminPath` + użycie w `SecureWebViewClient.shouldOverrideUrlLoading` (obrona w głąb, niezależnie od tego, że backend i tak wymaga tam osobnej roli). |
| 4 | **Zewnętrzne intencje bez ograniczenia do głównej ramki/gestu użytkownika i bez allowlisty schematów.** `shouldOverrideUrlLoading` otwierało `ACTION_VIEW` dla KAŻDEGO żądania spoza allowlisty — również z `iframe` i bez interakcji użytkownika, dla dowolnego schematu (`intent`, `file`, `content`...). | Zewnętrzna intencja jest teraz uruchamiana wyłącznie dla `isForMainFrame && hasGesture() && WebViewAllowlist.isAllowedExternalScheme(...)` (`https`/`mailto`/`tel`, `http` tylko w debug). Wszystko inne jest po cichu anulowane. Testy: `WebViewAllowlistTest.isAllowedExternalScheme`. |
| 5 | **`NotificationsApiBridge` używał `addJavascriptInterface`** — metoda natywna dostępna refleksyjnie każdej ramce strony (w tym potencjalnym `iframe` obcego pochodzenia), bez ograniczenia originem. | Zastąpione `WebViewCompat.addWebMessageListener` z jawną allowlistą originu (`allowedOriginRules` = dokładny origin `baseUrl`) i dodatkową weryfikacją `isMainFrame`/originu w kodzie natywnym. Na urządzeniach bez wsparcia (`WebViewFeature.WEB_MESSAGE_LISTENER`) most jest świadomie wyłączony, zamiast wracać do niebezpiecznego interfejsu. |
| 6 | **Pobieranie plików (`DownloadManager`) bez walidacji hosta/HTTPS.** `setDownloadListener` zlecał pobranie DOWOLNEGO adresu przekazanego przez WebView, z cookies sesji, bez sprawdzenia allowlisty. | `startDownload` odrzuca teraz adresy spoza tej samej allowlisty hostów/schematów co reszta WebView (`WebViewAllowlist.isAllowedHost`/`isAllowedScheme`). |
| 7 | **Podmiana globalnego `LocalContext` kontekstem konfiguracyjnym (locale).** `ZrodloSlowaNavHost` dostarczał jako `LocalContext` bezpośredni wynik `Context.createConfigurationContext(...)` — to NIE jest `Activity`; `WebView(context)` i `context.startActivity(...)` (linki zewnętrzne, OAuth, 3DORS Author) działały na kontekście niebędącym Activity, z realnym ryzykiem `AndroidRuntimeException` przy `startActivity` bez `FLAG_ACTIVITY_NEW_TASK`. | Kontekst Activity jest zachowany — zamiast podmiany, oryginalny kontekst jest opakowany w `ContextWrapper` z nadpisanym wyłącznie `getResources()` (locale), więc `startActivity`/`getSystemService` nadal trafiają do prawdziwej `Activity`. |
| 8 | **Brak dynamicznego `FLAG_SECURE` na ekranach z danymi poufnymi** (Portfel, Konto, panel autora) — możliwy zrzut/nagranie ekranu i dołączenie zrzutu do podglądu ostatnich aplikacji. | `ZrodloSlowaNavHost` ustawia/zdejmuje `WindowManager.LayoutParams.FLAG_SECURE` w zależności od aktualnej trasy (`WALLET`, `ACCOUNT`, `webpage/{path}` zawierające `author`/`withdraw`/`payout`/`wyplat`). |
| 9 | **Zbędne uprawnienie `CAMERA` w manifeście**, mimo że aplikacja nie wywołuje żadnego natywnego Camera API (upload zdjęcia idzie przez niejawną intencję `ACTION_IMAGE_CAPTURE` obsługiwaną przez zewnętrzną aplikację aparatu). | Usunięte z `AndroidManifest.xml` (pozostawiono `uses-feature android:required="false"`). |
| 10 | **Brak rewalidacji sesji po `ON_RESUME`.** `AuthGate` weryfikował sesję tylko raz, przy wejściu na ekran — po powrocie z tła (np. z 3DORS Author, po wygaśnięciu/unieważnieniu sesji po stronie serwera) ekran mógł nadal pokazywać stan "zalogowany". | `AuthGate` obserwuje teraz `Lifecycle.Event.ON_RESUME` (`LifecycleEventObserver`) i wymusza ponowną `verifySession` przy każdym powrocie do pierwszego planu. |
| 11 | **Wylogowanie nie było scentralizowane.** Reset stanu po wylogowaniu dotyczył tylko ekranu, na którym kliknięto "Wyloguj" — inne, już złożone ekrany bramkowane przez `AuthGate` (np. Powiadomienia zachowane na stosie nawigacji) i publiczne `SecureWebView` (Główna/Artykuły, mogące pokazywać spersonalizowaną treść) nie były odświeżane. | Dodano `WebSessionManager.logoutEpoch` (rośnie przy każdym `clearAllWebViewState`) — każdy `AuthGate` wymusza natychmiastową ponowną weryfikację sesji, a każdy `SecureWebView` przeładowuje swoją stronę, niezależnie od tego, na którym ekranie nastąpiło wylogowanie. |
| 12 | **Zmiana wersji językowej (innej domeny) nie tworzyła nowego WebView.** Ta sama instancja WebView była tylko przekierowywana (`loadUrl`) na nowy host, zachowując historię nawigacji POPRZEDNIEJ domeny (przycisk Wstecz mógł cofnąć do poprzedniego języka). | `SecureWebView` tworzy nową instancję WebView (`key(currentHost)`) przy każdej zmianie hosta — czysta historia; w obrębie tego samego hosta instancja jest nadal współdzielona (zamierzone działanie przycisku Wstecz w obrębie serwisu). |

**Uwaga o rzetelności:** powyższe naprawy zostały zweryfikowane WYŁĄCZNIE przez analizę kodu,
kompilację (`compileDebugKotlin`), testy jednostkowe JVM (`testDebugUnitTest`) i budowanie APK
(`assembleDebug`) — środowisko pracy nadal nie daje możliwości fizycznego uruchomienia aplikacji
(emulator/telefon), więc żaden z punktów z listy „Odbiór obowiązkowy” (pkt 8) nie mógł zostać
potwierdzony wizualnie w tej sesji. Punkty dyspozycji NIE zaadresowane w tej turze z powodu braku
możliwości ich weryfikacji/wykonania w tym środowisku:
- fizyczne testy z listy "Odbiór" (reader/author/logout→inne konto/6 domen/3DORS debug i
  release/upload/aparat/PDF/materiał poufny/powiadomienia/offline/zewnętrzne linki) — wymagają
  uruchomionej aplikacji;
- weryfikacja certyfikatu pakietu 3DORS Author (`pl.zrodloslowa.dors3.author`, podpis) przed
  otwarciem App Link — obecny kod ogranicza się do hosta/schematu URL (zgodnie z tym, co jest
  możliwe do zweryfikowania z poziomu WebView/Intent bez integracji `PackageManager`); dodanie
  weryfikacji podpisu pakietu wymagałoby integracji z `PackageManager.getPackageInfo(..., GET_SIGNATURES)`
  i traktujemy to jako otwarty punkt do osobnej decyzji projektowej (czy i jak sztywno wiązać
  wersję aplikacji z konkretnym certyfikatem podpisu, co utrudnia rotację kluczy);
- rzeczywisty host produkcyjny 3DORS Author/Admin (patrz pkt 1 tabeli wyżej — `CHANGE_ME` w
  szablonie `.env.production.example`) — wymaga uzupełnienia `ZRODLOSLOWA_DORS3_AUTHOR_HOSTS`/
  `ZRODLOSLOWA_DORS3_ADMIN_HOSTS` rzeczywistą wartością przed pilotem.

### Zmienione/nowe pliki (pkt 12)

```text
app/build.gradle.kts
app/src/main/AndroidManifest.xml
app/src/main/java/pl/zrodloslowa/app/config/SiteConfig.kt
app/src/main/java/pl/zrodloslowa/app/dors3/Dors3AuthorLauncher.kt
app/src/main/java/pl/zrodloslowa/app/notifications/NotificationsApiBridge.kt
app/src/main/java/pl/zrodloslowa/app/session/WebSessionManager.kt
app/src/main/java/pl/zrodloslowa/app/ui/auth/AuthGate.kt
app/src/main/java/pl/zrodloslowa/app/ui/navigation/ZrodloSlowaNavHost.kt
app/src/main/java/pl/zrodloslowa/app/webview/SecureWebView.kt
app/src/main/java/pl/zrodloslowa/app/webview/SecureWebViewClient.kt
app/src/main/java/pl/zrodloslowa/app/webview/WebViewAllowlist.kt
app/src/test/java/pl/zrodloslowa/app/config/SiteConfigTest.kt
app/src/test/java/pl/zrodloslowa/app/dors3/Dors3AuthorLauncherTest.kt
app/src/test/java/pl/zrodloslowa/app/webview/WebViewAllowlistTest.kt
```

Po wszystkich powyższych zmianach ponownie wykonano `compileDebugKotlin`, `testDebugUnitTest` i
`assembleDebug` — wszystkie zakończone sukcesem (build zielony, wszystkie testy jednostkowe
przechodzą).
