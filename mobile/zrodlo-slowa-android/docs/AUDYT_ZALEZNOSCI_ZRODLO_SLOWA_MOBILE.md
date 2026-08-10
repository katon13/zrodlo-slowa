# AUDYT ZALEŻNOŚCI — ŹRÓDŁO SŁOWA MOBILE (ETAP 0)

Data audytu: 2026-08-04
Zakres: analiza wyłącznie do odczytu istniejącego projektu `X:\zrodlo-slowa`. Nie wprowadzono żadnych zmian w istniejącym systemie.

> Zgodnie z dyspozycją, po ukończeniu tego audytu praca zatrzymuje się (STOP) i czeka na akceptację przed przejściem do ETAPU 1 (Architektura aplikacji).

---

## 1. MAPA EKRANÓW I TRAS (routing źródłowy: `public/index.php`)

| Ekran aplikacji mobilnej | Istniejąca trasa serwisu | Konto | Rola | Sposób otwarcia | Status |
|---|---|---|---|---|---|
| Strona główna | `GET /` | nie | anonim | WebView | GOTOWE W WEBVIEW |
| Artykuł | `GET /article` | nie/tak | zależnie od Premium | WebView | GOTOWE W WEBVIEW |
| Lista artykułów | `GET /articles` | nie | anonim | WebView | GOTOWE W WEBVIEW |
| Wsparcie artykułu | `POST /article/support` | tak | czytelnik | WebView (formularz) | GOTOWE W WEBVIEW |
| Zakup artykułu (Premium) | `POST /article/buy` | tak | czytelnik | WebView | GOTOWE W WEBVIEW |
| Ankiety | `GET /surveys`, `GET /survey`, `POST /survey/submit` | nie/tak | - | WebView | GOTOWE W WEBVIEW |
| Kampanie | `GET /campaigns`, `GET /campaign`, `POST /campaign/*` | nie/tak | - | WebView | GOTOWE W WEBVIEW |
| Autorzy / autor | `GET /authors`, `GET /author` | tak | dziennikarz/autor | WebView | GOTOWE W WEBVIEW |
| Panel autora — artykuły | `GET/POST /author/articles*` | tak | autor | WebView | GOTOWE W WEBVIEW |
| Rejestracja | `GET/POST /register` | nie | anonim | WebView | GOTOWE W WEBVIEW |
| Logowanie | `GET/POST /login` | nie | anonim | SecureWebView + WebSessionManager | GOTOWE W WEBVIEW |
| 2FA | `GET/POST /login/2fa` | nie | anonim | WebView | GOTOWE W WEBVIEW |
| Logowanie 3DORS mobile (challenge) | `GET /login/3dors-mobile`, `POST /login/3dors-mobile/complete` | nie | anonim | WebView | RYZYKO — wymaga dogłębnej analizy w ETAPIE 2 (osobny mechanizm logowania mobilnego, powiązany z 3DORS) |
| Wylogowanie | `POST /logout` | tak | - | WebView / natywny trigger | GOTOWE W WEBVIEW |
| OAuth Google/Apple | `GET /auth/google*`, `GET/POST /auth/apple*` | nie | anonim | WebView / Custom Tabs | RYZYKO — OAuth redirect flow w WebView bywa blokowany przez Google; wymaga weryfikacji w ETAPIE 2 |
| Konto — ustawienia | `GET/POST /account/settings`, `POST /account/avatar` | tak | czytelnik/autor | WebView | GOTOWE W WEBVIEW |
| Konto — bezpieczeństwo, 2FA | `GET /account/security`, `POST /account/security/*` | tak | - | WebView | GOTOWE W WEBVIEW |
| Reader dashboard | `GET /reader` | tak | czytelnik | WebView | GOTOWE W WEBVIEW |
| Portfel | `GET /wallet` | tak | czytelnik/autor | WebView (osobny ekran natywny — kontener) | GOTOWE W WEBVIEW |
| Doładowanie portfela | `GET/POST /wallet/topup`, `/wallet/topup/success`, `/wallet/topup/cancel` | tak | czytelnik | WebView | GOTOWE W WEBVIEW |
| Webhook Stripe | `POST /stripe/webhook` | - | - | tylko backend | NIE WOLNO PRZENOSIĆ DO APLIKACJI |
| Transfer TT→PLN | `POST /wallet/transfer/talent-to-pln` | tak | autor | WebView | GOTOWE W WEBVIEW |
| Metody wypłat / żądanie wypłaty | `POST /wallet/payout-methods`, `POST /wallet/payout-request` | tak | autor (`wallet_enabled=1`, `payout_enabled=1`) | WebView | GOTOWE W WEBVIEW |
| Powiadomienia — presence/lista/ACK | `POST /api/earnings/presence`, `GET /api/earnings/notifications`, `POST /api/earnings/notifications/ack`, `GET /api/earnings/jobs/status`, `POST /api/earnings/article-read` | tak | czytelnik/autor | API (JSON) — GOTOWE DO NATYWNEJ POWŁOKI | GOTOWE DO NATYWNEJ POWŁOKI |
| 3DORS mobile — start/status/enrollment/approve/reject/heartbeat | `POST/GET /auth/3dors/mobile/*`, `/api/3dors/mobile/*` | tak | autor | natywne API — ale to kontrakt aplikacji 3DORS, nie Źródła Słowa Mobile | GOTOWE DO NATYWNEJ POWŁOKI (tylko wywołanie/odbiór wyniku, bez logiki podpisu) |
| Darowizny | `GET /donations`, `POST /donations/manual` | nie/tak | - | WebView | GOTOWE W WEBVIEW |
| Panel administratora | `GET /admin*`, `/admin/security/3dors*`, `/admin/security/mobile/*` | tak | administrator | — | NIE WOLNO PRZENOSIĆ DO APLIKACJI (zgodnie z pkt 2.2 dyspozycji) |
| Sitemap | `GET /sitemap.xml` | nie | - | - | poza zakresem aplikacji mobilnej |

