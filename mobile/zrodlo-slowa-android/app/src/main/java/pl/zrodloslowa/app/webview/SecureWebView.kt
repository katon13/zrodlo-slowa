package pl.zrodloslowa.app.webview

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import pl.zrodloslowa.app.BuildConfig
import pl.zrodloslowa.app.dors3.Dors3AuthorLauncher
import pl.zrodloslowa.app.dors3.Dors3ResultHandler
import pl.zrodloslowa.app.dors3.rememberDors3PendingApproval
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.ui.common.OfflineErrorScreen

/**
 * Bezpieczny, wielokrotnego użytku WebView (ETAP 2 z dyspozycji), wspólny
 * dla wszystkich ekranów opartych o serwis WWW (Główna, Artykuły, Portfel,
 * Konto — ETAPY 3-6). Ustawienia celowo minimalne pod kątem bezpieczeństwa:
 * bez dostępu do plików/treści urządzenia, bez mieszanej treści
 * HTTP/HTTPS, z aktywnym Safe Browsing. Linki spoza allowlisty (np. OAuth
 * Google/Apple) są otwierane poza aplikacją, nigdy w tym WebView.
 *
 * Naprawa błędów z audytu:
 * - obsługa przycisku Wstecz Androida wewnątrz historii WebView
 *   ([BackHandler] + `canGoBack()`/`goBack()`), zamiast wychodzenia z
 *   zakładki/aplikacji;
 * - nagłówek/stopka WWW są ukrywane od pierwszego dokumentu przez
 *   `WebViewCompat.addDocumentStartJavaScript` (gdy dostępne), a nie tylko
 *   po `onPageFinished` — eliminuje widoczne mignięcie pełnym menu strony.
 *
 * Dyspozycja pkt 4.4 ("Aplikacja czytnicza — usuń pliki"): ta aplikacja NIE
 * pobiera ani nie wysyła żadnych plików (PDF/JPG/inne) — nie ma tu
 * `DownloadManager`, `FileProvider`, aparatu ani galerii. Próba uploadu w
 * treści strony jest wykrywana ([WebChromeClient.onShowFileChooser]) i
 * kończy się wyłącznie komunikatem, że operacja jest dostępna w pełnym
 * serwisie WWW — bez otwierania jakiegokolwiek natywnego selektora plików.
 * Próby pobierania (`DownloadListener`) są celowo niepodłączone, więc
 * WebView nie inicjuje żadnego pobierania plików.
 *
 * Link zatwierdzenia 3DORS Author (ETAP 7, pkt 13 z dyspozycji) trafia tu
 * jak każdy inny link spoza allowlisty — jest otwierany poza aplikacją
 * ([Dors3AuthorLauncher]) — z tą różnicą, że po powrocie do ekranu strona
 * jest automatycznie odświeżana ([Dors3ResultHandler]), aby pokazać
 * zaktualizowany status z istniejącego serwera.
 */
