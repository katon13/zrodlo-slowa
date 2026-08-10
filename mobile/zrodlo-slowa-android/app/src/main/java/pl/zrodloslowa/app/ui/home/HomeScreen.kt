package pl.zrodloslowa.app.ui.home

import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.webview.SecureWebView
import pl.zrodloslowa.app.webview.WebUrlResolver

/**
 * Ekran Główna (ETAP 3 z dyspozycji): strona główna serwisu wyświetlana w
 * bezpiecznym WebView ([SecureWebView], ETAP 2) — to stąd pochodzą kolorowe
 * zdjęcia i najnowsze artykuły widoczne na stronie głównej serwisu WWW.
 * Ekran publiczny — nie wymaga logowania.
 */
@Composable
fun HomeScreen(languageCode: String = "pl", modifier: Modifier = Modifier) {
    val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val baseUrl = remember(site) { WebUrlResolver.baseUrl(site) }
    val extraAllowedHosts = remember(baseUrl) { WebUrlResolver.extraAllowedHosts(baseUrl) }

    SecureWebView(
        url = baseUrl,
        modifier = modifier,
        extraAllowedHosts = extraAllowedHosts,
    )
}