Uwaga: pełna lista pozostałych ~67 tras (poniżej linii 325 w `public/index.php`, m.in. dalsze trasy adminowe, AI, tłumaczenia AdminArticleTranslationController) dotyczy wyłącznie panelu administracyjnego / operacji redakcyjnych backendu i **nie wchodzi w zakres aplikacji mobilnej Źródło Słowa** — oznaczone jako NIE WOLNO PRZENOSIĆ DO APLIKACJI.

---

## 2. MACIERZ RÓL

Potwierdzono w kodzie (`app/Services/UserService.php`, `app/Services/TalentService.php`, `app/Services/WalletService.php`, `app/Controllers/AuthorController.php`):

| Flaga / rola | Źródło | Znaczenie |
|---|---|---|
| `can_write` | `users.can_write` | uprawnienie redakcyjne (dziennikarz/autor) |
| `talent_enabled` | `users.talent_enabled` | dostęp do TT |
| `wallet_enabled` | `users.wallet_enabled` | aktywne konto rozliczeniowe (wymagane m.in. do wypłat — `WalletService::assertEnabled` sprawdza `wallet_enabled`) |
| `payout_enabled` | `users.payout_enabled` | zgoda na wypłatę środków |

Potwierdzone w `Dors3MobileOperationExecutor.php` i `Dors3MobileService.php`: wypłata wymaga jednocześnie `status='active' AND wallet_enabled=1 AND payout_enabled=1`. Praca redakcyjna (`can_write`) jest niezależna od `payout_enabled` — zgodne z wymogiem dyspozycji („Autor bez payout_enabled może pracować redakcyjnie”).

Rola ADMINISTRATOR obsługiwana jest przez panel WWW (`AdminController`) i osobną aplikację 3DORS Admin (`app/src/admin` w `mobile/3dors-android`) — potwierdzone brakiem panelu admina w zakresie tras publicznych. Status: **GOTOWE DO UŻYCIA** (logika ról pozostaje wyłącznie po stronie backendu, aplikacja mobilna Źródło Słowa tylko odczytuje wynik).

Nie znaleziono żadnej lokalnej logiki ról do odtworzenia — wszystkie decyzje muszą pochodzić z odpowiedzi serwera (zgodnie z wymogiem pkt 2.2).

---

## 3. MAPA ZALEŻNOŚCI 3DORS

