# KURS SŁOWA I TT — ŹRÓDŁO SŁOWA MOBILE

Zgodnie z pkt 2.7, 6, 10 dyspozycji.

## 1. Definicja

„Kurs TT” = istniejący wskaźnik **KURS SŁOWA** (np. `10 TT = 1 PLN`), liczony przez backend (`PaymentRuntimeConfigService`, `wallet.tt_per_pln`), widoczny w publicznym nagłówku serwisu i w Portfelu. Nie jest to kurs edukacyjny i nie ma nic wspólnego z kursami NBP wykorzystywanymi tylko wewnętrznie przez backend do innych przeliczeń walutowych.

## 2. Zasada nadrzędna

> Aplikacja nie oblicza kursu TT. Nie przelicza TT → PLN ani TT → innej waluty. Wyświetla wyłącznie wartość przygotowaną przez istniejący backend (`tt_rate_label`).

Zrealizowane w 100%: żaden plik Kotlin w `mobile/zrodlo-slowa-android` nie zawiera logiki przeliczającej TT, kursu, ani logiki finansowej — jest to potwierdzone przeglądem kodu (`HomeScreen.kt`, `WalletScreen.kt`, `NotificationsApiBridge.kt` nie zawierają żadnych obliczeń finansowych).

## 3. Sposób prezentacji w powłoce

- **Strona główna** (`HomeScreen`) — wskaźnik KURS SŁOWA jest częścią renderowanej strony `/` w `SecureWebView`; kliknięcie prowadzi do istniejącego Portfela zgodnie z istniejącym linkiem w treści strony (bez natywnej logiki nawigacyjnej narzucanej przez aplikację).
- **Portfel** (`WalletScreen`) — `tt_rate_label`, saldo TT i informacje o konwersji są w całości renderowane przez serwer wewnątrz `SecureWebView` na `/wallet`.
- Status: **GOTOWE W WEBVIEW** dla obu miejsc.

## 4. Dlaczego nie ma natywnego (Compose) wskaźnika Kursu Słowa w górnym pasku

Zgodnie z pkt 5 dyspozycji: „Jeżeli bez zmiany backendu nie da się bezpiecznie odtworzyć wartości Kursu Słowa w natywnym pasku: pozostaw istniejący serwerowy wskaźnik widoczny w WebView. Nie parsuj ani nie licz kursu jako źródła prawdy.”

Audyt (ETAP 0) potwierdził brak dedykowanego, publicznego endpointu JSON zwracającego `tt_rate_label` niezależnie od renderowanej strony HTML. Próba jego odtworzenia w natywnym pasku wymagałaby albo parsowania HTML (zakazane — ryzyko rozjazdu z rzeczywistą wartością), albo dodania nowego endpointu backendu (zakazane przez pkt 1 dyspozycji: „nie dodawaj nowych tras, endpointów”). W związku z tym wskaźnik pozostaje wyłącznie w WebView, zgodnie z wprost dopuszczonym w dyspozycji rozwiązaniem.

Status: **NIEDOSTĘPNE BEZ ZMIAN SERWISU** (natywna, Compose wersja wskaźnika) — świadomie i zgodnie z dyspozycją.

## 5. Testy dotyczące pkt 16 (Kurs Słowa i TT)

| # | Test | Sposób weryfikacji w tej powłoce |
|---|---|---|
| 25 | KURS SŁOWA widoczny na stronie głównej | renderowany przez serwer w `SecureWebView` (`HomeScreen`) |
| 26 | Kurs prowadzi do Portfela | istniejący link w treści strony (WebView), bez natywnej ingerencji |
| 27 | Kurs nie jest hardkodowany | brak w kodzie Kotlin jakiejkolwiek literalnej wartości kursu |
| 28 | Kurs aktualizuje się po zmianie ustawienia serwera | WebView renderuje bieżącą treść przy każdym wejściu na ekran |
| 29 | Zmiana waluty wyświetlania pokazuje wartość przygotowaną przez backend | renderowane w WebView Portfela/Konta, zależnie od `display_currency` backendu |
| 30 | Aplikacja nie używa lokalnej wartości do konwersji | potwierdzone przeglądem kodu — brak logiki konwersji |
| 31 | Saldo TT pochodzi z serwisu | renderowane w WebView Portfela (`WalletScreen`) |
