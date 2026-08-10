package pl.zrodloslowa.app.ui.common

import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.webview.SecureWebView
import pl.zrodloslowa.app.webview.WebUrlResolver
import pl.zrodloslowa.app.session.WebSessionManager

/**
 * Generyczny ekran WWW (ETAP 1, pkt 3.3 dyspozycji): pozwala otworzyć w
 * bezpiecznym [SecureWebView] dowolną podstronę bieżącej wersji językowej
 * serwisu wskazaną przez [path] (np. `surveys`, `campaigns`, `jak-zarabiac`,
 * `authors`, `register`) — używany przez pozycje menu hamburgera, które nie
 * mają własnej stałej zakładki w dolnym menu. Nie tworzy żadnej nowej trasy
 * backendu — otwiera wyłącznie istniejące adresy serwisu WWW.
 */
@Composable
fun WebPageScreen(path: String, languageCode: String = "pl", modifier: Modifier = Modifier) {
    val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val pageUrl = remember(site, path) { WebUrlResolver.pageUrl(site, path) }
    val extraAllowedHosts = remember(site) { WebUrlResolver.extraAllowedHosts(WebUrlResolver.baseUrl(site)) }

    SecureWebView(
        url = pageUrl,
        modifier = modifier,
        extraAllowedHosts = extraAllowedHosts,
        onPageFinished = {
            if (path.substringBefore('?').trim('/').equals("register", ignoreCase = true)) {
                WebSessionManager.verifySession(WebUrlResolver.baseUrl(site)) {}
            }
        },
    )
}