Plik `config/dors3.php` potwierdza:
- `mobile.enabled`, `mobile.mode` (`disabled|test|required`) — sterują czy przepływ mobile 3DORS jest aktywny;
- `mobile.author_app_enabled` i osobno `admin_app_enabled` — **dwie różne aplikacje** (Author i Admin) — Źródło Słowa Mobile może wywoływać wyłącznie wariant Author;
- `mobile.article_submit_approval`, `article_publish_approval`, `payout_approval` — flagi kontrolujące, kiedy backend wymusza podpis 3DORS dla danej operacji;
- `mobile.author_app_link_base_url` — bazowy adres App Link do otwarcia 3DORS Author;
- `request_ttl_seconds` (30–90s), `enrollment_ttl_seconds`, `api_token_ttl_seconds` — potwierdzają istnienie TTL wymienione w dyspozycji.

Endpointy API 3DORS mobile (`Dors3MobileApiController`) obsługują: `startAuth`, `authStatus`, `completeEnrollment`, `confirmEnrollment`, `requestDetails`, `approve`, `reject`, `pendingRequest`, `deviceStatus`, `heartbeat` — pełny kontrakt already istnieje i jest udokumentowany w `mobile/3dors-android/docs/KONTRAKT_BACKENDU_3DORS_MOBILE.md`.

Istniejąca aplikacja `mobile/3dors-android` (warianty `admin` i `author` we `variant/VariantPolicy.kt`) **jest tą właśnie aplikacją 3DORS** wspominaną w dyspozycji — Źródło Słowa Mobile ma jedynie otwierać wariant Author (np. przez App Link/Intent), nigdy nie zawierać jej kodu podpisu (`crypto/`, `biometric/`, `Dors3KeystoreManager` itd. pozostają wyłącznie w `mobile/3dors-android`).

Status: **GOTOWE DO NATYWNEJ POWŁOKI** (samo wywołanie/odbiór wyniku). Kod podpisu: **NIE WOLNO PRZENOSIĆ DO APLIKACJI**.

---

## 4. MAPA FINANSÓW I TT

Potwierdzone serwisy: `WalletService`, `WalletTopupService`, `WalletTransferService`, `PayoutService`, `PaymentRuntimeConfigService`, `CurrencyRateService`, `LedgerService` (+ `LedgerHashService`, `LedgerIntegrityService`, `LedgerMerkleService` — księga z integralnością kryptograficzną, wyłącznie backend).

`BaseController::view()` (linie 181–206) pokazuje, że serwer sam wylicza `tt_rate_label` przez `PaymentRuntimeConfigService::formatTtRateLabel()` na podstawie `CurrencyRateService::ttToLocalApprox()` i `display_currency` użytkownika — **wartość gotowa jest przekazywana do widoku, aplikacja mobilna nie powinna liczyć kursu samodzielnie**, tylko odczytać istniejący, wyrenderowany wskaźnik (z WebView) lub — jeśli backend udostępni to jako dane API w przyszłości — odebrać gotowy string.

Uwaga RYZYKO: nie znaleziono dedykowanego endpointu JSON zwracającego `tt_rate_label`/saldo poza renderowanym HTML — natywny pasek górny (pkt 5 dyspozycji) **nie może** bezpiecznie pokazać Kursu Słowa bez zmiany backendu; zgodnie z dyspozycją należy pozostawić serwerowy wskaźnik widoczny w WebView.

Status: **GOTOWE W WEBVIEW** dla Portfela i Kursu Słowa. **NIEDOSTĘPNE BEZ ZMIAN SERWISU** dla natywnego (natywnie renderowanego) wskaźnika Kursu Słowa w górnym pasku aplikacji.

---

## 5. MAPA POWIADOMIEŃ

Potwierdzone: `NotificationOutboxDispatcher` (kolejka `earnings.notifications`, `DurableJobQueue`, sygnał przez `QueueSignalInterface`/Valkey), `EarningsWorkerRuntime`, `EarningsJobDispatcher`, `EarningsQueueService`, `EarningsNotificationService`, `EarningsDiagnosticsService`.

