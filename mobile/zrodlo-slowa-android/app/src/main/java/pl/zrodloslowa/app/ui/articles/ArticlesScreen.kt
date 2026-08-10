package pl.zrodloslowa.app.ui.articles

import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.webview.SecureWebView
import pl.zrodloslowa.app.webview.WebUrlResolver

/**
 * Ekran Artykuły (ETAP 3 z dyspozycji): lista artykułów serwisu (`/articles`)
 * wyświetlana w bezpiecznym WebView ([SecureWebView], ETAP 2), wraz z
 * kolorowymi zdjęciami dołączonymi do artykułów przez serwis WWW.
 * Ekran publiczny — nie wymaga logowania.
 */
@Composable
fun ArticlesScreen(languageCode: String = "pl", modifier: Modifier = Modifier) {
    val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val articlesUrl = remember(site) { WebUrlResolver.pageUrl(site, "articles") }
    val extraAllowedHosts = remember(site) { WebUrlResolver.extraAllowedHosts(WebUrlResolver.baseUrl(site)) }

    SecureWebView(
        url = articlesUrl,
        modifier = modifier,
        extraAllowedHosts = extraAllowedHosts,
    )
}