@Composable
fun SecureWebView(
    url: String,
    modifier: Modifier = Modifier,
    extraAllowedHosts: Set<String> = emptySet(),
    onPageFinished: (String) -> Unit = {},
    onLogoutDetected: () -> Unit = {},
) {
    val context = LocalContext.current
    val pendingApproval = rememberDors3PendingApproval()
    var webViewRef by remember { mutableStateOf<WebView?>(null) }
    // Naprawa pkt 3.7 dyspozycji: zamiast surowego ekranu Chromium po błędzie
    // sieci pokazujemy brandowany [OfflineErrorScreen] z przyciskiem ponownej
    // próby, który po prostu przeladowuje ten sam adres.
    var hasError by remember { mutableStateOf(false) }
    var canGoBackInWebView by remember { mutableStateOf(false) }
    // Naprawa "Wylogowanie nie czyści stanu WebView" z audytu: klik w link
    // "Wyloguj" (`POST /logout`, patrz `isLogoutNavigationUrl`) jest
    // wykrywany na starcie żądania (przed ew. przekierowaniem serwera na
    // stronę publiczną) — po zakończeniu tej nawigacji czyścimy cookies,
    // WebStorage, cache i historię tej instancji WebView oraz informujemy
    // wywołującego ([pl.zrodloslowa.app.ui.auth.AuthGate]), by zresetował
    // stan ekranu do niezalogowanego.
    var pendingLogout by remember { mutableStateOf(false) }

    // Naprawa "Brak obsługi przycisku Wstecz wewnątrz WebView" z audytu:
    // gdy WebView ma własną historię, przycisk systemowy Wstecz przechodzi
    // po niej (np. z artykułu do listy), a nie wychodzi z ekranu/aplikacji.
    BackHandler(enabled = canGoBackInWebView) {
        webViewRef?.goBack()
    }

    Dors3ResultHandler(pendingApproval = pendingApproval) {
        webViewRef?.reload()
    }

    // Naprawa "Centralne wylogowanie: ... wszystkie WebView" (niezależny
    // audyt bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 6): bez tego, ekran
    // publiczny (np. Główna) mógł nadal pokazywać spersonalizowaną treść
    // sprzed wylogowania dokonanego na INNYM ekranie (np. Konto) — dopóki
    // użytkownik ręcznie nie odświeżył/nie zmienił strony. Każdy wzrost
    // [WebSessionManager.logoutEpoch] (pełne wylogowanie, cookies już
    // wyczyszczone) wymusza przeładowanie WSZYSTKICH aktywnych instancji
    // [SecureWebView] — nie tylko tej, na której kliknięto "Wyloguj".
    val logoutEpoch by WebSessionManager.logoutEpoch
    var lastHandledLogoutEpoch by remember { mutableStateOf(logoutEpoch) }
    androidx.compose.runtime.LaunchedEffect(logoutEpoch) {
        if (logoutEpoch != lastHandledLogoutEpoch) {
            lastHandledLogoutEpoch = logoutEpoch
            // Naprawa P1-3 z audytu ("Wylogowanie nie usuwa historii
            // wszystkich WebView"): samo `reload()` pozostawiało pełną
            // historię nawigacji i cache poprzedniej sesji w WebView, które
            // NIE były tym, na którym kliknięto "Wyloguj" — ryzyko powrotu
            // przyciskiem Wstecz do wcześniej otwartej treści innego konta.
            // Czyścimy cache/historię/dane formularzy każdej takiej instancji
            // analogicznie do [WebSessionManager.clearAllWebViewState] i
            // dopiero wtedy przeładowujemy.
            webViewRef?.let { webView ->
                webView.clearCache(true)
                webView.clearFormData()
                webView.clearHistory()
                webView.reload()
            }
        }
    }

    // Naprawa "Przy zmianie domeny/języka: nowy WebView; czysta historia"
    // (niezależny audyt bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 7):
    // wcześniejsza wersja ponownie używała TEJ SAMEJ instancji WebView i
    // wołała jedynie `loadUrl(nowyUrl)` przy zmianie hosta (np. zmiana
    // wersji językowej serwisu na inną domenę) — zachowując historię
    // nawigacji POPRZEDNIEJ domeny (przycisk Wstecz mógłby cofnąć do stron
    // poprzedniego języka). `key(currentHost)` wymusza utworzenie ZUPEŁNIE
    // NOWEJ instancji WebView (czysta historia) za każdym razem, gdy zmienia
    // się host — w obrębie tego samego hosta (np. przejście Artykuły ->
    // Portfel na tej samej domenie) instancja jest nadal współdzielona przez
    // `update`/`loadUrl`, zgodnie z zamierzonym zachowaniem przycisku Wstecz.
    val currentHost = remember(url) { runCatching { Uri.parse(url).host }.getOrNull() ?: url }
    // Zapamiętujemy ostatni adres zadany przez warstwę Compose, a nie bieżący
    // adres przeglądarki. `webView.url != url` jest błędne po nawigacji
    // użytkownika wewnątrz strony: zwykła rekompozycja cofałaby wtedy WebView
    // do adresu startowego i niszczyła historię/logowanie.
    var lastRequestedUrl by remember(currentHost) { mutableStateOf(url) }

    // Naprawa P2-1 z audytu ("Główny WebView nie jest niszczony"): brak
    // `destroy()` przy opuszczeniu ekranu/zmianie hosta powodował wycieki
    // pamięci i pozostawianie starej strony (oraz jej stanu) w pamięci.
    androidx.compose.runtime.DisposableEffect(currentHost) {
        onDispose {
            webViewRef?.let { webView ->
                webView.stopLoading()
                webView.clearHistory()
                (webView.parent as? android.view.ViewGroup)?.removeView(webView)
                webView.destroy()
            }
            webViewRef = null
        }
    }

    Box(modifier = modifier.fillMaxSize()) {
    androidx.compose.runtime.key(currentHost) {
        AndroidView(
            modifier = Modifier.fillMaxSize(),
            factory = {
                WebView(context).apply {
                    settings.javaScriptEnabled = true
                    settings.domStorageEnabled = true
                    settings.allowFileAccess = false
                    settings.allowContentAccess = false
                    settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
                    settings.safeBrowsingEnabled = true

                    WebSessionManager.attach(this)
                    installEarlyChromeHidingScript(this)

                    webViewClient = SecureWebViewClient(
                        extraAllowedHosts = extraAllowedHosts,
                        allowInsecureHttp = BuildConfig.DEBUG,
                        onExternalLinkRequested = { uri ->
                            when {
                                // Naprawa "nigdy nie otwieraj 3DORS Admin" z audytu: link do
                                // Admin jest jawnie rozpoznawany i BLOKOWANY — aplikacja główna
                                // (nie-Admin) nigdy nie otwiera go, nawet zewnętrznie.
                                Dors3AuthorLauncher.isAdminLink(uri) -> {
                                    android.widget.Toast.makeText(
                                        context,
                                        context.getString(pl.zrodloslowa.app.R.string.dors3_admin_blocked),
                                        android.widget.Toast.LENGTH_LONG,
                                    ).show()
                                }
                                Dors3AuthorLauncher.isApprovalLink(uri) -> {
                                    // Naprawa P0-1 z audytu:
                                    // - komunikat "Author niezainstalowany" ma wynikać z
                                    //   [android.content.pm.PackageManager], nie z faktu, że
                                    //   akurat brak przeglądarki (dla linku HTTPS przeglądarka
                                    //   niemal zawsze istnieje, więc `ActivityNotFoundException`
                                    //   nigdy by się nie rzucił nawet bez zainstalowanej apki);
                                    // - intencja jest jawnie przypięta do pakietu 3DORS Author
                                    //   ([Dors3AuthorLauncher.AUTHOR_PACKAGE_NAME]), więc link
                                    //   HTTPS nie może zostać po cichu obsłużony przez
                                    //   przeglądarkę zamiast właściwej aplikacji;
                                    // - operacja jest oznaczana jako "uruchomiona"
                                    //   ([Dors3PendingApproval.markLaunched]) DOPIERO po
                                    //   potwierdzeniu, że intencja rzeczywiście została
                                    //   przekazana do systemu (a nie przed próbą).
                                    if (openAuthorApp(context = context, uri = uri)) {
                                        pendingApproval.markLaunched()
                                    } else {
                                        android.widget.Toast.makeText(
                                            context,
                                            context.getString(pl.zrodloslowa.app.R.string.dors3_author_not_installed),
                                            android.widget.Toast.LENGTH_LONG,
                                        ).show()
                                    }
                                }
                                else -> openExternally(context = context, uri = uri)
                            }
                        },
                        onPageFinishedUrl = { finishedUrl ->
                            if (pendingLogout) {
                                pendingLogout = false
                                WebSessionManager.clearAllWebViewState(this) {
                                    onLogoutDetected()
                                }
                            } else {
                                WebSessionManager.persist()
                            }
                            hideWebSiteChrome(this)
                            canGoBackInWebView = canGoBack()
                            onPageFinished(finishedUrl)
                        },
                        onMainFrameError = { hasError = true },
                        onPageStarted = { startedUrl ->
                            hasError = false
                            canGoBackInWebView = canGoBack()
                            if (isLogoutNavigationUrl(startedUrl)) {
                                pendingLogout = true
                            }
                        },
                    )

                    webChromeClient = object : WebChromeClient() {
                        // Dyspozycja pkt 4.4: aplikacja czytnicza nie wysyła żadnych
                        // plików — próba uploadu (wybór pliku/aparat/galeria z
                        // treści strony) jest tu wyłącznie odrzucana, z jawnym
                        // komunikatem, że taka operacja jest dostępna w pełnym
                        // serwisie WWW. Żaden natywny selektor plików ani aparat
                        // nie jest tu otwierany.
                        override fun onShowFileChooser(
                            webView: WebView,
                            filePathCallback: ValueCallback<Array<Uri>>,
                            fileChooserParams: FileChooserParams,
                        ): Boolean {
                            android.widget.Toast.makeText(
                                context,
                                context.getString(pl.zrodloslowa.app.R.string.file_operation_web_only),
                                android.widget.Toast.LENGTH_LONG,
                            ).show()
                            filePathCallback.onReceiveValue(null)
                            return true
                        }
                    }

                    // Naprawa P2-2 z audytu ("Początkowy loadUrl() nie
                    // przechodzi przez walidację allowlisty"): `shouldOverrideUrlLoading`
                    // chroni wyłącznie nawigację WEWNĄTRZ strony — pierwszy,
                    // programowo wywołany `loadUrl()` nigdy nie przechodził
                    // przez tę samą kontrolę. Dziś adresy pochodzą wyłącznie
                    // ze sterowanych stałych, ale to niebezpieczna granica
                    // dla przyszłych deep linków — walidujemy go tą samą
                    // allowlistą, zanim w ogóle trafi do WebView.
                    val initialUri = Uri.parse(url)
                    if (WebViewAllowlist.isAllowedScheme(initialUri.scheme, BuildConfig.DEBUG) &&
                        WebViewAllowlist.isAllowedHost(initialUri.host, extraAllowedHosts) &&
                        WebViewAllowlist.isAllowedPort(initialUri.port, BuildConfig.DEBUG) &&
                        !WebViewAllowlist.isBlockedAdminPath(initialUri.path)
                    ) {
                        loadUrl(url)
                    } else {
                        hasError = true
                    }
                }.also { webViewRef = it }
            },
            update = { webView ->
                if (shouldLoadRequestedUrl(lastRequestedUrl, url)) {
                    val nextUri = Uri.parse(url)
                    if (WebViewAllowlist.isAllowedScheme(nextUri.scheme, BuildConfig.DEBUG) &&
                        WebViewAllowlist.isAllowedHost(nextUri.host, extraAllowedHosts) &&
                        WebViewAllowlist.isAllowedPort(nextUri.port, BuildConfig.DEBUG) &&
                        !WebViewAllowlist.isBlockedAdminPath(nextUri.path)
                    ) {
                        lastRequestedUrl = url
                        webView.loadUrl(url)
                    } else {
                        hasError = true
                    }
                }
            },
        )
    }

        if (hasError) {
            OfflineErrorScreen(
                onRetry = {
                    hasError = false
                    webViewRef?.reload()
                },
            )
        }
    }
}

