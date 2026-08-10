# MAPA EKRANÓW I TRAS — ŹRÓDŁO SŁOWA MOBILE

Zgodnie z pkt 2.1 i 2.9 dyspozycji. Dla każdego ekranu aplikacji: istniejąca trasa serwisu → wymagane konto/rola/uprawnienia → język → sposób otwarcia w powłoce.

| Ekran aplikacji | Trasa istniejącego serwisu | Wymagane konto | Wymagana rola | Wymagane uprawnienia | Język | Sposób otwarcia | Status |
|---|---|---|---|---|---|---|---|
| Główna | `GET /` | nie | dowolna | brak | wg domeny/`AppLanguageManager` | `SecureWebView` (`HomeScreen`) | GOTOWE W WEBVIEW |
| Artykuły (lista) | `GET /articles` | nie | dowolna | brak | wg domeny | `SecureWebView` (`ArticlesScreen`) | GOTOWE W WEBVIEW |
| Widok artykułu | link z listy (WebView) | nie / tak (Premium) | dowolna | Premium wg treści | wg wersji artykułu | otwierany wewnątrz tego samego `SecureWebView` (link z allowlisty) | GOTOWE W WEBVIEW |
| Kategorie / tematy | linki na stronie głównej/artykułach | nie | dowolna | brak | wg domeny | wewnątrz WebView | GOTOWE W WEBVIEW |
| Wyszukiwarka | pole wyszukiwania serwisu | nie | dowolna | brak | wg domeny | wewnątrz WebView (Główna/Artykuły) | GOTOWE W WEBVIEW |
| Komentarze | sekcja artykułu | tak (dodanie), nie (odczyt) | czytelnik+ | brak | wg domeny | wewnątrz WebView artykułu | GOTOWE W WEBVIEW |
| Ankiety / kampanie | sekcje strony głównej | zależnie od treści | dowolna | brak | wg domeny | wewnątrz WebView | GOTOWE W WEBVIEW |
| Premium (publiczne elementy) | oznaczenia na liście/artykule | nie (podgląd), tak (pełna treść) | czytelnik+ | Premium | wg domeny | wewnątrz WebView | GOTOWE W WEBVIEW |
| Logowanie | `GET/POST /login` | nie | — | — | wg domeny | `LoginScreen` (`SecureWebView`), wykrycie sukcesu po opuszczeniu `/login` | GOTOWE DO NATYWNEJ POWŁOKI |
| Rejestracja | `GET/POST /register` (jeśli istnieje w serwisie) | nie | — | — | wg domeny | wewnątrz WebView logowania (link) | GOTOWE W WEBVIEW |
| Portfel | `GET /wallet` | tak | czytelnik+ | — | wg domeny | `SecureWebView` (`WalletScreen`) za `AuthGate` | GOTOWE W WEBVIEW |
| Zasilenie konta | podstrona `/wallet` | tak | czytelnik+ | — | wg domeny | wewnątrz WebView Portfela | GOTOWE W WEBVIEW |
| TT / Kurs Słowa | wskaźnik w nagłówku/portfelu | nie/tak | dowolna | — | wg domeny | renderowane przez serwer w WebView | GOTOWE W WEBVIEW |
| Zarobki / wpływy / historia | podstrony `/wallet` | tak | autor (dla zarobków) | `wallet_enabled` | wg domeny | wewnątrz WebView Portfela | GOTOWE W WEBVIEW |
| Wypłaty | podstrona `/wallet` | tak | autor | `wallet_enabled=1` i `payout_enabled=1` | wg domeny | wewnątrz WebView Portfela | GOTOWE W WEBVIEW |
| Powiadomienia | `GET /api/earnings/notifications`, `POST /api/earnings/notifications/ack` | tak | czytelnik+ | — | wg domeny | natywna lista (`NotificationsScreen` + `NotificationsApiBridge`) za `AuthGate` | GOTOWE DO NATYWNEJ POWŁOKI |
| Konto — ustawienia | `GET /account/settings` | tak | czytelnik+ | — | wg domeny | `SecureWebView` (`AccountScreen`) za `AuthGate` | GOTOWE W WEBVIEW |
| Konto autora / Panel autora | `GET /author` (link z `/account/settings`) | tak | autor | `can_write=1` | wg domeny | link renderowany przez serwer wewnątrz WebView Konta | GOTOWE W WEBVIEW |
| Szkice i teksty autora | podstrony `/author` | tak | autor | `can_write=1` | wg domeny | wewnątrz WebView Panelu autora | GOTOWE W WEBVIEW |
| Upload plików | formularze serwisu (np. edycja tekstu, avatar) | tak | zależnie od formularza | zależnie od formularza | wg domeny | natywny selektor pliku/aparatu wywoływany przez `SecureWebView` (`onShowFileChooser`) | GOTOWE DO NATYWNEJ POWŁOKI |
| Download plików | linki do plików w serwisie | zależnie od pliku | zależnie od pliku | zależnie od pliku | wg domeny | `DownloadManager`/przeglądarka systemowa dla linków spoza allowlisty treści | GOTOWE DO NATYWNEJ POWŁOKI |
| 3DORS Author (przejście) | link zatwierdzenia z `config/dors3.php` (`/3dors/approve/{id}`) | tak | autor | wg operacji 3DORS | — | otwierany poza aplikacją (App Link/przeglądarka), `Dors3AuthorLauncher` + `Dors3ResultHandler` | GOTOWE DO NATYWNEJ POWŁOKI |
| Logout | link/przycisk „Wyloguj” w Koncie | tak | dowolna | — | wg domeny | wewnątrz WebView Konta; `WebSessionManager.clearSession()` po wykryciu wylogowania | GOTOWE W WEBVIEW |
| Trasy anonimowe | Główna, Artykuły, widok artykułu (bez Premium) | nie | — | — | wg domeny | WebView bez `AuthGate` | GOTOWE W WEBVIEW |
| Trasy chronione | Portfel, Powiadomienia, Konto | tak | czytelnik+ | — | wg domeny | WebView/natywne za `AuthGate` | GOTOWE DO NATYWNEJ POWŁOKI |
| Panel administratora | panel WWW / 3DORS Admin | tak | administrator | — | — | **niedostępne w aplikacji** | NIE WOLNO PRZENOSIĆ DO APLIKACJI |

Legenda statusów zgodna z pkt 2.9 dyspozycji: `GOTOWE DO UŻYCIA`, `GOTOWE W WEBVIEW`, `GOTOWE DO NATYWNEJ POWŁOKI`, `NIEDOSTĘPNE BEZ ZMIAN SERWISU`, `NIE WOLNO PRZENOSIĆ DO APLIKACJI`, `RYZYKO`.

Szczegóły ryzyk (sesja cross-domenowa, brak natywnego API Kursu Słowa) opisane w `AUDYT_ZALEZNOSCI_ZRODLO_SLOWA_MOBILE.md` oraz `KURS_SLOWA_TT.md` i `BEZPIECZENSTWO_WEBVIEW.md`.
