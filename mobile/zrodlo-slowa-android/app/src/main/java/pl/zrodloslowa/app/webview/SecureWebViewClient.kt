package pl.zrodloslowa.app.webview

import android.net.Uri
import android.net.http.SslError
import android.webkit.SslErrorHandler
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient

/**
 * `WebViewClient` bezpiecznego WebView (ETAP 2 z dyspozycji):
 * - nawigacja wewnątrz WebView jest dozwolona wyłącznie w obrębie allowlisty
 *   ([WebViewAllowlist]) — każdy inny link (OAuth, płatności zewnętrzne,
 *   odnośniki w treści) jest przekazywany na zewnątrz aplikacji przez
 *   [onExternalLinkRequested], zamiast być otwierany w tym WebView;
 * - błędy certyfikatu TLS NIGDY nie są ignorowane (brak `handler.proceed()`),
 *   zgodnie z wymogiem "bezpieczny WebView" z dyspozycji.
 *
 * Naprawa "Zablokuj dowolne zewnętrzne intencje" (niezależny audyt
 * bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 2): wcześniejsza wersja otwierała
 * zewnętrzną intencję ([onExternalLinkRequested]) dla KAŻDEGO żądania spoza
 * allowlisty — również z ramki podrzędnej (`iframe`) i bez żadnej interakcji
 * użytkownika (np. skrypt strony mógłby automatycznie „kliknąć” link
 * `intent://...` i uruchomić dowolną aplikację/link bez wiedzy użytkownika).
 * Teraz zewnętrzna intencja jest uruchamiana wyłącznie, gdy jednocześnie:
 * - żądanie dotyczy głównej ramki ([WebResourceRequest.isForMainFrame]);
 * - nawigacja wynika z gestu użytkownika ([WebResourceRequest.hasGesture]);
 * - schemat linku jest na jawnej allowliście schematów zewnętrznych
 *   ([WebViewAllowlist.isAllowedExternalScheme]) — `intent`, `file`,
 *   `content` i inne nieznane schematy są całkowicie blokowane, nigdy nie
 *   trafiają do [android.content.Intent.ACTION_VIEW].
 * We wszystkich pozostałych przypadkach (podrama, brak gestu, zablokowany
 * schemat) żądanie jest po prostu anulowane — bez otwierania czegokolwiek.
 */
class SecureWebViewClient(
    private val extraAllowedHosts: Set<String> = emptySet(),
    private val allowInsecureHttp: Boolean = false,
    private val onExternalLinkRequested: (Uri) -> Unit = {},
    private val onPageFinishedUrl: (String) -> Unit = {},
    private val onMainFrameError: () -> Unit = {},
    private val onPageStarted: (String?) -> Unit = {},
) : WebViewClient() {

    override fun onPageStarted(view: WebView, url: String?, favicon: android.graphics.Bitmap?) {
        super.onPageStarted(view, url, favicon)
        onPageStarted.invoke(url)
    }

    override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
        val uri = request.url
        val allowed = WebViewAllowlist.isAllowedScheme(uri.scheme, allowInsecureHttp) &&
            WebViewAllowlist.isAllowedHost(uri.host, extraAllowedHosts) &&
            WebViewAllowlist.isAllowedPort(uri.port, allowInsecureHttp) &&
            !WebViewAllowlist.isBlockedAdminPath(uri.path)
        if (allowed) {
            return false
        }
        if (request.isForMainFrame &&
            request.hasGesture() &&
            WebViewAllowlist.isAllowedExternalScheme(uri.scheme, allowInsecureHttp)
        ) {
            onExternalLinkRequested(uri)
        }
        return true
    }

    override fun onPageFinished(view: WebView, url: String?) {
        super.onPageFinished(view, url)
        if (url != null) {
            onPageFinishedUrl(url)
        }
    }

    override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) {
        // Celowo NIE wywołujemy handler.proceed() — błąd certyfikatu zawsze
        // przerywa ładowanie strony (anuluje żądanie).
        handler.cancel()
    }

    override fun onReceivedError(
        view: WebView,
        request: WebResourceRequest,
        error: WebResourceError,
    ) {
        super.onReceivedError(view, request, error)
        // Naprawa pkt 3.7 dyspozycji: zamiast surowego ekranu Chromium
        // ("Webpage not available") pokazujemy brandowany OfflineErrorScreen
        // — wyłącznie dla głównej ramki, aby drobne błędy zasług (np.
        // brakujący obrazek) nie przykrywały całej treści strony.
        if (request.isForMainFrame) {
            onMainFrameError()
        }
    }
}
