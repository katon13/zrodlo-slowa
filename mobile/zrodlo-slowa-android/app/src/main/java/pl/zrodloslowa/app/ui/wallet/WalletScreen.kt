package pl.zrodloslowa.app.ui.wallet

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.ui.auth.AuthGate
import pl.zrodloslowa.app.webview.SecureWebView
import pl.zrodloslowa.app.webview.WebUrlResolver

/**
 * Ekran Portfel (ETAP 4 z dyspozycji): `GET /wallet` (wraz z podstronami
 * doładowania, transferu TT→PLN i wypłat — zgodnie z audytem, wszystkie
 * gotowe w WebView po stronie serwera) wyświetlane w bezpiecznym
 * [SecureWebView] (ETAP 2). Wymaga zalogowania — bramkuje treść przez
 * [AuthGate] (ETAP 2); rozróżnienie czytelnik/autor (np. widoczność
 * transferu TT→PLN) jest wyliczane po stronie serwera na podstawie danych
 * konta, bez żadnej natywnej logiki uprawnień w aplikacji.
 */
@Composable
fun WalletScreen(languageCode: String = "pl", modifier: Modifier = Modifier) {
    AuthGate(languageCode = languageCode) { onLogoutDetected ->
        val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
        val walletUrl = remember(site) { WebUrlResolver.pageUrl(site, "wallet") }
        val extraAllowedHosts = remember(site) { WebUrlResolver.extraAllowedHosts(WebUrlResolver.baseUrl(site)) }

        SecureWebView(
            url = walletUrl,
            modifier = modifier.fillMaxSize(),
            extraAllowedHosts = extraAllowedHosts,
            onLogoutDetected = onLogoutDetected,
        )
    }
}