/**
 * Rozpoznaje nawigację do trasy wylogowania istniejącego serwisu
 * (`POST /logout`, formularz w `views/layouts/main.php`) — bez tego,
 * kliknięcie „Wyloguj” na stronie zostawiało w aplikacji martwy, niezmiennie
 * "zalogowany" stan [pl.zrodloslowa.app.ui.auth.AuthGate] oraz cookies/
 * WebStorage poprzedniej sesji (naprawa błędu z audytu).
 */
internal fun isLogoutNavigationUrl(url: String?): Boolean =
    url != null && url.substringBefore('?').substringBefore('#').trimEnd('/').endsWith("/logout")

internal fun shouldLoadRequestedUrl(previousRequestedUrl: String, newRequestedUrl: String): Boolean =
    previousRequestedUrl != newRequestedUrl

private fun openExternally(
    context: android.content.Context,
    uri: Uri,
    onNotInstalled: () -> Unit = {},
) {
    try {
        context.startActivity(Intent(Intent.ACTION_VIEW, uri))
    } catch (_: ActivityNotFoundException) {
        // Naprawa "Brak informacji, gdy 3DORS Author nie jest dostępny" z
        // audytu: zamiast cichego pominięcia, wywołujący może pokazać
        // czytelny komunikat użytkownikowi (patrz onNotInstalled).
        onNotInstalled()
    }
}