API dostępne już dla klienta: `GET /api/earnings/notifications`, `POST /api/earnings/notifications/ack`, `GET /api/earnings/jobs/status`, `POST /api/earnings/presence` — **gotowe API JSON**, nadające się bezpośrednio do natywnej zakładki „Powiadomienia” bez zmian backendu.

Typy zdarzeń potwierdzone w kodzie: `article_sale_income`, `article_support_income` (możliwe dalsze typy do potwierdzenia w kodzie `EarningsNotificationService` — do pogłębienia przy implementacji ETAPU 5).

Brak potwierdzonej integracji push (FCM) w przeglądanym kodzie — zgodnie z dyspozycją: **nie udawać powiadomień push**.

Status: **GOTOWE DO NATYWNEJ POWŁOKI**.

---

## 6. MAPA JĘZYKÓW I DOMEN

`config/languages.php`: 6 języków (`pl, en, de, fr, it, es`) z markami zgodnymi z dyspozycją (ŹRÓDŁO SŁOWA / SOURCE OF WORD / WORTQUELLE / SOURCE DES MOTS / FONTE DI PAROLE / FUENTE DE PALABRAS).

`config/sites.json` + `config/sites.php`: **każda wersja językowa ma osobną domenę**:

| Język | Domena |
|---|---|
| pl | zrodlo-slowa.pl |
| en | sourceofword.co.uk |
| de | de-wortquelle.de |
| fr | source-des-mots.fr |
| it | fonte-di-parole.it |
| es | fuente-de-palabras.es |

**RYZYKO KLUCZOWE**: ponieważ każdy język to osobna domena, sesja (cookies) ustanowiona na jednej domenie **nie przenosi się automatycznie** na inną przy zmianie języka — przeglądarki/WebView nie dzielą cookies między różnymi domenami. Zgodnie z pkt 14 dyspozycji: „Jeżeli istniejący serwis nie utrzymuje sesji pomiędzy wersjami domenowymi, opisz to jako ograniczenie. Nie obchodź tego przez niebezpieczne kopiowanie sesji.” — **to ograniczenie zostaje niniejszym opisane i musi zostać uwzględnione w ETAPIE 2 (Bezpieczny WebView / Sesja)**: użytkownik zmieniający język może zostać wylogowany na nowej domenie, chyba że dalszy audyt kodu logowania (poza zakresem tego przebiegu) wykaże mechanizm SSO między domenami.

`allowlist` WebView (do ETAPU 2) musi obejmować wszystkie 6 domen produkcyjnych + domenę/adres debug lokalnego serwera.

Nie potwierdzono w tym przebiegu jeszcze plików `PublicLanguageService.php`, `PublicSiteResolver.php` na poziomie treści (tylko istnienie) — do pogłębienia przy implementacji ETAPU 1/2, nie zmienia to jednak wniosków ogólnych audytu.

Status: **GOTOWE W WEBVIEW** (wybór i przełączanie języka), z jednym udokumentowanym **RYZYKIEM** (sesja cross-domenowa).

---

## 7. LISTA ELEMENTÓW DOSTĘPNYCH BEZ ZMIAN BACKENDU

- Strona główna, artykuły, kategorie, ankiety, kampanie, autorzy — przez WebView.
- Logowanie, 2FA, OAuth (do weryfikacji technicznej w ETAPIE 2), wylogowanie.
- Portfel, doładowanie, transfer TT→PLN, żądanie wypłaty — przez WebView.
- Powiadomienia (lista, ACK, presence, status jobów) — przez istniejące API JSON.
- Otwarcie 3DORS Author przez App Link (kontrakt już istnieje w `mobile/3dors-android`).
- 6 języków i 6 domen/marek — przez istniejącą konfigurację.

## 8. LISTA ELEMENTÓW NIEDOSTĘPNYCH BEZ ZMIAN BACKENDU

- Natywny (nie-WebView) wskaźnik Kursu Słowa w górnym pasku — brak dedykowanego endpointu JSON.
- Gwarancja utrzymania sesji między różnymi domenami językowymi — wymaga dalszej weryfikacji `PublicSiteResolver`/mechanizmu logowania; potencjalne ograniczenie UX.
- Powiadomienia push (brak potwierdzonej integracji FCM).
- „Rozmowa z autorem — Premium” — funkcja wspomniana warunkowo w dyspozycji; nie znaleziono jej w obecnym routingu — do potwierdzenia, że jeszcze nie istnieje w backendzie (NIEDOSTĘPNE / do wyświetlenia dopiero gdy backend ją opublikuje).

