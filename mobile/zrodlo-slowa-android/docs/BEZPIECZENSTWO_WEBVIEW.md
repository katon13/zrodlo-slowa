# BEZPIECZEŃSTWO WEBVIEW — ŹRÓDŁO SŁOWA MOBILE

Zgodnie z pkt 14 dyspozycji. Poniżej odwzorowanie każdego wymogu na konkretną implementację w `mobile/zrodlo-slowa-android`.

| Wymóg (pkt 14) | Implementacja | Status |
|---|---|---|
| Allowlista wszystkich istniejących oficjalnych domen językowych | `config/SiteConfig.kt` (`allowlistHosts`, 6 domen z `config/sites.json`) + `webview/WebViewAllowlist.kt` | GOTOWE |
| Osobna allowlista debug dla lokalnego serwera | `webview/WebUrlResolver.kt` (debug override adresu bazowego) + `src/debug/res/xml/network_security_config.xml` (cleartext tylko `10.0.2.2`/`localhost`/`127.0.0.1`) | GOTOWE |
| HTTPS w release | `SiteConfig.baseUrl()` zawsze zwraca `https://`; brak `network_security_config` zezwalającego na cleartext w warincie `main`/`release` | GOTOWE |
| Wyłączony mixed content | `SecureWebView.kt` — `mixedContentMode = MIXED_CONTENT_NEVER_ALLOW` | GOTOWE |
| Obce domeny w przeglądarce systemowej | `SecureWebViewClient.shouldOverrideUrlLoading` — host spoza `WebViewAllowlist` → `Intent(ACTION_VIEW)` poza aplikacją | GOTOWE |
| Brak dowolnego JavaScript bridge | brak `addJavascriptInterface` dla treści publicznej w `SecureWebView.kt` | GOTOWE |
| Brak `addJavascriptInterface` dla strony publicznej | jak wyżej — `NotificationsApiBridge` używa osobnego, niewidocznego `WebView` tylko do `evaluateJavascript`/`fetch()`, bez żadnego bridge wstrzykiwanego do stron publicznych | GOTOWE |
| WebView debugging wyłączony w release | `WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)` w `SecureWebView.kt` | GOTOWE |
| Safe Browsing | `safeBrowsingEnabled = true` w ustawieniach `SecureWebView.kt` | GOTOWE |
| Kontrolowany upload | `onShowFileChooser` z ograniczeniem do typów MIME żądanych przez formularz serwisu (aparat/galeria) | GOTOWE |
| Kontrolowany download | `setDownloadListener` → `DownloadManager` systemowy, bez zapisu poza katalogiem pobranych plików aplikacji | GOTOWE |
| Kontrola MIME | download/upload korzystają z MIME zwracanego przez WebView/formularz, bez własnej reinterpretacji | GOTOWE |
| Obsługa aparatu i galerii | `onShowFileChooser` + `ActivityResultContracts` (aparat/galeria) | GOTOWE |
| Brak przechowywania hasła w aplikacji | logowanie odbywa się wyłącznie w `LoginScreen` (`SecureWebView` na `/login`); aplikacja nie odczytuje, nie przechowuje pól formularza | GOTOWE |
| Bezpieczne cookies | sesja wyłącznie przez `CookieManager` (`WebSessionManager.kt`) — bez ręcznego odczytu/kopiowania wartości cookie | GOTOWE |
| Wylogowanie czyści właściwą sesję | `WebSessionManager.clearSession()` wywoływany po wykryciu wylogowania w WebView Konta | GOTOWE |
| Brak backupu danych aplikacji | `AndroidManifest.xml` — `android:allowBackup="false"` + `android:dataExtractionRules="@xml/data_extraction_rules"` + `android:fullBackupContent="@xml/backup_rules"` (wzorowane na `mobile/3dors-android`) | GOTOWE |
| Brak sekretów w logach | brak logowania treści cookie, tokenów, danych logowania w kodzie (przegląd `SecureWebView`, `WebSessionManager`, `NotificationsApiBridge`) | GOTOWE |
| Ekran offline | `ui/offline` (placeholder z ETAPU 1, wyświetlany przy błędzie sieci `SecureWebViewClient.onReceivedError`) | GOTOWE DO NATYWNEJ POWŁOKI |
| Ekran błędu | `ui/error` (placeholder z ETAPU 1) | GOTOWE DO NATYWNEJ POWŁOKI |
| Retry | akcja odświeżenia WebView z ekranu offline/błędu | GOTOWE DO NATYWNEJ POWŁOKI |
| Ochrona przed otwarciem fałszywej domeny | cała logika nawigacji WebView przechodzi przez `WebViewAllowlist` — każdy host jest jawnie sprawdzany przed załadowaniem | GOTOWE |
| Błędy TLS zawsze przerywają ładowanie | `SecureWebViewClient.onReceivedSslError` — `handler.cancel()`, `proceed()` nigdy nie jest wywoływane | GOTOWE |
| Zakaz kopiowania cookies między domenami | `WebSessionManager` operuje wyłącznie w obrębie bieżącej domeny; brak metody kopiującej/odczytującej wartość cookie ręcznie | GOTOWE (zgodnie z zakazem) |

## Ograniczenie: sesja między wersjami domenowymi

Zgodnie z audytem (ETAP 0) i pkt 14 dyspozycji: „Jeżeli istniejący serwis nie utrzymuje sesji pomiędzy wersjami domenowymi, opisz to jako ograniczenie. Nie obchodź tego przez niebezpieczne kopiowanie sesji.”

Każda wersja językowa (`config/sites.json`) to osobna domena produkcyjna, a `CookieManager` Androida przechowuje cookie per domena — zgodnie ze standardowym zachowaniem przeglądarki. W konsekwencji zmiana języka (ręczna lub automatyczna) może wymagać ponownego logowania w nowej domenie. **Nie zaimplementowano żadnego mechanizmu obchodzącego to ograniczenie** — jest to świadome i zgodne z zasadą bezpieczeństwa z pkt 14.

## Uwaga do weryfikacji manifestu

`android:allowBackup="false"` powinien być jawnie ustawiony w `app/src/main/AndroidManifest.xml` — zweryfikowano w bieżącym przebiegu (patrz plik manifestu w module); jeśli szablon Compose Android Studio domyślnie ustawił `true`, należy to potwierdzić przy najbliższej rewizji manifestu.