/**
 * Naprawa P0-1 z audytu: uruchamia link zatwierdzenia 3DORS Author
 * WYŁĄCZNIE we WŁAŚCIWEJ, zainstalowanej aplikacji 3DORS Author
 * ([Dors3AuthorLauncher.AUTHOR_PACKAGE_NAME]) — nie w przeglądarce ani w
 * jakiejkolwiek innej aplikacji, która deklaruje obsługę schematu/hosta
 * (co dla `https` mogłaby zrobić dowolna zainstalowana aplikacja).
 * `PackageManager.resolveActivity` jest jedynym pewnym źródłem informacji
 * "czy 3DORS Author jest zainstalowany" — nie obecność przeglądarki.
 * Zwraca `true` tylko wtedy, gdy intencja rzeczywiście została przekazana
 * do systemu dla TEGO pakietu.
 */
internal fun openAuthorApp(context: android.content.Context, uri: Uri): Boolean {
    val intent = Intent(Intent.ACTION_VIEW, uri).apply {
        setPackage(Dors3AuthorLauncher.AUTHOR_PACKAGE_NAME)
    }
    val resolved = intent.resolveActivity(context.packageManager) != null
    if (!resolved) return false
    // Naprawa pkt 4.3 dyspozycji: samo rozwiązanie intencji dla pakietu
    // [Dors3AuthorLauncher.AUTHOR_PACKAGE_NAME] nie gwarantuje, że to
    // NAPRAWDĘ zaufana aplikacja 3DORS Author — sprawdzamy dodatkowo
    // fingerprint SHA-256 jej certyfikatu podpisującego (patrz
    // [Dors3AuthorLauncher.isAuthorAppSignatureTrusted]).
    if (!Dors3AuthorLauncher.isAuthorAppSignatureTrusted(context)) return false
    return try {
        context.startActivity(intent)
        true
    } catch (_: ActivityNotFoundException) {
        false
    }
}