## 9. PLAN IMPLEMENTACJI (wyłącznie w nowej aplikacji `mobile/zrodlo-slowa-android`)

Zgodnie z etapami z dyspozycji, po akceptacji tego audytu:
1. ETAP 1 — architektura powłoki (moduły `shell/`, `navigation/`, `MainActivity`), stałe dolne menu wg makiety. **WYKONANE.**
2. ETAP 2 — `SecureWebView`, `WebSessionManager`, allowlista 6 domen + debug, obsługa logowania/OAuth, udokumentowanie ograniczenia sesji cross-domenowej. **WYKONANE**: `webview/WebViewAllowlist.kt` (logika allowlisty, testowana jednostkowo), `webview/SecureWebViewClient.kt` + `webview/SecureWebView.kt` (WebView z `safeBrowsingEnabled`, bez dostępu do plików, bez mixed-content, TLS bez `proceed()`, linki spoza allowlisty otwierane poza aplikacją), `session/WebSessionManager.kt` (cookies wyłącznie przez `CookieManager`, bez ręcznego odczytu/kopiowania sesji), `ui/auth/LoginScreen.kt` + `ui/auth/AuthGate.kt` (prawdziwy `/login` serwisu w WebView, bramkowanie Portfela/Konta), `config/SiteConfig.kt` (6 domen z `config/sites.json`), `src/debug/` network security config ograniczony do adresów lokalnych. Zastrzeżenie sesji cross-domenowej z pkt 6 pozostaje w mocy — brak jakiejkolwiek próby jej obejścia.
3. ETAP 3 — ekran główny (WebView), lista artykułów, zachowanie kolorowych zdjęć bez przetwarzania. **WYKONANE**: `ui/home/HomeScreen.kt` (strona główna `/` serwisu) i `ui/articles/ArticlesScreen.kt` (`/articles`) korzystają teraz z `SecureWebView` z ETAPU 2 — zdjęcia i kolory pochodzą wprost z renderowanej strony WWW, bez żadnego natywnego przetwarzania. Dodano wspólny `webview/WebUrlResolver.kt` (adres bazowy per język + debug override + host do allowlisty), z którego korzysta też `LoginScreen` (refaktoryzacja bez zmiany zachowania).
4. ETAP 4 — ekran Portfela (WebView + kontener natywny), rozróżnienie czytelnik/autor wg danych z serwera. **WYKONANE**: `ui/wallet/WalletScreen.kt` ładuje `GET /wallet` przez `SecureWebView` z ETAPU 2, bramkowany przez `AuthGate` (wymaga sesji). Podstrony (doładowanie, transfer TT→PLN, wypłaty) oraz rozróżnienie czytelnik/autor pozostają w całości po stronie serwera renderującego WebView — bez żadnej natywnej logiki uprawnień w aplikacji.
5. ETAP 5 — zakładka powiadomień oparta o istniejące API `/api/earnings/notifications*`. **WYKONANE**: `notifications/EarningsNotification.kt` (model + parser JSON kontraktu `EarningsApiController::notifications`), `notifications/NotificationsApiBridge.kt` — klient API oparty o niewidoczny `WebView` tej samej domeny wykonujący `fetch()` z `credentials: 'same-origin'`, dzięki czemu cookie sesji jest dołączane automatycznie przez WebView (zgodnie z zasadą z ETAPU 2: bez ręcznego odczytu/kopiowania wartości cookie w kodzie natywnym), `ui/notifications/NotificationsScreen.kt` — lista powiadomień z cyklicznym odpytywaniem (`next_cursor`, co 20s) i przyciskiem „Oznacz wszystkie jako przeczytane” (`POST /api/earnings/notifications/ack`). Ekran bramkowany przez `AuthGate` (wymaga sesji), tak jak Portfel.
6. ETAP 6 — Konto / Panel autora (WebView). **WYKONANE**: `ui/account/AccountScreen.kt` ładuje `GET /account/settings` przez `SecureWebView` z ETAPU 2, bramkowany przez `AuthGate` (wymaga sesji), tak jak Portfel i Powiadomienia. Odnośnik do Panelu autora (`GET /author`) jest renderowany przez serwis wyłącznie dla kont z uprawnieniem `can_write` — bez żadnej natywnej logiki uprawnień w aplikacji, zgodnie z wnioskami z audytu.
7. ETAP 7 — `Dors3AuthorLauncher` (wywołanie App Link do wariantu Author istniejącej aplikacji 3DORS) + `Dors3ResultHandler` (odbiór wyniku, odświeżenie WebView). **WYKONANE**: `dors3/Dors3AuthorLauncher.kt` rozpoznaje istniejący, w całości tworzony przez backend link zatwierdzenia (`config/dors3.php`: `author_app_link_base_url` + `/{id}`, ścieżka `/3dors/approve/{id}`) — powłoka nie wybiera typu podpisu, nie podpisuje, nie przechowuje klucza 3DORS, nie odtwarza fingerprintu, nie otwiera 3DORS Admin i nie zawiera kodu 3DORS. `SecureWebView` (ETAP 2) otwiera taki link poza aplikacją tak jak każdy inny link spoza allowlisty (Intent `ACTION_VIEW` → App Link do zainstalowanej aplikacji 3DORS Author albo przeglądarka). `dors3/Dors3ResultHandler.kt` — ponieważ 3DORS Author nie zwraca wyniku przez `Activity Result` (brak takiego kontraktu w istniejącym `mobile/3dors-android`, a powłoka nie ma prawa go dodawać), powrót jest wykrywany przez obserwację cyklu życia ekranu (`ON_RESUME`) i skutkuje odświeżeniem WebView, aby pokazać zaktualizowany status z serwera.
8. ETAP 8 — zasoby językowe natywnej powłoki (`values`, `values-en/de/fr/it/es`), `AppLanguageManager`, rozpoznawanie języka systemowego z fallbackiem PL. **WYKONANE**: dodano brakujące `values-de/fr/it/es/strings.xml` (menu, ekran logowania, powiadomienia) obok istniejących `values` (PL) i `values-en`. Nowy `config/AppLanguageManager.kt` rozpoznaje jedną z 6 wersji [SiteConfig] na podstawie `Locale.getDefault()`, z fallbackiem do PL, gdy język systemu nie jest obsługiwany. `ui/navigation/ZrodloSlowaNavHost.kt` przyjmuje teraz parametr `languageCode` (domyślnie z `AppLanguageManager`) i przekazuje go do wszystkich pięciu ekranów zamiast wcześniejszego sztywnego `"pl"`. Kurs TT (`tt_rate_label`) pozostaje w całości po stronie backendu (bez zmian) — zgodnie z RYZYKIEM opisanym w pkt 4 i 6, zmiana języka nadal oznacza inną domenę, więc sesja może wymagać ponownego logowania (ograniczenie udokumentowane, nieobejście).
9. ETAP 9 — testy wg listy z pkt 16 dyspozycji, APK debug, dokumentacja odbioru. **WYKONANE**: `gradlew clean testDebugUnitTest assembleDebug` — BUILD SUCCESSFUL (6 klas testów jednostkowych, wszystkie zielone), zbudowano `app/build/outputs/apk/debug/app-debug.apk`. Pełna dokumentacja odbioru z listą testów manualnych i znanymi ograniczeniami: `docs/ODBIOR_ETAP_9.md`.

---

## 10. STATUS KOŃCOWY AUDYTU

Audyt wykonano na podstawie analizy rzeczywistego kodu (routing, konfiguracja, serwisy) katalogu `X:\zrodlo-slowa`. Żaden plik istniejącego projektu nie został zmieniony. Zidentyfikowano dwa istotne ryzyka wymagające decyzji przed ETAPEM 2: (1) brak natywnego API dla Kursu Słowa, (2) potencjalny brak sesji między domenami językowymi.

**Praca zatrzymuje się na tym etapie (STOP) i czeka na akceptację przed rozpoczęciem ETAPU 1.**