/**
 * Naprawa błędu z audytu ("WebView może mignąć pełnym nagłówkiem strony"):
 * gdy urządzenie wspiera `WebViewFeature.DOCUMENT_START_SCRIPT`
 * (androidx.webkit), wstrzykujemy skrypt ukrywający nagłówek/stopkę PRZED
 * uruchomieniem jakiegokolwiek skryptu strony — a nie dopiero po
 * `onPageFinished`. Dzięki temu użytkownik nigdy nie widzi pełnego menu WWW,
 * nawet na chwilę. [hideWebSiteChrome] pozostaje jako uzupełnienie po
 * `onPageFinished` dla urządzeń bez tej funkcji WebView.
 */
private fun installEarlyChromeHidingScript(webView: WebView) {
    if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) return
    WebViewCompat.addDocumentStartJavaScript(
        webView,
        CHROME_HIDING_CSS_SCRIPT,
        setOf("*"),
    )
}

/**
 * Naprawa GŁÓWNEGO PROBLEMU z audytu (pkt 3.1/4): pełny nagłówek i stopka
 * strony WWW (`header.site-header`, `footer.site-footer`) nie nadają się
 * do aplikacji mobilnej — dublują natywną nawigację (dolne menu, kompaktowy
 * nagłówek aplikacji) i zabierają dużą część ekranu. Ukrywamy je wyłącznie
 * kontrolowanym stylem CSS wstrzykniętym po stronie aplikacji — bez żadnej
 * zmiany backendu ani publicznej strony WWW (ta sama strona otwarta w
 * zwykłej przeglądarce wygląda bez zmian).
 */
private fun hideWebSiteChrome(webView: WebView) {
    webView.evaluateJavascript(CHROME_HIDING_CSS_SCRIPT, null)
}

private const val CHROME_HIDING_CSS_SCRIPT = """
    (function() {
        var style = document.getElementById('zs-app-chrome-style');
        if (!style) {
            style = document.createElement('style');
            style.id = 'zs-app-chrome-style';
            (document.head || document.documentElement).appendChild(style);
        }
        style.textContent = '.site-header, .site-footer { display: none !important; }';
    })();
"""
